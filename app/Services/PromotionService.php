<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\PromotionClaim;
use App\Models\PromotionRedemption;
use App\Models\PromotionRedemptionAllocation;
use App\Models\SellerOrder;
use App\Models\StorePromotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    private const ACTIVE_REDEMPTION_STATUSES = [
        PromotionRedemption::STATUS_RESERVED,
        PromotionRedemption::STATUS_REDEEMED,
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Publicly displayable platform offers for the Buyer storefront.
     *
     * @param  array<int, string>  $kinds
     * @return Collection<int, array<string, mixed>>
     */
    public function publicOffers(array $kinds = [], bool $featuredOnly = false): Collection
    {
        $query = $this->activePlatformQuery()
            ->orderBy('position')
            ->orderBy('id');

        if ($kinds !== []) {
            $query->whereIn('kind', $kinds);
        }

        if ($featuredOnly) {
            $query->where('is_featured', true)->campaigns();
        }

        return $query
            ->withCount(['redemptions as active_redemptions_count' => fn (Builder $redemptions) => $redemptions
                ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)])
            ->withSum(['redemptions as active_discount_minor' => fn (Builder $redemptions) => $redemptions
                ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)], 'discount_minor')
            ->withSum(['redemptions as active_shipping_discount_minor' => fn (Builder $redemptions) => $redemptions
                ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)], 'shipping_discount_minor')
            ->get()
            ->filter(function (PlatformPromotion $promotion): bool {
                if ($promotion->isCampaign()) {
                    return true;
                }

                return ! $promotion->isExhausted(
                    (int) $promotion->active_redemptions_count,
                    (int) $promotion->active_discount_minor + (int) $promotion->active_shipping_discount_minor,
                );
            })
            ->map(function (PlatformPromotion $promotion): array {
            $isCampaign = $promotion->isCampaign();

            return [
                'id' => (int) $promotion->id,
                'name' => (string) $promotion->name,
                'kind' => (string) $promotion->kind,
                'code' => $promotion->code,
                'description' => $promotion->description,
                'terms' => $promotion->terms,
                'discount_label' => $promotion->discountLabel(),
                'minimum_spend_minor' => (int) $promotion->minimum_spend_minor,
                'starts_on' => $promotion->starts_on?->toDateString(),
                'ends_on' => $promotion->ends_on?->toDateString(),
                'starts_at' => $promotion->startsAt()?->toIso8601String(),
                'ends_at' => $promotion->endsAt()?->toIso8601String(),
                'is_featured' => $isCampaign && (bool) $promotion->is_featured,
                'banner_url' => $isCampaign && $promotion->banner_path
                    ? Storage::disk($promotion->banner_disk ?: 'public')->url($promotion->banner_path)
                    : null,
                'banner_title' => $isCampaign ? $promotion->banner_title : null,
                'banner_link' => $isCampaign ? $promotion->banner_link : null,
                'campaign_type' => $promotion->campaign_type,
                'campaign_type_label' => $isCampaign ? $promotion->campaignTypeLabel() : null,
                'funding_type' => $promotion->funding_type,
                'is_campaign' => $isCampaign,
            ];
            })->values();
    }

    /**
     * @return array{platform: Collection<int, array<string, mixed>>, store: Collection<int, array<string, mixed>>, claimed_count: int}
     */
    public function wallet(User $buyer): array
    {
        $platform = $this->activePlatformQuery()
            ->where(function (Builder $query): void {
                $query->where(function (Builder $vouchers): void {
                    $vouchers->where('kind', PlatformPromotion::KIND_VOUCHER)
                        ->where('is_claimable', true);
                })->orWhere(function (Builder $shipping): void {
                    $shipping->where('kind', PlatformPromotion::KIND_FREE_SHIPPING)
                        ->where(function (Builder $availableShipping): void {
                            $availableShipping->where('is_claimable', true)
                                ->orWhereNull('code');
                        });
                });
            })
            ->with(['products:id', 'categories:id', 'stores:id,name'])
            ->withCount(['redemptions as active_redemptions_count' => fn (Builder $query) => $query
                ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)])
            ->withSum(['redemptions as active_discount_minor' => fn (Builder $query) => $query
                ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)], 'discount_minor')
            ->withSum(['redemptions as active_shipping_discount_minor' => fn (Builder $query) => $query
                ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)], 'shipping_discount_minor')
            ->orderBy('position')
            ->orderBy('ends_on')
            ->orderBy('ends_time')
            ->orderBy('id')
            ->get();

        $store = $this->activeStoreQuery()
            ->where('is_claimable', true)
            ->with(['store:id,name,slug,publication_status'])
            ->withCount('products')
            ->withCount(['redemptions as active_redemptions_count' => fn (Builder $query) => $query
                ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)])
            ->orderBy('ends_on')
            ->orderBy('ends_time')
            ->orderBy('id')
            ->get();

        $platformClaims = PromotionClaim::query()
            ->where('buyer_id', $buyer->id)
            ->whereNotNull('platform_promotion_id')
            ->get()
            ->keyBy('platform_promotion_id');
        $storeClaims = PromotionClaim::query()
            ->where('buyer_id', $buyer->id)
            ->whereNotNull('store_promotion_id')
            ->get()
            ->keyBy('store_promotion_id');
        $platformUsage = $this->buyerUsageCounts($buyer, 'platform');
        $storeUsage = $this->buyerUsageCounts($buyer, 'store');

        $platformRows = $platform->map(function (PlatformPromotion $promotion) use ($platformClaims, $platformUsage): array {
            return $this->platformVoucherPayload(
                $promotion,
                $platformClaims->get($promotion->id),
                (int) ($platformUsage[$promotion->id] ?? 0),
                [
                    'active_count' => (int) $promotion->active_redemptions_count,
                    'buyer_count' => (int) ($platformUsage[$promotion->id] ?? 0),
                    'budget_spend_minor' => (int) $promotion->active_discount_minor + (int) $promotion->active_shipping_discount_minor,
                ],
            );
        });

        $storeRows = $store->map(function (StorePromotion $promotion) use ($storeClaims, $storeUsage): array {
            return $this->storeVoucherPayload(
                $promotion,
                $storeClaims->get($promotion->id),
                (int) ($storeUsage[$promotion->id] ?? 0),
                ['active_count' => (int) $promotion->active_redemptions_count],
            );
        });

        return [
            'platform' => $platformRows,
            'store' => $storeRows,
            'claimed_count' => $platformRows->where('claimed', true)->count()
                + $storeRows->where('claimed', true)->count(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    public function optionsFor(
        User $buyer,
        array $groups,
        bool $lock = false,
        ?array $lockedAutomaticPromotionIds = null,
    ): array
    {
        $groups = $this->initializeGroups($groups);
        $platformClaims = PromotionClaim::query()
            ->where('buyer_id', $buyer->id)
            ->whereNotNull('platform_promotion_id')
            ->with(['platformPromotion.products:id', 'platformPromotion.categories:id', 'platformPromotion.stores:id,name'])
            ->get();
        $storeClaims = PromotionClaim::query()
            ->where('buyer_id', $buyer->id)
            ->whereNotNull('store_promotion_id')
            ->with(['storePromotion.store:id,name,slug,publication_status', 'storePromotion.products:id'])
            ->get();
        $platformPromotions = $platformClaims->pluck('platformPromotion')->filter()->values();
        $storePromotions = $storeClaims->pluck('storePromotion')->filter()->values();
        $platformUsage = $this->redemptionStatsFor($platformPromotions, $buyer, 'platform');
        $storeUsage = $this->redemptionStatsFor($storePromotions, $buyer, 'store');

        $platform = $platformClaims
            ->map(function (PromotionClaim $claim) use ($groups, $platformUsage): ?array {
                $promotion = $claim->platformPromotion;
                if (! $promotion || ! $promotion->isActiveAt()) {
                    return null;
                }
                $usage = $platformUsage[(int) $promotion->id] ?? [];
                $preview = $this->previewWithRemainingBudget($promotion, $groups, $usage);
                return $this->optionPayload(
                    $this->platformVoucherPayload($promotion, $claim, (int) ($usage['buyer_count'] ?? 0), $usage),
                    $preview,
                );
            })
            ->filter()
            ->values();

        $store = $storeClaims
            ->map(function (PromotionClaim $claim) use ($groups, $storeUsage): ?array {
                $promotion = $claim->storePromotion;
                if (! $promotion || ! $promotion->isActiveAt() || $promotion->type !== StorePromotion::TYPE_STORE_VOUCHER) {
                    return null;
                }
                $usage = $storeUsage[(int) $promotion->id] ?? [];
                $preview = $this->applyPromotionAvailability(
                    $promotion,
                    $this->previewStore($promotion, $groups),
                    $usage,
                    checkBudget: false,
                );
                return $this->optionPayload(
                    $this->storeVoucherPayload($promotion, $claim, (int) ($usage['buyer_count'] ?? 0), $usage),
                    $preview,
                );
            })
            ->filter()
            ->values();

        $automaticQuery = $this->automaticPlatformQuery($lock && $lockedAutomaticPromotionIds === null);
        if ($lockedAutomaticPromotionIds !== null) {
            $automaticQuery->whereIn('id', $lockedAutomaticPromotionIds);
        }
        $automaticPromotions = $automaticQuery
            ->with(['products:id', 'categories:id', 'stores:id,name'])
            ->orderBy('id')
            ->get();
        $automaticUsage = $this->redemptionStatsFor($automaticPromotions, $buyer, 'platform', $lock);
        $automatic = $automaticPromotions
            ->map(function (PlatformPromotion $promotion) use ($groups, $automaticUsage): array {
                $usage = $automaticUsage[(int) $promotion->id] ?? [];
                $preview = $this->previewWithRemainingBudget($promotion, $groups, $usage);
                $row = $this->platformVoucherPayload($promotion, null, 0, $usage);
                $row['automatic'] = true;
                $row['applies_with_platform_voucher'] = $promotion->isShippingDiscount();
                $row['_promotion'] = $promotion;
                $row['_usage'] = $usage;
                $row['_line_weights'] = $preview['line_weights'] ?? [];
                $row['_shipping_weights'] = $preview['shipping_weights'] ?? [];
                return $this->optionPayload($row, $preview);
            })
            ->values();

        return [
            'platform' => $platform,
            'store' => $store,
            'automatic' => $automatic,
        ];
    }

    /**
     * Resolve the Buyer's selected vouchers and active automatic promotions.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array{platform_voucher_id?: int|null, platform_shipping_voucher_id?: int|null, store_voucher_id?: int|null, voucher_code?: string|null}  $selection
     * @return array<string, mixed>
     */
    public function resolve(User $buyer, array $groups, array $selection = [], bool $lock = false): array
    {
        $groups = $this->initializeGroups($groups);
        $selectedPlatformId = isset($selection['platform_voucher_id']) && (int) $selection['platform_voucher_id'] > 0
            ? (int) $selection['platform_voucher_id']
            : null;
        $selectedShippingId = isset($selection['platform_shipping_voucher_id']) && (int) $selection['platform_shipping_voucher_id'] > 0
            ? (int) $selection['platform_shipping_voucher_id']
            : null;
        $selectedStoreId = isset($selection['store_voucher_id']) && (int) $selection['store_voucher_id'] > 0
            ? (int) $selection['store_voucher_id']
            : null;
        $code = strtoupper(trim((string) ($selection['voucher_code'] ?? '')));

        $platformId = $selectedPlatformId;
        $platformShippingId = $selectedShippingId;
        $storeId = $selectedStoreId;
        $codePlatform = null;
        $codeStore = null;
        $platform = null;
        $platformShipping = null;
        $store = null;
        $platformClaim = null;
        $platformShippingClaim = null;
        $storeClaim = null;

        // Older checkout clients submit a shipping voucher in the original
        // platform_voucher_id slot. Normalize before code stacking is resolved
        // so those clients may still combine it with one merchandise voucher.
        if ($platformId !== null) {
            $legacySelection = PlatformPromotion::query()
                ->select(['id', 'discount_type'])
                ->find($platformId);
            if ($legacySelection?->isShippingDiscount()) {
                if ($platformShippingId !== null && $platformShippingId !== $platformId) {
                    throw ValidationException::withMessages([
                        'platform_shipping_voucher_id' => 'Only one platform shipping voucher can be applied per order.',
                    ]);
                }
                $platformId = null;
                $selectedPlatformId = null;
                $platformShippingId = (int) $legacySelection->id;
                $selectedShippingId = (int) $legacySelection->id;
            }
        }

        if ($code !== '') {
            [$codePlatform, $codeStore] = $this->findByCode($code, false);

            if ($codePlatform && $codeStore) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'This code matches more than one promotion. Use the voucher selector instead.',
                ]);
            }

            if ($codePlatform) {
                if ($codePlatform->isShippingDiscount()) {
                    if ($platformShippingId !== null && $platformShippingId !== (int) $codePlatform->id) {
                        throw ValidationException::withMessages([
                            'voucher_code' => 'Only one platform shipping voucher can be applied per order.',
                        ]);
                    }
                    $platformShippingId = (int) $codePlatform->id;
                } else {
                    if ($platformId !== null && $platformId !== (int) $codePlatform->id) {
                        throw ValidationException::withMessages([
                            'voucher_code' => 'Only one platform merchandise voucher can be applied per order.',
                        ]);
                    }
                    $platformId = (int) $codePlatform->id;
                }
            } elseif ($codeStore) {
                if ($storeId !== null && $storeId !== (int) $codeStore->id) {
                    throw ValidationException::withMessages([
                        'voucher_code' => 'Only one store voucher can be applied per order.',
                    ]);
                }
                $store = $codeStore;
                $storeId = (int) $codeStore->id;
            } else {
                throw ValidationException::withMessages([
                    'voucher_code' => 'That voucher code is invalid, expired, or no longer available.',
                ]);
            }
        }

        $lockedAutomaticPromotionIds = null;
        if ($lock) {
            // Lock every possible automatic offer and explicitly selected
            // platform promotion in one deterministic ID order. Separate
            // selected-then-automatic locks can deadlock when two checkouts
            // choose different offers from the same automatic pool.
            $lockedAutomaticPromotionIds = $this->automaticPromotionIdsForGroups($groups);
            $platformIdsToLock = collect([$platformId, $platformShippingId, $codePlatform?->id])
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->merge($lockedAutomaticPromotionIds)
                ->unique()
                ->sort()
                ->values();
            if ($platformIdsToLock->isNotEmpty()) {
                PlatformPromotion::query()
                    ->whereIn('id', $platformIdsToLock->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }
            $storeIdToLock = $storeId ?? (int) ($codeStore?->id ?? 0);
            if ($storeIdToLock > 0) {
                StorePromotion::query()->whereKey($storeIdToLock)->lockForUpdate()->first();
            }
        }

        if ($platformId !== null) {
            [$platform, $platformClaim] = $this->findPlatformVoucherForBuyer(
                $buyer,
                $platformId,
                $lock,
                $code !== '' && $codePlatform && (int) $codePlatform->id === $platformId,
                'platform_voucher_id',
            );
            if ($platform->isShippingDiscount()) {
                throw ValidationException::withMessages([
                    'platform_voucher_id' => 'Choose a merchandise voucher in this field. Select shipping vouchers separately.',
                ]);
            }
            if ($code !== '' && $codePlatform && (int) $codePlatform->id === (int) $platform->id
                && ! hash_equals(strtoupper((string) $platform->code), $code)) {
                throw ValidationException::withMessages(['voucher_code' => 'That voucher code is no longer valid.']);
            }
            $platformCodeMatches = $codePlatform && (int) $codePlatform->id === (int) $platform->id;
            $platformUsage = $this->assertUsable($platform, $buyer, true, $lock, (bool) $platformCodeMatches, 'platform_voucher_id');
        } else {
            $platformUsage = [];
        }

        if ($platformShippingId !== null) {
            [$platformShipping, $platformShippingClaim] = $this->findPlatformVoucherForBuyer(
                $buyer,
                $platformShippingId,
                $lock,
                $code !== '' && $codePlatform && (int) $codePlatform->id === $platformShippingId,
                'platform_shipping_voucher_id',
            );
            if (! $platformShipping->isShippingDiscount()) {
                throw ValidationException::withMessages([
                    'platform_shipping_voucher_id' => 'Choose a shipping voucher in this field.',
                ]);
            }
            if ($code !== '' && $codePlatform && (int) $codePlatform->id === (int) $platformShipping->id
                && ! hash_equals(strtoupper((string) $platformShipping->code), $code)) {
                throw ValidationException::withMessages(['voucher_code' => 'That voucher code is no longer valid.']);
            }
            $platformShippingUsage = $this->assertUsable(
                $platformShipping,
                $buyer,
                true,
                $lock,
                (bool) ($codePlatform && (int) $codePlatform->id === (int) $platformShipping->id),
                'platform_shipping_voucher_id',
            );
        } else {
            $platformShippingUsage = [];
        }

        if ($storeId !== null) {
            [$store, $storeClaim] = $this->findStoreVoucherForBuyer(
                $buyer,
                $storeId,
                $lock,
                $code !== '' && $codeStore && (int) $codeStore->id === $storeId,
            );
            if ($code !== '' && $codeStore && (int) $codeStore->id === (int) $store->id
                && ! hash_equals(strtoupper((string) $store->code), $code)) {
                throw ValidationException::withMessages(['voucher_code' => 'That voucher code is no longer valid.']);
            }
            $storeCodeMatches = $codeStore && (int) $codeStore->id === (int) $store->id;
            $storeUsage = $this->assertUsable($store, $buyer, false, $lock, (bool) $storeCodeMatches, 'store_voucher_id');
        } else {
            $storeUsage = [];
        }

        $applied = [
            'store' => null,
            'platform' => null,
            'platform_shipping' => null,
            'automatic' => null,
            'shipping' => null,
        ];
        $options = $this->optionsFor($buyer, $groups, $lock, $lockedAutomaticPromotionIds);

        if ($store) {
            $preview = $this->previewStore($store, $groups);
            $preview = $this->applyPromotionAvailability($store, $preview, $storeUsage);
            if (! $preview['eligible']) {
                throw ValidationException::withMessages([
                    'store_voucher_id' => $preview['reason'] ?: 'This store voucher is not eligible for the selected items.',
                ]);
            }
            $allocations = $this->allocateProportionally(
                $preview['discount_minor'],
                $preview['line_weights'],
            );
            $applied['store'] = [
                'source' => 'store',
                'promotion' => $store,
                'claim' => $storeClaim,
                'discount_minor' => $preview['discount_minor'],
                'shipping_discount_minor' => 0,
                'line_allocations' => $allocations,
                'shipping_allocations' => [],
            ];
            $this->applyLineAllocations($groups, $allocations, 'seller');
        }

        if ($platform) {
            $preview = $this->applyPromotionAvailability(
                $platform,
                $this->previewPlatform($platform, $groups),
                $platformUsage,
                checkBudget: false,
            );
            if (! $preview['eligible']) {
                throw ValidationException::withMessages([
                    'platform_voucher_id' => $preview['reason'] ?: 'This platform voucher is not eligible for the selected items.',
                ]);
            }

            $allocations = $this->capPlatformAllocations(
                $this->allocateProportionally($preview['discount_minor'], $preview['line_weights']),
                $groups,
            );
            $discount = array_sum($allocations);
            $this->assertDiscountWithinRemainingBudget($platform, $platformUsage, $discount, 'platform_voucher_id');
            $applied['platform'] = [
                'source' => 'platform',
                'promotion' => $platform,
                'claim' => $platformClaim,
                'discount_minor' => $discount,
                'shipping_discount_minor' => 0,
                'line_allocations' => $allocations,
                'shipping_allocations' => [],
            ];
            $this->applyLineAllocations($groups, $allocations, 'platform');
        }

        if ($platformShipping) {
            $preview = $this->applyPromotionAvailability(
                $platformShipping,
                $this->previewPlatform($platformShipping, $groups),
                $platformShippingUsage,
                checkBudget: false,
            );
            if (! $preview['eligible']) {
                throw ValidationException::withMessages([
                    'platform_shipping_voucher_id' => $preview['reason'] ?: 'This shipping voucher is not eligible for this delivery.',
                ]);
            }
            $shippingAllocations = $this->allocateProportionally(
                $preview['shipping_discount_minor'],
                $preview['shipping_weights'],
            );
            $shippingDiscount = array_sum($shippingAllocations);
            $this->assertDiscountWithinRemainingBudget($platformShipping, $platformShippingUsage, $shippingDiscount, 'platform_shipping_voucher_id');
            $applied['platform_shipping'] = [
                'source' => 'platform',
                'promotion' => $platformShipping,
                'claim' => $platformShippingClaim,
                'discount_minor' => 0,
                'shipping_discount_minor' => $shippingDiscount,
                'line_allocations' => [],
                'shipping_allocations' => $shippingAllocations,
            ];
            $this->applyShippingAllocations($groups, $shippingAllocations);
        }

        if (! $platform) {
            $bestAutomatic = $this->bestAutomaticMerchandiseOption($options['automatic'], $groups);
            if ($bestAutomatic) {
                $promotion = $bestAutomatic['promotion'];
                $allocations = $bestAutomatic['allocations'];
                $discount = (int) $bestAutomatic['discount_minor'];
                $this->assertDiscountWithinRemainingBudget($promotion, $bestAutomatic['usage'], $discount, 'promotion');
                $applied['automatic'] = [
                    'source' => 'automatic',
                    'promotion' => $promotion,
                    'claim' => null,
                    'discount_minor' => $discount,
                    'shipping_discount_minor' => 0,
                    'line_allocations' => $allocations,
                    'shipping_allocations' => [],
                ];
                $this->applyLineAllocations($groups, $allocations, 'platform');
            }
        }

        if (! $platformShipping) {
            $bestShipping = $this->bestAutomaticShippingOption($options['automatic'], $groups);
            if ($bestShipping) {
                $promotion = $bestShipping['promotion'];
                $allocations = $bestShipping['allocations'];
                $shippingDiscount = (int) $bestShipping['shipping_discount_minor'];
                $this->assertDiscountWithinRemainingBudget($promotion, $bestShipping['usage'], $shippingDiscount, 'promotion');
                $applied['shipping'] = [
                    'source' => 'automatic',
                    'promotion' => $promotion,
                    'claim' => null,
                    'discount_minor' => 0,
                    'shipping_discount_minor' => $shippingDiscount,
                    'line_allocations' => [],
                    'shipping_allocations' => $allocations,
                ];
                $this->applyShippingAllocations($groups, $allocations);
            }
        }

        $groups = $this->finalizeGroups($groups);
        $totals = $this->summary($groups, $applied);

        return [
            'groups' => $groups,
            'applied' => $applied,
            'options' => $this->publicOptions($options),
            'selection' => [
                'platform_voucher_id' => $selectedPlatformId,
                'platform_shipping_voucher_id' => $selectedShippingId,
                'store_voucher_id' => $selectedStoreId,
                'voucher_code' => $code !== '' ? $code : null,
            ],
            'totals' => $totals,
        ];
    }

    public function claim(User $buyer, array $data): array
    {
        return DB::transaction(function () use ($buyer, $data): array {
            $code = strtoupper(trim((string) ($data['code'] ?? '')));
            $platformId = isset($data['platform_promotion_id']) ? (int) $data['platform_promotion_id'] : null;
            $storeId = isset($data['store_promotion_id']) ? (int) $data['store_promotion_id'] : null;

            if ($code !== '') {
                [$platform, $store] = $this->findByCode($code, true);
                if ($platform && $store) {
                    throw ValidationException::withMessages([
                        'code' => 'This code matches more than one promotion. Contact Bearly support.',
                    ]);
                }
                $platformId = $platform ? (int) $platform->id : null;
                $storeId = $store ? (int) $store->id : null;
            }

            if ($platformId === null && $storeId === null) {
                throw ValidationException::withMessages([
                    'code' => 'Choose a voucher or enter a voucher code.',
                ]);
            }

            if ($platformId !== null) {
                $promotion = PlatformPromotion::query()->whereKey($platformId)->lockForUpdate()->firstOrFail();
                if (! $this->canBeClaimedFromWallet($promotion)) {
                    throw ValidationException::withMessages([
                        'platform_promotion_id' => filled($promotion->code)
                            ? 'Enter this shipping voucher code directly at checkout; it cannot be claimed into My Vouchers.'
                            : 'This Bearly offer is applied automatically and cannot be claimed as a voucher.',
                    ]);
                }
                if (! $promotion->is_claimable || ! $promotion->is_public) {
                    throw ValidationException::withMessages([
                        'platform_promotion_id' => 'This platform offer does not need to be claimed. Apply its code at checkout.',
                    ]);
                }
                $this->assertUsable($promotion, $buyer, true, true);

                $claim = PromotionClaim::query()->firstOrCreate(
                    ['buyer_id' => $buyer->id, 'platform_promotion_id' => $promotion->id],
                    ['status' => PromotionClaim::STATUS_CLAIMED, 'claimed_at' => now()],
                );
                $claim->update([
                    'status' => PromotionClaim::STATUS_CLAIMED,
                    'claimed_at' => $claim->claimed_at ?? now(),
                    'used_at' => null,
                ]);

                $usage = $this->redemptionStatsFor([$promotion], $buyer, 'platform', true)[$promotion->id] ?? [];
                return $this->platformVoucherPayload(
                    $promotion->load(['products:id', 'categories:id', 'stores:id,name']),
                    $claim,
                    (int) ($usage['buyer_count'] ?? 0),
                    $usage,
                );
            }

            $promotion = StorePromotion::query()->whereKey($storeId)->lockForUpdate()->firstOrFail();
            if ($promotion->type !== StorePromotion::TYPE_STORE_VOUCHER || ! $promotion->is_claimable || ! $promotion->is_public) {
                throw ValidationException::withMessages([
                    'store_promotion_id' => 'This store offer cannot be claimed.',
                ]);
            }
            $this->assertUsable($promotion, $buyer, false, true);

            $claim = PromotionClaim::query()->firstOrCreate(
                ['buyer_id' => $buyer->id, 'store_promotion_id' => $promotion->id],
                ['status' => PromotionClaim::STATUS_CLAIMED, 'claimed_at' => now()],
            );
            $claim->update([
                'status' => PromotionClaim::STATUS_CLAIMED,
                'claimed_at' => $claim->claimed_at ?? now(),
                'used_at' => null,
            ]);

            $usage = $this->redemptionStatsFor([$promotion], $buyer, 'store', true)[$promotion->id] ?? [];
            return $this->storeVoucherPayload(
                $promotion->load(['store:id,name,slug,publication_status', 'products:id']),
                $claim,
                (int) ($usage['buyer_count'] ?? 0),
                $usage,
            );
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<int, int>  $orderItemsByCartItemId
     * @param  array<int, SellerOrder>  $sellerOrdersByStoreId
     */
    public function recordRedemptions(
        Order $order,
        User $buyer,
        array $resolved,
        array $orderItemsByCartItemId,
        array $sellerOrdersByStoreId,
    ): void {
        foreach (['store', 'platform', 'platform_shipping', 'automatic', 'shipping'] as $slot) {
            $applied = $resolved['applied'][$slot] ?? null;
            if (! $applied || (int) $applied['discount_minor'] < 1 && (int) $applied['shipping_discount_minor'] < 1) {
                continue;
            }

            /** @var PlatformPromotion|StorePromotion $promotion */
            $promotion = $applied['promotion'];
            $claim = $applied['claim'] ?? null;
            $totalDiscountMinor = (int) $applied['discount_minor'] + (int) $applied['shipping_discount_minor'];

            if ($promotion instanceof PlatformPromotion) {
                $usage = $this->redemptionStatsFor([$promotion], $buyer, 'platform', true)[$promotion->id] ?? [];
                $this->assertDiscountWithinRemainingBudget(
                    $promotion,
                    $usage,
                    $totalDiscountMinor,
                    'promotion',
                );
            }

            if (! $claim && $promotion->is_claimable && $promotion instanceof PlatformPromotion) {
                $claim = PromotionClaim::query()->firstOrCreate(
                    ['buyer_id' => $buyer->id, 'platform_promotion_id' => $promotion->id],
                    ['status' => PromotionClaim::STATUS_CLAIMED, 'claimed_at' => now()],
                );
            }
            if (! $claim && $promotion->is_claimable && $promotion instanceof StorePromotion) {
                $claim = PromotionClaim::query()->firstOrCreate(
                    ['buyer_id' => $buyer->id, 'store_promotion_id' => $promotion->id],
                    ['status' => PromotionClaim::STATUS_CLAIMED, 'claimed_at' => now()],
                );
            }
            if ($claim) {
                $claim->update(['status' => PromotionClaim::STATUS_USED, 'used_at' => now()]);
            }

            $redemption = PromotionRedemption::query()->create([
                'order_id' => $order->id,
                'buyer_id' => $buyer->id,
                'platform_promotion_id' => $promotion instanceof PlatformPromotion ? $promotion->id : null,
                'store_promotion_id' => $promotion instanceof StorePromotion ? $promotion->id : null,
                'promotion_claim_id' => $claim?->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'kind' => $promotion instanceof PlatformPromotion ? $promotion->kind : $promotion->type,
                'funding_type' => $promotion instanceof PlatformPromotion
                    ? PlatformPromotion::FUNDING_PLATFORM
                    : StorePromotion::FUNDING_SELLER,
                'discount_type' => $promotion->discount_type,
                'discount_minor' => (int) $applied['discount_minor'],
                'shipping_discount_minor' => (int) $applied['shipping_discount_minor'],
                'status' => PromotionRedemption::STATUS_RESERVED,
                'reserved_at' => now(),
                'redeemed_at' => null,
            ]);

            foreach ((array) ($applied['line_allocations'] ?? []) as $lineKey => $amountMinor) {
                if ((int) $amountMinor < 1) {
                    continue;
                }
                [$storeId, $cartItemId] = array_map('intval', explode(':', (string) $lineKey, 2));
                $sellerOrder = $sellerOrdersByStoreId[$storeId] ?? null;
                $orderItemId = $orderItemsByCartItemId[$cartItemId] ?? null;
                if (! $sellerOrder || ! $orderItemId) {
                    continue;
                }
                PromotionRedemptionAllocation::query()->create([
                    'promotion_redemption_id' => $redemption->id,
                    'seller_order_id' => $sellerOrder->id,
                    'order_item_id' => $orderItemId,
                    'amount_minor' => (int) $amountMinor,
                ]);
            }

            foreach ((array) ($applied['shipping_allocations'] ?? []) as $storeId => $amountMinor) {
                if ((int) $amountMinor < 1 || ! isset($sellerOrdersByStoreId[(int) $storeId])) {
                    continue;
                }
                PromotionRedemptionAllocation::query()->create([
                    'promotion_redemption_id' => $redemption->id,
                    'seller_order_id' => $sellerOrdersByStoreId[(int) $storeId]->id,
                    'order_item_id' => null,
                    'amount_minor' => (int) $amountMinor,
                ]);
            }
        }
    }

    /**
     * Convert all reserved promotion redemptions for an Order to confirmed use
     * after the authoritative payment transition succeeds. Call inside the
     * payment transaction after locking the parent Order.
     */
    public function markOrderPaid(Order $order): void
    {
        $redemptions = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->where('status', PromotionRedemption::STATUS_RESERVED)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($redemptions as $redemption) {
            $redemption->update([
                'status' => PromotionRedemption::STATUS_REDEEMED,
                'redeemed_at' => now(),
            ]);
        }
    }

    /**
     * Release only the promotion portions allocated to a Seller Order that is
     * cancelled while its marketplace COD payment is still pending.
     */
    public function releaseForSellerOrder(Order $order, SellerOrder $sellerOrder, User $actor, string $reason): void
    {
        $redemptions = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)
            ->with('claim')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($redemptions as $redemption) {
            $allocations = PromotionRedemptionAllocation::query()
                ->where('promotion_redemption_id', $redemption->id)
                ->where('seller_order_id', $sellerOrder->id)
                ->whereColumn('released_amount_minor', '<', 'amount_minor')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($allocations->isEmpty()) {
                continue;
            }

            $releasedMerchandiseMinor = 0;
            $releasedShippingMinor = 0;
            $now = now();
            foreach ($allocations as $allocation) {
                $remainingMinor = max(0, (int) $allocation->amount_minor - (int) $allocation->released_amount_minor);
                if ($remainingMinor < 1) {
                    continue;
                }
                if ($allocation->order_item_id === null) {
                    $releasedShippingMinor += $remainingMinor;
                } else {
                    $releasedMerchandiseMinor += $remainingMinor;
                }
                $allocation->update([
                    'released_amount_minor' => (int) $allocation->amount_minor,
                    'released_at' => $now,
                ]);
            }

            $remainingAllocations = PromotionRedemptionAllocation::query()
                ->where('promotion_redemption_id', $redemption->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['order_item_id', 'amount_minor', 'released_amount_minor']);
            $remainingMerchandiseMinor = (int) $remainingAllocations
                ->whereNotNull('order_item_id')
                ->sum(fn (PromotionRedemptionAllocation $allocation): int => max(
                    0,
                    (int) $allocation->amount_minor - (int) $allocation->released_amount_minor,
                ));
            $remainingShippingMinor = (int) $remainingAllocations
                ->whereNull('order_item_id')
                ->sum(fn (PromotionRedemptionAllocation $allocation): int => max(
                    0,
                    (int) $allocation->amount_minor - (int) $allocation->released_amount_minor,
                ));
            $oldValues = [
                'status' => $redemption->status,
                'discount_minor' => (int) $redemption->discount_minor,
                'shipping_discount_minor' => (int) $redemption->shipping_discount_minor,
            ];
            $newStatus = $remainingMerchandiseMinor + $remainingShippingMinor < 1
                ? PromotionRedemption::STATUS_RELEASED
                : $redemption->status;
            $redemption->update([
                'discount_minor' => $remainingMerchandiseMinor,
                'shipping_discount_minor' => $remainingShippingMinor,
                'status' => $newStatus,
                'released_at' => $newStatus === PromotionRedemption::STATUS_RELEASED ? $now : $redemption->released_at,
            ]);

            $this->audit->record(
                action: 'promotion.redemption_allocation_released',
                module: 'marketing',
                subject: $redemption,
                description: "Promotion allocation for cancelled Seller Order {$sellerOrder->seller_order_no} was released.",
                oldValues: $oldValues,
                newValues: [
                    'status' => $newStatus,
                    'seller_order_id' => (int) $sellerOrder->id,
                    'released_discount_minor' => $releasedMerchandiseMinor,
                    'released_shipping_discount_minor' => $releasedShippingMinor,
                    'remaining_discount_minor' => $remainingMerchandiseMinor,
                    'remaining_shipping_discount_minor' => $remainingShippingMinor,
                    'reason' => trim($reason),
                ],
                severity: 'warning',
                actor: $actor,
            );

            if ($newStatus === PromotionRedemption::STATUS_RELEASED) {
                $this->restoreClaimIfUnused($redemption);
            }
        }
    }

    public function releaseForOrder(Order $order, string $status = PromotionRedemption::STATUS_RELEASED, bool $restoreClaims = true): void
    {
        $redemptions = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)
            ->with('claim')
            ->lockForUpdate()
            ->get();

        foreach ($redemptions as $redemption) {
            $now = now();
            $redemption->update([
                'status' => $status,
                'released_at' => $now,
            ]);

            PromotionRedemptionAllocation::query()
                ->where('promotion_redemption_id', $redemption->id)
                ->whereColumn('released_amount_minor', '<', 'amount_minor')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(fn (PromotionRedemptionAllocation $allocation) => $allocation->update([
                    'released_amount_minor' => (int) $allocation->amount_minor,
                    'released_at' => $now,
                ]));

            if ($restoreClaims) {
                $this->restoreClaimIfUnused($redemption);
            }
        }
    }

    private function restoreClaimIfUnused(PromotionRedemption $redemption): void
    {
        if (! $redemption->promotion_claim_id) {
            return;
        }

        $claim = PromotionClaim::query()
            ->whereKey($redemption->promotion_claim_id)
            ->lockForUpdate()
            ->first();
        if (! $claim) {
            return;
        }

        $otherActive = PromotionRedemption::query()
            ->where('promotion_claim_id', $claim->id)
            ->whereKeyNot($redemption->id)
            ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)
            ->exists();
        $claim->update([
            'status' => $otherActive ? PromotionClaim::STATUS_USED : PromotionClaim::STATUS_CLAIMED,
            'used_at' => $otherActive ? $claim->used_at : null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    public function previewPlatform(PlatformPromotion $promotion, array $groups): array
    {
        $groups = $this->initializeGroups($groups);
        $eligible = $this->eligibleLinesForPlatform($promotion, $groups);

        if ($promotion->isShippingDiscount()) {
            $shippingWeights = [];
            $shipping = 0;
            $eligibleStoreIds = collect($eligible)
                ->pluck('store_id')
                ->map(fn ($storeId): int => (int) $storeId)
                ->unique()
                ->all();
            foreach ($groups as $storeId => $group) {
                if (! $this->platformTargetsStore($promotion, (int) $storeId)
                    || ! in_array((int) $storeId, $eligibleStoreIds, true)) {
                    continue;
                }
                $fee = (int) ($group['quote']['shipping_fee_minor'] ?? 0);
                if ($fee > 0) {
                    $shippingWeights[(string) $storeId] = $fee;
                    $shipping += $fee;
                }
            }
            $eligibleSubtotal = array_sum(array_column($eligible, 'subtotal_minor'));
            $minSpend = (int) $promotion->minimum_spend_minor;
            if ($minSpend > 0 && $eligibleSubtotal < $minSpend) {
                return $this->previewFailure('Add ₱'.number_format(($minSpend - $eligibleSubtotal) / 100, 2).' more in eligible items to use this offer.');
            }
            $discount = $this->shippingDiscount($promotion, $shipping);
            return $discount > 0
                ? [
                    'eligible' => true,
                    'reason' => null,
                    'discount_minor' => 0,
                    'shipping_discount_minor' => $discount,
                    'eligible_subtotal_minor' => $eligibleSubtotal,
                    'line_weights' => [],
                    'shipping_weights' => $shippingWeights,
                ]
                : $this->previewFailure('This shipping offer is not available for the selected delivery option.');
        }

        $weights = [];
        $subtotal = 0;
        foreach ($eligible as $key => $line) {
            $weights[$key] = (int) $line['subtotal_minor'];
            $subtotal += (int) $line['subtotal_minor'];
        }

        $minSpend = (int) $promotion->minimum_spend_minor;
        if ($subtotal < 1) {
            return $this->previewFailure('This voucher is not valid for the selected items.');
        }
        if ($minSpend > 0 && $subtotal < $minSpend) {
            return $this->previewFailure('Add ₱'.number_format(($minSpend - $subtotal) / 100, 2).' more in eligible items to use this voucher.');
        }

        $discount = $this->merchandiseDiscount($promotion, $subtotal);
        return $discount > 0
            ? [
                'eligible' => true,
                'reason' => null,
                'discount_minor' => $discount,
                'shipping_discount_minor' => 0,
                'eligible_subtotal_minor' => $subtotal,
                'line_weights' => $weights,
                'shipping_weights' => [],
            ]
            : $this->previewFailure('This voucher has no remaining value for the selected items.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    public function previewStore(StorePromotion $promotion, array $groups): array
    {
        $groups = $this->initializeGroups($groups);
        $eligible = $this->eligibleLinesForStore($promotion, $groups);
        $weights = [];
        $subtotal = 0;
        foreach ($eligible as $key => $line) {
            $weights[$key] = (int) $line['subtotal_minor'];
            $subtotal += (int) $line['subtotal_minor'];
        }

        if ($subtotal < 1) {
            return $this->previewFailure('This store voucher is not valid for the selected items.');
        }

        $minSpend = (int) $promotion->minimum_spend_minor;
        if ($minSpend > 0 && $subtotal < $minSpend) {
            return $this->previewFailure('Add ₱'.number_format(($minSpend - $subtotal) / 100, 2).' more from this store to use this voucher.');
        }

        $discount = $this->storeDiscount($promotion, $subtotal);
        return $discount > 0
            ? [
                'eligible' => true,
                'reason' => null,
                'discount_minor' => $discount,
                'shipping_discount_minor' => 0,
                'eligible_subtotal_minor' => $subtotal,
                'line_weights' => $weights,
                'shipping_weights' => [],
            ]
            : $this->previewFailure('This store voucher has no remaining value for the selected items.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function initializeGroups(array $groups): array
    {
        foreach ($groups as &$group) {
            $sellerSubtotal = 0;
            $basePlatformDiscount = 0;
            $group['seller_discount_minor'] = 0;
            $group['platform_shipping_discount_minor'] = 0;
            foreach ($group['items'] as &$line) {
                $line['seller_discount_minor'] = 0;
                $line['campaign_platform_discount_minor'] = (int) ($line['campaign_platform_discount_minor'] ?? 0);
                $line['platform_discount_minor'] = (int) ($line['platform_discount_minor'] ?? 0);
                $sellerSubtotal += (int) ($line['seller_subtotal_minor'] ?? $line['subtotal_minor']);
                $basePlatformDiscount += $line['platform_discount_minor'];
            }
            unset($line);

            $group['seller_subtotal_minor'] = $sellerSubtotal;
            $group['platform_discount_minor'] = $basePlatformDiscount;
        }
        unset($group);

        return $groups;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function finalizeGroups(array $groups): array
    {
        foreach ($groups as &$group) {
            $sellerDiscount = 0;
            $platformMerchandise = 0;
            $campaignPlatformDiscount = 0;
            $sellerSubtotal = 0;
            foreach ($group['items'] as &$line) {
                $line['seller_discount_minor'] = (int) ($line['seller_discount_minor'] ?? 0);
                $line['platform_discount_minor'] = (int) ($line['platform_discount_minor'] ?? 0);
                $line['campaign_platform_discount_minor'] = (int) ($line['campaign_platform_discount_minor'] ?? 0);
                $sellerDiscount += $line['seller_discount_minor'];
                $platformMerchandise += $line['platform_discount_minor'];
                $campaignPlatformDiscount += $line['campaign_platform_discount_minor'];
                $sellerSubtotal += (int) ($line['seller_subtotal_minor'] ?? $line['subtotal_minor']);
            }
            unset($line);

            $shipping = (int) ($group['quote']['shipping_fee_minor'] ?? 0);
            $buyerSubtotal = (int) $group['subtotal_minor'];
            $group['seller_subtotal_minor'] = $sellerSubtotal;
            $group['seller_discount_minor'] = $sellerDiscount;
            $group['platform_shipping_discount_minor'] = (int) ($group['platform_shipping_discount_minor'] ?? 0);
            $group['platform_discount_minor'] = $platformMerchandise;
            $group['campaign_platform_discount_minor'] = $campaignPlatformDiscount;
            $group['platform_total_discount_minor'] = $platformMerchandise + $group['platform_shipping_discount_minor'];
            $group['seller_total_minor'] = max(0, $sellerSubtotal - $sellerDiscount + $shipping);
            $group['total_minor'] = $group['seller_total_minor'];
            $group['original_shipping_fee_minor'] = $shipping;
            $group['platform_shipping_subsidy_minor'] = min($shipping, $group['platform_shipping_discount_minor']);
            $group['buyer_paid_shipping_fee_minor'] = max(0, $shipping - $group['platform_shipping_subsidy_minor']);
            $group['shipping_funding_source'] = match (true) {
                $group['platform_shipping_subsidy_minor'] < 1 => 'buyer',
                $group['buyer_paid_shipping_fee_minor'] < 1 => 'platform',
                default => 'buyer_and_platform',
            };
            $group['shipping_funding_sources'] = array_values(array_filter([
                $group['buyer_paid_shipping_fee_minor'] > 0
                    ? ['source' => 'buyer', 'amount_minor' => $group['buyer_paid_shipping_fee_minor']]
                    : null,
                $group['platform_shipping_subsidy_minor'] > 0
                    ? ['source' => 'platform', 'amount_minor' => $group['platform_shipping_subsidy_minor']]
                    : null,
            ]));
            $group['buyer_total_minor'] = max(
                0,
                $buyerSubtotal + $shipping - $sellerDiscount - $group['platform_total_discount_minor'],
            );
        }
        unset($group);

        return $groups;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, mixed>  $applied
     * @return array<string, mixed>
     */
    private function summary(array $groups, array $applied): array
    {
        $subtotal = 0;
        $sellerSubtotal = 0;
        $shipping = 0;
        $sellerDiscount = 0;
        $platformDiscount = 0;
        $campaignPlatformDiscount = 0;
        $shippingDiscount = 0;
        $buyerPaidShipping = 0;

        foreach ($groups as $group) {
            $subtotal += (int) $group['subtotal_minor'];
            $sellerSubtotal += (int) ($group['seller_subtotal_minor'] ?? $group['subtotal_minor']);
            $shipping += (int) ($group['quote']['shipping_fee_minor'] ?? 0);
            $sellerDiscount += (int) $group['seller_discount_minor'];
            $platformDiscount += (int) $group['platform_discount_minor'];
            $campaignPlatformDiscount += (int) ($group['campaign_platform_discount_minor'] ?? 0);
            $shippingDiscount += (int) $group['platform_shipping_discount_minor'];
            $buyerPaidShipping += (int) ($group['buyer_paid_shipping_fee_minor'] ?? 0);
        }

        $total = max(0, $subtotal + $shipping - $sellerDiscount - $platformDiscount - $shippingDiscount);

        return [
            'subtotal_minor' => $subtotal,
            'seller_subtotal_minor' => $sellerSubtotal,
            'shipping_fee_minor' => $shipping,
            'buyer_paid_shipping_fee_minor' => $buyerPaidShipping,
            'platform_shipping_subsidy_minor' => $shippingDiscount,
            'shipping_funding_source' => match (true) {
                $shippingDiscount < 1 => 'buyer',
                $buyerPaidShipping < 1 => 'platform',
                default => 'buyer_and_platform',
            },
            'seller_discount_minor' => $sellerDiscount,
            'platform_discount_minor' => $platformDiscount,
            'campaign_platform_discount_minor' => $campaignPlatformDiscount,
            'shipping_discount_minor' => $shippingDiscount,
            'discount_minor' => $sellerDiscount + $platformDiscount,
            'total_discount_minor' => $sellerDiscount + $platformDiscount + $shippingDiscount,
            'total_minor' => $total,
            'applied' => array_values(array_filter([
                $applied['store'] ? [
                    'source' => 'store',
                    'name' => $applied['store']['promotion']->name,
                    'discount_minor' => (int) $applied['store']['discount_minor'],
                    'shipping_discount_minor' => 0,
                ] : null,
                $applied['platform'] ? [
                    'source' => 'platform',
                    'name' => $applied['platform']['promotion']->name,
                    'discount_minor' => (int) $applied['platform']['discount_minor'],
                    'shipping_discount_minor' => 0,
                ] : null,
                $applied['platform_shipping'] ? [
                    'source' => 'platform',
                    'name' => $applied['platform_shipping']['promotion']->name,
                    'discount_minor' => 0,
                    'shipping_discount_minor' => (int) $applied['platform_shipping']['shipping_discount_minor'],
                ] : null,
                $applied['automatic'] ? [
                    'source' => 'automatic',
                    'name' => $applied['automatic']['promotion']->name,
                    'discount_minor' => (int) $applied['automatic']['discount_minor'],
                    'shipping_discount_minor' => 0,
                ] : null,
                $applied['shipping'] ? [
                    'source' => 'shipping',
                    'name' => $applied['shipping']['promotion']->name,
                    'discount_minor' => 0,
                    'shipping_discount_minor' => (int) $applied['shipping']['shipping_discount_minor'],
                ] : null,
            ])),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function eligibleLinesForPlatform(PlatformPromotion $promotion, array $groups): array
    {
        $productIds = $promotion->products->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $categoryIds = $promotion->categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $storeIds = $promotion->stores->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $hasTargets = $productIds !== [] || $categoryIds !== [] || $storeIds !== [];
        $requiresVoucherEligibility = $promotion->kind === PlatformPromotion::KIND_VOUCHER
            && ! $promotion->isShippingDiscount();
        $lines = [];

        foreach ($groups as $storeId => $group) {
            if ($storeIds !== [] && ! in_array((int) $storeId, $storeIds, true)) {
                continue;
            }

            foreach ($group['items'] as $line) {
                /** @var Product $product */
                $product = $line['product'];
                if ($requiresVoucherEligibility && ! $product->voucher_eligible) {
                    continue;
                }

                if ($hasTargets) {
                    $matches = ($productIds !== [] && in_array((int) $product->id, $productIds, true))
                        || ($categoryIds !== [] && in_array((int) $product->category_id, $categoryIds, true))
                        || ($storeIds !== [] && in_array((int) $storeId, $storeIds, true));
                    if (! $matches) {
                        continue;
                    }
                }

                $key = $this->lineKey((int) $storeId, $line);
                $lines[$key] = [
                    'store_id' => (int) $storeId,
                    'line' => $line,
                    'subtotal_minor' => (int) $line['subtotal_minor'],
                ];
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function eligibleLinesForStore(StorePromotion $promotion, array $groups): array
    {
        $productIds = $promotion->products->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $lines = [];

        foreach ($groups as $storeId => $group) {
            if ((int) $storeId !== (int) $promotion->store_id) {
                continue;
            }
            foreach ($group['items'] as $line) {
                /** @var Product $product */
                $product = $line['product'];
                if (! $product->voucher_eligible || ! in_array((int) $product->id, $productIds, true)) {
                    continue;
                }
                $key = $this->lineKey((int) $storeId, $line);
                $lines[$key] = [
                    'store_id' => (int) $storeId,
                    'line' => $line,
                    'subtotal_minor' => (int) $line['subtotal_minor'],
                ];
            }
        }

        return $lines;
    }

    private function platformTargetsStore(PlatformPromotion $promotion, int $storeId): bool
    {
        $storeIds = $promotion->stores->pluck('id')->map(fn ($id): int => (int) $id)->all();
        return $storeIds === [] || in_array($storeId, $storeIds, true);
    }

    private function merchandiseDiscount(PlatformPromotion|StorePromotion $promotion, int $subtotal): int
    {
        if ($subtotal < 1) {
            return 0;
        }

        if ($promotion->discount_type === PlatformPromotion::DISCOUNT_FIXED) {
            $fixedMinor = $promotion instanceof PlatformPromotion
                ? (int) $promotion->value_minor
                : (int) $promotion->voucher_amount_minor;

            return min($subtotal, max(0, $fixedMinor));
        }

        $percent = (int) $promotion->discount_percent;
        $discount = intdiv($subtotal * $percent, 100);
        $max = $promotion->max_discount_minor;
        if ($max !== null) {
            $discount = min($discount, (int) $max);
        }

        return min($subtotal, max(0, $discount));
    }

    private function storeDiscount(StorePromotion $promotion, int $subtotal): int
    {
        return $this->merchandiseDiscount($promotion, $subtotal);
    }

    private function shippingDiscount(PlatformPromotion $promotion, int $shipping): int
    {
        if ($shipping < 1) {
            return 0;
        }

        if ($promotion->discount_type === PlatformPromotion::DISCOUNT_FREE_SHIPPING) {
            $maximum = $promotion->max_discount_minor;

            return $maximum === null
                ? $shipping
                : min($shipping, max(0, (int) $maximum));
        }
        if ($promotion->discount_type === PlatformPromotion::DISCOUNT_FIXED_SHIPPING) {
            return min($shipping, max(0, (int) $promotion->value_minor));
        }

        $discount = intdiv($shipping * (int) $promotion->discount_percent, 100);
        if ($promotion->max_discount_minor !== null) {
            $discount = min($discount, (int) $promotion->max_discount_minor);
        }

        return min($shipping, max(0, $discount));
    }

    /**
     * @param  array<string, int>  $weights
     * @return array<string, int>
     */
    private function allocateProportionally(int $amount, array $weights): array
    {
        $amount = max(0, $amount);
        $weights = array_filter($weights, fn ($weight): bool => (int) $weight > 0);
        if ($amount < 1 || $weights === []) {
            return [];
        }

        $totalWeight = array_sum($weights);
        $positiveKeys = array_keys($weights);
        $lastKey = end($positiveKeys);
        $allocations = [];
        $remaining = $amount;

        foreach ($positiveKeys as $key) {
            if ($key === $lastKey) {
                $share = $remaining;
            } else {
                $share = intdiv($amount * (int) $weights[$key], $totalWeight);
                $share = min($share, $remaining);
            }
            if ($share > 0) {
                $allocations[(string) $key] = $share;
                $remaining -= $share;
            }
        }

        return $allocations;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, int>  $allocations
     */
    private function applyLineAllocations(array &$groups, array $allocations, string $funding): void
    {
        foreach ($allocations as $key => $amount) {
            [$storeId, $cartItemId] = array_map('intval', explode(':', (string) $key, 2));
            if (! isset($groups[$storeId])) {
                continue;
            }
            foreach ($groups[$storeId]['items'] as &$line) {
                if ((int) $line['cart_item_id'] !== $cartItemId) {
                    continue;
                }
                if ($funding === 'seller') {
                    $line['seller_discount_minor'] = (int) $line['seller_discount_minor'] + (int) $amount;
                } else {
                    $line['platform_discount_minor'] = (int) $line['platform_discount_minor'] + (int) $amount;
                }
                break;
            }
            unset($line);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, int>  $allocations
     */
    private function applyShippingAllocations(array &$groups, array $allocations): void
    {
        foreach ($allocations as $storeId => $amount) {
            if (isset($groups[(int) $storeId])) {
                $groups[(int) $storeId]['platform_shipping_discount_minor'] =
                    (int) $groups[(int) $storeId]['platform_shipping_discount_minor'] + (int) $amount;
            }
        }
    }

    /**
     * Prevent a platform discount from pushing a line below zero after a Seller voucher.
     *
     * @param  array<string, int>  $allocations
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, int>
     */
    private function capPlatformAllocations(array $allocations, array $groups): array
    {
        $capped = [];
        foreach ($allocations as $key => $amount) {
            [$storeId, $cartItemId] = array_map('intval', explode(':', (string) $key, 2));
            $line = null;
            foreach ($groups[(int) $storeId]['items'] ?? [] as $candidate) {
                if ((int) $candidate['cart_item_id'] === $cartItemId) {
                    $line = $candidate;
                    break;
                }
            }
            if (! $line) {
                continue;
            }
            $remaining = max(0, (int) $line['subtotal_minor']
                - (int) $line['seller_discount_minor']
                - (int) $line['platform_discount_minor']);
            $share = min((int) $amount, $remaining);
            if ($share > 0) {
                $capped[(string) $key] = $share;
            }
        }

        return $capped;
    }

    private function lineKey(int $storeId, array $line): string
    {
        return $storeId.':'.(int) $line['cart_item_id'];
    }

    /**
     * @param  array{platform: Collection<int, array<string, mixed>>, store: Collection<int, array<string, mixed>>, automatic: Collection<int, array<string, mixed>>}  $options
     * @return array{platform: array<int, array<string, mixed>>, store: array<int, array<string, mixed>>, automatic: array<int, array<string, mixed>>}
     */
    private function publicOptions(array $options): array
    {
        $clean = static function (Collection $rows): array {
            return $rows->map(static function (array $row): array {
                unset($row['_promotion'], $row['_usage'], $row['_line_weights'], $row['_shipping_weights']);

                return $row;
            })->values()->all();
        };

        return [
            'platform' => $clean($options['platform']->reject(
                fn (array $row): bool => in_array($row['discount_type'] ?? null, [
                    PlatformPromotion::DISCOUNT_FREE_SHIPPING,
                    PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
                    PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
                ], true),
            )),
            'platform_shipping' => $clean($options['platform']->filter(
                fn (array $row): bool => in_array($row['discount_type'] ?? null, [
                    PlatformPromotion::DISCOUNT_FREE_SHIPPING,
                    PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
                    PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
                ], true),
            )),
            'store' => $clean($options['store']),
            'automatic' => $clean($options['automatic']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function optionPayload(array $payload, array $preview): array
    {
        return array_merge($payload, [
            'eligible' => (bool) ($preview['eligible'] ?? false),
            'reason' => $preview['reason'] ?? null,
            'discount_minor' => (int) ($preview['discount_minor'] ?? 0),
            'shipping_discount_minor' => (int) ($preview['shipping_discount_minor'] ?? 0),
            'eligible_subtotal_minor' => (int) ($preview['eligible_subtotal_minor'] ?? 0),
        ]);
    }

    /** @return array<string, mixed> */
    private function previewFailure(string $reason): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'discount_minor' => 0,
            'shipping_discount_minor' => 0,
            'eligible_subtotal_minor' => 0,
            'line_weights' => [],
            'shipping_weights' => [],
        ];
    }

    /**
     * Current reservation/redemption counts and subsidy totals for the supplied
     * promotions. Checkout uses locking reads after locking the promotion row;
     * preview-only quotes use an aggregate read and are rechecked at placement.
     *
     * @param  iterable<PlatformPromotion|StorePromotion>  $promotions
     * @return array<int, array{active_count:int,buyer_count:int,budget_spend_minor:int}>
     */
    private function redemptionStatsFor(iterable $promotions, User $buyer, string $source, bool $lock = false): array
    {
        $promotionIds = collect($promotions)
            ->map(fn (PlatformPromotion|StorePromotion $promotion): int => (int) $promotion->id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($promotionIds->isEmpty()) {
            return [];
        }

        $promotionColumn = $source === 'store' ? 'store_promotion_id' : 'platform_promotion_id';
        $query = PromotionRedemption::query()
            ->whereIn($promotionColumn, $promotionIds->all())
            ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES);

        if ($lock) {
            $rows = $query
                ->orderBy($promotionColumn)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([$promotionColumn, 'buyer_id', 'discount_minor', 'shipping_discount_minor']);

            $stats = [];
            foreach ($rows as $row) {
                $id = (int) $row->{$promotionColumn};
                $stats[$id] ??= ['active_count' => 0, 'buyer_count' => 0, 'budget_spend_minor' => 0];
                $stats[$id]['active_count']++;
                $stats[$id]['buyer_count'] += (int) $row->buyer_id === (int) $buyer->id ? 1 : 0;
                $stats[$id]['budget_spend_minor'] += (int) $row->discount_minor + (int) $row->shipping_discount_minor;
            }

            return $stats;
        }

        return $query
            ->select($promotionColumn)
            ->selectRaw('COUNT(*) as active_count')
            ->selectRaw('SUM(CASE WHEN buyer_id = ? THEN 1 ELSE 0 END) as buyer_count', [(int) $buyer->id])
            ->selectRaw('COALESCE(SUM(discount_minor + shipping_discount_minor), 0) as budget_spend_minor')
            ->groupBy($promotionColumn)
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->{$promotionColumn} => [
                'active_count' => (int) $row->active_count,
                'buyer_count' => (int) $row->buyer_count,
                'budget_spend_minor' => (int) $row->budget_spend_minor,
            ]])
            ->all();
    }

    /** @param array<string, mixed> $usage */
    private function promotionUsageReason(
        PlatformPromotion|StorePromotion $promotion,
        array $usage,
    ): ?string {
        if ($promotion->redemption_limit !== null
            && (int) ($usage['active_count'] ?? 0) >= (int) $promotion->redemption_limit) {
            return 'This offer has reached its total redemption limit.';
        }
        if ((int) ($usage['buyer_count'] ?? 0) >= max(1, (int) $promotion->per_buyer_limit)) {
            return 'You have reached this offer’s per-Buyer use limit.';
        }
        if ($promotion instanceof PlatformPromotion
            && $promotion->budget_minor !== null
            && (int) ($usage['budget_spend_minor'] ?? 0) >= (int) $promotion->budget_minor) {
            return 'This offer is no longer available.';
        }

        return null;
    }

    /** @param array<string, mixed> $usage */
    private function applyPromotionAvailability(
        PlatformPromotion|StorePromotion $promotion,
        array $preview,
        array $usage,
        bool $checkBudget = true,
    ): array {
        if (! ($preview['eligible'] ?? false)) {
            return $preview;
        }

        $reason = $this->promotionUsageReason($promotion, $usage);
        if ($reason === null && $checkBudget && $promotion instanceof PlatformPromotion && $promotion->budget_minor !== null) {
            $previewDiscount = (int) ($preview['discount_minor'] ?? 0) + (int) ($preview['shipping_discount_minor'] ?? 0);
            $remaining = max(0, (int) $promotion->budget_minor - (int) ($usage['budget_spend_minor'] ?? 0));
            if ($previewDiscount > $remaining) {
                $reason = 'This offer no longer has enough budget to cover the full discount.';
            }
        }
        if ($reason === null) {
            return $preview;
        }

        $preview['eligible'] = false;
        $preview['reason'] = $reason;
        $preview['discount_minor'] = 0;
        $preview['shipping_discount_minor'] = 0;
        $preview['line_weights'] = [];
        $preview['shipping_weights'] = [];

        return $preview;
    }

    /** @param array<string, mixed> $usage */
    private function previewWithRemainingBudget(
        PlatformPromotion $promotion,
        array $groups,
        array $usage,
    ): array {
        $preview = $this->applyPromotionAvailability(
            $promotion,
            $this->previewPlatform($promotion, $groups),
            $usage,
            checkBudget: false,
        );
        if (! ($preview['eligible'] ?? false) || $promotion->budget_minor === null) {
            return $preview;
        }

        if ($promotion->isShippingDiscount()) {
            $allocations = $this->allocateProportionally(
                (int) $preview['shipping_discount_minor'],
                $preview['shipping_weights'],
            );
            $discountMinor = array_sum($allocations);
        } else {
            $allocations = $this->capPlatformAllocations(
                $this->allocateProportionally((int) $preview['discount_minor'], $preview['line_weights']),
                $groups,
            );
            $discountMinor = array_sum($allocations);
        }

        if ($this->fitsRemainingBudget($promotion, $usage, $discountMinor)) {
            return $preview;
        }

        $preview['eligible'] = false;
        $preview['reason'] = $discountMinor > 0
            ? 'This offer no longer has enough budget to cover the full discount.'
            : 'This offer does not provide a discount for the selected items or delivery.';
        $preview['discount_minor'] = 0;
        $preview['shipping_discount_minor'] = 0;
        $preview['line_weights'] = [];
        $preview['shipping_weights'] = [];

        return $preview;
    }

    /** @param array<string, mixed> $usage */
    private function assertDiscountWithinRemainingBudget(
        PlatformPromotion $promotion,
        array $usage,
        int $discountMinor,
        string $field,
    ): void {
        if ($discountMinor < 1) {
            throw ValidationException::withMessages([
                $field => 'This offer does not provide a discount for the selected items or delivery.',
            ]);
        }
        if ($promotion->budget_minor === null) {
            return;
        }

        $remainingMinor = max(0, (int) $promotion->budget_minor - (int) ($usage['budget_spend_minor'] ?? 0));
        if ($discountMinor > $remainingMinor) {
            throw ValidationException::withMessages([
                $field => 'This offer no longer has enough budget to cover the full discount. Choose another offer or remove this one.',
            ]);
        }
    }

    private function activePlatformQuery(bool $lock = false): Builder
    {
        $query = PlatformPromotion::query()
            ->where('status', PlatformPromotion::STATUS_ACTIVE)
            ->where('is_public', true)
            ->withinScheduleAt(now('Asia/Manila'));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    private function automaticPlatformQuery(bool $lock = false): Builder
    {
        $query = $this->activePlatformQuery($lock)
            ->where(function (Builder $query): void {
                $query->where('kind', PlatformPromotion::KIND_AUTOMATIC_DISCOUNT)
                    ->orWhere(function (Builder $shipping): void {
                        $shipping->where('kind', PlatformPromotion::KIND_FREE_SHIPPING)
                            ->where('is_claimable', false)
                            ->whereNull('code');
                    });
            })
            ->whereNull('campaign_type');

        return $query;
    }

    /**
     * Lock only automatic offers that could apply to at least one current cart
     * line or delivery. Ineligible campaigns should not serialize unrelated
     * checkouts merely because they are active and public.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<int>
     */
    private function automaticPromotionIdsForGroups(array $groups): array
    {
        return $this->automaticPlatformQuery()
            ->with(['products:id', 'categories:id', 'stores:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (PlatformPromotion $promotion): bool => (bool) $this->previewPlatform($promotion, $groups)['eligible'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function activeStoreQuery(bool $lock = false): Builder
    {
        $query = StorePromotion::query()
            ->where('type', StorePromotion::TYPE_STORE_VOUCHER)
            ->where('status', StorePromotion::STATUS_ACTIVE)
            ->where('is_public', true)
            ->withinScheduleAt(now('Asia/Manila'))
            ->whereHas('store', fn (Builder $store) => $store->where('publication_status', 'published'));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    /** @return array{0: PlatformPromotion|null, 1: StorePromotion|null} */
    private function findByCode(string $code, bool $lock): array
    {
        $platformQuery = PlatformPromotion::query()->where('code', $code);
        $storeQuery = StorePromotion::query()->where('code', $code);
        if ($lock) {
            $platformQuery->lockForUpdate();
            $storeQuery->lockForUpdate();
        }

        return [$platformQuery->first(), $storeQuery->first()];
    }

    /** @return array{0: PlatformPromotion, 1: PromotionClaim|null} */
    private function findPlatformVoucherForBuyer(
        User $buyer,
        int $id,
        bool $lock,
        bool $viaCode,
        string $field = 'platform_voucher_id',
    ): array
    {
        $query = PlatformPromotion::query()
            ->with(['products:id', 'categories:id', 'stores:id,name'])
            ->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $promotion = $query->firstOrFail();

        $selectablePlatformVoucher = $promotion->kind === PlatformPromotion::KIND_VOUCHER
            || ($promotion->kind === PlatformPromotion::KIND_FREE_SHIPPING
                && ((bool) $promotion->is_claimable || ($viaCode && filled($promotion->code))));
        if (! $selectablePlatformVoucher) {
            throw ValidationException::withMessages([
                $field => 'This Bearly offer applies automatically and cannot be selected as a voucher.',
            ]);
        }

        $claimQuery = PromotionClaim::query()
            ->where('buyer_id', $buyer->id)
            ->where('platform_promotion_id', $promotion->id);
        if ($lock) {
            $claimQuery->lockForUpdate();
        }
        $claim = $claimQuery->first();

        if (! $viaCode && filled($promotion->code) && ! $promotion->is_claimable) {
            throw ValidationException::withMessages([
                $field => 'Enter this voucher code at checkout instead of selecting it by ID.',
            ]);
        }

        if (! $viaCode && $promotion->is_claimable && ! $claim) {
            throw ValidationException::withMessages([
                $field => 'Claim this voucher in My Vouchers before using it at checkout.',
            ]);
        }

        return [$promotion, $claim];
    }

    private function canBeClaimedFromWallet(PlatformPromotion $promotion): bool
    {
        return $promotion->kind === PlatformPromotion::KIND_VOUCHER
            || ($promotion->kind === PlatformPromotion::KIND_FREE_SHIPPING && (bool) $promotion->is_claimable);
    }

    /** @return array{0: StorePromotion, 1: PromotionClaim|null} */
    private function findStoreVoucherForBuyer(User $buyer, int $id, bool $lock, bool $viaCode): array
    {
        $query = StorePromotion::query()
            ->with(['store:id,name,slug,publication_status', 'products:id'])
            ->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $promotion = $query->firstOrFail();

        if ($promotion->type !== StorePromotion::TYPE_STORE_VOUCHER) {
            throw ValidationException::withMessages([
                'store_voucher_id' => 'This Store offer is not a voucher and cannot be applied at checkout.',
            ]);
        }

        if (! $viaCode && ! $promotion->is_claimable) {
            throw ValidationException::withMessages([
                'store_voucher_id' => 'Enter this Store voucher code at checkout instead of selecting it by ID.',
            ]);
        }

        $claimQuery = PromotionClaim::query()
            ->where('buyer_id', $buyer->id)
            ->where('store_promotion_id', $promotion->id);
        if ($lock) {
            $claimQuery->lockForUpdate();
        }
        $claim = $claimQuery->first();

        if (! $viaCode && $promotion->is_claimable && ! $claim) {
            throw ValidationException::withMessages([
                'store_voucher_id' => 'Claim this store voucher in My Vouchers before using it at checkout.',
            ]);
        }

        return [$promotion, $claim];
    }

    private function assertUsable(
        PlatformPromotion|StorePromotion $promotion,
        User $buyer,
        bool $platform,
        bool $lock,
        bool $viaCode = false,
        ?string $field = null,
    ): array
    {
        $field ??= $platform ? 'platform_voucher_id' : 'store_voucher_id';
        if (! $promotion->isActiveAt()) {
            throw ValidationException::withMessages([
                $field => 'This voucher is not active right now.',
            ]);
        }

        if (! $promotion->is_public && ! $viaCode) {
            throw ValidationException::withMessages([
                $field => 'This voucher is no longer available.',
            ]);
        }

        if ($promotion instanceof StorePromotion
            && (! $promotion->store || $promotion->store->publication_status !== 'published')) {
            throw ValidationException::withMessages([
                $field => 'This Store is not available for checkout.',
            ]);
        }

        $usage = $this->redemptionStatsFor(
            [$promotion],
            $buyer,
            $platform ? 'platform' : 'store',
            $lock,
        )[(int) $promotion->id] ?? [
            'active_count' => 0,
            'buyer_count' => 0,
            'budget_spend_minor' => 0,
        ];

        if ($promotion->redemption_limit !== null
            && (int) $usage['active_count'] >= (int) $promotion->redemption_limit) {
            throw ValidationException::withMessages([
                $field => 'This voucher has reached its total redemption limit.',
            ]);
        }

        if ((int) $usage['buyer_count'] >= max(1, (int) $promotion->per_buyer_limit)) {
            throw ValidationException::withMessages([
                $field => 'You have reached this voucher’s per-Buyer use limit.',
            ]);
        }

        if ($promotion instanceof PlatformPromotion && $promotion->budget_minor !== null) {
            if ((int) $usage['budget_spend_minor'] >= (int) $promotion->budget_minor) {
                throw ValidationException::withMessages([
                    $field => 'This offer is no longer available.',
                ]);
            }
        }

        return $usage;
    }

    /** @return array<int, int> */
    private function buyerUsageCounts(User $buyer, string $source): array
    {
        $column = $source === 'platform' ? 'platform_promotion_id' : 'store_promotion_id';
        return PromotionRedemption::query()
            ->where('buyer_id', $buyer->id)
            ->whereNotNull($column)
            ->whereIn('status', self::ACTIVE_REDEMPTION_STATUSES)
            ->selectRaw("{$column}, COUNT(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $automatic
     * @param  array<string, array<string, mixed>>  $groups
     * @return array{promotion: PlatformPromotion, preview: array<string, mixed>, allocations: array<string, int>, discount_minor: int, usage: array<string, int>}|null
     */
    private function bestAutomaticMerchandiseOption(Collection $automatic, array $groups): ?array
    {
        $rows = $automatic
            ->filter(fn (array $row): bool => (bool) ($row['eligible'] ?? false)
                && (int) ($row['discount_minor'] ?? 0) > 0
                && empty($row['shipping_discount_minor']))
            ->sortByDesc(fn (array $row): int => (int) $row['discount_minor'])
            ->values();

        foreach ($rows as $row) {
            $promotion = $row['_promotion'];
            $usage = $row['_usage'] ?? [];
            $preview = $this->previewPlatform($promotion, $groups);
            $preview = $this->applyPromotionAvailability($promotion, $preview, $usage, checkBudget: false);
            if (! $preview['eligible']) {
                continue;
            }

            $allocations = $this->capPlatformAllocations(
                $this->allocateProportionally($preview['discount_minor'], $preview['line_weights']),
                $groups,
            );
            $discountMinor = array_sum($allocations);
            if (! $this->fitsRemainingBudget($promotion, $usage, $discountMinor)) {
                continue;
            }

            return [
                'promotion' => $promotion,
                'preview' => $preview,
                'allocations' => $allocations,
                'discount_minor' => $discountMinor,
                'usage' => $usage,
            ];
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $automatic
     * @param  array<string, array<string, mixed>>  $groups
     * @return array{promotion: PlatformPromotion, preview: array<string, mixed>, allocations: array<int, int>, shipping_discount_minor: int, usage: array<string, int>}|null
     */
    private function bestAutomaticShippingOption(Collection $automatic, array $groups): ?array
    {
        $rows = $automatic
            ->filter(fn (array $row): bool => (bool) ($row['eligible'] ?? false)
                && (int) ($row['shipping_discount_minor'] ?? 0) > 0)
            ->sortByDesc(fn (array $row): int => (int) $row['shipping_discount_minor'])
            ->values();

        foreach ($rows as $row) {
            $promotion = $row['_promotion'];
            $usage = $row['_usage'] ?? [];
            $preview = $this->previewPlatform($promotion, $groups);
            $preview = $this->applyPromotionAvailability($promotion, $preview, $usage, checkBudget: false);
            if (! $preview['eligible']) {
                continue;
            }

            $allocations = $this->allocateProportionally(
                $preview['shipping_discount_minor'],
                $preview['shipping_weights'],
            );
            $shippingDiscountMinor = array_sum($allocations);
            if (! $this->fitsRemainingBudget($promotion, $usage, $shippingDiscountMinor)) {
                continue;
            }

            return [
                'promotion' => $promotion,
                'preview' => $preview,
                'allocations' => $allocations,
                'shipping_discount_minor' => $shippingDiscountMinor,
                'usage' => $usage,
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $usage */
    private function fitsRemainingBudget(PlatformPromotion $promotion, array $usage, int $discountMinor): bool
    {
        return $discountMinor > 0
            && ($promotion->budget_minor === null
                || $discountMinor <= max(0, (int) $promotion->budget_minor - (int) ($usage['budget_spend_minor'] ?? 0)));
    }

    /**
     * @return array<string, mixed>
     */
    private function platformVoucherPayload(
        PlatformPromotion $promotion,
        ?PromotionClaim $claim,
        int $usageCount,
        array $usageStats = [],
    ): array {
        $status = $promotion->statusKey();
        $activeCount = (int) ($usageStats['active_count'] ?? $promotion->active_redemptions_count ?? 0);
        $budgetSpendMinor = (int) ($usageStats['budget_spend_minor']
            ?? ((int) ($promotion->active_discount_minor ?? 0) + (int) ($promotion->active_shipping_discount_minor ?? 0)));
        if ($status === PlatformPromotion::STATUS_ACTIVE
            && $promotion->isExhausted($activeCount, $budgetSpendMinor)) {
            $status = PlatformPromotion::STATUS_EXHAUSTED;
        }
        $availabilityReason = $this->promotionUsageReason($promotion, array_merge($usageStats, [
            'active_count' => $activeCount,
            'buyer_count' => (int) ($usageStats['buyer_count'] ?? $usageCount),
            'budget_spend_minor' => $budgetSpendMinor,
        ]));
        $availabilityReason ??= match ($status) {
            PlatformPromotion::STATUS_DRAFT => 'This promotion has not been published.',
            PlatformPromotion::STATUS_SCHEDULED => 'This promotion is scheduled for a future date.',
            PlatformPromotion::STATUS_PAUSED => 'This promotion is temporarily paused.',
            PlatformPromotion::STATUS_EXPIRED => 'This promotion has expired.',
            PlatformPromotion::STATUS_EXHAUSTED => 'This offer is no longer available.',
            default => null,
        };
        $available = $status === PlatformPromotion::STATUS_ACTIVE && $availabilityReason === null;
        $autoApplies = $available
            && $promotion->kind === PlatformPromotion::KIND_FREE_SHIPPING
            && ! $promotion->is_claimable
            && blank($promotion->code);
        $section = $promotion->isShippingDiscount()
            ? 'shipping'
            : ($promotion->stores->isNotEmpty()
                ? 'shop'
                : ($promotion->categories->isNotEmpty() ? 'category' : 'discount'));

        $valueLabel = match ($promotion->discount_type) {
            PlatformPromotion::DISCOUNT_FIXED => '₱'.number_format(((int) $promotion->value_minor) / 100, 2),
            PlatformPromotion::DISCOUNT_PERCENT => ((int) $promotion->discount_percent).'%',
            PlatformPromotion::DISCOUNT_FREE_SHIPPING => 'FREE',
            PlatformPromotion::DISCOUNT_FIXED_SHIPPING => '₱'.number_format(((int) $promotion->value_minor) / 100, 2),
            PlatformPromotion::DISCOUNT_PERCENT_SHIPPING => ((int) $promotion->discount_percent).'%',
            default => 'OFF',
        };
        $valueCaption = $promotion->isShippingDiscount() ? 'OFF SHIPPING' : 'OFF';
        $scope = $promotion->stores->isNotEmpty()
            ? 'Selected Stores'
            : ($promotion->categories->isNotEmpty() ? 'Selected Categories' : ($promotion->products->isNotEmpty() ? 'Selected Products' : 'All Products'));

        return [
            'source' => 'platform',
            'id' => (int) $promotion->id,
            'name' => $promotion->name,
            'code' => $promotion->code,
            'title' => $promotion->discountLabel(),
            'value_label' => $valueLabel,
            'value_caption' => $valueCaption,
            'description' => $promotion->description ?: ($autoApplies
                ? 'This public Free Shipping offer applies automatically to eligible checkouts.'
                : $promotion->discountLabel().' on eligible Bearly items.'),
            'terms' => $promotion->terms ?: ($autoApplies
                ? 'This public Free Shipping offer applies automatically when your checkout meets its eligibility rules.'
                : 'Platform vouchers are funded by Bearly. One merchandise voucher, one shipping voucher, and one Store voucher may be applied per order.'),
            'minimum' => ((int) $promotion->minimum_spend_minor) > 0
                ? 'Min. Spend ₱'.number_format(((int) $promotion->minimum_spend_minor) / 100, 2)
                : 'No minimum spend',
            'min_spend_minor' => (int) $promotion->minimum_spend_minor,
            'scope' => $scope,
            'type' => $section,
            'section' => $section,
            'expires' => $promotion->endsAt()->format('M j, Y g:i A').' PHT',
            'expires_on' => $promotion->ends_on->toDateString(),
            'expires_at' => $promotion->endsAt()->toIso8601String(),
            'starts_at' => $promotion->startsAt()?->toIso8601String(),
            'starts_at_display' => $promotion->startsAt()?->format('M j, Y g:i A').' PHT',
            'claimed' => $claim !== null,
            'claim_status' => $claim?->status,
            'can_claim' => $available && $promotion->is_claimable && $claim === null,
            'can_use' => $available,
            'availability_status' => $status,
            'availability_reason' => $availabilityReason,
            'auto_applies' => $autoApplies,
            'code_applies' => $available && filled($promotion->code),
            'per_buyer_limit' => (int) $promotion->per_buyer_limit,
            'redemption_limit' => $promotion->redemption_limit,
            'kind' => $promotion->kind,
            'discount_type' => $promotion->discount_type,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'is_new' => $promotion->created_at?->gte(now()->subDays(14)) ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeVoucherPayload(
        StorePromotion $promotion,
        ?PromotionClaim $claim,
        int $usageCount,
        array $usageStats = [],
    ): array {
        $availabilityReason = $this->promotionUsageReason($promotion, array_merge($usageStats, [
            'active_count' => (int) ($usageStats['active_count'] ?? $promotion->active_redemptions_count ?? 0),
            'buyer_count' => (int) ($usageStats['buyer_count'] ?? $usageCount),
        ]));
        $available = $promotion->isActiveAt()
            && $availabilityReason === null
            && $promotion->is_public
            && $promotion->store?->publication_status === 'published';

        $valueLabel = $promotion->isPercentDiscount()
            ? ((int) $promotion->discount_percent).'%'
            : '₱'.number_format(((int) $promotion->voucher_amount_minor) / 100, 2);

        return [
            'source' => 'store',
            'id' => (int) $promotion->id,
            'name' => $promotion->name,
            'code' => $promotion->code,
            'title' => $promotion->discountLabel().' at '.($promotion->store?->name ?: 'this Store'),
            'value_label' => $valueLabel,
            'value_caption' => 'OFF',
            'description' => 'Save on eligible items from '.($promotion->store?->name ?: 'this Store').'.',
            'terms' => $promotion->terms ?: 'Store vouchers are funded by the Seller. One store voucher may be applied per order.',
            'minimum' => ((int) $promotion->minimum_spend_minor) > 0
                ? 'Min. Spend ₱'.number_format(((int) $promotion->minimum_spend_minor) / 100, 2)
                : 'No minimum spend',
            'min_spend_minor' => (int) $promotion->minimum_spend_minor,
            'scope' => $promotion->store?->name ?: 'Store Voucher',
            'type' => 'shop',
            'section' => 'shop',
            'expires' => $promotion->endsAt()->format('M j, Y g:i A').' PHT',
            'expires_on' => $promotion->ends_on->toDateString(),
            'expires_at' => $promotion->endsAt()->toIso8601String(),
            'starts_at' => $promotion->startsAt()?->toIso8601String(),
            'starts_at_display' => $promotion->startsAt()?->format('M j, Y g:i A').' PHT',
            'claimed' => $claim !== null,
            'claim_status' => $claim?->status,
            'can_claim' => $available && $promotion->is_claimable && $claim === null,
            'can_use' => $available,
            'availability_status' => $available ? 'active' : 'unavailable',
            'availability_reason' => $availabilityReason,
            'per_buyer_limit' => (int) $promotion->per_buyer_limit,
            'redemption_limit' => $promotion->redemption_limit,
            'kind' => StorePromotion::TYPE_STORE_VOUCHER,
            'discount_type' => $promotion->discount_type,
            'funding_type' => StorePromotion::FUNDING_SELLER,
            'product_count' => (int) ($promotion->products_count ?? $promotion->products->count()),
            'is_new' => $promotion->created_at?->gte(now()->subDays(14)) ?? false,
        ];
    }
}
