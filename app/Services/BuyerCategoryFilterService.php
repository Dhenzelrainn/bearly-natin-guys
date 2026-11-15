<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromotionRedemption;
use App\Models\StorePromotion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BuyerCategoryFilterService
{
    public const CATEGORY_SLUGS = [
        'men-s-apparel',
        'women-s-apparel',
        'electronics-and-gadgets',
        'books-and-media',
        'pet-supplies',
        'sports-and-outdoors',
        'jewelry-and-watches',
        'kids-and-baby',
        'home-and-garden',
        'health-and-beauty',
        'food-and-gourmet',
        'furniture-and-office-equipment',
    ];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly BuyerCatalogService $catalog,
        private readonly ProductReviewService $reviews,
    ) {}

    /**
     * Return a filtered Buyer-visible catalog page and its facets. A null
     * category slug intentionally means the full Buyer-visible catalog.
     * Variant constraints are evaluated against a single SKU at a time, so a
     * product only matches when one real variant satisfies every selected
     * size, color, and effective-price constraint.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function results(
        ?string $categorySlug,
        ?string $subcategorySlug,
        array $filters,
        User $buyer,
        int $page = 1,
    ): array {
        $query = $this->catalog->publishedProductsQuery($categorySlug);

        if (filled($subcategorySlug)) {
            $marketplaceCategory = $this->catalog->marketplaceCategoryForSlug($categorySlug);
            $subcategory = $marketplaceCategory
                ? $marketplaceCategory->subcategories()
                    ->where('is_active', true)
                    ->where('slug', trim($subcategorySlug))
                    ->first()
                : null;

            if ($subcategory) {
                $query->where('marketplace_subcategory_id', $subcategory->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $products = $query->get();
        $products->loadMissing('store.sellerProfile.pickupAddress');
        $cards = collect($this->catalog->cards($products, $buyer));

        if ($cards->isEmpty()) {
            return $this->emptyResult($page);
        }

        $productIds = $cards->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ratings = $this->reviews->productSummaries($productIds);
        $voucherProductIds = $this->activePublicVoucherProductIds($products);
        $productsById = $products->keyBy(fn (Product $product): int => (int) $product->id);

        $cards = $cards->map(function (array $card) use ($productsById, $ratings, $voucherProductIds): array {
            $productId = (int) $card['id'];
            $product = $productsById->get($productId);
            $address = $product?->store?->sellerProfile?->pickupAddress;
            $city = trim((string) ($address?->city_municipality ?? ''));
            $province = trim((string) ($address?->province ?? ''));
            $location = implode(', ', array_values(array_filter([$city, $province])));

            $card['condition'] = 'New';
            $card['location'] = $location;
            $card['seller_location'] = $location;
            $card['rating'] = (float) ($ratings[$productId]['average'] ?? 0);
            $card['review_count'] = (int) ($ratings[$productId]['count'] ?? 0);
            $card['voucher'] = (bool) ($product?->voucher_eligible)
                && in_array($productId, $voucherProductIds, true);

            return $card;
        });

        $facets = $this->facets($cards);
        $savedCount = $cards->where('is_wishlisted', true)->count();
        $normalizedFilters = $this->normalizeFilters($filters, $facets);
        $filtered = $cards
            ->map(fn (array $card): ?array => $this->filterCard($card, $normalizedFilters))
            ->filter()
            ->values();

        if ($normalizedFilters['sort'] === 'price-low') {
            $filtered = $filtered->sortBy(fn (array $card): int => (int) $card['_sort_price_minor'])->values();
        } elseif ($normalizedFilters['sort'] === 'price-high') {
            $filtered = $filtered->sortByDesc(fn (array $card): int => (int) $card['_sort_price_minor'])->values();
        } elseif ($normalizedFilters['sort'] === 'newest') {
            $filtered = $filtered->sortByDesc('id')->values();
        }

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $pageCards = $filtered
            ->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)
            ->map(function (array $card): array {
                unset($card['_sort_price_minor']);

                return $card;
            })
            ->values()
            ->all();

        return [
            'products' => $pageCards,
            'facets' => $facets,
            'saved_count' => $savedCount,
            'pagination' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => self::PER_PAGE,
                'has_more' => $page < $lastPage,
            ],
        ];
    }

    /**
     * Return global size/color options and a slider ceiling without building
     * priced catalog cards for every product on the default paginated page.
     *
     * @return array{sizes: array<int, string>, colors: array<int, string>, conditions: array<int, string>, locations: array<int, string>, price_limit: int}
     */
    public function availableFacets(?string $categorySlug = null): array
    {
        $visibleProductIds = $this->catalog->publishedProductsQuery($categorySlug)
            ->reorder()
            ->select('products.id');

        $sizes = [];
        $colors = [];
        $maxPriceMinor = 0;

        ProductVariant::query()
            ->where('is_active', true)
            ->whereIn('product_id', $visibleProductIds)
            ->get(['options', 'price_minor'])
            ->each(function (ProductVariant $variant) use (&$sizes, &$colors, &$maxPriceMinor): void {
                $options = (array) ($variant->options ?? []);
                foreach ($options as $key => $value) {
                    $key = Str::lower(trim((string) $key));
                    $value = trim((string) $value);

                    if ($value === '') {
                        continue;
                    }

                    if ($key === 'size') {
                        $sizes[] = $value;
                    } elseif (in_array($key, ['color', 'colour'], true)) {
                        $colors[] = $value;
                    }
                }

                $maxPriceMinor = max($maxPriceMinor, (int) $variant->price_minor);
            });

        return [
            'sizes' => $this->uniqueValues($sizes),
            'colors' => $this->uniqueValues($colors),
            'conditions' => [],
            'locations' => [],
            'price_limit' => max(5000, (int) (ceil(($maxPriceMinor / 100) / 1000) * 1000)),
        ];
    }

    /** @return array<string, mixed> */
    private function facets(Collection $cards): array
    {
        $sizes = [];
        $colors = [];
        $locations = [];
        $maxPriceMinor = 0;

        foreach ($cards as $card) {
            $locations[] = (string) ($card['location'] ?? '');
            foreach ((array) ($card['variants'] ?? []) as $variant) {
                $size = trim((string) ($variant['size'] ?? ''));
                $color = trim((string) ($variant['color'] ?? ''));
                if ($size !== '') {
                    $sizes[] = $size;
                }
                if ($color !== '') {
                    $colors[] = $color;
                }
                $maxPriceMinor = max($maxPriceMinor, (int) ($variant['price_minor'] ?? 0));
            }
        }

        $priceLimit = max(5000, (int) (ceil(($maxPriceMinor / 100) / 1000) * 1000));

        return [
            'sizes' => $this->uniqueValues($sizes),
            'colors' => $this->uniqueValues($colors),
            'conditions' => $cards->isNotEmpty() ? ['New'] : [],
            'locations' => $this->uniqueValues($locations),
            'price_limit' => $priceLimit,
        ];
    }

    /** @param array<string, mixed> $filters
     * @param  array<string, mixed>  $facets
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters, array $facets): array
    {
        return [
            'search' => mb_strtolower(trim((string) ($filters['q'] ?? ''))),
            'size' => $this->validSelections($filters['size'] ?? [], $facets['sizes']),
            'color' => $this->validSelections($filters['color'] ?? [], $facets['colors']),
            'condition' => $this->validSelections($filters['condition'] ?? [], $facets['conditions']),
            'location' => $this->validSelections($filters['location'] ?? [], $facets['locations']),
            'min_minor' => max(0, (int) ($filters['min'] ?? 0)) * 100,
            'max_minor' => array_key_exists('max', $filters) && $filters['max'] !== null
                ? max(0, (int) $filters['max']) * 100
                : null,
            'voucher' => ($filters['voucher'] ?? null) === '1' || ($filters['voucher'] ?? null) === 1,
            'saved' => ($filters['saved'] ?? null) === '1' || ($filters['saved'] ?? null) === 1,
            'rating' => (int) ($filters['rating'] ?? 0) === 4 ? 4 : 0,
            'sort' => in_array($filters['sort'] ?? 'featured', ['featured', 'newest', 'price-low', 'price-high'], true)
                ? (string) ($filters['sort'] ?? 'featured')
                : 'featured',
        ];
    }

    /** @param array<int, mixed> $values
     * @param  array<int, string>  $available
     * @return array<int, string>
     */
    private function validSelections(mixed $values, array $available): array
    {
        if (! is_array($values)) {
            return [];
        }

        $availableByKey = collect($available)->mapWithKeys(fn (string $value): array => [
            Str::lower($value) => $value,
        ]);

        return collect($values)
            ->filter(fn ($value): bool => is_string($value))
            ->map(fn (string $value): ?string => $availableByKey->get(Str::lower(trim($value))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, string> $values
     * @return array<int, string>
     */
    private function uniqueValues(array $values): array
    {
        return collect($values)
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $card
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function filterCard(array $card, array $filters): ?array
    {
        if ($filters['search'] !== ''
            && ! str_contains(mb_strtolower((string) $card['name']), $filters['search'])) {
            return null;
        }

        if ($filters['condition'] !== []
            && ! in_array(Str::lower((string) $card['condition']), array_map(Str::lower(...), $filters['condition']), true)) {
            return null;
        }

        if ($filters['location'] !== []
            && ! in_array(Str::lower((string) $card['location']), array_map(Str::lower(...), $filters['location']), true)) {
            return null;
        }

        if ($filters['voucher'] && ! $card['voucher']) {
            return null;
        }

        if ($filters['saved'] && ! $card['is_wishlisted']) {
            return null;
        }

        if ((float) $card['rating'] < $filters['rating']) {
            return null;
        }

        $hasVariantFilters = $filters['size'] !== []
            || $filters['color'] !== []
            || $filters['min_minor'] > 0
            || $filters['max_minor'] !== null;
        $variants = collect((array) ($card['variants'] ?? []))
            ->filter(function (array $variant) use ($filters): bool {
                $size = Str::lower(trim((string) ($variant['size'] ?? '')));
                $color = Str::lower(trim((string) ($variant['color'] ?? '')));
                $priceMinor = (int) ($variant['price_minor'] ?? 0);

                return ($filters['size'] === [] || in_array($size, array_map(Str::lower(...), $filters['size']), true))
                    && ($filters['color'] === [] || in_array($color, array_map(Str::lower(...), $filters['color']), true))
                    && $priceMinor >= $filters['min_minor']
                    && ($filters['max_minor'] === null || $priceMinor <= $filters['max_minor']);
            })
            ->sortBy(fn (array $variant): int => (int) ($variant['price_minor'] ?? 0))
            ->values();

        if ($variants->isEmpty()) {
            return null;
        }

        $minimumPriceMinor = (int) collect((array) ($card['variants'] ?? []))
            ->min(fn (array $variant): int => (int) ($variant['price_minor'] ?? 0));
        $card['_sort_price_minor'] = (int) $variants->first()['price_minor'];

        if ($hasVariantFilters || in_array($filters['sort'], ['price-low', 'price-high'], true)) {
            $matchingVariant = $variants->first();
            foreach ([
                'price', 'price_minor', 'regular_price', 'regular_price_minor',
                'discount_percent', 'campaign_name', 'product_discount_name',
                'product_discount_starts_at', 'product_discount_ends_at',
                'campaign_type', 'campaign_funding', 'campaign_starts_at',
                'campaign_ends_at', 'price_source',
            ] as $key) {
                if (array_key_exists($key, $matchingVariant)) {
                    $card[$key] = $matchingVariant[$key];
                }
            }
            $card['default_variant_id'] = $matchingVariant['id'];
            $card['color'] = (string) ($matchingVariant['color'] ?? '');
            if (! empty($matchingVariant['image'])) {
                $card['image'] = $matchingVariant['image'];
            }
        } else {
            $card['_sort_price_minor'] = $minimumPriceMinor;
        }

        return $card;
    }

    /** @param Collection<int, Product> $products
     * @return array<int, int>
     */
    private function activePublicVoucherProductIds(Collection $products): array
    {
        $productIds = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($productIds === []) {
            return [];
        }

        return StorePromotion::query()
            ->join('store_promotion_products', 'store_promotion_products.store_promotion_id', '=', 'store_promotions.id')
            ->join('products', 'products.id', '=', 'store_promotion_products.product_id')
            ->whereIn('store_promotions.store_id', $products->pluck('store_id')->unique()->all())
            ->whereIn('products.id', $productIds)
            ->where('store_promotions.type', StorePromotion::TYPE_STORE_VOUCHER)
            ->where('store_promotions.status', StorePromotion::STATUS_ACTIVE)
            ->where('store_promotions.is_public', true)
            ->where('store_promotions.is_claimable', true)
            ->where('products.voucher_eligible', true)
            ->whereColumn('products.store_id', 'store_promotions.store_id')
            ->where(function ($availability): void {
                $availability->whereNull('store_promotions.redemption_limit')
                    ->orWhereRaw(
                        '(SELECT COUNT(*) FROM promotion_redemptions '
                        .'WHERE promotion_redemptions.store_promotion_id = store_promotions.id '
                        .'AND promotion_redemptions.status IN (?, ?)) < store_promotions.redemption_limit',
                        [PromotionRedemption::STATUS_RESERVED, PromotionRedemption::STATUS_REDEEMED],
                    );
            })
            ->withinScheduleAt(now('Asia/Manila'))
            ->select('products.id')
            ->distinct()
            ->pluck('products.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array<string, mixed> */
    private function emptyResult(int $page): array
    {
        return [
            'products' => [],
            'facets' => [
                'sizes' => [],
                'colors' => [],
                'conditions' => [],
                'locations' => [],
                'price_limit' => 5000,
            ],
            'saved_count' => 0,
            'pagination' => [
                'total' => 0,
                'current_page' => max(1, $page),
                'last_page' => 1,
                'per_page' => self::PER_PAGE,
                'has_more' => false,
            ],
        ];
    }
}
