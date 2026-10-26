<?php

namespace App\Services;

use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformCampaignParticipationItem;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorePromotion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ProductPricingService
{
    /** @var array<int, StorePromotion|null> */
    private array $productDiscounts = [];

    /** @var array<int, array<int, PlatformCampaignParticipationItem>> */
    private array $campaignItems = [];

    /** @var array<int, int> */
    private array $campaignBudget = [];

    /** @var array<int, int> */
    private array $campaignSold = [];

    /** @var array<int, int> */
    private array $campaignStockProjected = [];

    /** @var array<string, int> */
    private array $campaignBuyerSold = [];

    /** @var array<string, int> */
    private array $campaignBuyerProjected = [];

    /** @var array<int, int> */
    private array $campaignBudgetProjected = [];

    /** @var array<string, array<string, mixed>> */
    private array $priceCache = [];

    /** @var array<int, bool> */
    private array $warmedProducts = [];

    private ?Carbon $at = null;

    /**
     * Preload product discounts and Seller campaign opt-ins for a catalog page.
     *
     * @param  iterable<Product>  $products
     */
    public function warm(iterable $products, ?Carbon $at = null): void
    {
        $this->at = $at ?? now('Asia/Manila');
        $products = collect($products)
            ->filter(fn ($product): bool => $product instanceof Product)
            ->reject(fn (Product $product): bool => isset($this->warmedProducts[(int) $product->id]))
            ->values();
        $productIds = $products->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($productIds->isEmpty()) {
            return;
        }

        foreach ($productIds as $productId) {
            $this->warmedProducts[(int) $productId] = true;
        }

        $storeIds = $products->pluck('store_id')->map(fn ($id): int => (int) $id)->unique()->values();

        StorePromotion::query()
            ->whereIn('store_id', $storeIds->all())
            ->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)
            ->where('status', StorePromotion::STATUS_ACTIVE)
            ->withinScheduleAt($this->at)
            ->with('products:id')
            ->orderByDesc('discount_percent')
            ->orderByDesc('id')
            ->get()
            ->each(function (StorePromotion $promotion): void {
                foreach ($promotion->products as $product) {
                    $productId = (int) $product->id;
                    if (! isset($this->productDiscounts[$productId])) {
                        $this->productDiscounts[$productId] = $promotion;
                    }
                }
            });

        PlatformCampaignParticipationItem::query()
            ->whereIn('product_id', $productIds->all())
            ->whereHas('participation', function ($participation): void {
                $participation->where('status', PlatformCampaignParticipation::STATUS_APPROVED)
                    ->whereHas('campaign', fn ($campaign) => $this->activeCampaignQuery($campaign));
            })
            ->with(['participation.campaign'])
            ->orderBy('campaign_price_minor')
            ->orderBy('id')
            ->get()
            ->each(function (PlatformCampaignParticipationItem $item): void {
                $productId = (int) $item->product_id;
                $this->campaignItems[$productId] ??= [];
                $this->campaignItems[$productId][] = $item;
            });
    }

    /**
     * Resolve the effective Buyer and Seller unit prices for one SKU.
     *
     * Seller-funded product discounts and Seller-funded campaigns reduce the
     * Seller's selling price. Platform-funded campaign differences are kept
     * separate so Seller earnings and commission keep their Seller Order basis.
     *
     * @return array{
     *     regular_unit_price_minor: int,
     *     seller_unit_price_minor: int,
     *     unit_price_minor: int,
     *     platform_unit_discount_minor: int,
     *     discount_percent: int,
     *     price_source: string,
     *     product_discount_id: int|null,
     *     product_discount_name: string|null,
     *     product_discount_starts_at: string|null,
     *     product_discount_ends_at: string|null,
     *     campaign_participation_item_id: int|null,
     *     campaign_name: string|null,
     *     campaign_funding: string|null,
     *     campaign_type: string|null,
     *     campaign_starts_at: string|null,
     *     campaign_ends_at: string|null
     * }
     */
    public function priceForVariant(
        Product $product,
        ProductVariant $variant,
        int $quantity = 1,
        bool $lock = false,
        ?Carbon $at = null,
        ?User $buyer = null,
    ): array {
        $quantity = max(1, $quantity);
        $cacheKey = $variant->id.':'.$quantity.':'.($lock ? '1' : '0').':'.($at?->timestamp ?? '');
        $tracksReservations = $lock || $buyer !== null;
        if (! $tracksReservations && isset($this->priceCache[$cacheKey])) {
            return $this->priceCache[$cacheKey];
        }

        $at ??= $this->at ?? now('Asia/Manila');
        $this->at = $at;
        $this->warm([$product], $at);

        $sellerBase = max(0, (int) $variant->price_minor);
        $regular = max($sellerBase, (int) ($variant->compare_at_price_minor ?? 0));
        $productDiscount = $this->productDiscounts[(int) $product->id] ?? null;
        $productDiscountPrice = null;

        if ($productDiscount && $productDiscount->discount_type === StorePromotion::DISCOUNT_PERCENT) {
            $percent = min(90, max(1, (int) $productDiscount->discount_percent));
            $productDiscountPrice = max(1, intdiv($regular * (100 - $percent) + 50, 100));
        }

        $sellerPrice = $productDiscountPrice ?? $sellerBase;
        $priceSource = $productDiscountPrice !== null
            ? 'product_discount'
            : 'regular';
        $preCampaignSellerPrice = $sellerPrice;

        $campaign = $this->bestCampaignFor($product, $variant, $sellerPrice, $quantity, $lock, $tracksReservations, $at, $buyer);
        $buyerPrice = $sellerPrice;
        $platformDiscount = 0;
        $campaignItem = $campaign['item'] ?? null;
        $campaignPrice = $campaign['price'] ?? null;
        $campaignFunding = $campaign['funding'] ?? null;

        if ($campaignItem !== null && $campaignPrice !== null) {
            if ($campaignFunding === PlatformPromotion::FUNDING_SELLER) {
                $sellerPrice = min($sellerPrice, $campaignPrice);
                $buyerPrice = $sellerPrice;
            } else {
                $buyerPrice = min($sellerPrice, $campaignPrice);
                $platformDiscount = max(0, $sellerPrice - $buyerPrice);
            }
            $priceSource = 'campaign';

            if ($tracksReservations) {
                if ($campaignItem->stock_limit !== null) {
                    $itemId = (int) $campaignItem->id;
                    $this->campaignStockProjected[$itemId] = ($this->campaignStockProjected[$itemId] ?? 0) + $quantity;
                }
                if ($buyer !== null && $campaignItem->per_buyer_limit !== null) {
                    $buyerKey = $this->campaignBuyerKey($campaignItem, $buyer);
                    $this->campaignBuyerProjected[$buyerKey] = ($this->campaignBuyerProjected[$buyerKey] ?? 0) + $quantity;
                }
                if ($campaignFunding === PlatformPromotion::FUNDING_PLATFORM) {
                    $campaignId = (int) $campaignItem->participation?->platform_promotion_id;
                    $this->campaignBudgetProjected[$campaignId] = ($this->campaignBudgetProjected[$campaignId] ?? 0)
                        + ($platformDiscount * $quantity);
                }
            }
        }

        $campaignPromotion = $campaignItem?->participation?->campaign;
        $displayBaseline = $priceSource === 'campaign'
            ? $preCampaignSellerPrice
            : $regular;

        $data = [
            'regular_unit_price_minor' => $displayBaseline,
            'seller_unit_price_minor' => $sellerPrice,
            'unit_price_minor' => $buyerPrice,
            'platform_unit_discount_minor' => $platformDiscount,
            'discount_percent' => $displayBaseline > 0
                ? (int) round(100 - (($buyerPrice / $displayBaseline) * 100))
                : 0,
            'price_source' => $priceSource,
            'product_discount_id' => $priceSource === 'product_discount' ? (int) $productDiscount->id : null,
            'product_discount_name' => $priceSource === 'product_discount'
                ? ($productDiscount->buyer_campaign_name ?: null)
                : null,
            'product_discount_starts_at' => $priceSource === 'product_discount'
                ? $productDiscount->startsAt()?->toIso8601String()
                : null,
            'product_discount_ends_at' => $priceSource === 'product_discount'
                ? $productDiscount->endsAt()?->toIso8601String()
                : null,
            'campaign_participation_item_id' => $campaignItem ? (int) $campaignItem->id : null,
            'campaign_name' => $campaignPromotion?->name,
            'campaign_funding' => $campaignPromotion?->funding_type,
            'campaign_type' => $campaignPromotion?->campaign_type,
            'campaign_starts_at' => $campaignPromotion?->startsAt()?->toIso8601String(),
            'campaign_ends_at' => $campaignPromotion?->endsAt()?->toIso8601String(),
        ];

        if (! $tracksReservations) {
            $this->priceCache[$cacheKey] = $data;
        }

        return $data;
    }

    public function isWarm(Product $product): bool
    {
        return array_key_exists((int) $product->id, $this->productDiscounts)
            || array_key_exists((int) $product->id, $this->campaignItems);
    }

    public function campaignActualSpendMinor(PlatformPromotion $campaign): int
    {
        $campaignId = (int) $campaign->id;

        return $this->campaignBudget[$campaignId] ??= (int) $this->eligibleCampaignOrderItems()
            ->join('platform_campaign_participations', 'platform_campaign_participations.id', '=', 'platform_campaign_participation_items.platform_campaign_participation_id')
            ->where('platform_campaign_participations.platform_promotion_id', $campaignId)
            ->sum('order_items.campaign_platform_discount_minor');
    }

    /**
     * @param  array<int, int>  $participationItemIds
     * @return array<int, int>
     */
    public function campaignSoldUnitsForItems(array $participationItemIds): array
    {
        $ids = collect($participationItemIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $missing = $ids->filter(fn (int $id): bool => ! array_key_exists($id, $this->campaignSold));
        foreach ($missing as $id) {
            $this->campaignSold[$id] = 0;
        }

        if ($missing->isNotEmpty()) {
            $sold = $this->eligibleCampaignOrderItems()
                ->whereIn('order_items.platform_campaign_participation_item_id', $missing->all())
                ->select('order_items.platform_campaign_participation_item_id')
                ->selectRaw('SUM(order_items.quantity) AS sold_quantity')
                ->groupBy('order_items.platform_campaign_participation_item_id')
                ->get();

            foreach ($sold as $row) {
                $this->campaignSold[(int) $row->platform_campaign_participation_item_id] = (int) $row->sold_quantity;
            }
        }

        return $ids->mapWithKeys(fn (int $id): array => [$id => $this->campaignSold[$id]])->all();
    }

    public function savedCampaignPriceMinor(PlatformCampaignParticipationItem $item): ?int
    {
        if ($item->campaign_price_minor !== null) {
            return max(0, (int) $item->campaign_price_minor);
        }

        if ($item->discount_percent !== null) {
            $regular = max(0, (int) $item->regular_price_minor);
            $percent = min(100, max(0, (int) $item->discount_percent));

            return max(0, intdiv($regular * (100 - $percent) + 50, 100));
        }

        return null;
    }

    /**
     * @return array{item: PlatformCampaignParticipationItem, price: int, funding: string}|null
     */
    private function bestCampaignFor(
        Product $product,
        ProductVariant $variant,
        int $sellerPrice,
        int $quantity,
        bool $lock,
        bool $tracksReservations,
        Carbon $at,
        ?User $buyer,
    ): ?array {
        $candidates = $this->campaignItems[(int) $product->id] ?? [];

        if ($lock) {
            $candidates = PlatformCampaignParticipationItem::query()
                ->where('product_id', $product->id)
                ->where(function ($query) use ($variant): void {
                    $query->whereNull('product_variant_id')
                        ->orWhere('product_variant_id', $variant->id);
                })
                ->whereHas('participation', function ($participation): void {
                    $participation->where('status', PlatformCampaignParticipation::STATUS_APPROVED)
                        ->whereHas('campaign', fn ($campaign) => $this->activeCampaignQuery($campaign));
                })
                ->with(['participation.campaign'])
                ->lockForUpdate()
                ->get();
            $campaignIds = $candidates
                ->map(fn (PlatformCampaignParticipationItem $item): int => (int) $item->participation?->platform_promotion_id)
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($campaignIds !== []) {
                PlatformPromotion::query()
                    ->whereIn('id', $campaignIds)
                    ->lockForUpdate()
                    ->get();
            }
            $candidates = $candidates->all();
        }

        $best = null;
        foreach ($candidates as $item) {
            if ($item->product_variant_id !== null && (int) $item->product_variant_id !== (int) $variant->id) {
                continue;
            }

            $campaign = $item->participation?->campaign;
            if (! $campaign) {
                continue;
            }

            $stockProjected = $tracksReservations ? ($this->campaignStockProjected[(int) $item->id] ?? 0) : 0;
            if ($item->stock_limit !== null && $this->remainingStock($item) - $stockProjected < $quantity) {
                continue;
            }

            if ($buyer !== null && $item->per_buyer_limit !== null) {
                $buyerKey = $this->campaignBuyerKey($item, $buyer);
                $buyerSold = $this->campaignBuyerSold[$buyerKey] ??= $this->soldForBuyer($item, $buyer);
                $buyerProjected = $this->campaignBuyerProjected[$buyerKey] ?? 0;
                if ($buyerSold + $buyerProjected + $quantity > (int) $item->per_buyer_limit) {
                    continue;
                }
            }

            $price = $this->campaignPrice($item, $campaign, $variant, $at);
            if ($price === null || $price >= $sellerPrice) {
                continue;
            }

            if ($campaign->minimum_discount_percent !== null) {
                $minimumDiscount = (int) $campaign->minimum_discount_percent;
                $minimumPrice = max(1, intdiv(max(0, $sellerPrice) * (100 - $minimumDiscount) + 50, 100));
                if ($price > $minimumPrice) {
                    continue;
                }

                // A Bearly-funded campaign must not silently subsidize a
                // Seller price increase beyond the baseline Admin approved.
                // Legacy campaigns without a configured minimum retain their
                // existing pricing behavior.
                if ($campaign->funding_type === PlatformPromotion::FUNDING_PLATFORM
                    && $sellerPrice > (int) $item->regular_price_minor) {
                    continue;
                }
            }

            $platformDiscount = $campaign->funding_type === PlatformPromotion::FUNDING_PLATFORM
                ? max(0, ($sellerPrice - $price) * $quantity)
                : 0;
            if (! $this->campaignBudgetAvailable($campaign, $platformDiscount, $tracksReservations)) {
                continue;
            }

            if ($best === null
                || $price < $best['price']
                || ($price === $best['price'] && $campaign->funding_type === PlatformPromotion::FUNDING_PLATFORM)) {
                $best = [
                    'item' => $item,
                    'price' => $price,
                    'funding' => $campaign->funding_type,
                ];
            }
        }

        return $best;
    }

    private function campaignPrice(
        PlatformCampaignParticipationItem $item,
        PlatformPromotion $campaign,
        ProductVariant $variant,
        Carbon $at,
    ): ?int {
        return $this->savedCampaignPriceMinor($item);
    }

    private function remainingStock(PlatformCampaignParticipationItem $item): int
    {
        if ($item->stock_limit === null) {
            return PHP_INT_MAX;
        }

        $sold = $this->campaignSoldUnitsForItems([(int) $item->id])[(int) $item->id] ?? 0;

        return max(0, (int) $item->stock_limit - $sold);
    }

    private function soldForBuyer(PlatformCampaignParticipationItem $item, User $buyer): int
    {
        return (int) $this->eligibleCampaignOrderItems()
            ->where('order_items.platform_campaign_participation_item_id', $item->id)
            ->where('orders.buyer_id', $buyer->id)
            ->sum('order_items.quantity');
    }

    private function campaignBuyerKey(PlatformCampaignParticipationItem $item, User $buyer): string
    {
        return (int) $item->id.':'.(int) $buyer->id;
    }

    private function campaignBudgetAvailable(
        PlatformPromotion $campaign,
        int $platformDiscount,
        bool $lock,
    ): bool {
        if ($campaign->budget_minor === null) {
            return true;
        }

        $spent = $this->campaignActualSpendMinor($campaign);

        $projected = $lock ? ($this->campaignBudgetProjected[(int) $campaign->id] ?? 0) : 0;

        return $spent + $projected + $platformDiscount <= (int) $campaign->budget_minor;
    }

    private function eligibleCampaignOrderItems(): QueryBuilder
    {
        return DB::table('order_items')
            ->join('platform_campaign_participation_items', 'platform_campaign_participation_items.id', '=', 'order_items.platform_campaign_participation_item_id')
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('seller_orders.status', '!=', 'cancelled')
            ->where('orders.payment_status', '!=', 'refunded');
    }

    private function activeCampaignQuery($query): void
    {
        $query->where('status', PlatformPromotion::STATUS_ACTIVE)
            ->where('is_public', true)
            ->withinScheduleAt($this->at ?? now('Asia/Manila'))
            ->where(function ($campaigns): void {
                $campaigns->whereIn('kind', [
                    PlatformPromotion::KIND_FLASH_DEAL,
                    PlatformPromotion::KIND_SELLER_CAMPAIGN,
                ])->orWhereNotNull('campaign_type');
            });
    }
}
