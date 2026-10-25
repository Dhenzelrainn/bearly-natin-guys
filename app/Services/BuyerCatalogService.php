<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BuyerCatalogService
{
    private ?bool $marketplaceTaxonomyAvailable = null;

    public function __construct(private readonly ProductPricingService $pricing) {}

    private const CATEGORY_ALIASES = [
        'men-s-apparel' => ['fashion-and-apparel', 'Fashion and Apparel'],
        'women-s-apparel' => ['fashion-and-apparel', 'Fashion and Apparel'],
        'electronics-and-gadgets' => ['electronics-and-gadgets', 'Electronics and Gadgets'],
        'jewelry-and-watches' => ['jewelry-and-watches', 'Jewelry and Watches'],
        'home-and-garden' => ['home-and-furniture', 'Home and Furniture'],
        'health-and-beauty' => ['beauty-and-personal-care', 'Beauty and Personal Care'],
        'food-and-gourmet' => ['food-and-gourmet', 'Food and Gourmet'],
        'sports-and-outdoors' => ['sports-and-outdoors', 'Sports and Outdoors'],
        'books-and-media' => ['books-and-stationery', 'Books and Stationery'],
        'automotive' => ['automotive-and-parts', 'Automotive and Parts'],
        'kids-and-baby' => ['kids-and-baby', 'Kids and Baby'],
        'pet-supplies' => ['pet-supplies', 'Pet Supplies'],
        'furniture-and-office-equipment' => ['furniture-and-office-equipment', 'Furniture and Office Equipment'],
    ];

    public function publishedProducts(?string $categorySlug = null, ?Store $store = null, ?int $limit = null): Collection
    {
        $query = $this->publishedProductsQuery($categorySlug, $store);

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return $query->get();
    }

    public function publishedProductsQuery(?string $categorySlug = null, ?Store $store = null): Builder
    {
        $query = Product::query()
            ->with($this->catalogRelations())
            ->visibleToBuyers()
            ->acceptsCashOnDelivery();

        if ($store) {
            $query->where('store_id', $store->id);
        }

        if ($categorySlug !== null) {
            $this->filterByBuyerCategory($query, $categorySlug);
        }

        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function publishedProductsPage(
        ?string $categorySlug = null,
        ?Store $store = null,
        int $perPage = 60,
    ): LengthAwarePaginator {
        return $this->publishedProductsQuery($categorySlug, $store)
            ->paginate(max(1, $perPage))
            ->withQueryString();
    }

    public function filterByBuyerCategory(Builder $query, string $categorySlug): Builder
    {
        if ($this->hasMarketplaceTaxonomyTables()) {
            $marketplaceCategory = $this->marketplaceCategoryForSlug($categorySlug);

            if ($marketplaceCategory) {
                $this->constrainMarketplaceCategory($query, $marketplaceCategory);
            } else {
                $query->whereRaw('1 = 0');
            }

            return $query;
        }

        $category = $this->legacyCategoryFallbackIsUnambiguous($categorySlug)
            ? $this->categoryForSlug($categorySlug)
            : null;

        return $category
            ? $query->where('category_id', $category->id)
            : $query->whereRaw('1 = 0');
    }

    /** @return array<string, mixed> */
    private function catalogRelations(): array
    {
        $relations = [
            'category',
            'subcategory',
            'store.sellerProfile',
            'images' => fn ($images) => $images->with('variant')->orderBy('position'),
            'variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('position'),
        ];
        if ($this->hasMarketplaceTaxonomyTables()) {
            $relations['category.marketplaceCategories'] = fn ($categories) => $categories
                ->where('marketplace_categories.is_active', true);
            $relations[] = 'marketplaceSubcategory.marketplaceCategory';
        }

        return $relations;
    }

    public function categoryForSlug(string $slug): ?Category
    {
        $slug = Str::lower(trim($slug));
        $aliases = self::CATEGORY_ALIASES[$slug] ?? [$slug, Str::headline(str_replace('-', ' ', $slug))];

        return Category::query()
            ->where('is_active', true)
            ->where(function ($categories) use ($aliases) {
                $categories
                    ->whereIn('slug', array_filter([$aliases[0] ?? null]))
                    ->orWhereIn('name', array_filter([$aliases[1] ?? null]));
            })
            ->first();
    }

    public function marketplaceCategoryForSlug(string $slug): ?MarketplaceCategory
    {
        return MarketplaceCategory::query()
            ->where('slug', Str::lower(trim($slug)))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Canonical subcategories shown in a Buyer category page are active and
     * have at least one product that is currently buyer-visible.
     *
     * @return Collection<int, \App\Models\MarketplaceSubcategory>
     */
    public function marketplaceSubcategoriesForBuyer(MarketplaceCategory $category): Collection
    {
        return $category->subcategories()
            ->where('is_active', true)
            ->whereHas('products', fn ($products) => $products
                ->visibleToBuyers()
                ->acceptsCashOnDelivery())
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * Products assigned to a canonical marketplace subcategory, plus legacy
     * unassigned products only where their approved business category maps to
     * exactly this one Buyer category.
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function publishedProductsForMarketplaceCategory(
        MarketplaceCategory $category,
        ?string $subcategorySlug = null,
        int $perPage = 60,
    ): LengthAwarePaginator
    {
        $query = Product::query()
            ->with($this->catalogRelations())
            ->visibleToBuyers()
            ->acceptsCashOnDelivery();

        $this->constrainMarketplaceCategory($query, $category);

        if (filled($subcategorySlug)) {
            $subcategory = $category->subcategories()
                ->where('is_active', true)
                ->where('slug', trim($subcategorySlug))
                ->first();

            if ($subcategory) {
                $query->where('marketplace_subcategory_id', $subcategory->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(max(1, $perPage))
            ->withQueryString();
    }

    /**
     * Related products remain within the source product's Buyer category
     * branch; ambiguous business-category aliases do not broaden the results.
     *
     * @return Collection<int, Product>
     */
    public function relatedProducts(Product $product, int $limit = 4): Collection
    {
        $query = Product::query()
            ->with($this->catalogRelations())
            ->visibleToBuyers()
            ->acceptsCashOnDelivery()
            ->where('products.id', '!=', $product->getKey());

        if ($this->hasMarketplaceTaxonomyTables()) {
            $product->loadMissing([
                'marketplaceSubcategory.marketplaceCategory',
                'category.marketplaceCategories' => fn ($categories) => $categories
                    ->where('marketplace_categories.is_active', true),
            ]);
            $category = $this->marketplaceCategoryFor($product, $product->marketplaceSubcategory);

            if ($category) {
                $this->constrainMarketplaceCategory($query, $category);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($product->category_id && $this->legacyCategoryFallbackIsUnambiguous($this->buyerCategorySlug($product->category))) {
            $query->where('category_id', $product->category_id);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /** @return Collection<int, StoreSubcategory> */
    public function storeSubcategoriesForBuyer(Store $store): Collection
    {
        return StoreSubcategory::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->whereHas('products', fn ($products) => $products
                ->where('store_id', $store->id)
                ->visibleToBuyers()
                ->acceptsCashOnDelivery())
            ->orderBy('name')
            ->get();
    }

    private function constrainMarketplaceCategory(Builder $query, MarketplaceCategory $category): void
    {
        $unambiguousCategoryIds = DB::table('marketplace_category_business_categories as mapping')
            ->join('marketplace_categories as mapped_marketplace_categories', 'mapped_marketplace_categories.id', '=', 'mapping.marketplace_category_id')
            ->join('categories as mapped_business_categories', 'mapped_business_categories.id', '=', 'mapping.category_id')
            ->where('mapped_marketplace_categories.is_active', true)
            ->where('mapped_business_categories.is_active', true)
            ->select('mapping.category_id')
            ->groupBy('mapping.category_id')
            ->havingRaw('COUNT(DISTINCT mapping.marketplace_category_id) = 1');

        $legacyCategoryIds = DB::table('marketplace_category_business_categories as category_mapping')
            ->join('marketplace_categories as target_marketplace_category', 'target_marketplace_category.id', '=', 'category_mapping.marketplace_category_id')
            ->select('category_mapping.category_id')
            ->where('target_marketplace_category.is_active', true)
            ->where('category_mapping.marketplace_category_id', $category->id)
            ->whereIn('category_mapping.category_id', $unambiguousCategoryIds);

        $query->where(function (Builder $products) use ($category, $legacyCategoryIds): void {
            $products->whereHas('marketplaceSubcategory', fn (Builder $subcategories) => $subcategories
                ->where('marketplace_category_id', $category->id)
                ->where('is_active', true)
                ->whereHas('marketplaceCategory', fn (Builder $parents) => $parents->where('is_active', true)))
                ->whereHas('category', fn (Builder $businessCategories) => $businessCategories->where('is_active', true));

            $products->orWhere(function (Builder $legacy) use ($legacyCategoryIds): void {
                $legacy->whereNull('marketplace_subcategory_id')
                    ->whereHas('category', fn (Builder $categories) => $categories->where('is_active', true))
                    ->whereIn('category_id', $legacyCategoryIds);
            });
        });
    }

    private function legacyCategoryFallbackIsUnambiguous(string $slug): bool
    {
        $aliases = self::CATEGORY_ALIASES[$slug] ?? [$slug, Str::headline(str_replace('-', ' ', $slug))];
        $legacySlug = (string) ($aliases[0] ?? '');
        $buyerBranches = collect(self::CATEGORY_ALIASES)
            ->filter(fn (array $candidate): bool => (string) ($candidate[0] ?? '') === $legacySlug)
            ->keys();

        return $buyerBranches->count() <= 1;
    }

    /**
     * Home rankings derived only from current Buyer saves and completed seller
     * orders. No catalog-only product can appear in Top Sales without sales.
     *
     * @return array{popular: array<int, array<string, mixed>>, top_sales: array<int, array<string, mixed>>}
     */
    public function homepageRankings(User $buyer, int $limit = 6): array
    {
        $limit = min(12, max(1, $limit));

        return [
            'popular' => $this->rankedHomeCards($this->rankingMetrics('popular'), $buyer, $limit, 'buyer_likes_count'),
            'top_sales' => $this->rankedHomeCards($this->rankingMetrics('top_sales'), $buyer, $limit, 'completed_units_sold'),
        ];
    }

    /**
     * Active canonical Buyer categories and their ranked, Buyer-orderable
     * product counts. Empty categories remain available for honest empty states.
     *
     * @return Collection<int, array{id: int, slug: string, name: string, product_count: int}>
     */
    public function rankingCategories(string $rankingType): Collection
    {
        if (! $this->hasMarketplaceTaxonomyTables()) {
            return collect();
        }

        return MarketplaceCategory::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->map(function (MarketplaceCategory $category) use ($rankingType): array {
                $query = $this->rankedProductsQuery($this->rankingMetrics($rankingType));
                $this->constrainMarketplaceCategory($query, $category);
                $count = (int) $query->count('products.id');

                return [
                    'id' => (int) $category->id,
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'product_count' => $count,
                ];
            })
            ->values();
    }

    /**
     * Database-paginated products for a Buyer ranking page. The ranking metric,
     * category filter, availability, and ordering remain in SQL; only the
     * selected page is hydrated through the existing catalog-card contract.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function rankedProductsPage(
        User $buyer,
        string $rankingType,
        ?string $categorySlug = null,
        int $perPage = 24,
    ): LengthAwarePaginator {
        $query = $this->rankedProductsQuery($this->rankingMetrics($rankingType), withRelations: true);

        if (filled($categorySlug)) {
            $this->filterByBuyerCategory($query, (string) $categorySlug);
        }

        $page = $query
            ->orderByDesc('ranking_metrics.ranking_metric')
            ->orderBy('products.id')
            ->paginate(min(48, max(1, $perPage)))
            ->withQueryString();

        $products = $page->getCollection();
        $metricByProductId = $products->mapWithKeys(fn (Product $product): array => [
            (int) $product->id => (int) $product->ranking_metric,
        ]);
        $metricKey = $this->rankingMetricKey($rankingType);
        $firstRank = (int) ($page->firstItem() ?? 1);
        $cards = collect($this->cards($products, $buyer))
            ->values()
            ->map(function (array $card, int $index) use ($metricByProductId, $metricKey, $firstRank): array {
                $metric = (int) ($metricByProductId->get((int) $card['id']) ?? 0);
                unset($card['rating'], $card['sold']);
                $card[$metricKey] = $metric;
                $card['ranking_metric'] = $metric;
                $card['ranking_position'] = $firstRank + $index;

                return $card;
            });

        $page->setCollection($cards);

        return $page;
    }

    private function rankingMetrics(string $rankingType): QueryBuilder
    {
        return match ($rankingType) {
            'popular' => DB::table('wishlists')
                ->join('users', 'users.id', '=', 'wishlists.user_id')
                ->where('users.role', 'buyer')
                ->where('users.status', 'active')
                ->select('wishlists.product_id')
                ->selectRaw('COUNT(DISTINCT wishlists.user_id) AS ranking_metric')
                ->groupBy('wishlists.product_id')
                ->havingRaw('COUNT(DISTINCT wishlists.user_id) > 0'),
            'top_sales' => DB::table('order_items')
                ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
                ->where('seller_orders.status', SellerOrderStatus::Completed->value)
                ->whereNotNull('seller_orders.completed_at')
                ->whereNotNull('order_items.product_id')
                ->select('order_items.product_id')
                ->selectRaw('SUM(order_items.quantity) AS ranking_metric')
                ->groupBy('order_items.product_id')
                ->havingRaw('SUM(order_items.quantity) > 0'),
            default => throw new \InvalidArgumentException('Unsupported Buyer product ranking type.'),
        };
    }

    private function rankingMetricKey(string $rankingType): string
    {
        return match ($rankingType) {
            'popular' => 'buyer_likes_count',
            'top_sales' => 'completed_units_sold',
            default => throw new \InvalidArgumentException('Unsupported Buyer product ranking type.'),
        };
    }

    private function rankedProductsQuery(QueryBuilder $metrics, bool $withRelations = false): Builder
    {
        $query = Product::query()
            ->joinSub($metrics, 'ranking_metrics', fn ($join) => $join
                ->on('ranking_metrics.product_id', '=', 'products.id'))
            ->select(['products.*', 'ranking_metrics.ranking_metric'])
            ->visibleToBuyers()
            ->acceptsCashOnDelivery()
            ->whereHas('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved'));

        if ($withRelations) {
            $relations = $this->catalogRelations();
            $relations['variants'] = fn ($variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved')
                ->orderBy('position')
                ->orderBy('id');
            $query->with($relations);
        }

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rankedHomeCards(QueryBuilder $metrics, User $buyer, int $limit, string $metricKey): array
    {
        $products = $this->rankedProductsQuery($metrics, withRelations: true)
            ->orderByDesc('ranking_metrics.ranking_metric')
            ->orderBy('products.id')
            ->limit($limit)
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $metricByProductId = $products->mapWithKeys(fn (Product $product): array => [
            (int) $product->id => (int) $product->ranking_metric,
        ]);

        return array_map(function (array $card) use ($metricByProductId, $metricKey): array {
            $card[$metricKey] = (int) ($metricByProductId->get((int) $card['id']) ?? 0);
            unset($card['rating'], $card['sold']);

            return $card;
        }, $this->cards($products, $buyer));
    }

    public function cards(
        Collection $products,
        ?User $buyer = null,
        bool $includeStoreSubcategory = false,
        bool $includeCampaignItemId = false,
    ): array
    {
        $products = $products
            ->filter(fn ($product): bool => $product instanceof Product && $product->acceptsCashOnDelivery())
            ->values();
        $productModels = $products->filter(fn ($product): bool => $product instanceof Product)->values();
        if ($this->hasMarketplaceTaxonomyTables() && $products instanceof EloquentCollection) {
            $products->loadMissing([
                'marketplaceSubcategory.marketplaceCategory',
                'category.marketplaceCategories' => fn ($categories) => $categories
                    ->where('marketplace_categories.is_active', true),
            ]);
        } elseif ($productModels->isNotEmpty()) {
            $relations = [];
            if ($this->hasMarketplaceTaxonomyTables()) {
                $relations = [
                    'marketplaceSubcategory.marketplaceCategory',
                    'category.marketplaceCategories' => fn ($categories) => $categories
                        ->where('marketplace_categories.is_active', true),
                ];
            }
            $loadedMarketplaceRelations = Product::query()
                ->with($relations)
                ->whereKey($productModels->pluck('id')->all())
                ->get()
                ->keyBy('id');
            $productModels->each(function (Product $product) use ($loadedMarketplaceRelations): void {
                $loaded = $loadedMarketplaceRelations->get($product->id);
                if ($loaded) {
                    foreach (['marketplaceSubcategory', 'category'] as $relation) {
                        if ($loaded->relationLoaded($relation)) {
                            $product->setRelation($relation, $loaded->getRelation($relation));
                        }
                    }
                }
            });
        }
        $wishlistIds = $buyer
            ? Wishlist::query()->where('user_id', $buyer->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $this->pricing->warm($products);

        return $products
            ->map(fn (Product $product): array => $this->card(
                $product,
                in_array((int) $product->id, $wishlistIds, true),
                $includeStoreSubcategory,
                $includeCampaignItemId,
            ))
            ->values()
            ->all();
    }

    public function card(
        Product $product,
        bool $isWishlisted = false,
        bool $includeStoreSubcategory = false,
        bool $includeCampaignItemId = false,
    ): array
    {
        if ($this->hasMarketplaceTaxonomyTables()) {
            $product->loadMissing([
                'marketplaceSubcategory.marketplaceCategory',
                'category.marketplaceCategories' => fn ($categories) => $categories
                    ->where('marketplace_categories.is_active', true),
            ]);
        }
        $marketplaceSubcategory = $this->hasMarketplaceTaxonomyTables()
            ? $product->marketplaceSubcategory
            : null;
        $marketplaceCategory = $this->marketplaceCategoryFor($product, $marketplaceSubcategory);
        $variants = $product->relationLoaded('variants')
            ? $product->variants->where('is_active', true)->values()
            : $product->variants()->where('is_active', true)->orderBy('position')->get();
        $variantImages = $this->variantImagesByVariant($product, $variants);
        $firstVariant = $variants->first();
        $primary = $product->relationLoaded('images')
            ? ($product->images->whereNull('variant_id')->firstWhere('is_primary', true) ?: $product->images->whereNull('variant_id')->first())
            : $product->images()->whereNull('variant_id')->first();
        $defaultVariantImage = $firstVariant
            ? ($variantImages[(int) $firstVariant->id] ?? null)
            : null;
        $this->pricing->warm([$product]);

        $variantCards = $variants
            ->map(fn (ProductVariant $variant): array => $this->variant($product, $variant, $variantImages[(int) $variant->id] ?? null))
            ->values();
        $firstPrice = $firstVariant
            ? $this->pricing->priceForVariant($product, $firstVariant)
            : null;

        $card = [
            'id' => (int) $product->id,
            'product_id' => (int) $product->id,
            'name' => $product->name,
            'price' => ((int) ($firstPrice['unit_price_minor'] ?? 0)) / 100,
            'price_minor' => (int) ($firstPrice['unit_price_minor'] ?? 0),
            'regular_price' => ((int) ($firstPrice['regular_unit_price_minor'] ?? 0)) / 100,
            'regular_price_minor' => (int) ($firstPrice['regular_unit_price_minor'] ?? 0),
            'discount_percent' => (int) ($firstPrice['discount_percent'] ?? 0),
            'campaign_name' => $firstPrice['campaign_name'] ?? null,
            'product_discount_name' => $firstPrice['product_discount_name'] ?? null,
            'product_discount_starts_at' => $firstPrice['product_discount_starts_at'] ?? null,
            'product_discount_ends_at' => $firstPrice['product_discount_ends_at'] ?? null,
            'campaign_type' => $firstPrice['campaign_type'] ?? null,
            'campaign_funding' => $firstPrice['campaign_funding'] ?? null,
            'campaign_starts_at' => $firstPrice['campaign_starts_at'] ?? null,
            'campaign_ends_at' => $firstPrice['campaign_ends_at'] ?? null,
            'price_source' => $firstPrice['price_source'] ?? 'regular',
            'subcategory' => $product->category?->name ?? 'Marketplace',
            'category' => $product->category?->name ?? 'Marketplace',
            'category_slug' => $marketplaceCategory?->slug
                ?? (! $this->hasMarketplaceTaxonomyTables() ? $this->buyerCategorySlug($product->category) : null),
            'marketplace_category_slug' => $marketplaceCategory?->slug,
            'marketplace_category_name' => $marketplaceCategory?->name,
            'marketplace_subcategory_id' => $marketplaceSubcategory?->id
                ? (int) $marketplaceSubcategory->id
                : null,
            'marketplace_subcategory_slug' => $marketplaceSubcategory?->slug,
            'marketplace_subcategory_name' => $marketplaceSubcategory?->name,
            'photo' => 0,
            'image' => $primary
                ? $this->imageUrl($primary->path, $primary->disk ?: 'public')
                : ($defaultVariantImage ?: ''),
            'gallery' => $product->relationLoaded('images')
                ? $product->images->whereNull('variant_id')->map(fn ($image) => $this->imageUrl($image->path, $image->disk ?: 'public'))->values()->all()
                : [],
            'color' => $this->firstOption($variantCards, ['color', 'colour']),
            'colors' => $this->optionValues($variantCards, ['color', 'colour']),
            'sizes' => $this->optionValues($variantCards, ['size']),
            'condition' => 'New',
            'location' => 'Philippines',
            'seller_location' => 'Philippines',
            'seller_name' => $product->store?->name ?? 'Bearly Seller',
            'store_slug' => $product->store?->slug,
            'free_shipping' => false,
            'voucher' => (bool) $product->voucher_eligible,
            'payment_methods' => ['cod'],
            'rating' => 0,
            'sold' => 0,
            'description' => $product->description ?? '',
            'stock' => (int) $variants->sum(fn (ProductVariant $variant) => $variant->available_stock),
            'variants' => $variantCards->all(),
            'default_variant_id' => $firstVariant?->id,
            'live' => true,
            'is_wishlisted' => $isWishlisted,
            'featured_key' => 'live:'.$product->id,
        ];

        if ($includeStoreSubcategory) {
            $card['seller_subcategory'] = $product->subcategory?->name;
        }

        if ($includeCampaignItemId) {
            $campaignItemId = $firstPrice['campaign_participation_item_id'] ?? null;
            $card['campaign_participation_item_id'] = $campaignItemId !== null
                ? (int) $campaignItemId
                : null;
        }

        return $card;
    }

    public function buyerCategorySlug(?Category $category): string
    {
        $name = Str::lower((string) $category?->name);

        return match ($name) {
            'fashion and apparel' => 'men-s-apparel',
            'home and furniture' => 'home-and-garden',
            'beauty and personal care' => 'health-and-beauty',
            'books and stationery' => 'books-and-media',
            default => Str::slug((string) $category?->name),
        };
    }

    private function marketplaceCategoryFor(Product $product, mixed $marketplaceSubcategory): ?MarketplaceCategory
    {
        if (! $this->hasMarketplaceTaxonomyTables()) {
            return null;
        }

        if ($marketplaceSubcategory) {
            $parent = $marketplaceSubcategory->marketplaceCategory;

            if ($marketplaceSubcategory->is_active && $parent?->is_active && $product->category?->is_active) {
                return $parent;
            }

            return null;
        }

        $mappedCategories = $product->category?->marketplaceCategories;
        if (! $product->category?->is_active || ! $mappedCategories || $mappedCategories->count() !== 1) {
            return null;
        }

        return $mappedCategories->first();
    }

    private function hasMarketplaceTaxonomyTables(): bool
    {
        return $this->marketplaceTaxonomyAvailable ??= Schema::hasTable('marketplace_categories')
            && Schema::hasTable('marketplace_subcategories')
            && Schema::hasTable('marketplace_category_business_categories')
            && Schema::hasColumn('products', 'marketplace_subcategory_id');
    }

    private function variant(Product $product, ProductVariant $variant, ?string $image = null): array
    {
        $options = (array) ($variant->options ?: []);
        $size = $this->option($options, ['size']);
        $color = $this->option($options, ['color', 'colour']);
        $price = $this->pricing->priceForVariant($product, $variant);

        return [
            'id' => (int) $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'price' => ((int) $price['unit_price_minor']) / 100,
            'price_minor' => (int) $price['unit_price_minor'],
            'regular_price' => ((int) $price['regular_unit_price_minor']) / 100,
            'regular_price_minor' => (int) $price['regular_unit_price_minor'],
            'discount_percent' => (int) $price['discount_percent'],
            'campaign_name' => $price['campaign_name'],
            'product_discount_name' => $price['product_discount_name'],
            'product_discount_starts_at' => $price['product_discount_starts_at'],
            'product_discount_ends_at' => $price['product_discount_ends_at'],
            'campaign_type' => $price['campaign_type'],
            'campaign_funding' => $price['campaign_funding'],
            'campaign_starts_at' => $price['campaign_starts_at'],
            'campaign_ends_at' => $price['campaign_ends_at'],
            'price_source' => $price['price_source'],
            'stock' => (int) $variant->available_stock,
            'size' => $size,
            'color' => $color,
            'options' => $options,
            'image' => $image ?: '',
        ];
    }

    public function variantImageUrl(Product $product, ProductVariant $variant): ?string
    {
        $image = $this->variantImageRecord($product, $variant);

        return $image ? $this->imageUrl($image->path, $image->disk ?: 'public') : null;
    }

    public function orderImageRecord(Product $product, ProductVariant $variant): ?ProductImage
    {
        $images = $this->productImages($product);
        $images->loadMissing('variant');
        $variantImage = $this->variantColorImage($images, $variant)
            ?: $this->exactSkuImage($images, $variant)
            ?: $this->legacyColorImage($images, $variant);

        return $variantImage
            ?: $images->first(fn (ProductImage $image): bool => $image->variant_id === null && $image->is_primary)
            ?: $images->first(fn (ProductImage $image): bool => $image->variant_id === null);
    }

    /**
     * Resolve the display image for an immutable order-item snapshot,
     * preferring the snapshot path recorded at checkout before falling back
     * to the live product/variant image resolution.
     */
    public function orderItemImageUrl(OrderItem $item): string
    {
        $imagePath = trim((string) ($item->product_image_path ?? ''));
        $imageDisk = (string) ($item->product_image_disk ?: 'public');

        if ($imagePath === '' && $item->product && $item->variant) {
            $image = $this->orderImageRecord($item->product, $item->variant);
            $imagePath = trim((string) ($image?->path ?? ''));
            $imageDisk = (string) ($image?->disk ?: 'public');
        }

        return $this->imageUrl($imagePath === '' ? null : $imagePath, $imageDisk);
    }

    private function variantImageRecord(Product $product, ProductVariant $variant): ?ProductImage
    {
        return $this->variantImageRecordFromImages($this->productImages($product), $variant);
    }

    private function variantImageRecordFromImages(Collection $images, ProductVariant $variant): ?ProductImage
    {
        $images->loadMissing('variant');

        $colorImage = $this->variantColorImage($images, $variant);
        if ($colorImage) {
            return $colorImage;
        }

        $exactImage = $this->exactSkuImage($images, $variant);
        if ($exactImage) {
            return $exactImage;
        }

        $sameVariantImage = $images->first(fn (ProductImage $image): bool => $image->variant_id !== null
            && (int) $image->variant_id === (int) $variant->id);

        if ($sameVariantImage) {
            return $sameVariantImage;
        }

        return $this->legacyColorImage($images, $variant);
    }

    private function exactSkuImage(Collection $images, ProductVariant $variant): ?ProductImage
    {
        return $images->first(fn (ProductImage $image): bool => $image->variant_id !== null
            && (int) $image->variant_id === (int) $variant->id
            && (string) $image->purpose === 'variant_sku');
    }

    private function legacyColorImage(Collection $images, ProductVariant $variant): ?ProductImage
    {
        $color = Str::lower(trim($this->option((array) ($variant->options ?: []), ['color', 'colour'])));
        if ($color === '') {
            return null;
        }

        return $images->first(function (ProductImage $image) use ($color): bool {
            if ($image->variant_id === null || ! $image->variant || (string) $image->purpose !== 'variant') {
                return false;
            }

            $imageColor = Str::lower(trim($this->option(
                (array) ($image->variant->options ?: []),
                ['color', 'colour'],
            )));

            return $imageColor !== '' && $imageColor === $color;
        });
    }

    private function variantColorImage(Collection $images, ProductVariant $variant): ?ProductImage
    {
        $color = Str::lower(trim($this->option((array) ($variant->options ?: []), ['color', 'colour'])));
        if ($color === '') {
            return null;
        }

        return $images->first(function (ProductImage $image) use ($color): bool {
            if ($image->variant_id === null || ! $image->variant || (string) $image->purpose !== 'variant_color') {
                return false;
            }

            $imageColor = Str::lower(trim($this->option(
                (array) ($image->variant->options ?: []),
                ['color', 'colour'],
            )));

            return $imageColor !== '' && $imageColor === $color;
        });
    }

    private function productImages(Product $product): Collection
    {
        return $product->relationLoaded('images')
            ? $product->images
            : $product->images()->with('variant')->orderBy('position')->get();
    }

    private function variantImagesByVariant(Product $product, Collection $variants): array
    {
        $variantsById = $variants->keyBy('id');
        $imageRecords = $product->relationLoaded('images')
            ? $product->images
            : $product->images()->whereNotNull('variant_id')->with('variant')->orderBy('position')->get();
        $imageRecords->loadMissing('variant');
        $exactImages = [];
        $sharedColorImages = [];
        $legacyColorImages = [];

        foreach ($imageRecords as $image) {
            $variant = $image->relationLoaded('variant')
                ? $image->variant
                : $variantsById->get($image->variant_id);
            if (! $variant) {
                continue;
            }

            $variantId = (int) $variant->id;
            $imageUrl = $this->imageUrl($image->path, $image->disk ?: 'public');
            $isActiveVariant = $variantsById->has($variant->id);

            if ($isActiveVariant && (string) $image->purpose === 'variant_sku') {
                $exactImages[$variantId] = $imageUrl;
            }

            if (in_array((string) $image->purpose, ['variant_color', 'variant'], true)) {
                $color = Str::lower($this->option((array) ($variant->options ?: []), ['color', 'colour']));
                if ($color !== '') {
                    if ((string) $image->purpose === 'variant_color') {
                        $sharedColorImages[$color] ??= $imageUrl;
                    } else {
                        $legacyColorImages[$color] ??= $imageUrl;
                    }
                }
            }
        }

        $variantImages = [];
        foreach ($variants as $variant) {
            $variantId = (int) $variant->id;
            $color = Str::lower($this->option((array) ($variant->options ?: []), ['color', 'colour']));
            $variantImages[$variantId] = ($color !== '' ? ($sharedColorImages[$color] ?? null) : null)
                ?? ($exactImages[$variantId] ?? null)
                ?? ($color !== '' ? ($legacyColorImages[$color] ?? null) : null);
        }

        return $variantImages;
    }

    private function option(array $options, array $names): string
    {
        foreach ($options as $key => $value) {
            if (in_array(Str::lower(trim((string) $key)), $names, true)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function optionValues(Collection $variants, array $names): array
    {
        return $variants
            ->map(fn (array $variant) => $this->option((array) ($variant['options'] ?? []), $names))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function firstOption(Collection $variants, array $names): string
    {
        return (string) ($this->optionValues($variants, $names)[0] ?? '');
    }

    private function imageUrl(?string $path, string $disk = 'public'): string
    {
        if (! $path) {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/'])
            ? $path
            : Storage::disk($disk)->url($path);
    }
}
