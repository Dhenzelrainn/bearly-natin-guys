<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StorePromotion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerProductDiscountService
{
    public function __construct(private SellerProductService $products) {}

    /**
     * @return array{
     *     products: Collection<int, array<string, mixed>>,
     *     promotions: Collection<int, StorePromotion>,
     *     summary: array<string, int|string>
     * }
     */
    public function listingFor(User $user): array
    {
        $store = $this->products->storeFor($user);
        $today = now('Asia/Manila');
        $endingSoon = $today->copy()->addDays(2);
        $products = $this->products->productsForSeller($user);

        $promotions = $store->promotions()
            ->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)
            ->with('products:id')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $byProduct = [];
        foreach ($promotions as $promotion) {
            foreach ($promotion->products as $product) {
                $productId = (int) $product->id;
                $existing = $byProduct[$productId] ?? null;
                $statusRank = $this->statusRank($promotion->statusKey());
                $existingRank = $existing ? $this->statusRank($existing->statusKey()) : -1;
                if (! $existing || $statusRank > $existingRank) {
                    $byProduct[$productId] = $promotion;
                }
            }
        }

        $rows = $products->map(function (array $product) use ($byProduct): array {
            $promotion = $byProduct[(int) $product['id']] ?? null;
            $statusKey = $promotion?->statusKey();
            $regular = (float) ($product['price'] ?? 0);
            $sellerDiscount = (int) ($product['discount_percent'] ?? 0);
            $sellerPrice = $sellerDiscount > 0
                ? round($regular * (1 - ($sellerDiscount / 100)), 2)
                : $regular;
            $isPlanned = in_array($statusKey, [StorePromotion::STATUS_ACTIVE, StorePromotion::STATUS_SCHEDULED], true);
            $promoPrice = $sellerPrice;
            if ($isPlanned && $promotion && $promotion->discount_type === StorePromotion::DISCOUNT_PERCENT) {
                $promoPrice = round($regular * (1 - ((int) $promotion->discount_percent / 100)), 2);
            }

            return [
                ...$product,
                'regular_price' => $regular,
                'seller_price' => $sellerPrice,
                'promo_price' => $promoPrice,
                'promo_discount_percent' => $isPlanned && $promotion ? (int) $promotion->discount_percent : null,
                'promotion_id' => $promotion?->id,
                'promotion_name' => $promotion?->name,
                'buyer_campaign_name' => $promotion?->buyer_campaign_name,
                'promotion_status_key' => $statusKey,
                'promotion_status' => $promotion ? $this->statusLabel($statusKey) : 'No product discount',
                'promotion_period' => $promotion
                    ? $promotion->schedulePeriod()
                    : null,
                'promotion_ends_at' => $promotion?->endsAt()?->toIso8601String(),
                'promotion_is_active' => $statusKey === StorePromotion::STATUS_ACTIVE,
            ];
        })->values();

        $activePromotions = $promotions->filter(fn (StorePromotion $promotion): bool => $promotion->statusKey() === StorePromotion::STATUS_ACTIVE);
        $scheduledPromotions = $promotions->filter(fn (StorePromotion $promotion): bool => $promotion->statusKey() === StorePromotion::STATUS_SCHEDULED);
        $discountedProducts = $rows->filter(fn (array $row): bool => $row['promotion_is_active']
            || (int) ($row['discount_percent'] ?? 0) > 0)->count();
        $endingSoonCount = $activePromotions->filter(fn (StorePromotion $promotion): bool => $promotion->endsAt()?->lessThanOrEqualTo($endingSoon) ?? false)->count();

        return [
            'products' => $rows,
            'promotions' => $promotions,
            'summary' => [
                'active' => $activePromotions->count(),
                'scheduled' => $scheduledPromotions->count(),
                'discounted_products' => $discountedProducts,
                'ending_soon' => $endingSoonCount,
            ],
        ];
    }

    public function create(User $user, array $data): StorePromotion
    {
        return DB::transaction(function () use ($user, $data): StorePromotion {
            $store = $this->products->storeFor($user);
            $productIds = $this->ownedProductIds($store->id, $data['products'] ?? []);
            $startsAt = StorePromotion::dateTimeFromInput($data['start_date'], $data['start_time']);
            $endsAt = StorePromotion::dateTimeFromInput($data['end_date'], $data['end_time'], inclusiveMinute: true);
            $this->assertNoOverlap($store->id, $productIds, $startsAt, $endsAt);

            $promotion = $store->promotions()->create([
                'created_by' => $user->id,
                'name' => trim((string) $data['name']),
                'buyer_campaign_name' => filled($data['buyer_campaign_name'] ?? null)
                    ? trim((string) $data['buyer_campaign_name'])
                    : null,
                'code' => null,
                'type' => StorePromotion::TYPE_PRODUCT_DISCOUNT,
                'status' => StorePromotion::STATUS_ACTIVE,
                'funding_type' => StorePromotion::FUNDING_SELLER,
                'discount_type' => StorePromotion::DISCOUNT_PERCENT,
                'discount_percent' => (int) $data['discount_percent'],
                'voucher_amount_minor' => null,
                'max_discount_minor' => null,
                'minimum_spend_minor' => 0,
                'redemption_limit' => null,
                'per_buyer_limit' => 1,
                'is_claimable' => false,
                'is_public' => true,
                'starts_on' => $data['start_date'],
                'ends_on' => $data['end_date'],
                'starts_time' => StorePromotion::timeFromInput($data['start_time']),
                'ends_time' => StorePromotion::timeFromInput($data['end_time'], inclusiveMinute: true),
            ]);

            $promotion->products()->attach($productIds);

            return $promotion->load('products');
        }, attempts: 3);
    }

    public function update(User $user, StorePromotion $promotion, array $data): StorePromotion
    {
        return DB::transaction(function () use ($user, $promotion, $data): StorePromotion {
            $store = $this->products->storeFor($user);
            $promotion = StorePromotion::query()
                ->where('store_id', $store->id)
                ->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)
                ->lockForUpdate()
                ->findOrFail($promotion->id);

            if ($promotion->status === StorePromotion::STATUS_ENDED) {
                throw ValidationException::withMessages([
                    'promotion' => 'Ended product discounts cannot be edited. Duplicate it to schedule a new one.',
                ]);
            }

            $productIds = $this->ownedProductIds($store->id, $data['products'] ?? []);
            $startsAt = StorePromotion::dateTimeFromInput($data['start_date'], $data['start_time']);
            $endsAt = StorePromotion::dateTimeFromInput($data['end_date'], $data['end_time'], inclusiveMinute: true);
            $this->assertNoOverlap(
                $store->id,
                $productIds,
                $startsAt,
                $endsAt,
                (int) $promotion->id,
            );

            $promotion->update([
                'name' => trim((string) $data['name']),
                'buyer_campaign_name' => filled($data['buyer_campaign_name'] ?? null)
                    ? trim((string) $data['buyer_campaign_name'])
                    : null,
                'discount_type' => StorePromotion::DISCOUNT_PERCENT,
                'discount_percent' => (int) $data['discount_percent'],
                'starts_on' => $data['start_date'],
                'ends_on' => $data['end_date'],
                'starts_time' => StorePromotion::timeFromInput($data['start_time']),
                'ends_time' => StorePromotion::timeFromInput($data['end_time'], inclusiveMinute: true),
            ]);
            $promotion->products()->sync($productIds);

            return $promotion->fresh('products');
        }, attempts: 3);
    }

    public function pause(User $user, StorePromotion $promotion): StorePromotion
    {
        return $this->setStatus($user, $promotion, StorePromotion::STATUS_PAUSED);
    }

    public function resume(User $user, StorePromotion $promotion): StorePromotion
    {
        return DB::transaction(function () use ($user, $promotion): StorePromotion {
            $promotion = $this->ownedPromotion($user, $promotion);
            if ($promotion->endsAt()?->lessThan(now('Asia/Manila'))) {
                throw ValidationException::withMessages([
                    'promotion' => 'This product discount has already ended. Duplicate it to schedule a new one.',
                ]);
            }

            $productIds = $promotion->products()->pluck('products.id')->map(fn ($id): int => (int) $id)->all();
            $this->assertNoOverlap(
                (int) $promotion->store_id,
                $productIds,
                $promotion->startsAt(),
                $promotion->endsAt(),
                (int) $promotion->id,
            );

            $promotion->update(['status' => StorePromotion::STATUS_ACTIVE]);

            return $promotion->fresh('products');
        }, attempts: 3);
    }

    public function end(User $user, StorePromotion $promotion): StorePromotion
    {
        return DB::transaction(function () use ($user, $promotion): StorePromotion {
            $promotion = $this->ownedPromotion($user, $promotion);
            $now = now('Asia/Manila');
            $promotion->update([
                'status' => StorePromotion::STATUS_ENDED,
                'ends_on' => $now->toDateString(),
                'ends_time' => $now->format('H:i:s'),
            ]);

            return $promotion->fresh('products');
        }, attempts: 3);
    }

    public function duplicate(User $user, StorePromotion $promotion): StorePromotion
    {
        return DB::transaction(function () use ($user, $promotion): StorePromotion {
            $store = $this->products->storeFor($user);
            $promotion = StorePromotion::query()
                ->where('store_id', $store->id)
                ->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)
                ->with('products:id')
                ->findOrFail($promotion->id);

            $start = now('Asia/Manila')->addDay()->toDateString();
            $originalDays = max(0, $promotion->starts_on->diffInDays($promotion->ends_on));
            $end = now('Asia/Manila')->addDay()->addDays(max(1, $originalDays))->toDateString();
            $startTime = substr((string) $promotion->starts_time, 0, 5);
            $endTime = substr((string) $promotion->ends_time, 0, 5);
            $startsAt = StorePromotion::dateTimeFromInput($start, $startTime);
            $endsAt = StorePromotion::dateTimeFromInput($end, $endTime, inclusiveMinute: true);
            $productIds = $promotion->products->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $this->assertNoOverlap($store->id, $productIds, $startsAt, $endsAt);

            $copy = $store->promotions()->create([
                'created_by' => $user->id,
                'name' => mb_substr($promotion->name.' copy', 0, 80),
                'buyer_campaign_name' => $promotion->buyer_campaign_name,
                'code' => null,
                'type' => StorePromotion::TYPE_PRODUCT_DISCOUNT,
                'status' => StorePromotion::STATUS_ACTIVE,
                'funding_type' => StorePromotion::FUNDING_SELLER,
                'discount_type' => StorePromotion::DISCOUNT_PERCENT,
                'discount_percent' => (int) $promotion->discount_percent,
                'minimum_spend_minor' => 0,
                'per_buyer_limit' => 1,
                'is_claimable' => false,
                'is_public' => true,
                'starts_on' => $start,
                'ends_on' => $end,
                'starts_time' => StorePromotion::timeFromInput($startTime),
                'ends_time' => StorePromotion::timeFromInput($endTime, inclusiveMinute: true),
            ]);
            $copy->products()->attach($productIds);

            return $copy->load('products');
        }, attempts: 3);
    }

    private function setStatus(User $user, StorePromotion $promotion, string $status): StorePromotion
    {
        return DB::transaction(function () use ($user, $promotion, $status): StorePromotion {
            $promotion = $this->ownedPromotion($user, $promotion);
            if ($promotion->status === StorePromotion::STATUS_ENDED) {
                throw ValidationException::withMessages([
                    'promotion' => 'Ended product discounts cannot be paused or resumed.',
                ]);
            }
            $promotion->update(['status' => $status]);

            return $promotion->fresh('products');
        }, attempts: 3);
    }

    private function ownedPromotion(User $user, StorePromotion $promotion): StorePromotion
    {
        $store = $this->products->storeFor($user);

        return StorePromotion::query()
            ->where('store_id', $store->id)
            ->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)
            ->lockForUpdate()
            ->findOrFail($promotion->id);
    }

    /**
     * @param  array<int, mixed>  $submitted
     * @return array<int, int>
     */
    private function ownedProductIds(int $storeId, array $submitted): array
    {
        $productIds = collect($submitted)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            throw ValidationException::withMessages([
                'products' => 'Select at least one eligible product.',
            ]);
        }

        $owned = Product::query()
            ->where('store_id', $storeId)
            ->whereIn('id', $productIds->all())
            ->whereNull('removed_at')
            ->where('product_status', '!=', 'archived')
            ->whereHas('variants', fn ($variants) => $variants->where('is_active', true))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($owned) !== $productIds->count()) {
            throw ValidationException::withMessages([
                'products' => 'Every selected product must belong to your Store, be available, and have an active SKU.',
            ]);
        }

        return $owned;
    }

    /**
     * @param  array<int, int>  $productIds
     */
    private function assertNoOverlap(
        int $storeId,
        array $productIds,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreId = null,
    ): void {
        $query = StorePromotion::query()
            ->where('store_id', $storeId)
            ->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)
            ->whereNotIn('status', [StorePromotion::STATUS_PAUSED, StorePromotion::STATUS_ENDED])
            ->overlappingSchedule($startsAt, $endsAt)
            ->whereHas('products', fn ($products) => $products->whereIn('products.id', $productIds));
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'products' => 'One or more selected products already have an overlapping product discount. End or pause it first.',
            ]);
        }
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            StorePromotion::STATUS_ACTIVE => 4,
            StorePromotion::STATUS_SCHEDULED => 3,
            StorePromotion::STATUS_PAUSED => 2,
            StorePromotion::STATUS_DRAFT => 1,
            default => 0,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            StorePromotion::STATUS_ACTIVE => 'Active',
            StorePromotion::STATUS_SCHEDULED => 'Scheduled',
            StorePromotion::STATUS_PAUSED => 'Paused',
            StorePromotion::STATUS_ENDED => 'Ended',
            default => 'Draft',
        };
    }
}
