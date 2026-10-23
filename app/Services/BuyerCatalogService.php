<?php

namespace App\Services;

use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BuyerCatalogService
{
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

    public function publishedProducts(?string $categorySlug = null, ?Store $store = null): Collection
    {
        $query = Product::query()
            ->with([
                'category',
                'subcategory',
                'store.sellerProfile',
                'images' => fn ($images) => $images->with('variant')->orderBy('position'),
                'variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('position'),
            ])
            ->visibleToBuyers();

        if ($store) {
            $query->where('store_id', $store->id);
        }

        if ($categorySlug !== null) {
            $category = $this->categoryForSlug($categorySlug);

            if (! $category) {
                return collect();
            }

            $query->where('category_id', $category->id);
        }

        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
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

    public function cards(Collection $products, ?User $buyer = null, bool $includeStoreSubcategory = false): array
    {
        $wishlistIds = $buyer
            ? Wishlist::query()->where('user_id', $buyer->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $this->pricing->warm($products);

        return $products
            ->map(fn (Product $product): array => $this->card(
                $product,
                in_array((int) $product->id, $wishlistIds, true),
                $includeStoreSubcategory,
            ))
            ->values()
            ->all();
    }

    public function card(Product $product, bool $isWishlisted = false, bool $includeStoreSubcategory = false): array
    {
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
            'price_source' => $firstPrice['price_source'] ?? 'regular',
            'subcategory' => $product->category?->name ?? 'Marketplace',
            'category' => $product->category?->name ?? 'Marketplace',
            'category_slug' => $this->buyerCategorySlug($product->category),
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
            'payment_methods' => array_values(array_intersect(
                (array) ($product->payment_methods ?? ['cod', 'online']),
                ['cod', 'online'],
            )) ?: ['cod', 'online'],
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

    public function publicImageUrl(?ProductImage $image): ?string
    {
        if (! $image) {
            return null;
        }

        $diskName = (string) ($image->disk ?: 'public');
        $diskConfig = config("filesystems.disks.{$diskName}");
        if (! is_array($diskConfig)) {
            return null;
        }

        $isPublicDisk = $diskName === 'public'
            || ($diskConfig['visibility'] ?? null) === 'public'
            || filled($diskConfig['url'] ?? null);
        if (! $isPublicDisk) {
            return null;
        }

        $path = trim((string) $image->path);
        if ($path === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $path) === 1) {
            return preg_replace('~^http://~i', 'https://', $path) ?: $path;
        }

        if (str_starts_with($path, '//')) {
            return 'https:'.$path;
        }

        $diskUrl = (string) ($diskConfig['url'] ?? '');
        $basePath = trim((string) (parse_url($diskUrl, PHP_URL_PATH) ?: ''), '/');
        $path = ltrim($path, '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath.'/'))) {
            $path = ltrim(substr($path, strlen($basePath)), '/');
        }

        $url = Storage::disk($diskName)->url($path);
        if (! Str::startsWith($url, ['http://', 'https://', '//'])) {
            $url = url('/'.ltrim($url, '/'));
        }

        if (Str::startsWith($url, '//')) {
            return 'https:'.$url;
        }

        return preg_replace('~^http://~i', 'https://', $url) ?: $url;
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
            if (in_array(Str::lower((string) $key), $names, true)) {
                return (string) $value;
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
