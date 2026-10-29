<?php

namespace App\Services;

use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformCampaignParticipationItem;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Support\ImageUrlPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public function __construct(
        private readonly SellerProductService $products,
        private readonly ProductPricingService $pricing,
        private readonly AuditService $audit,
    ) {}

    /**
     * Campaigns visible to an authenticated Seller.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function availableFor(User $seller): Collection
    {
        $store = $this->products->storeFor($seller);
        $at = now('Asia/Manila');
        $today = $at->toDateString();

        return PlatformPromotion::query()
            ->campaigns()
            ->where('status', '!=', PlatformPromotion::STATUS_DRAFT)
            ->where('status', '!=', PlatformPromotion::STATUS_ENDED)
            // Ended campaigns leave Seller discovery even when this Store
            // participated; historical details remain available by direct link.
            ->notEndedAt($at)
            ->where(function ($query) use ($store, $today, $at): void {
                $query->where(function ($visible) use ($today, $at): void {
                    $visible->whereIn('status', [
                        PlatformPromotion::STATUS_OPEN,
                        PlatformPromotion::STATUS_ACTIVE,
                        PlatformPromotion::STATUS_PAUSED,
                    ])
                        ->where(function ($joinWindow) use ($today): void {
                            $joinWindow->whereIn('status', [
                                PlatformPromotion::STATUS_ACTIVE,
                                PlatformPromotion::STATUS_PAUSED,
                            ])->orWhere(function ($open) use ($today): void {
                                $open->where('status', PlatformPromotion::STATUS_OPEN)
                                    ->where(function ($deadline) use ($today): void {
                                        $deadline->whereNull('join_deadline')
                                            ->orWhereDate('join_deadline', '>=', $today);
                                    });
                            });
                        });
                })->orWhereHas('participations', fn ($participations) => $participations->where('store_id', $store->id));
            })
            ->where(function ($query) use ($store): void {
                $query->whereDoesntHave('stores')
                    ->orWhereHas('stores', fn ($stores) => $stores->whereKey($store->id));
            })
            ->with(['stores:id,name', 'categories:id,name', 'products:id,name'])
            ->withCount([
                'participations as approved_participations_count' => fn ($query) => $query
                    ->where('status', PlatformCampaignParticipation::STATUS_APPROVED),
            ])
            ->with(['participations' => fn ($query) => $query
                ->where('store_id', $store->id)
                ->with(['items.product:id,name', 'items.variant:id,sku,name'])])
            ->orderBy('ends_on')
            ->orderBy('ends_time')
            ->orderBy('id')
            ->get()
            ->map(fn (PlatformPromotion $campaign): array => $this->sellerCampaignPayload($campaign))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function sellerCampaignDetail(User $seller, PlatformPromotion $campaign): array
    {
        $store = $this->products->storeFor($seller);
        $this->assertStoreEligible($campaign, $store, requirePublished: false);

        $campaign->load([
            'stores:id,name',
            'categories:id,name',
            'products:id,name',
            'participations' => fn ($query) => $query
                ->where('store_id', $store->id)
                ->with(['items.product:id,name', 'items.variant:id,sku,name', 'reviewer:id,name']),
        ]);

        return [
            'campaign' => $this->sellerCampaignPayload($campaign),
            'participation' => $campaign->participations->first(),
            'eligible_products' => $this->eligibleProducts($campaign, $store),
            'can_join' => $campaign->isJoinable()
                && $store->publication_status === 'published'
                && ! in_array($campaign->participations->first()?->status, [
                    PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
                    PlatformCampaignParticipation::STATUS_APPROVED,
                ], true),
            'store_published' => $store->publication_status === 'published',
        ];
    }

    public function join(User $seller, PlatformPromotion $campaign, array $data): PlatformCampaignParticipation
    {
        return DB::transaction(function () use ($seller, $campaign, $data): PlatformCampaignParticipation {
            $store = $this->products->storeFor($seller);
            $campaign = PlatformPromotion::query()
                ->campaigns()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $campaign->isJoinable()) {
                throw ValidationException::withMessages([
                    'campaign' => 'This campaign is no longer accepting Seller participation.',
                ]);
            }
            $this->assertStoreEligible($campaign, $store);

            $existing = PlatformCampaignParticipation::query()
                ->where('platform_promotion_id', $campaign->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            if ($existing && in_array($existing->status, [
                PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
                PlatformCampaignParticipation::STATUS_APPROVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'campaign' => 'You already joined this campaign. View your participation to review the selected products.',
                ]);
            }

            if ($existing?->status === PlatformCampaignParticipation::STATUS_WITHDRAWN) {
                throw ValidationException::withMessages([
                    'campaign' => 'A withdrawn participation cannot be resubmitted for this campaign.',
                ]);
            }

            if ($campaign->max_participating_stores !== null) {
                $approvedStores = PlatformCampaignParticipation::query()
                    ->where('platform_promotion_id', $campaign->id)
                    ->where('store_id', '!=', $store->id)
                    ->whereIn('status', [
                        PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
                        PlatformCampaignParticipation::STATUS_APPROVED,
                    ])
                    ->distinct('store_id')
                    ->count('store_id');
                if ($approvedStores >= (int) $campaign->max_participating_stores) {
                    throw ValidationException::withMessages([
                        'campaign' => 'This campaign has reached its Seller participation limit.',
                    ]);
                }
            }

            if (! (bool) ($data['terms_accepted'] ?? false)) {
                throw ValidationException::withMessages([
                    'terms_accepted' => 'Review and accept the campaign terms before joining.',
                ]);
            }

            $items = $this->validatedJoinItems($campaign, $store, $data['items'] ?? []);
            $productCount = collect($items)->pluck('product_id')->unique()->count();
            if ($campaign->max_products_per_store !== null && $productCount > (int) $campaign->max_products_per_store) {
                throw ValidationException::withMessages([
                    'items' => 'Select no more than '.$campaign->max_products_per_store.' products for this campaign.',
                ]);
            }

            $status = $campaign->requires_approval
                ? PlatformCampaignParticipation::STATUS_PENDING_REVIEW
                : PlatformCampaignParticipation::STATUS_APPROVED;
            $participation = $existing ?: new PlatformCampaignParticipation([
                'platform_promotion_id' => $campaign->id,
                'store_id' => $store->id,
            ]);

            $participation->fill([
                'submitted_by' => $seller->id,
                'status' => $status,
                'seller_note' => filled($data['seller_note'] ?? null) ? trim((string) $data['seller_note']) : null,
                'terms_accepted_at' => now(),
                'submitted_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_reason' => null,
                'withdrawn_at' => null,
            ]);
            $participation->save();

            $participation->items()->delete();
            foreach ($items as $item) {
                $participation->items()->create($item);
            }

            $this->audit->record(
                'campaign.participation_submitted',
                'marketing',
                $participation,
                'Seller submitted campaign participation.',
                [],
                [
                    'campaign_id' => (int) $campaign->id,
                    'store_id' => (int) $store->id,
                    'status' => $participation->status,
                    'product_count' => $productCount,
                ],
                'info',
            );

            return $participation->load('items.product', 'items.variant', 'campaign');
        }, attempts: 3);
    }

    public function withdraw(User $seller, PlatformCampaignParticipation $participation): PlatformCampaignParticipation
    {
        return DB::transaction(function () use ($seller, $participation): PlatformCampaignParticipation {
            $store = $this->products->storeFor($seller);
            $participation = PlatformCampaignParticipation::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->findOrFail($participation->id);

            if (! in_array($participation->status, [
                PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
                PlatformCampaignParticipation::STATUS_APPROVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'participation' => 'This participation can no longer be withdrawn.',
                ]);
            }

            $oldStatus = $participation->status;
            $participation->update([
                'status' => PlatformCampaignParticipation::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
            ]);

            $this->audit->record(
                'campaign.participation_withdrawn',
                'marketing',
                $participation,
                'Seller withdrew campaign participation.',
                ['status' => $oldStatus],
                ['status' => $participation->status],
                'warning',
            );

            return $participation->fresh('items');
        }, attempts: 3);
    }

    /**
     * Active, public, in-schedule campaigns for the Buyer storefront.
     *
     * @return Collection<int, PlatformPromotion>
     */
    public function activeBuyerCampaigns(?Carbon $at = null): Collection
    {
        return PlatformPromotion::query()
            ->campaigns()
            ->where('status', PlatformPromotion::STATUS_ACTIVE)
            ->where('is_public', true)
            ->withinScheduleAt($at ?? now('Asia/Manila'))
            ->orderBy('position')
            ->orderByDesc('is_featured')
            ->orderBy('ends_on')
            ->orderBy('ends_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * Public, scheduled Flash Deal campaigns with at least one approved
     * participation that has a potentially Buyer-visible, COD, in-stock item.
     * Effective campaign pricing is checked before a campaign is rendered.
     *
     * @return Collection<int, PlatformPromotion>
     */
    public function activeBuyerFlashDealCampaigns(?Carbon $at = null): Collection
    {
        $at ??= now('Asia/Manila');

        return PlatformPromotion::query()
            ->campaigns()
            ->where('campaign_type', PlatformPromotion::CAMPAIGN_FLASH_DEAL)
            ->where('status', PlatformPromotion::STATUS_ACTIVE)
            ->where('is_public', true)
            ->withinScheduleAt($at)
            ->whereHas('approvedParticipations', fn ($participations) => $participations
                ->whereHas('store', fn ($stores) => $stores->visibleToBuyers())
                ->whereHas('items.product', fn ($products) => $products
                    ->visibleToBuyers()
                    ->acceptsCashOnDelivery()
                    ->whereHas('variants', fn ($variants) => $variants
                        ->where('is_active', true)
                        ->whereColumn('stock_on_hand', '>', 'stock_reserved'))))
            ->orderBy('position')
            ->orderBy('ends_on')
            ->orderBy('ends_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * Validate Buyer visibility and load only the relations the campaign page
     * needs. Inactive, private, and out-of-schedule campaigns are treated as
     * missing so their existence is not revealed.
     */
    public function buyerCampaignDetail(PlatformPromotion $campaign, ?Carbon $at = null): PlatformPromotion
    {
        if (! $campaign->isCampaign()
            || $campaign->status !== PlatformPromotion::STATUS_ACTIVE
            || ! (bool) $campaign->is_public
            || ! $campaign->isWithinScheduleAt($at ?? now('Asia/Manila'))) {
            abort(404);
        }

        return $campaign->load([
            'approvedParticipations' => fn ($participations) => $participations
                ->whereHas('store', fn ($stores) => $stores->visibleToBuyers()),
            'approvedParticipations.store:id,name',
        ]);
    }

    /**
     * Products with approved campaign items that buyers may currently see.
     * Pricing is resolved by ProductPricingService, and products whose active
     * variants do not receive campaign pricing are excluded so the page never
     * advertises a campaign price checkout would not charge.
     *
     * @return Collection<int, Product>
     */
    public function buyerCampaignProducts(PlatformPromotion $campaign): Collection
    {
        return $this->campaignPricedProducts(
            $campaign,
            $this->buyerCampaignProductsQuery($campaign)->get(),
        );
    }

    /**
     * Return a bounded set of Buyer-shoppable products, fetching candidate
     * products in database chunks and stopping after the requested number of
     * exact campaign-price matches has been collected.
     *
     * @return Collection<int, Product>
     */
    public function buyerCampaignProductPreview(PlatformPromotion $campaign, int $limit = 12): Collection
    {
        $limit = min(24, max(1, $limit));
        $eligibleProducts = collect();

        $this->buyerCampaignProductsQuery($campaign)->chunkById(36, function (Collection $candidates) use ($campaign, $limit, $eligibleProducts): bool {
            foreach ($this->campaignPricedProducts($campaign, $candidates) as $product) {
                $eligibleProducts->push($product);
                if ($eligibleProducts->count() >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $eligibleProducts->values();
    }

    private function buyerCampaignProductsQuery(PlatformPromotion $campaign): Builder
    {
        $campaignProductIds = PlatformCampaignParticipationItem::query()
            ->select('product_id')
            ->whereHas('participation', fn ($participations) => $participations
                ->where('platform_promotion_id', $campaign->id)
                ->where('status', PlatformCampaignParticipation::STATUS_APPROVED));

        return Product::query()
            ->whereIn('id', $campaignProductIds)
            ->visibleToBuyers()
            ->acceptsCashOnDelivery()
            ->whereHas('variants', fn ($variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved'))
            ->with([
                'store',
                'category',
                'images' => fn ($images) => $images->with('variant')->orderBy('position'),
                'variants' => fn ($variants) => $variants
                    ->where('is_active', true)
                    ->whereColumn('stock_on_hand', '>', 'stock_reserved')
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->orderBy('id');
    }

    /**
     * Retain only products whose effective unit price is backed by an approved
     * participation item belonging to this specific campaign.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function campaignPricedProducts(PlatformPromotion $campaign, Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return collect();
        }

        $itemsByProduct = PlatformCampaignParticipationItem::query()
            ->whereIn('product_id', $products->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->whereHas('participation', fn ($participations) => $participations
                ->where('platform_promotion_id', $campaign->id)
                ->where('status', PlatformCampaignParticipation::STATUS_APPROVED))
            ->get(['id', 'product_id', 'product_variant_id'])
            ->groupBy(fn (PlatformCampaignParticipationItem $item): int => (int) $item->product_id);

        $this->pricing->warm($products);
        $eligible = collect();

        foreach ($products as $product) {
            $campaignItems = $itemsByProduct->get((int) $product->id, collect());
            $campaignVariant = null;
            $campaignItemId = null;

            foreach ($product->variants as $variant) {
                $price = $this->pricing->priceForVariant($product, $variant);
                $effectiveItemId = (int) ($price['campaign_participation_item_id'] ?? 0);
                $effectiveItem = $campaignItems->firstWhere('id', $effectiveItemId);

                if ($price['price_source'] !== 'campaign'
                    || ! $effectiveItem
                    || ($effectiveItem->product_variant_id !== null
                        && (int) $effectiveItem->product_variant_id !== (int) $variant->id)) {
                    continue;
                }

                $campaignVariant = $variant;
                $campaignItemId = $effectiveItemId;
                break;
            }

            if (! $campaignVariant) {
                continue;
            }

            if ((int) $product->variants->first()?->id !== (int) $campaignVariant->id) {
                $product->setRelation('variants', $product->variants
                    ->reject(fn (ProductVariant $variant): bool => (int) $variant->id === (int) $campaignVariant->id)
                    ->prepend($campaignVariant)
                    ->values());
            }

            $product->setAttribute('buyer_campaign_participation_item_id', $campaignItemId);
            $eligible->push($product);
        }

        return $eligible->values();
    }

    /**
     * Minimal public campaign payload. Seller notes, terms, participation
     * records, review data, and audit fields are intentionally excluded.
     *
     * @return array<string, mixed>
     */
    public function buyerCampaignPayload(PlatformPromotion $campaign): array
    {
        return [
            'id' => (int) $campaign->id,
            'name' => (string) $campaign->name,
            'type_label' => $campaign->campaignTypeLabel(),
            'funding_label' => $campaign->funding_type === PlatformPromotion::FUNDING_SELLER
                ? 'Seller-funded'
                : 'Bearly-funded',
            'description' => $campaign->description,
            'starts_at' => $campaign->startsAt()?->toIso8601String(),
            'ends_at' => $campaign->endsAt()?->toIso8601String(),
            'period' => $campaign->schedulePeriod(),
            'banner_url' => $this->campaignBannerUrl($campaign),
            'banner_title' => $campaign->banner_title,
            'store_names' => $campaign->relationLoaded('approvedParticipations')
                ? $campaign->approvedParticipations
                    ->pluck('store.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * Compact public payload for the Buyer home campaign showcase.
     *
     * @return array<string, mixed>
     */
    public function buyerCampaignSummaryPayload(PlatformPromotion $campaign): array
    {
        return [
            'id' => (int) $campaign->id,
            'name' => (string) $campaign->name,
            'type_key' => $campaign->campaign_type ?: 'general',
            'type_label' => $campaign->campaignTypeLabel(),
            'description' => $campaign->description,
            'starts_at' => $campaign->startsAt()?->toIso8601String(),
            'ends_at' => $campaign->endsAt()?->toIso8601String(),
            'banner_url' => $this->campaignBannerUrl($campaign),
            'banner_title' => $campaign->banner_title,
        ];
    }

    private function campaignBannerUrl(PlatformPromotion $campaign): ?string
    {
        if (! $campaign->banner_path) {
            return null;
        }

        return ImageUrlPolicy::diskPathUrl($campaign->banner_disk ?: 'public', $campaign->banner_path);
    }

    /**
     * @return Collection<int, PlatformPromotion>
     */
    public function adminListing(): Collection
    {
        return PlatformPromotion::query()
            ->campaigns()
            ->with(['stores:id,name', 'categories:id,name'])
            ->withCount([
                'participations',
                'participations as pending_participations_count' => fn ($query) => $query
                    ->where('status', PlatformCampaignParticipation::STATUS_PENDING_REVIEW),
                'participations as approved_participations_count' => fn ($query) => $query
                    ->where('status', PlatformCampaignParticipation::STATUS_APPROVED),
            ])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
    }

    public function adminDetail(PlatformPromotion $campaign): PlatformPromotion
    {
        return $campaign->load([
            'stores:id,name',
            'categories:id,name',
            'participations.store:id,name',
            'participations.submitter:id,name',
            'participations.reviewer:id,name',
            'participations.items.product:id,name',
            'participations.items.variant:id,product_id,sku,name,stock_on_hand,stock_reserved,is_active,deleted_at',
        ]);
    }

    /**
     * Admin-only campaign budget estimate. This is a read model only: it never
     * reserves funds or participates in the checkout budget decision.
     *
     * @return array<string, mixed>
     */
    public function adminBudgetEstimate(PlatformPromotion $campaign): array
    {
        if ($campaign->funding_type !== PlatformPromotion::FUNDING_PLATFORM) {
            return [
                'applies' => false,
                'status_key' => 'seller_funded',
                'budget_minor' => null,
                'actual_spend_minor' => null,
                'approved_remaining_exposure_minor' => null,
                'projected_total_minor' => null,
                'remaining_headroom_minor' => null,
                'actual_at_or_over_budget' => false,
                'projected_exceeds_budget' => false,
                'within_budget' => false,
                'item_estimates' => [],
                'pending_participations' => [],
            ];
        }

        $campaign->loadMissing([
            'participations.items.product:id,name',
            'participations.items.variant:id,product_id,sku,name,stock_on_hand,stock_reserved,is_active,deleted_at',
        ]);

        $participations = $campaign->participations;
        $items = $participations->flatMap(fn (PlatformCampaignParticipation $participation) => $participation->items)
            ->values();
        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $soldUnitsByItemId = $this->pricing->campaignSoldUnitsForItems($itemIds);
        $productIds = $items->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $visibleProductIds = $productIds->isEmpty()
            ? []
            : Product::query()
                ->whereIn('id', $productIds->all())
                ->visibleToBuyers()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->flip()
                ->all();

        $pooledProductIds = $items
            ->whereNull('product_variant_id')
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->filter(fn (int $id): bool => isset($visibleProductIds[$id]))
            ->values();
        $availableUnitsByProductId = $pooledProductIds->isEmpty()
            ? []
            : ProductVariant::query()
                ->whereIn('product_id', $pooledProductIds->all())
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved')
                ->select('product_id')
                ->selectRaw('SUM(stock_on_hand - stock_reserved) AS available_units')
                ->groupBy('product_id')
                ->pluck('available_units', 'product_id')
                ->map(fn ($units): int => max(0, (int) $units))
                ->all();

        $itemEstimates = [];
        $pendingEstimates = [];
        $approvedExposure = 0;
        $approvedInventoryBasedItems = 0;

        foreach ($participations as $participation) {
            if (! in_array($participation->status, [
                PlatformCampaignParticipation::STATUS_APPROVED,
                PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
            ], true)) {
                continue;
            }

            $participationExposure = 0;
            $participationInventoryBasedItems = 0;

            foreach ($participation->items as $item) {
                $productId = (int) $item->product_id;
                $productVisible = isset($visibleProductIds[$productId]);
                $availableUnits = 0;

                if ($productVisible && $item->product_variant_id !== null) {
                    $variant = $item->variant;
                    if ($variant
                        && ! $variant->trashed()
                        && (int) $variant->product_id === $productId
                        && (bool) $variant->is_active) {
                        $availableUnits = max(0, (int) $variant->stock_on_hand - (int) $variant->stock_reserved);
                    }
                } elseif ($productVisible) {
                    $availableUnits = (int) ($availableUnitsByProductId[$productId] ?? 0);
                }

                $soldUnits = (int) ($soldUnitsByItemId[(int) $item->id] ?? 0);
                $campaignPrice = $this->pricing->savedCampaignPriceMinor($item);
                $baselinePrice = max(0, (int) $item->regular_price_minor);
                $unitSubsidy = $campaignPrice !== null
                    ? max(0, $baselinePrice - $campaignPrice)
                    : 0;
                $stockLimited = $item->stock_limit !== null;
                $remainingLimit = $stockLimited
                    ? max(0, (int) $item->stock_limit - $soldUnits)
                    : null;
                $estimatedUnits = $stockLimited
                    ? min((int) $remainingLimit, $availableUnits)
                    : $availableUnits;
                $exposure = $unitSubsidy * $estimatedUnits;
                $inventoryBased = ! $stockLimited;

                $itemEstimates[(int) $item->id] = [
                    'product_name' => $item->product?->name,
                    'sku' => $item->variant?->sku,
                    'baseline_minor' => $baselinePrice,
                    'campaign_price_minor' => $campaignPrice,
                    'unit_subsidy_minor' => $unitSubsidy,
                    'sold_units' => $soldUnits,
                    'available_units' => $availableUnits,
                    'remaining_campaign_limit_units' => $remainingLimit,
                    'estimated_units' => $estimatedUnits,
                    'exposure_minor' => $exposure,
                    'inventory_based' => $inventoryBased,
                    'product_visible' => $productVisible,
                    'price_available' => $campaignPrice !== null,
                    'basis_label' => $stockLimited
                        ? 'Remaining campaign limit, capped by current available inventory'
                        : 'Current available inventory; changeable',
                ];

                $participationExposure += $exposure;
                if ($inventoryBased && $productVisible && $campaignPrice !== null) {
                    $participationInventoryBasedItems++;
                }
            }

            if ($participation->status === PlatformCampaignParticipation::STATUS_APPROVED) {
                $approvedExposure += $participationExposure;
                $approvedInventoryBasedItems += $participationInventoryBasedItems;
            } else {
                $pendingEstimates[(int) $participation->id] = [
                    'additional_exposure_minor' => $participationExposure,
                    'projected_total_if_approved_minor' => null,
                    'remaining_headroom_if_approved_minor' => null,
                    'would_exceed_budget' => false,
                    'inventory_based_items_count' => $participationInventoryBasedItems,
                ];
            }
        }

        $actualSpend = $this->pricing->campaignActualSpendMinor($campaign);
        $budget = $campaign->budget_minor !== null ? (int) $campaign->budget_minor : null;
        $projectedTotal = $actualSpend + $approvedExposure;
        $actualAtOrOverBudget = $budget !== null && $actualSpend >= $budget;
        $projectedExceedsBudget = $budget !== null && $projectedTotal > $budget;

        foreach ($pendingEstimates as $participationId => $estimate) {
            $projectedIfApproved = $projectedTotal + (int) $estimate['additional_exposure_minor'];
            $pendingEstimates[$participationId]['projected_total_if_approved_minor'] = $projectedIfApproved;
            $pendingEstimates[$participationId]['remaining_headroom_if_approved_minor'] = $budget !== null
                ? $budget - $projectedIfApproved
                : null;
            $pendingEstimates[$participationId]['would_exceed_budget'] = $budget !== null
                && $projectedIfApproved > $budget;
        }

        return [
            'applies' => true,
            'status_key' => $budget === null
                ? 'no_cap'
                : ($projectedExceedsBudget
                    ? 'projected_over_budget'
                    : ($actualAtOrOverBudget ? 'actual_at_or_over_budget' : 'within_budget')),
            'budget_minor' => $budget,
            'actual_spend_minor' => $actualSpend,
            'approved_remaining_exposure_minor' => $approvedExposure,
            'projected_total_minor' => $projectedTotal,
            'remaining_headroom_minor' => $budget !== null ? $budget - $projectedTotal : null,
            'actual_at_or_over_budget' => $actualAtOrOverBudget,
            'projected_exceeds_budget' => $projectedExceedsBudget,
            'within_budget' => $budget !== null && ! $actualAtOrOverBudget && ! $projectedExceedsBudget,
            'approved_inventory_based_items_count' => $approvedInventoryBasedItems,
            'item_estimates' => $itemEstimates,
            'pending_participations' => $pendingEstimates,
        ];
    }

    public function createCampaign(User $admin, array $data, ?UploadedFile $banner = null): PlatformPromotion
    {
        $minimumDiscount = $this->minimumDiscount($data['minimum_discount_percent'] ?? null, required: true);
        if (($data['funding_type'] ?? null) !== PlatformPromotion::FUNDING_PLATFORM) {
            throw ValidationException::withMessages([
                'funding_type' => 'Admin campaigns are Bearly-funded. Sellers manage their own Store promotions independently.',
            ]);
        }
        $budgetMinor = $this->minor($data['budget'] ?? null, 'budget');
        if ($budgetMinor === null || $budgetMinor < 1) {
            throw ValidationException::withMessages([
                'budget' => 'Set a Bearly-funded budget greater than ₱0.00.',
            ]);
        }

        return DB::transaction(function () use ($admin, $data, $banner, $minimumDiscount, $budgetMinor): PlatformPromotion {
            $bannerPath = $banner?->store('campaigns/banners', 'public');
            $campaignType = (string) $data['campaign_type'];
            $campaign = PlatformPromotion::query()->create([
                'created_by' => $admin->id,
                'name' => trim((string) $data['name']),
                'code' => null,
                'kind' => $campaignType === PlatformPromotion::CAMPAIGN_FLASH_DEAL
                    ? PlatformPromotion::KIND_FLASH_DEAL
                    : PlatformPromotion::KIND_SELLER_CAMPAIGN,
                'campaign_type' => $campaignType,
                'funding_type' => $data['funding_type'],
                'minimum_discount_percent' => $minimumDiscount,
                'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
                'status' => $data['status'] ?? PlatformPromotion::STATUS_DRAFT,
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'terms' => filled($data['terms'] ?? null) ? trim((string) $data['terms']) : null,
                'campaign_notes' => filled($data['campaign_notes'] ?? null) ? trim((string) $data['campaign_notes']) : null,
                'value_minor' => 0,
                'discount_percent' => null,
                'max_discount_minor' => null,
                'minimum_spend_minor' => 0,
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'starts_time' => PlatformPromotion::timeFromInput($data['start_time']),
                'ends_time' => PlatformPromotion::timeFromInput($data['end_time'], inclusiveMinute: true),
                'join_deadline' => $data['join_deadline'],
                'requires_approval' => (bool) ($data['requires_approval'] ?? true),
                'max_participating_stores' => $data['max_participating_stores'] ?? null,
                'max_products_per_store' => $data['max_products_per_store'] ?? null,
                'redemption_limit' => null,
                'per_buyer_limit' => 1,
                'budget_minor' => $budgetMinor,
                'is_claimable' => false,
                'is_public' => true,
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'banner_path' => $bannerPath,
                'banner_disk' => $bannerPath ? 'public' : null,
                'banner_title' => filled($data['banner_title'] ?? null) ? trim((string) $data['banner_title']) : null,
                'banner_link' => filled($data['banner_link'] ?? null) ? trim((string) $data['banner_link']) : null,
                'position' => (int) ($data['position'] ?? 0),
            ]);

            $campaign->stores()->sync($data['store_ids'] ?? []);
            $campaign->categories()->sync($data['category_ids'] ?? []);
            $campaign->products()->sync($data['product_ids'] ?? []);

            $this->audit->record(
                'campaign.created',
                'marketing',
                $campaign,
                'Bearly campaign created.',
                [],
                [
                    'name' => $campaign->name,
                    'campaign_type' => $campaign->campaign_type,
                    'funding_type' => $campaign->funding_type,
                    'minimum_discount_percent' => $campaign->minimum_discount_percent,
                    'status' => $campaign->status,
                    'starts_on' => $campaign->starts_on?->toDateString(),
                    'ends_on' => $campaign->ends_on?->toDateString(),
                    'starts_time' => $campaign->starts_time,
                    'ends_time' => $campaign->ends_time,
                    'join_deadline' => $campaign->join_deadline?->toDateString(),
                ],
                'info',
            );

            return $campaign->load(['stores:id,name', 'categories:id,name', 'products:id,name']);
        }, attempts: 3);
    }

    public function updateCampaign(
        User $admin,
        PlatformPromotion $campaign,
        array $data,
        ?UploadedFile $banner = null,
    ): PlatformPromotion {
        return DB::transaction(function () use ($campaign, $data, $banner): PlatformPromotion {
            $campaign = PlatformPromotion::query()
                ->campaigns()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($campaign->status === PlatformPromotion::STATUS_ENDED) {
                throw ValidationException::withMessages([
                    'campaign' => 'Ended campaigns cannot be edited. Duplicate the event as a new campaign instead.',
                ]);
            }

            $oldFunding = $campaign->funding_type;
            $newFunding = (string) ($data['funding_type'] ?? $oldFunding);
            if ($newFunding === PlatformPromotion::FUNDING_SELLER && filled($data['budget'] ?? null)) {
                throw ValidationException::withMessages([
                    'budget' => 'A platform budget can only be set for Bearly-funded campaigns.',
                ]);
            }
            if ($newFunding !== $oldFunding) {
                throw ValidationException::withMessages([
                    'funding_type' => 'Campaign funding cannot be changed after creation. Keep legacy Seller-funded campaigns unchanged and create new Admin campaigns as Bearly-funded.',
                ]);
            }

            $budgetMinor = $newFunding === PlatformPromotion::FUNDING_PLATFORM
                ? (array_key_exists('budget', $data) ? $this->minor($data['budget'], 'budget') : $campaign->budget_minor)
                : null;
            if ($newFunding === PlatformPromotion::FUNDING_PLATFORM
                && ($budgetMinor === null || (int) $budgetMinor < 1)) {
                throw ValidationException::withMessages([
                    'budget' => 'Set a Bearly-funded budget greater than ₱0.00. No-cap Bearly campaigns are not available for Admin campaigns.',
                ]);
            }

            $storedMinimum = $campaign->minimum_discount_percent !== null
                ? (int) $campaign->minimum_discount_percent
                : null;
            $submittedMinimum = $data['minimum_discount_percent'] ?? null;
            $minimumDiscount = $submittedMinimum !== null && $submittedMinimum !== ''
                ? $this->minimumDiscount($submittedMinimum, required: false)
                : $storedMinimum;
            if ($minimumDiscount !== $storedMinimum) {
                $this->assertExistingPricesMeetMinimum($campaign, $minimumDiscount);
            }

            if (array_key_exists('scope_submitted', $data) && $campaign->participations()->exists()) {
                $sameStores = $campaign->stores()->pluck('stores.id')->map(fn ($id): int => (int) $id)->sort()->values()->all()
                    === collect($data['store_ids'] ?? [])->map(fn ($id): int => (int) $id)->sort()->values()->all();
                $sameCategories = $campaign->categories()->pluck('categories.id')->map(fn ($id): int => (int) $id)->sort()->values()->all()
                    === collect($data['category_ids'] ?? [])->map(fn ($id): int => (int) $id)->sort()->values()->all();
                if (! $sameStores || ! $sameCategories) {
                    throw ValidationException::withMessages([
                        'scope_submitted' => 'Store and category eligibility cannot change after Sellers have joined this campaign.',
                    ]);
                }
            }
            if (array_key_exists('product_ids', $data) && $campaign->participations()->exists()) {
                $currentProducts = $campaign->products()->pluck('products.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                $requestedProducts = collect($data['product_ids'] ?? [])->map(fn ($id): int => (int) $id)->sort()->values()->all();
                if ($currentProducts !== $requestedProducts) {
                    throw ValidationException::withMessages([
                        'product_ids' => 'Product eligibility cannot change after Sellers have joined this campaign.',
                    ]);
                }
            }

            $maxStores = array_key_exists('max_participating_stores', $data)
                ? ($data['max_participating_stores'] !== null ? (int) $data['max_participating_stores'] : null)
                : $campaign->max_participating_stores;
            if ($maxStores !== null) {
                $approvedStores = $campaign->participations()
                    ->whereIn('status', [
                        PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
                        PlatformCampaignParticipation::STATUS_APPROVED,
                    ])
                    ->distinct('store_id')
                    ->count('store_id');
                if ($maxStores < $approvedStores) {
                    throw ValidationException::withMessages([
                        'max_participating_stores' => 'The Seller limit cannot be lower than the number of pending and approved participations.',
                    ]);
                }
            }

            $maxProducts = array_key_exists('max_products_per_store', $data)
                ? ($data['max_products_per_store'] !== null ? (int) $data['max_products_per_store'] : null)
                : ($campaign->max_products_per_store !== null ? (int) $campaign->max_products_per_store : null);
            if ($maxProducts !== null) {
                // Select only the grouped column: MySQL's ONLY_FULL_GROUP_BY
                // rejects selecting item rows alongside this aggregate.
                $hasOverLimitParticipation = DB::table('platform_campaign_participation_items')
                    ->join('platform_campaign_participations', 'platform_campaign_participations.id', '=', 'platform_campaign_participation_items.platform_campaign_participation_id')
                    ->where('platform_campaign_participations.platform_promotion_id', $campaign->id)
                    ->whereIn('platform_campaign_participations.status', [
                        PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
                        PlatformCampaignParticipation::STATUS_APPROVED,
                    ])
                    ->select('platform_campaign_participations.id')
                    ->groupBy('platform_campaign_participations.id')
                    ->havingRaw('COUNT(DISTINCT platform_campaign_participation_items.product_id) > ?', [$maxProducts])
                    ->exists();
                if ($hasOverLimitParticipation) {
                    throw ValidationException::withMessages([
                        'max_products_per_store' => 'The product limit cannot be lower than an existing pending or approved participation.',
                    ]);
                }
            }

            $campaignType = (string) ($data['campaign_type'] ?? $campaign->campaign_type);
            $oldBannerPath = $campaign->banner_path;
            $oldBannerDisk = $campaign->banner_disk ?: 'public';
            $bannerPath = $banner?->store('campaigns/banners', 'public');
            $before = [
                'name' => $campaign->name,
                'campaign_type' => $campaign->campaign_type,
                'funding_type' => $campaign->funding_type,
                'minimum_discount_percent' => $campaign->minimum_discount_percent,
                'starts_on' => $campaign->starts_on?->toDateString(),
                'ends_on' => $campaign->ends_on?->toDateString(),
                'starts_time' => $campaign->starts_time,
                'ends_time' => $campaign->ends_time,
                'join_deadline' => $campaign->join_deadline?->toDateString(),
            ];

            $updates = [
                'name' => trim((string) ($data['name'] ?? $campaign->name)),
                'kind' => $campaignType === PlatformPromotion::CAMPAIGN_FLASH_DEAL
                    ? PlatformPromotion::KIND_FLASH_DEAL
                    : PlatformPromotion::KIND_SELLER_CAMPAIGN,
                'campaign_type' => $campaignType,
                'funding_type' => $newFunding,
                'minimum_discount_percent' => $minimumDiscount,
                'description' => array_key_exists('description', $data)
                    ? (filled($data['description']) ? trim((string) $data['description']) : null)
                    : $campaign->description,
                'terms' => array_key_exists('terms', $data)
                    ? (filled($data['terms']) ? trim((string) $data['terms']) : null)
                    : $campaign->terms,
                'campaign_notes' => array_key_exists('campaign_notes', $data)
                    ? (filled($data['campaign_notes']) ? trim((string) $data['campaign_notes']) : null)
                    : $campaign->campaign_notes,
                'starts_on' => $data['starts_on'] ?? $campaign->starts_on,
                'ends_on' => $data['ends_on'] ?? $campaign->ends_on,
                'starts_time' => array_key_exists('start_time', $data)
                    ? PlatformPromotion::timeFromInput($data['start_time'])
                    : $campaign->starts_time,
                'ends_time' => array_key_exists('end_time', $data)
                    ? PlatformPromotion::timeFromInput($data['end_time'], inclusiveMinute: true)
                    : $campaign->ends_time,
                'join_deadline' => $data['join_deadline'] ?? $campaign->join_deadline,
                'requires_approval' => (bool) ($data['requires_approval'] ?? $campaign->requires_approval),
                'max_participating_stores' => $maxStores,
                'max_products_per_store' => $maxProducts,
                'budget_minor' => $budgetMinor,
                'is_featured' => (bool) ($data['is_featured'] ?? $campaign->is_featured),
                'banner_title' => array_key_exists('banner_title', $data)
                    ? (filled($data['banner_title']) ? trim((string) $data['banner_title']) : null)
                    : $campaign->banner_title,
                'banner_link' => array_key_exists('banner_link', $data)
                    ? (filled($data['banner_link']) ? trim((string) $data['banner_link']) : null)
                    : $campaign->banner_link,
            ];

            if ($bannerPath !== null) {
                $updates['banner_path'] = $bannerPath;
                $updates['banner_disk'] = 'public';
            }

            $campaign->update($updates);

            if (array_key_exists('scope_submitted', $data)) {
                $campaign->stores()->sync($data['store_ids'] ?? []);
                $campaign->categories()->sync($data['category_ids'] ?? []);
            }
            if (array_key_exists('product_ids', $data)) {
                $campaign->products()->sync($data['product_ids'] ?? []);
            }

            $this->audit->record(
                'campaign.updated',
                'marketing',
                $campaign,
                'Bearly campaign details updated.',
                $before,
                [
                    'name' => $campaign->name,
                    'campaign_type' => $campaign->campaign_type,
                    'funding_type' => $campaign->funding_type,
                    'minimum_discount_percent' => $campaign->minimum_discount_percent,
                    'starts_on' => $campaign->starts_on?->toDateString(),
                    'ends_on' => $campaign->ends_on?->toDateString(),
                    'starts_time' => $campaign->starts_time,
                    'ends_time' => $campaign->ends_time,
                    'join_deadline' => $campaign->join_deadline?->toDateString(),
                ],
                'info',
            );

            if ($bannerPath !== null && $oldBannerPath) {
                DB::afterCommit(fn () => Storage::disk($oldBannerDisk)->delete($oldBannerPath));
            }

            return $campaign->fresh(['stores:id,name', 'categories:id,name', 'products:id,name']);
        }, attempts: 3);
    }

    public function updateStatus(User $admin, PlatformPromotion $campaign, string $status, ?string $reason = null): PlatformPromotion
    {
        return DB::transaction(function () use ($campaign, $status, $reason): PlatformPromotion {
            $campaign = PlatformPromotion::query()->campaigns()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $campaign->status;
            $allowed = [
                PlatformPromotion::STATUS_DRAFT => [PlatformPromotion::STATUS_OPEN, PlatformPromotion::STATUS_ENDED],
                PlatformPromotion::STATUS_OPEN => [PlatformPromotion::STATUS_ACTIVE, PlatformPromotion::STATUS_PAUSED, PlatformPromotion::STATUS_ENDED],
                PlatformPromotion::STATUS_ACTIVE => [PlatformPromotion::STATUS_PAUSED, PlatformPromotion::STATUS_ENDED],
                PlatformPromotion::STATUS_PAUSED => [PlatformPromotion::STATUS_ACTIVE, PlatformPromotion::STATUS_ENDED],
                PlatformPromotion::STATUS_ENDED => [],
            ];

            if ($status !== $oldStatus && ! in_array($status, $allowed[$oldStatus] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => 'That campaign status transition is not allowed.',
                ]);
            }

            if ($status === PlatformPromotion::STATUS_ACTIVE && $campaign->endsAt()?->lessThan(now('Asia/Manila'))) {
                throw ValidationException::withMessages([
                    'status' => 'The campaign schedule has already ended.',
                ]);
            }

            $campaign->update(['status' => $status]);

            $this->audit->record(
                'campaign.status_updated',
                'marketing',
                $campaign,
                'Bearly campaign status updated.',
                ['status' => $oldStatus],
                ['status' => $status, 'reason' => $reason],
                'info',
            );

            return $campaign->fresh();
        }, attempts: 3);
    }

    public function reviewParticipation(
        User $admin,
        PlatformCampaignParticipation $participation,
        string $decision,
        ?string $reason = null,
    ): PlatformCampaignParticipation {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        return DB::transaction(function () use ($admin, $participation, $decision, $reason): PlatformCampaignParticipation {
            $campaignId = PlatformCampaignParticipation::query()
                ->whereKey($participation->id)
                ->value('platform_promotion_id');
            $campaign = PlatformPromotion::query()
                ->campaigns()
                ->whereKey($campaignId)
                ->lockForUpdate()
                ->firstOrFail();
            $participation = PlatformCampaignParticipation::query()
                ->where('platform_promotion_id', $campaign->id)
                ->lockForUpdate()
                ->findOrFail($participation->id);

            if ($participation->status !== PlatformCampaignParticipation::STATUS_PENDING_REVIEW) {
                throw ValidationException::withMessages([
                    'participation' => 'Only participations pending review can be approved or rejected.',
                ]);
            }

            if ($decision === 'approve' && $campaign->max_participating_stores !== null) {
                $approvedStores = PlatformCampaignParticipation::query()
                    ->where('platform_promotion_id', $campaign->id)
                    ->where('status', PlatformCampaignParticipation::STATUS_APPROVED)
                    ->whereKeyNot($participation->id)
                    ->distinct('store_id')
                    ->count('store_id');
                if ($approvedStores >= (int) $campaign->max_participating_stores) {
                    throw ValidationException::withMessages([
                        'participation' => 'The campaign has reached its approved Seller limit.',
                    ]);
                }
            }

            $oldStatus = $participation->status;
            $participation->update([
                'status' => $decision === 'approve'
                    ? PlatformCampaignParticipation::STATUS_APPROVED
                    : PlatformCampaignParticipation::STATUS_REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_reason' => filled($reason) ? trim((string) $reason) : null,
            ]);

            $this->audit->record(
                'campaign.participation_reviewed',
                'marketing',
                $participation,
                'Admin reviewed Seller campaign participation.',
                ['status' => $oldStatus],
                ['status' => $participation->status, 'reason' => $reason],
                'info',
            );

            return $participation->fresh('items');
        }, attempts: 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eligibleProducts(PlatformPromotion $campaign, Store $store): array
    {
        $campaign->loadMissing(['categories:id', 'products:id']);
        $categoryIds = $campaign->categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $productIds = $campaign->products->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $store->products()
            ->visibleToBuyers()
            ->when($categoryIds !== [], fn ($query) => $query->whereIn('category_id', $categoryIds))
            ->when($productIds !== [], fn ($query) => $query->whereIn('id', $productIds))
            ->with(['variants' => fn ($variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved')
                ->orderBy('position')
                ->orderBy('id')])
            ->whereHas('variants', fn ($variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved'))
            ->orderBy('name')
            ->get()
            ->map(function (Product $product): array {
                $variants = $product->variants->map(function (ProductVariant $variant) use ($product): array {
                    $effectiveSellerPrice = (int) $this->pricing
                        ->priceForVariant($product, $variant)['seller_unit_price_minor'];

                    return [
                        'id' => (int) $variant->id,
                        'sku' => $variant->sku,
                        'name' => $variant->name,
                        'regular_price_minor' => $effectiveSellerPrice,
                        'stock' => (int) $variant->available_stock,
                    ];
                })->values();

                return [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'category_id' => (int) $product->category_id,
                    'sku' => $product->variants->first()?->sku,
                    'price' => ((int) ($variants->min('regular_price_minor') ?? 0)) / 100,
                    'variants' => $variants->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $submittedItems
     * @return array<int, array<string, mixed>>
     */
    private function validatedJoinItems(PlatformPromotion $campaign, Store $store, array $submittedItems): array
    {
        if ($submittedItems === []) {
            throw ValidationException::withMessages([
                'items' => 'Select at least one eligible product for this campaign.',
            ]);
        }

        $campaign->loadMissing(['categories:id', 'products:id']);
        $categoryIds = $campaign->categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $campaignProductIds = $campaign->products->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $productIds = collect($submittedItems)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->count() !== count($submittedItems)) {
            throw ValidationException::withMessages([
                'items' => 'Submit each eligible product only once.',
            ]);
        }

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds->all())
            ->visibleToBuyers()
            ->when($categoryIds !== [], fn ($query) => $query->whereIn('category_id', $categoryIds))
            ->when($campaignProductIds !== [], fn ($query) => $query->whereIn('id', $campaignProductIds))
            ->with(['variants' => fn ($variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved')
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()])
            ->whereHas('variants', fn ($variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Every selected product must be eligible for this campaign and belong to your Store.',
            ]);
        }

        $rows = [];
        foreach ($submittedItems as $submitted) {
            $product = $products->get((int) ($submitted['product_id'] ?? 0));
            if (! $product) {
                continue;
            }

            if (array_key_exists('campaign_price', $submitted) || array_key_exists('variant_id', $submitted)) {
                throw ValidationException::withMessages([
                    'items' => 'Submit a discount percentage per product. Campaign prices and SKU selection are computed by Bearly.',
                ]);
            }

            $variants = $product->variants->values();
            if ($variants->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => "{$product->name} has no active SKU with available stock for this campaign.",
                ]);
            }

            $discountPercent = filter_var($submitted['discount_percent'] ?? null, FILTER_VALIDATE_INT);
            if ($discountPercent === false || $discountPercent < 1 || $discountPercent > 90) {
                throw ValidationException::withMessages([
                    'items' => "The discount for {$product->name} must be a whole percentage between 1 and 90.",
                ]);
            }

            $minimumDiscount = $campaign->minimum_discount_percent !== null
                ? (int) $campaign->minimum_discount_percent
                : 1;
            if ($discountPercent < $minimumDiscount) {
                throw ValidationException::withMessages([
                    'items' => "This campaign requires at least a {$minimumDiscount}% discount for {$product->name}.",
                ]);
            }

            $stockLimit = isset($submitted['stock_limit']) && $submitted['stock_limit'] !== ''
                ? (int) $submitted['stock_limit']
                : null;
            if ($stockLimit !== null && $stockLimit < 1) {
                throw ValidationException::withMessages([
                    'items' => "Enter a valid campaign stock for {$product->name}.",
                ]);
            }

            $perBuyerLimit = isset($submitted['per_buyer_limit']) && $submitted['per_buyer_limit'] !== ''
                ? (int) $submitted['per_buyer_limit']
                : null;
            if ($perBuyerLimit !== null && ($perBuyerLimit < 1 || $perBuyerLimit > 1000)) {
                throw ValidationException::withMessages([
                    'items' => "Enter a valid per-buyer limit for {$product->name}.",
                ]);
            }

            foreach ($variants as $variant) {
                $regular = (int) $this->pricing
                    ->priceForVariant($product, $variant)['seller_unit_price_minor'];
                $price = $this->discountedPriceMinor($regular, $discountPercent);
                if ($price < 1 || $price >= $regular) {
                    throw ValidationException::withMessages([
                        'items' => "The discount for {$product->name} ({$variant->sku}) does not create a lower centavo price. Choose a higher discount.",
                    ]);
                }

                $rows[] = [
                    'product_id' => (int) $product->id,
                    'product_variant_id' => (int) $variant->id,
                    'regular_price_minor' => $regular,
                    'campaign_price_minor' => $price,
                    'discount_percent' => $discountPercent,
                    'stock_limit' => $stockLimit,
                    'per_buyer_limit' => $perBuyerLimit,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function sellerCampaignPayload(PlatformPromotion $campaign): array
    {
        $participation = $campaign->relationLoaded('participations')
            ? $campaign->participations->first()
            : null;
        $items = $participation?->relationLoaded('items')
            ? $participation->items
            : collect();

        return [
            'id' => (int) $campaign->id,
            'name' => $campaign->name,
            'campaign_type' => $campaign->campaign_type,
            'type_label' => $campaign->campaignTypeLabel(),
            'description' => $campaign->description,
            'terms' => $campaign->terms,
            'funding_type' => $campaign->funding_type,
            'minimum_discount_percent' => $campaign->minimum_discount_percent !== null
                ? (int) $campaign->minimum_discount_percent
                : null,
            'effective_minimum_discount_percent' => max(1, (int) ($campaign->minimum_discount_percent ?? 1)),
            'funding_label' => $campaign->funding_type === PlatformPromotion::FUNDING_SELLER
                ? 'Seller-funded'
                : 'Bearly-funded',
            'status_key' => $campaign->statusKey(),
            'status_label' => ucfirst(str_replace('_', ' ', $campaign->statusKey())),
            'starts_on' => $campaign->starts_on?->toDateString(),
            'ends_on' => $campaign->ends_on?->toDateString(),
            'starts_time' => substr((string) $campaign->starts_time, 0, 5),
            'ends_time' => substr((string) $campaign->ends_time, 0, 5),
            'starts_at' => $campaign->startsAt()?->toIso8601String(),
            'ends_at' => $campaign->endsAt()?->toIso8601String(),
            'join_deadline' => $campaign->join_deadline?->toDateString(),
            'period' => $campaign->schedulePeriod(),
            'requires_approval' => (bool) $campaign->requires_approval,
            'max_participating_stores' => $campaign->max_participating_stores,
            'max_products_per_store' => $campaign->max_products_per_store,
            'approved_participations_count' => (int) ($campaign->approved_participations_count ?? 0),
            'banner_url' => $campaign->banner_path
                ? Storage::disk($campaign->banner_disk ?: 'public')->url($campaign->banner_path)
                : null,
            'banner_title' => $campaign->banner_title,
            'scope' => $campaign->stores->isNotEmpty()
                ? $campaign->stores->pluck('name')->join(', ')
                : ($campaign->categories->isNotEmpty()
                    ? $campaign->categories->pluck('name')->join(', ')
                    : 'All eligible Stores'),
            'participation' => $participation ? [
                'id' => (int) $participation->id,
                'status' => $participation->status,
                'status_label' => $participation->statusLabel(),
                'submitted_at' => $participation->submitted_at?->toIso8601String(),
                'review_reason' => $participation->review_reason,
                'product_count' => $items->pluck('product_id')->unique()->count(),
                'items' => $items->map(fn (PlatformCampaignParticipationItem $item): array => [
                    'id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'product_name' => $item->product?->name,
                    'sku' => $item->variant?->sku,
                    'campaign_price_minor' => (int) $item->campaign_price_minor,
                    'regular_price_minor' => (int) $item->regular_price_minor,
                    'discount_percent' => $item->discount_percent !== null ? (int) $item->discount_percent : null,
                    'stock_limit' => $item->stock_limit,
                ])->values()->all(),
            ] : null,
        ];
    }

    private function assertStoreEligible(
        PlatformPromotion $campaign,
        Store $store,
        bool $requirePublished = true,
    ): void {
        $campaign->loadMissing('stores:id');
        if ($campaign->stores->isNotEmpty() && ! $campaign->stores->contains(fn (Store $eligible): bool => (int) $eligible->id === (int) $store->id)) {
            throw ValidationException::withMessages([
                'campaign' => 'Your Store is not eligible for this campaign.',
            ]);
        }
        if ($requirePublished && $store->publication_status !== 'published') {
            throw ValidationException::withMessages([
                'campaign' => 'Publish your Store before joining a Bearly campaign.',
            ]);
        }
    }

    private function minor(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid peso amount with up to two decimal places.',
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function minimumDiscount(mixed $value, bool $required): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    'minimum_discount_percent' => 'Set the minimum Seller discount percentage for this campaign.',
                ]);
            }

            return null;
        }

        $minimum = filter_var($value, FILTER_VALIDATE_INT);
        if ($minimum === false || $minimum < 1 || $minimum > 90) {
            throw ValidationException::withMessages([
                'minimum_discount_percent' => 'The minimum discount must be a whole percentage from 1 to 90.',
            ]);
        }

        return $minimum;
    }

    private function assertExistingPricesMeetMinimum(PlatformPromotion $campaign, ?int $minimum): void
    {
        if ($minimum === null) {
            return;
        }

        $items = PlatformCampaignParticipationItem::query()
            ->whereHas('participation', fn ($participations) => $participations
                ->where('platform_promotion_id', $campaign->id)
                ->whereIn('status', [
                    PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
                    PlatformCampaignParticipation::STATUS_APPROVED,
                ]))
            ->get(['regular_price_minor', 'campaign_price_minor', 'discount_percent']);

        foreach ($items as $item) {
            $baseline = max(0, (int) $item->regular_price_minor);
            $price = $item->campaign_price_minor !== null
                ? (int) $item->campaign_price_minor
                : ($item->discount_percent !== null
                    ? $this->discountedPriceMinor($baseline, (int) $item->discount_percent)
                    : null);
            $maximumAllowedPrice = $this->discountedPriceMinor($baseline, $minimum);

            if ($baseline < 1 || $price === null || $price < 1 || $price >= $baseline || $price > $maximumAllowedPrice) {
                throw ValidationException::withMessages([
                    'minimum_discount_percent' => "The minimum cannot be set to {$minimum}% because a pending or approved Seller submission does not meet it. Review or reject that participation first; submitted prices will not be changed.",
                ]);
            }
        }
    }

    private function discountedPriceMinor(int $baselineMinor, int $discountPercent): int
    {
        return max(1, intdiv(max(0, $baselineMinor) * (100 - $discountPercent) + 50, 100));
    }
}
