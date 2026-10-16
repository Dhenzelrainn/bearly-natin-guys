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
            ->where('product_status', '!=', 'archived')
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

        $voucherAmountMinor = $this->moneyToMinor((string) ($data['value'] ?? ''), 'value');

        if ($voucherAmountMinor < 1) {
            throw ValidationException::withMessages([
                'value' => 'Enter a voucher amount greater than zero.',
            ]);
        }

        $minimumSpendMinor = $this->moneyToMinor((string) ($data['minimum_spend'] ?? '0'), 'minimum_spend');

        return DB::transaction(function () use (
            $user,
            $data,
            $type,
            $productIds,
            $voucherAmountMinor,
            $minimumSpendMinor,
        ): StorePromotion {
            $store = $this->products->storeFor($user);
            $eligibleIds = $store->products()
                ->whereIn('id', $productIds)
                ->whereNull('removed_at')
                ->where('product_status', '!=', 'archived')
                ->whereHas('variants', fn ($variants) => $variants->where('is_active', true))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($eligibleIds->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'products' => 'Every selected product must belong to your Store, be available for promotion, and have an active SKU.',
                ]);
            }

            $promotion = $store->promotions()->create([
                'created_by' => $user->id,
                'name' => trim((string) $data['name']),
                'type' => $type,
                'voucher_amount_minor' => $voucherAmountMinor,
                'starts_on' => $data['start_date'],
                'ends_on' => $data['end_date'],
                'minimum_spend_minor' => $minimumSpendMinor,
                'redemption_limit' => filled($data['redemption_limit'] ?? null)
                    ? (int) $data['redemption_limit']
                    : null,
                'per_buyer_limit' => (int) $data['buyer_limit'],
            ]);

            $promotion->products()->attach($eligibleIds->all());

            return $promotion->load('products');
        });
    }

    /**
     * @return array{campaigns: Collection<int, array<string, mixed>>, active_count: int, ending_soon_count: int}
     */
    public function listingFor(User $user): array
    {
        $store = $this->products->storeFor($user);
        $today = today();
        $endingSoonDate = $today->copy()->addDays(2)->toDateString();
        $campaigns = $store->promotions()
            ->withCount('products')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(function (StorePromotion $promotion) use ($endingSoonDate): array {
                $statusKey = $promotion->statusKey();
                $type = $promotion->type;
                $offer = $type === StorePromotion::TYPE_PRODUCT_DISCOUNT
                    ? ((int) $promotion->discount_percent).'% off'
                    : '₱'.number_format(((int) $promotion->voucher_amount_minor) / 100, 2).' voucher';
                $minimumSpend = (int) $promotion->minimum_spend_minor;
                $terms = [
                    $minimumSpend > 0
                        ? 'Min. spend ₱'.number_format($minimumSpend / 100, 2)
                        : 'No minimum spend',
                    $promotion->redemption_limit !== null
                        ? number_format((int) $promotion->redemption_limit).' total redemptions'
                        : 'No total redemption limit',
                    (int) $promotion->per_buyer_limit.' per buyer',
                ];

                return [
                    'id' => (int) $promotion->id,
                    'name' => $promotion->name,
                    'type_key' => $type,
                    'type' => $type === StorePromotion::TYPE_PRODUCT_DISCOUNT ? 'Product Discount' : 'Store Voucher',
                    'offer' => $offer,
                    'period' => $promotion->starts_on->format('M j, Y').' – '.$promotion->ends_on->format('M j, Y'),
                    'terms' => implode(' · ', $terms),
                    'product_count' => (int) $promotion->products_count,
                    'status_key' => $statusKey,
                    'status' => match ($statusKey) {
                        StorePromotion::STATUS_ACTIVE => 'In date range',
                        StorePromotion::STATUS_SCHEDULED => 'Scheduled',
                        default => 'Ended',
                    },
                    'ends_soon' => $statusKey === StorePromotion::STATUS_ACTIVE
                        && $promotion->ends_on->toDateString() <= $endingSoonDate,
                ];
            });

        return [
            'campaigns' => $campaigns,
            'active_count' => $campaigns->where('status_key', StorePromotion::STATUS_ACTIVE)->count(),
            'ending_soon_count' => $campaigns->where('ends_soon', true)->count(),
        ];
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
}
