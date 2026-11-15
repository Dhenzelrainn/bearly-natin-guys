<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StorePromotion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerPromotionService
{
    public function __construct(private SellerProductService $products) {}

    /** @return Collection<int, array<string, mixed>> */
    public function eligibleProductsFor(User $user): Collection
    {
        $store = $this->products->storeFor($user);

        return $store->products()
            ->whereNull('removed_at')
            ->where('product_status', 'active')
            ->where('compliance_status', 'clear')
            ->whereHas('variants', fn ($variants) => $variants->where('is_active', true))
            ->with([
                'category',
                'subcategory',
                'variants' => fn ($variants) => $variants
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->orderBy('id'),
                'images' => fn ($images) => $images
                    ->whereNull('variant_id')
                    ->orderBy('position'),
                'violations' => fn ($violations) => $violations
                    ->whereHas('warnings', fn ($warnings) => $warnings->where('status', 'active'))
                    ->with(['warnings' => fn ($warnings) => $warnings
                        ->where('status', 'active')
                        ->orderByDesc('issued_at')]),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Product $product): array => $this->products->formPayload($product));
    }

    public function create(User $user, array $data): StorePromotion
    {
        $type = (string) ($data['type'] ?? '');
        $productIds = collect($data['products'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            throw ValidationException::withMessages([
                'products' => 'Select at least one eligible product.',
            ]);
        }

        if ($type !== StorePromotion::TYPE_STORE_VOUCHER) {
            throw ValidationException::withMessages([
                'type' => 'Only Store Voucher campaigns can be created here. Manage product discounts in Product Pricing.',
            ]);
        }

        $isPublic = (bool) ($data['is_public'] ?? true);
        $isClaimable = (bool) ($data['is_claimable'] ?? true);
        if ($isClaimable && ! $isPublic) {
            throw ValidationException::withMessages([
                'is_claimable' => 'A private voucher cannot be claimed from the public Buyer wallet.',
            ]);
        }
        if ((! $isPublic || ! $isClaimable) && ! filled($data['code'] ?? null)) {
            throw ValidationException::withMessages([
                'code' => 'A private or code-only Store Voucher requires a voucher code.',
            ]);
        }

        $discountType = (string) ($data['discount_type'] ?? StorePromotion::DISCOUNT_FIXED);
        if (! in_array($discountType, [StorePromotion::DISCOUNT_FIXED, StorePromotion::DISCOUNT_PERCENT], true)) {
            throw ValidationException::withMessages([
                'discount_type' => 'Choose a fixed amount or percentage discount.',
            ]);
        }

        $voucherAmountMinor = $discountType === StorePromotion::DISCOUNT_FIXED
            ? $this->moneyToMinor((string) ($data['value'] ?? ''), 'value')
            : null;

        if ($discountType === StorePromotion::DISCOUNT_FIXED && (int) $voucherAmountMinor < 1) {
            throw ValidationException::withMessages([
                'value' => 'Enter a voucher amount greater than zero.',
            ]);
        }

        $discountPercent = $discountType === StorePromotion::DISCOUNT_PERCENT
            ? (int) ($data['discount_percent'] ?? 0)
            : null;

        if ($discountType === StorePromotion::DISCOUNT_PERCENT && ($discountPercent < 1 || $discountPercent > 100)) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Enter a discount percentage between 1 and 100.',
            ]);
        }

        $maxDiscountMinor = filled($data['max_discount'] ?? null)
            ? $this->moneyToMinor((string) $data['max_discount'], 'max_discount')
            : null;
        $minimumSpendMinor = $this->moneyToMinor((string) ($data['minimum_spend'] ?? '0'), 'minimum_spend');

        return DB::transaction(function () use (
            $user,
            $data,
            $type,
            $productIds,
            $voucherAmountMinor,
            $discountType,
            $discountPercent,
            $maxDiscountMinor,
            $minimumSpendMinor,
            $isPublic,
            $isClaimable,
        ): StorePromotion {
            $store = $this->products->storeFor($user);
            $eligibleIds = $store->products()
                ->whereIn('id', $productIds)
                ->where('voucher_eligible', true)
                ->whereNull('removed_at')
                ->where('product_status', 'active')
                ->where('compliance_status', 'clear')
                ->whereHas('variants', fn ($variants) => $variants->where('is_active', true))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($eligibleIds->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'products' => 'Every selected product must belong to your Store, be available, have an active SKU, and be enabled for Store Vouchers in Product Management.',
                ]);
            }

            $promotion = $store->promotions()->create([
                'created_by' => $user->id,
                'name' => filled($data['name'] ?? null)
                    ? trim((string) $data['name'])
                    : $this->voucherName($discountType, $discountPercent, $voucherAmountMinor),
                'code' => filled($data['code'] ?? null) ? strtoupper(trim((string) $data['code'])) : null,
                'type' => $type,
                'status' => StorePromotion::STATUS_ACTIVE,
                'funding_type' => StorePromotion::FUNDING_SELLER,
                'discount_type' => $discountType,
                'voucher_amount_minor' => $voucherAmountMinor,
                'discount_percent' => $discountPercent,
                'max_discount_minor' => $maxDiscountMinor,
                'terms' => filled($data['terms'] ?? null) ? trim((string) $data['terms']) : null,
                'starts_on' => $data['start_date'],
                'ends_on' => $data['end_date'],
                'starts_time' => StorePromotion::timeFromInput($data['start_time']),
                'ends_time' => StorePromotion::timeFromInput($data['end_time'], inclusiveMinute: true),
                'minimum_spend_minor' => $minimumSpendMinor,
                'redemption_limit' => filled($data['redemption_limit'] ?? null)
                    ? (int) $data['redemption_limit']
                    : null,
                'per_buyer_limit' => (int) $data['buyer_limit'],
                'is_claimable' => $isClaimable,
                'is_public' => $isPublic,
            ]);

            $promotion->products()->attach($eligibleIds->all());

            return $promotion->load('products');
        });
    }

    /**
     * @return array{
     *     campaigns: Collection<int, array<string, mixed>>,
     *     active_count: int,
     *     scheduled_count: int,
     *     claimed_count: int,
     *     redeemed_count: int,
     *     ending_soon_count: int
     * }
     */
    public function listingFor(User $user): array
    {
        $store = $this->products->storeFor($user);
        $endingSoonAt = now('Asia/Manila')->copy()->addDays(2);
        $campaigns = $store->promotions()
            ->where('type', StorePromotion::TYPE_STORE_VOUCHER)
            ->with(['products:id,name'])
            ->withCount([
                'products',
                'claims',
                'redemptions as active_redemptions_count' => fn ($query) => $query
                    ->whereIn('status', ['reserved', 'redeemed']),
            ])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(function (StorePromotion $promotion) use ($endingSoonAt): array {
                $statusKey = $promotion->statusKey();
                $offer = $promotion->isPercentDiscount()
                    ? ((int) $promotion->discount_percent).'% off'
                        .($promotion->max_discount_minor ? ' up to ₱'.number_format((int) $promotion->max_discount_minor / 100, 2) : '')
                    : '₱'.number_format(((int) $promotion->voucher_amount_minor) / 100, 2).' off';
                $minimumSpend = (int) $promotion->minimum_spend_minor;
                $terms = [
                    $promotion->code ? 'Code '.$promotion->code : 'Claim in My Vouchers',
                    $minimumSpend > 0
                        ? 'Min. spend ₱'.number_format($minimumSpend / 100, 2)
                        : 'No minimum spend',
                    $promotion->redemption_limit !== null
                        ? number_format((int) $promotion->redemption_limit).' total redemptions'
                        : 'No total redemption limit',
                    (int) $promotion->per_buyer_limit.' per buyer',
                ];
                $productNames = $promotion->products->pluck('name');

                return [
                    'id' => (int) $promotion->id,
                    'name' => $promotion->name,
                    'code' => $promotion->code,
                    'type_key' => $promotion->type,
                    'type' => 'Store Voucher',
                    'offer' => $offer,
                    'period' => $promotion->schedulePeriod(),
                    'terms' => implode(' · ', $terms),
                    'product_count' => (int) $promotion->products_count,
                    'product_names' => $productNames->take(2)->join(', ').($productNames->count() > 2 ? ' +'.($productNames->count() - 2) : ''),
                    'claimed_count' => (int) $promotion->claims_count,
                    'used_count' => (int) $promotion->active_redemptions_count,
                    'redemption_limit' => $promotion->redemption_limit,
                    'per_buyer_limit' => (int) $promotion->per_buyer_limit,
                    'minimum_spend_minor' => $minimumSpend,
                    'is_public' => (bool) $promotion->is_public,
                    'status_key' => $statusKey,
                    'status' => match ($statusKey) {
                        StorePromotion::STATUS_ACTIVE => 'Active',
                        StorePromotion::STATUS_PAUSED => 'Paused',
                        StorePromotion::STATUS_SCHEDULED => 'Scheduled',
                        StorePromotion::STATUS_DRAFT => 'Draft',
                        default => 'Ended',
                    },
                    'ends_soon' => $statusKey === StorePromotion::STATUS_ACTIVE
                        && $promotion->endsAt()?->lessThanOrEqualTo($endingSoonAt),
                ];
            });

        return [
            'campaigns' => $campaigns,
            'active_count' => $campaigns->where('status_key', StorePromotion::STATUS_ACTIVE)->count(),
            'scheduled_count' => $campaigns->where('status_key', StorePromotion::STATUS_SCHEDULED)->count(),
            'claimed_count' => $campaigns->sum('claimed_count'),
            'redeemed_count' => $campaigns->sum('used_count'),
            'ending_soon_count' => $campaigns->where('ends_soon', true)->count(),
        ];
    }

    public function updateStatus(User $user, StorePromotion $promotion, string $status): StorePromotion
    {
        if (! in_array($status, [
            StorePromotion::STATUS_ACTIVE,
            StorePromotion::STATUS_PAUSED,
            StorePromotion::STATUS_ENDED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose a valid voucher status.',
            ]);
        }

        return DB::transaction(function () use ($user, $promotion, $status): StorePromotion {
            $store = $this->products->storeFor($user);
            $promotion = StorePromotion::query()
                ->where('store_id', $store->id)
                ->where('type', StorePromotion::TYPE_STORE_VOUCHER)
                ->lockForUpdate()
                ->findOrFail($promotion->id);

            if ($promotion->status === StorePromotion::STATUS_ENDED && $status !== StorePromotion::STATUS_ENDED) {
                throw ValidationException::withMessages([
                    'status' => 'Ended vouchers cannot be reactivated. Create a new voucher instead.',
                ]);
            }

            if ($status === StorePromotion::STATUS_ACTIVE
                && $promotion->endsAt()?->lessThan(now('Asia/Manila'))) {
                throw ValidationException::withMessages([
                    'status' => 'This voucher has already passed its end time.',
                ]);
            }

            $updates = [
                'status' => $status,
            ];
            if ($status === StorePromotion::STATUS_ENDED) {
                $now = now('Asia/Manila');
                $updates['ends_on'] = $now->toDateString();
                $updates['ends_time'] = $now->format('H:i:s');
            }
            $promotion->update($updates);

            return $promotion->fresh('products');
        }, attempts: 3);
    }

    private function moneyToMinor(string $value, string $field): int
    {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid peso amount with up to two decimal places.',
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function voucherName(string $discountType, ?int $discountPercent, ?int $voucherAmountMinor): string
    {
        if ($discountType === StorePromotion::DISCOUNT_PERCENT) {
            return (int) $discountPercent.'% off voucher';
        }

        $minor = max(0, (int) $voucherAmountMinor);
        $amount = intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0');

        return '₱'.$amount.' off voucher';
    }
}
