<?php

namespace App\Services;

use App\Models\Category;
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
        $variantCards = $variants
            ->map(fn (ProductVariant $variant): array => $this->variant($variant, $variantImages[(int) $variant->id] ?? null))
            ->values();

        $card = [
            'id' => (int) $product->id,
            'product_id' => (int) $product->id,
            'name' => $product->name,
            'price' => ((int) ($firstVariant?->price_minor ?? 0)) / 100,
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

    private function variant(ProductVariant $variant, ?string $image = null): array
    {
        $options = (array) ($variant->options ?: []);
        $size = $this->option($options, ['size']);
        $color = $this->option($options, ['color', 'colour']);

        return [
            'id' => (int) $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'price' => ((int) $variant->price_minor) / 100,
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
        $variantImage = $this->exactSkuImage($images, $variant)
            ?: $this->legacyColorImage($images, $variant);

        return $variantImage
            ?: $images->first(fn (ProductImage $image): bool => $image->variant_id === null && $image->is_primary)
            ?: $images->first(fn (ProductImage $image): bool => $image->variant_id === null);
    }

    private function variantImageRecord(Product $product, ProductVariant $variant): ?ProductImage
    {
        return $this->variantImageRecordFromImages($this->productImages($product), $variant);
    }

    private function variantImageRecordFromImages(Collection $images, ProductVariant $variant): ?ProductImage
    {
        $images->loadMissing('variant');

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
        $exactImages = [];
        $legacyColorImages = [];

        foreach ($imageRecords as $image) {
            $variant = $image->relationLoaded('variant')
                ? $image->variant
                : $variantsById->get($image->variant_id);
            if (! $variant || ! $variantsById->has($variant->id)) {
                continue;
            }

            $variantId = (int) $variant->id;
            $imageUrl = $this->imageUrl($image->path, $image->disk ?: 'public');
            $exactImages[$variantId] ??= $imageUrl;

            if ((string) $image->purpose === 'variant_sku') {
                $exactImages[$variantId] = $imageUrl;
            }

            if ((string) $image->purpose === 'variant') {
                $color = Str::lower($this->option((array) ($variant->options ?: []), ['color', 'colour']));
                if ($color !== '') {
                    $legacyColorImages[$color] ??= $this->imageUrl($image->path, $image->disk ?: 'public');
                }
            }
        }

        $variantImages = [];
        foreach ($variants as $variant) {
            $variantId = (int) $variant->id;
            $color = Str::lower($this->option((array) ($variant->options ?: []), ['color', 'colour']));
            $variantImages[$variantId] = $exactImages[$variantId]
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
