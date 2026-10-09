<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SellerProductService
{
    public function registeredCategoryName(User $user): string
    {
        $category = $user->sellerProfile?->approvedCategory;

        return (string) (
            $category?->name
            ?: $user->business_category
            ?: 'Fashion and Apparel'
        );
    }

    public function storeFor(User $user): Store
    {
        $profile = $user->sellerProfile()->first();

        if (! $profile) {
            throw (new ModelNotFoundException)
                ->setModel(SellerProfile::class, [$user->id]);
        }

        return $this->storeForProfile($profile, $user);
    }

    /**
     * Resolve the one Store owned by a SellerProfile, creating the initial
     * draft Store when an approved/legacy Seller does not have one yet.
     */
    public function storeForProfile(
        SellerProfile $profile,
        ?User $user = null,
    ): Store {
        $store = $profile->store()->first();

        if (! $store) {
            $user ??= $profile->user()->first();
            $name = trim((string) ($user?->business_name ?: $profile->legal_business_name ?: 'Bearly Store'));
            $baseSlug = Str::slug($name) ?: 'bearly-store';
            $slug = $this->uniqueStoreSlug($baseSlug, (int) $profile->id);

            $store = $profile->store()->create([
                'name' => $name,
                'slug' => $slug,
                'contact_email' => $user?->email,
                'contact_phone' => $user?->contact_number ?: $user?->phone,
                'publication_status' => 'draft',
            ]);
        }

        $this->seedDefaultSubcategories($store, $profile->approvedCategory);

        return $store;
    }

    private function seedDefaultSubcategories(Store $store, ?Category $category): void
    {
        if (! $category) {
            return;
        }

        $categoryKey = Str::slug((string) ($category->slug ?: $category->name));
        $categoryGroups = config('store_subcategories.category_groups', []);
        $groupNames = config('store_subcategories.it3a_groups', []);
        $groupKeys = $categoryGroups[$categoryKey] ?? [];
        $names = collect($groupKeys)
            ->flatMap(fn (string $groupKey): array => $groupNames[$groupKey] ?? [])
            ->filter(fn ($name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => Str::squish($name))
            ->unique(fn (string $name): string => Str::lower($name))
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($store, $category, $names): void {
            $existing = StoreSubcategory::query()
                ->where('store_id', $store->id)
                ->where('parent_category_id', $category->id)
                ->get(['name', 'slug']);
            $existingNames = [];
            $usedSlugs = [];

            foreach ($existing as $subcategory) {
                $existingNames[Str::lower($subcategory->name)] = true;
                $usedSlugs[Str::lower($subcategory->slug)] = true;
            }

            $rows = [];
            $timestamp = now()->toDateTimeString();

            foreach ($names as $name) {
                $nameKey = Str::lower($name);

                if (isset($existingNames[$nameKey])) {
                    continue;
                }

                $baseSlug = Str::slug($name) ?: 'subcategory';
                $slug = $baseSlug;
                $suffix = 2;

                while (isset($usedSlugs[Str::lower($slug)])) {
                    $slug = $baseSlug.'-'.$suffix;
                    $suffix++;
                }

                $rows[] = [
                    'store_id' => $store->id,
                    'parent_category_id' => $category->id,
                    'name' => $name,
                    'slug' => $slug,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $existingNames[$nameKey] = true;
                $usedSlugs[Str::lower($slug)] = true;
            }

            if ($rows !== []) {
                StoreSubcategory::query()->insertOrIgnore($rows);
            }
        });
    }

    private function uniqueStoreSlug(string $baseSlug, int $profileId): string
    {
        $root = $baseSlug.'-'.$profileId;
        $slug = $root;
        $suffix = 2;

        while (Store::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $root.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function categoryFor(User $user): Category
    {
        $category = $user->sellerProfile?->approvedCategory;

        if (! $category) {
            throw ValidationException::withMessages([
                'category' => 'Your approved seller category is not available yet.',
            ]);
        }

        return $category;
    }

    /** @return EloquentCollection<int, StoreSubcategory> */
    public function subcategoriesFor(User $user): EloquentCollection
    {
        $store = $this->storeFor($user);
        $category = $this->categoryFor($user);

        return $store->subcategories()
            ->where('parent_category_id', $category->id)
            ->orderBy('name')
            ->get();
    }

    private function resolveSubcategory(Store $store, Category $category, array $data): ?StoreSubcategory
    {
        $subcategoryId = $data['subcategory_id'] ?? null;
        $name = Str::squish((string) ($data['new_subcategory_name'] ?? ''));

        if (filled($subcategoryId) && $name !== '') {
            throw ValidationException::withMessages([
                'new_subcategory_name' => 'Choose an existing subcategory or create a new one, not both.',
            ]);
        }

        if (filled($subcategoryId)) {
            $subcategory = StoreSubcategory::query()
                ->where('store_id', $store->id)
                ->where('parent_category_id', $category->id)
                ->where('is_active', true)
                ->find((int) $subcategoryId);

            if (! $subcategory) {
                throw ValidationException::withMessages([
                    'subcategory_id' => 'Choose a subcategory belonging to your store and approved category.',
                ]);
            }

            return $subcategory;
        }

        if ($name === '') {
            return null;
        }

        $existing = StoreSubcategory::query()
            ->where('store_id', $store->id)
            ->where('parent_category_id', $category->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                throw ValidationException::withMessages([
                    'new_subcategory_name' => 'This subcategory is inactive and cannot be used for a product.',
                ]);
            }

            return $existing;
        }

        $baseSlug = Str::slug($name) ?: 'subcategory';
        $slug = $baseSlug;
        $suffix = 2;

        while (StoreSubcategory::query()
            ->where('store_id', $store->id)
            ->where('parent_category_id', $category->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return StoreSubcategory::query()->create([
            'store_id' => $store->id,
            'parent_category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    public function save(
        User $user,
        array $data,
        ?UploadedFile $primaryImage,
        array $galleryImages,
        ?Product $product = null,
        array $variantImages = [],
        array $removeProductImageIds = [],
    ): Product {
        $newImagePaths = [];

        try {
            return DB::transaction(function () use ($user, $data, $primaryImage, $galleryImages, $product, $variantImages, $removeProductImageIds, &$newImagePaths): Product {
                $store = $this->storeFor($user);
                $category = $this->categoryFor($user);
                $subcategory = $this->resolveSubcategory($store, $category, $data);

                if ($product) {
                    $product = Product::query()
                        ->where('store_id', $store->id)
                        ->lockForUpdate()
                        ->findOrFail($product->getKey());
                }

                $isUpdate = $product !== null;
                $productMode = $product
                    ? $this->productMode($product)
                    : (string) ($data['product_mode'] ?? 'single');

                if (! in_array($productMode, ['single', 'with_variants'], true)) {
                    throw ValidationException::withMessages([
                        'product_mode' => 'Choose whether this is a single product or has variants.',
                    ]);
                }

                $data['product_mode'] = $productMode;

                if ($productMode === 'single') {
                    $data['variants'] = [];
                    $data['option_one_name'] = '';
                    $data['option_one_values'] = '';
                    $data['option_two_name'] = '';
                    $data['option_two_values'] = '';
                }

                $paymentMethods = array_values(array_unique(array_intersect(
                    (array) ($data['payment_methods'] ?? ['cod', 'online']),
                    ['cod', 'online'],
                )));

                if ($paymentMethods === []) {
                    throw ValidationException::withMessages([
                        'payment_methods' => 'Select at least one accepted payment method.',
                    ]);
                }

                if ($product && (int) $product->store_id !== (int) $store->id) {
                    abort(404);
                }

                if ($product && $product->removed_at !== null) {
                    throw ValidationException::withMessages([
                        'product' => 'This product was removed by platform moderation and cannot be edited.',
                    ]);
                }

                $product ??= new Product;
                $product->store_id = $store->id;
                $product->category_id = $category->id;
                $product->subcategory_id = $subcategory?->id;

                if (! $product->exists) {
                    $product->slug = $this->uniqueSlug($store, (string) $data['name']);
                }

                $intent = (string) $data['intent'];
                $product->fill([
                    'name' => trim((string) $data['name']),
                    'description' => trim((string) ($data['description'] ?? '')) ?: null,
                    'product_status' => $intent === 'publish' ? 'pending_review' : 'draft',
                    'compliance_status' => 'pending_scan',
                    'voucher_eligible' => (bool) ($data['voucher_eligible'] ?? false),
                    'payment_methods' => $paymentMethods,
                    'published_at' => null,
                    'archived_at' => null,
                    'archived_previous_status' => null,
                    'archived_previous_published_at' => null,
                ]);
                $product->save();

                $this->syncVariants($product, $data, $user);
                $this->removeProductImages($product, $removeProductImageIds);
                $this->syncImages($product, $primaryImage, $galleryImages, $newImagePaths);
                $this->syncVariantImages($product, $variantImages, $newImagePaths);

                $product->load('store.sellerProfile');
                $check = app(ComplianceScanner::class)->scan($product, $isUpdate ? 'update' : 'create');
                $result = (string) $check->result;

                if ($result === 'clear') {
                    $product->update([
                        'product_status' => $intent === 'publish' ? 'active' : 'draft',
                        'compliance_status' => 'clear',
                        'published_at' => $intent === 'publish' ? now() : null,
                    ]);
                } elseif ($result === 'blocked') {
                    $product->update([
                        'product_status' => 'blocked',
                        'compliance_status' => 'blocked',
                    ]);
                } else {
                    $product->update([
                        'product_status' => 'flagged',
                        'compliance_status' => 'flagged',
                    ]);
                }

                return $product->fresh(['category', 'subcategory', 'variants', 'images', 'store']);
            });
        } catch (Throwable $exception) {
            foreach ($newImagePaths as $storedImage) {
                try {
                    if (! ProductImage::query()
                        ->where('path', $storedImage['path'])
                        ->where('disk', $storedImage['disk'])
                        ->exists()) {
                        Storage::disk($storedImage['disk'])->delete($storedImage['path']);
                    }
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    public function findForSeller(User $user, string|int $id): Product
    {
        $store = $this->storeFor($user);

        return Product::query()
            ->where('store_id', $store->id)
            ->with(['category', 'subcategory', 'variants', 'images', 'store'])
            ->findOrFail($id);
    }

    public function productsForSeller(User $user): Collection
    {
        $store = $this->storeFor($user);

        return $store->products()
            ->with(['category', 'subcategory', 'variants', 'images'])
            ->latest('id')
            ->get()
            ->map(fn (Product $product): array => $this->formPayload($product));
    }

    /**
     * Paginated, filtered source for Seller Product Management.
     *
     * @return array{products: LengthAwarePaginator, subcategories: EloquentCollection<int, StoreSubcategory>, statuses: array<int, string>, counts: array<string, int>}
     */
    public function productManagementListing(User $user, array $filters = [], int $perPage = 25): array
    {
        $store = $this->storeFor($user);
        $base = $store->products();
        $activeProducts = (clone $base)->where('product_status', 'active');

        $lowStockCount = (clone $activeProducts)
            ->whereRaw('COALESCE((
                SELECT SUM(CASE
                    WHEN low_stock_variants.stock_on_hand > low_stock_variants.stock_reserved
                    THEN low_stock_variants.stock_on_hand - low_stock_variants.stock_reserved
                    ELSE 0
                END)
                FROM product_variants AS low_stock_variants
                WHERE low_stock_variants.product_id = products.id
                    AND low_stock_variants.is_active = 1
            ), 0) <= COALESCE((
                SELECT threshold_variant.low_stock_threshold
                FROM product_variants AS threshold_variant
                WHERE threshold_variant.product_id = products.id
                    AND threshold_variant.is_active = 1
                ORDER BY threshold_variant.position, threshold_variant.id
                LIMIT 1
            ), 5)')
            ->count();

        $parentCategory = $this->categoryFor($user);
        $subcategories = $store->subcategories()
            ->where('parent_category_id', $parentCategory->id)
            ->orderBy('name')
            ->get(['id', 'name']);
        $statuses = (clone $base)
            ->select('product_status')
            ->distinct()
            ->orderBy('product_status')
            ->pluck('product_status')
            ->map(fn (string $status): string => $this->displayStatus($status))
            ->unique()
            ->values()
            ->all();

        $query = $store->products()
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
            ])
            ->orderByDesc('id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($products) use ($search): void {
                $products
                    ->where('products.name', 'like', '%'.$search.'%')
                    ->orWhereHas('variants', fn ($variants) => $variants
                        ->where('is_active', true)
                        ->where('sku', 'like', '%'.$search.'%'));
            });
        }

        $subcategory = trim((string) ($filters['subcategory'] ?? ''));
        if ($subcategory === 'none') {
            $query->whereNull('subcategory_id');
        } elseif ($subcategory !== '' && ctype_digit($subcategory)) {
            $query->where('subcategory_id', (int) $subcategory)
                ->whereHas('subcategory', fn ($subcategories) => $subcategories
                    ->where('store_id', $store->id)
                    ->where('parent_category_id', $parentCategory->id)
                    ->where('is_active', true));
        }

        $statusCodes = [
            'Active' => 'active',
            'Archived' => 'archived',
            'Blocked' => 'blocked',
            'Draft' => 'draft',
            'Needs Review' => 'flagged',
            'Pending Review' => 'pending_review',
        ];
        $status = (string) ($filters['status'] ?? '');
        if (isset($statusCodes[$status])) {
            $query->where('product_status', $statusCodes[$status]);
        }

        $products = $query
            ->paginate($perPage)
            ->appends(array_filter([
                'search' => $search,
                'subcategory' => $subcategory,
                'status' => $status,
            ], fn (string $value): bool => $value !== ''));
        $products->getCollection()->transform(function (Product $product): array {
            $payload = $this->formPayload($product);
            $payload['stock'] = (int) collect($payload['variants'])
                ->sum(fn (array $variant): int => (int) $variant['available_stock']);

            return $payload;
        });

        return [
            'products' => $products,
            'subcategories' => $subcategories,
            'statuses' => $statuses,
            'counts' => [
                'all' => (clone $base)->count(),
                'active' => (clone $activeProducts)->count(),
                'low' => $lowStockCount,
                'archived' => (clone $base)->where('product_status', 'archived')->count(),
            ],
        ];
    }

    public function formPayload(Product $product): array
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants->where('is_active', true)->sortBy('position')->values()
            : $product->variants()->where('is_active', true)->orderBy('position')->orderBy('id')->get();
        $images = $product->relationLoaded('images')
            ? $product->images
            : $product->images()->get();
        $variantImagePayload = $this->variantImagePayload($images, $variants);
        $legacyColorImagePayload = $this->legacyColorImagePayload($images, $variants);
        $primary = $images->whereNull('variant_id')->firstWhere('is_primary', true)
            ?: $images->whereNull('variant_id')->first();
        $galleryImages = $images
            ->filter(fn (ProductImage $image): bool => ! $image->is_primary
                && $image->variant_id === null
                && ($primary === null || (int) $image->id !== (int) $primary->id))
            ->values();
        $firstVariant = $variants->first();
        $productMode = $this->productMode($product);
        $regularMinor = (int) ($firstVariant?->compare_at_price_minor ?: $firstVariant?->price_minor ?: 0);
        $saleMinor = (int) ($firstVariant?->price_minor ?: 0);
        $discount = $regularMinor > 0 && $saleMinor < $regularMinor
            ? (int) round(100 - (($saleMinor / $regularMinor) * 100))
            : 0;
        $optionNames = collect($variants)
            ->flatMap(fn (ProductVariant $variant) => array_keys((array) $variant->options))
            ->reject(fn (string $name) => $name === 'label')
            ->unique()
            ->values();
        $mediaPreview = [];

        if ($primary) {
            $mediaPreview[] = [
                'id' => 'primary',
                'kind' => 'primary',
                'image_id' => (int) $primary->id,
                'label' => 'Primary image',
                'url' => $this->productImageUrl($primary),
            ];
        }

        foreach ($galleryImages as $index => $image) {
            $mediaPreview[] = [
                'id' => 'gallery-'.$index,
                'kind' => 'gallery',
                'image_id' => (int) $image->id,
                'label' => 'Gallery image '.($index + 1),
                'url' => $this->productImageUrl($image),
            ];
        }

        $includedVariantImageIds = [];
        foreach ($variantImagePayload as $variantId => $image) {
            $mediaPreview[] = [
                'id' => 'variant:'.$variantId,
                'kind' => 'variant',
                'image_id' => (int) $image['image_id'],
                'variant_id' => (int) $variantId,
                'variant_label' => $image['variant_label'],
                'color' => $image['color'],
                'legacy_color' => $image['legacy_color'],
                'label' => $image['variant_label'].' image',
                'url' => $image['url'],
            ];
            $includedVariantImageIds[(int) $image['image_id']] = true;
        }

        foreach ($legacyColorImagePayload as $color => $image) {
            if (isset($includedVariantImageIds[(int) $image['image_id']])) {
                continue;
            }

            $mediaPreview[] = [
                'id' => 'legacy-color:'.$color,
                'kind' => 'variant',
                'image_id' => (int) $image['image_id'],
                'variant_id' => (int) $image['variant_id'],
                'variant_label' => $image['variant_label'],
                'color' => $image['color'],
                'legacy_color' => true,
                'label' => Str::title($image['color']).' shared fallback',
                'url' => $image['url'],
            ];
        }

        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'category' => $product->category?->name ?? '',
            'subcategory_id' => $product->subcategory_id,
            'subcategory' => $product->subcategory?->name ?? '',
            'description' => $product->description ?? '',
            'sku' => $firstVariant?->sku ?? '',
            'price' => $regularMinor / 100,
            'package_weight_kg' => $firstVariant?->package_weight_kg ?? '',
            'package_length_cm' => $firstVariant?->package_length_cm ?? '',
            'package_width_cm' => $firstVariant?->package_width_cm ?? '',
            'package_height_cm' => $firstVariant?->package_height_cm ?? '',
            'discount_percent' => $discount,
            'voucher_eligible' => (bool) $product->voucher_eligible,
            'payment_methods' => $this->paymentMethods($product),
            'product_mode' => $productMode,
            'stock' => $productMode === 'single'
                ? (int) ($firstVariant?->stock_on_hand ?? 0)
                : (int) $variants->sum(fn (ProductVariant $variant): int => (int) $variant->stock_on_hand),
            'reserved_stock' => $productMode === 'single'
                ? (int) ($firstVariant?->stock_reserved ?? 0)
                : (int) $variants->sum(fn (ProductVariant $variant): int => (int) $variant->stock_reserved),
            'low_stock_threshold' => $productMode === 'single'
                ? (int) ($firstVariant?->low_stock_threshold ?? 5)
                : 5,
            'option_one_name' => (string) ($optionNames->get(0) ?? ''),
            'option_one_values' => $this->optionValues($variants, (string) ($optionNames->get(0) ?? '')),
            'option_two_name' => (string) ($optionNames->get(1) ?? ''),
            'option_two_values' => $this->optionValues($variants, (string) ($optionNames->get(1) ?? '')),
            'variants' => $variants->map(function (ProductVariant $variant): array {
                $regular = (int) ($variant->compare_at_price_minor ?: $variant->price_minor);

                return [
                    'id' => (int) $variant->id,
                    'label' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $regular / 100,
                    'stock' => (int) $variant->stock_on_hand,
                    'low_stock_threshold' => (int) $variant->low_stock_threshold,
                    'reserved_stock' => (int) $variant->stock_reserved,
                    'available_stock' => (int) $variant->available_stock,
                    'package_weight_kg' => $variant->package_weight_kg ?? '',
                    'package_length_cm' => $variant->package_length_cm ?? '',
                    'package_width_cm' => $variant->package_width_cm ?? '',
                    'package_height_cm' => $variant->package_height_cm ?? '',
                    'options' => $variant->options ?: [],
                ];
            })->values()->all(),
            'status' => $this->displayStatus((string) $product->product_status),
            'previous_status' => $this->displayStatus((string) $product->product_status),
            'image' => $primary?->path,
            'image_url' => $primary ? $this->productImageUrl($primary) : null,
            'gallery_images' => $galleryImages
                ->pluck('path')
                ->values()
                ->all(),
            'gallery_image_urls' => $galleryImages
                ->map(fn (ProductImage $image): string => $this->productImageUrl($image))
                ->values()
                ->all(),
            'media_preview' => $mediaPreview,
        ];
    }

    public function productMode(Product $product): string
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->withTrashed()->get();

        if ($variants->count() > 1) {
            return 'with_variants';
        }

        $variant = $variants->first();

        if (! $variant || Str::lower(trim((string) $variant->name)) !== 'default') {
            return $variant ? 'with_variants' : 'single';
        }

        $options = collect((array) ($variant->options ?: []))
            ->reject(fn ($value, $key): bool => Str::lower((string) $key) === 'label' || blank($value));

        return $options->isEmpty() ? 'single' : 'with_variants';
    }

    private function paymentMethods(Product $product): array
    {
        $methods = array_values(array_intersect(
            (array) ($product->payment_methods ?? []),
            ['cod', 'online'],
        ));

        return $methods === [] ? ['cod', 'online'] : $methods;
    }

    public function archive(User $user, string|int $id): Product
    {
        return DB::transaction(function () use ($user, $id): Product {
            $store = $this->storeFor($user);
            $product = Product::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($product->removed_at !== null) {
                throw ValidationException::withMessages([
                    'product' => 'This product was removed by platform moderation and cannot be archived.',
                ]);
            }

            if ($product->product_status === 'archived') {
                $previousStatus = in_array($product->archived_previous_status, [
                    'draft', 'pending_review', 'active', 'flagged', 'blocked',
                ], true)
                    ? $product->archived_previous_status
                    : 'draft';

                if ($previousStatus === 'active' && $product->compliance_status !== 'clear') {
                    throw ValidationException::withMessages([
                        'product' => 'This product cannot be restored as active because its compliance status is no longer clear.',
                    ]);
                }

                $product->update([
                    'product_status' => $previousStatus,
                    'archived_at' => null,
                    'archived_previous_status' => null,
                    'published_at' => $product->archived_previous_published_at,
                    'archived_previous_published_at' => null,
                ]);
            } else {
                $product->update([
                    'product_status' => 'archived',
                    'archived_at' => now(),
                    'archived_previous_status' => $product->product_status,
                    'archived_previous_published_at' => $product->published_at,
                    'published_at' => null,
                ]);
            }

            return $product->fresh();
        });
    }

    private function syncVariants(Product $product, array $data, User $user): void
    {
        $submittedRows = collect($data['variants'] ?? [])
            ->filter(fn (array $variant) => trim((string) ($variant['label'] ?? '')) !== '')
            ->values()
            ->all();
        $normalizedLabels = collect($submittedRows)
            ->map(fn (array $variant): string => Str::lower(trim((string) ($variant['label'] ?? ''))));

        if ($normalizedLabels->count() !== $normalizedLabels->unique()->count()) {
            throw ValidationException::withMessages([
                'variants' => 'Each variant combination must have a unique label, ignoring capitalization.',
            ]);
        }

        $existingVariants = $product->variants()
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $activeVariants = $existingVariants->where('is_active', true)->values();

        if (($data['product_mode'] ?? null) === 'single') {
            $existingSingle = $activeVariants->first();
            $rows = [[
                'label' => 'Default',
                'sku' => (string) ($data['sku'] ?? '') ?: $existingSingle?->sku,
                'price' => $data['price'],
                'stock' => $data['stock'],
                'package_weight_kg' => $data['package_weight_kg'],
                'package_length_cm' => $data['package_length_cm'],
                'package_width_cm' => $data['package_width_cm'],
                'package_height_cm' => $data['package_height_cm'],
            ]];
        } elseif ($submittedRows === []) {
            $rows = $activeVariants->isNotEmpty()
                ? $activeVariants->map(fn (ProductVariant $variant): array => $this->variantRow($variant))->all()
                : [[
                    'label' => 'Default',
                    'sku' => (string) ($data['sku'] ?? '') ?: null,
                    'price' => $data['price'],
                    'stock' => $data['stock'],
                ]];
        } else {
            $submittedLabels = collect($submittedRows)
                ->map(fn (array $variant): string => trim((string) ($variant['label'] ?? '')))
                ->filter()
                ->map(fn (string $label): string => Str::lower($label))
                ->values();
            $submittedSkus = collect($submittedRows)
                ->map(fn (array $variant): string => trim((string) ($variant['sku'] ?? '')))
                ->filter()
                ->values();
            $hasOnlyPlaceholder = $activeVariants->count() === 1
                && strtolower(trim((string) $activeVariants->first()->name)) === 'default'
                && ! $submittedLabels->contains(fn (string $label): bool => strtolower($label) === 'default');
            $preservedRows = $activeVariants
                ->filter(function (ProductVariant $variant) use ($hasOnlyPlaceholder, $submittedLabels, $submittedSkus): bool {
                    if ($hasOnlyPlaceholder) {
                        return false;
                    }

                    return ! $submittedSkus->contains($variant->sku)
                        && ! $submittedLabels->contains(Str::lower(trim($variant->name)));
                })
                ->map(fn (ProductVariant $variant): array => $this->variantRow($variant))
                ->all();

            $rows = array_merge($preservedRows, $submittedRows);
        }

        $oldVariants = $existingVariants->keyBy('sku');
        $oldVariantsByLabel = $existingVariants
            ->keyBy(fn (ProductVariant $variant): string => Str::lower(trim($variant->name)));
        $activeSkus = [];
        $usedVariantIds = [];
        $discount = $this->discountPercentForVariant($activeVariants->first());
        $globalThreshold = filled($data['low_stock_threshold'] ?? null)
            ? max(0, (int) $data['low_stock_threshold'])
            : null;

        foreach ($rows as $index => $row) {
            $label = trim((string) ($row['label'] ?? 'Variant '.($index + 1)));
            $sku = trim((string) ($row['sku'] ?? ''));

            // Older editor versions derived new SKUs from option labels alone.
            // On a failed create redirect, Laravel can restore those stale values;
            // replace the legacy value only when it collides with another product.
            if (($data['product_mode'] ?? null) === 'with_variants'
                && $sku === $this->legacyGeneratedVariantSku($label)
                && ! $oldVariants->has($sku)
                && ProductVariant::withTrashed()
                    ->where('sku', $sku)
                    ->where('product_id', '!=', $product->id)
                    ->exists()) {
                $sku = '';
            }

            $variantBySku = $sku !== '' ? $oldVariants->get($sku) : null;
            $variantByLabel = $oldVariantsByLabel->get(Str::lower($label));

            if ($sku !== '' && $variantBySku && $variantByLabel
                && (int) $variantBySku->id !== (int) $variantByLabel->id) {
                throw ValidationException::withMessages([
                    'variants' => "SKU {$sku} is already assigned to a different variant in this product.",
                ]);
            }

            $variant = $variantBySku ?: $variantByLabel;

            if ($sku === '' && $variant) {
                $sku = (string) $variant->sku;
            }

            if ($sku === '') {
                $sku = $this->generateVariantSku($product, $index, $activeSkus);
            }

            if (in_array($sku, $activeSkus, true)) {
                throw ValidationException::withMessages([
                    'sku' => "SKU {$sku} is repeated in this product's variants.",
                ]);
            }

            $conflict = ProductVariant::withTrashed()
                ->where('sku', $sku)
                ->when($variant, fn ($query) => $query->where('id', '!=', $variant->id))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'sku' => "SKU {$sku} is already used by another product.",
                ]);
            }

            $variantPrice = $row['price'] ?? null;
            $regularMinor = $this->moneyToMinor(
                $variantPrice === null || $variantPrice === ''
                    ? $data['price']
                    : $variantPrice
            );
            $saleMinor = $this->discountedMinor($regularMinor, $discount);
            $stock = max(0, (int) ($row['stock'] ?? 0));

            if ($variant && in_array($variant->id, $usedVariantIds, true)) {
                throw ValidationException::withMessages([
                    'sku' => "SKU {$sku} is assigned to more than one variant.",
                ]);
            }

            if ($variant && $stock < (int) $variant->stock_reserved) {
                $errorKey = ($data['product_mode'] ?? null) === 'single' ? 'stock' : 'variants';
                throw ValidationException::withMessages([
                    $errorKey => "Stock for {$label} cannot be lower than its reserved quantity of {$variant->stock_reserved}.",
                ]);
            }

            $variant ??= new ProductVariant;
            $wasExisting = $variant->exists;
            $oldStock = $wasExisting ? (int) $variant->stock_on_hand : 0;
            $rowThreshold = filled($row['low_stock_threshold'] ?? null)
                ? max(0, (int) $row['low_stock_threshold'])
                : null;
            $threshold = $rowThreshold
                ?? $globalThreshold
                ?? ($wasExisting ? (int) $variant->low_stock_threshold : 5);
            $variant->fill([
                'product_id' => $product->id,
                'sku' => $sku,
                'name' => $label,
                'options' => $this->optionsForLabel($data, $label),
                'price_minor' => $saleMinor,
                'compare_at_price_minor' => $discount > 0 ? $regularMinor : null,
                'stock_on_hand' => $stock,
                'low_stock_threshold' => $threshold,
                'package_weight_kg' => $row['package_weight_kg'],
                'package_length_cm' => $row['package_length_cm'],
                'package_width_cm' => $row['package_width_cm'],
                'package_height_cm' => $row['package_height_cm'],
                'is_active' => true,
                'position' => $index + 1,
            ]);
            $variant->save();
            $activeSkus[] = $sku;

            if ($variant->id !== null) {
                $usedVariantIds[] = $variant->id;
            }

            if ($oldStock !== $stock) {
                InventoryMovement::create([
                    'variant_id' => $variant->id,
                    'type' => $wasExisting ? 'adjustment' : 'initial_stock',
                    'quantity_delta' => $stock - $oldStock,
                    'balance_after' => $stock,
                    'reference_type' => Product::class,
                    'reference_id' => $product->id,
                    'reason' => $wasExisting ? 'Seller product update' : 'Seller product creation',
                    'created_by' => $user->id,
                    'occurred_at' => now(),
                ]);
            }
        }

        $product->variants()
            ->whereNotIn('sku', $activeSkus)
            ->update(['is_active' => false]);
    }

    private function variantRow(ProductVariant $variant): array
    {
        $regularMinor = (int) ($variant->compare_at_price_minor ?: $variant->price_minor);

        return [
            'label' => $variant->name,
            'sku' => $variant->sku,
            'price' => number_format($regularMinor / 100, 2, '.', ''),
            'stock' => (int) $variant->stock_on_hand,
            'low_stock_threshold' => (int) $variant->low_stock_threshold,
            'package_weight_kg' => $variant->package_weight_kg ?? '',
            'package_length_cm' => $variant->package_length_cm ?? '',
            'package_width_cm' => $variant->package_width_cm ?? '',
            'package_height_cm' => $variant->package_height_cm ?? '',
        ];
    }

    private function discountPercentForVariant(?ProductVariant $variant): int
    {
        if (! $variant) {
            return 0;
        }

        $regularMinor = (int) ($variant->compare_at_price_minor ?: $variant->price_minor);
        $saleMinor = (int) $variant->price_minor;

        if ($regularMinor <= 0 || $saleMinor >= $regularMinor) {
            return 0;
        }

        return max(0, min(90, (int) round(100 - (($saleMinor / $regularMinor) * 100))));
    }

    private function legacyGeneratedVariantSku(string $label): string
    {
        $slug = preg_replace('/[^A-Z0-9]+/', '-', Str::upper($label)) ?? '';

        return 'VAR-'.substr(trim($slug, '-'), 0, 28);
    }

    private function removeProductImages(Product $product, array $imageIds): void
    {
        $imageIds = collect($imageIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($imageIds->isEmpty()) {
            return;
        }

        $product->images()
            ->whereIn('id', $imageIds)
            ->get()
            ->each(fn (ProductImage $image) => $this->deleteProductImage($image));
    }

    private function syncImages(
        Product $product,
        ?UploadedFile $primaryImage,
        array $galleryImages,
        array &$newImagePaths,
    ): void {
        if ($primaryImage) {
            $oldPrimary = $product->images()->where('is_primary', true)->first();
            $storedImage = $this->storeProductImage($primaryImage, 'seller-products', 'primary', $newImagePaths);

            if ($oldPrimary) {
                if ($oldPrimary->path !== $storedImage['path'] || ($oldPrimary->disk ?: 'public') !== $storedImage['disk']) {
                    $this->deleteImageFileAfterCommit($oldPrimary->path, $oldPrimary->disk ?: 'public');
                }
                $oldPrimary->update(array_merge($storedImage, [
                    'alt_text' => $product->name,
                    'position' => 0,
                    'is_primary' => true,
                ]));
            } else {
                $product->images()->create(array_merge($storedImage, [
                    'alt_text' => $product->name,
                    'position' => 0,
                    'is_primary' => true,
                ]));
            }
        }

        if ($galleryImages === []) {
            return;
        }

        $oldGallery = $product->images()
            ->where('is_primary', false)
            ->whereNull('variant_id')
            ->get();
        foreach ($oldGallery as $image) {
            $this->deleteProductImage($image);
        }

        foreach ($galleryImages as $position => $image) {
            if (! $image instanceof UploadedFile) {
                continue;
            }

            $storedImage = $this->storeProductImage($image, 'seller-products', 'gallery', $newImagePaths);
            $product->images()->create(array_merge($storedImage, [
                'alt_text' => $product->name,
                'position' => $position + 1,
                'is_primary' => false,
            ]));
        }
    }

    private function syncVariantImages(Product $product, array $variantImages, array &$newImagePaths): void
    {
        if ($variantImages === []) {
            return;
        }

        $activeVariants = $product->variants()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($activeVariants as $index => $variant) {
            $uploaded = $variantImages[$index] ?? null;

            if (! $uploaded instanceof UploadedFile) {
                continue;
            }

            $product->images()
                ->where('variant_id', $variant->id)
                ->where('purpose', 'variant_sku')
                ->get()
                ->each(fn (ProductImage $image) => $this->deleteProductImage($image));

            $storedImage = $this->storeProductImage(
                $uploaded,
                'seller-products/variants',
                'variant_sku',
                $newImagePaths,
            );
            $product->images()->create(array_merge($storedImage, [
                'variant_id' => $variant->id,
                'alt_text' => $product->name.' - '.$variant->name,
                'position' => $variant->position,
                'is_primary' => false,
            ]));
        }
    }

    /** @return array<int, array{image_id: int, path: string, url: string, variant_id: int, variant_label: string, color: string, legacy_color: bool, purpose: string}> */
    private function variantImagePayload(Collection|EloquentCollection $images, Collection|EloquentCollection $variants): array
    {
        $variantsById = $variants->keyBy('id');
        $payload = [];

        foreach ($images->whereNotNull('variant_id') as $image) {
            $variant = $variantsById->get($image->variant_id);

            if (! $variant) {
                continue;
            }

            $variantId = (int) $variant->id;
            $isSkuImage = (string) $image->purpose === 'variant_sku';
            $currentIsSkuImage = (string) ($payload[$variantId]['purpose'] ?? '') === 'variant_sku';
            if ($currentIsSkuImage || (isset($payload[$variantId]) && ! $isSkuImage)) {
                continue;
            }

            $payload[$variantId] = [
                'image_id' => (int) $image->id,
                'path' => $image->path,
                'url' => $this->productImageUrl($image),
                'variant_id' => (int) $variant->id,
                'variant_label' => (string) $variant->name,
                'color' => $this->variantColor($variant),
                'legacy_color' => (string) $image->purpose === 'variant',
                'purpose' => (string) $image->purpose,
            ];
        }

        return $payload;
    }

    /** @return array<string, array{image_id: int, path: string, url: string, variant_id: int, variant_label: string, color: string, legacy_color: bool}> */
    private function legacyColorImagePayload(Collection|EloquentCollection $images, Collection|EloquentCollection $variants): array
    {
        $variantsById = $variants->keyBy('id');
        $payload = [];

        foreach ($images->whereNotNull('variant_id') as $image) {
            if ((string) $image->purpose !== 'variant') {
                continue;
            }

            $variant = $variantsById->get($image->variant_id);
            $color = $variant ? $this->variantColor($variant) : '';
            $colorKey = Str::lower($color);

            if (! $variant || $colorKey === '' || isset($payload[$colorKey])) {
                continue;
            }

            $payload[$colorKey] = [
                'image_id' => (int) $image->id,
                'path' => $image->path,
                'url' => $this->productImageUrl($image),
                'variant_id' => (int) $variant->id,
                'variant_label' => (string) $variant->name,
                'color' => $color,
                'legacy_color' => true,
            ];
        }

        return $payload;
    }

    private function deleteProductImage(ProductImage $image): void
    {
        $path = $image->path;
        $disk = $image->disk ?: 'public';
        $image->delete();

        $this->deleteImageFileAfterCommit($path, $disk);
    }

    private function deleteImageFileAfterCommit(?string $path, string $disk = 'public'): void
    {
        if (! $path) {
            return;
        }

        DB::afterCommit(function () use ($path, $disk): void {
            $imageStillInCatalog = ProductImage::query()
                ->where('path', $path)
                ->where('disk', $disk)
                ->exists();
            $imageStillInOrderHistory = DB::table('order_items')
                ->where('product_image_path', $path)
                ->where('product_image_disk', $disk)
                ->exists();

            if (! $imageStillInCatalog && ! $imageStillInOrderHistory) {
                Storage::disk($disk)->delete($path);
            }
        });
    }

    /**
     * Store an uploaded image and collect its metadata for both persistence and
     * rollback cleanup.
     *
     * @param  array<string, array{disk: string, path: string}>  $newImagePaths
     * @return array{disk: string, path: string, mime_type: string, size_bytes: int, purpose: string}
     */
    private function storeProductImage(
        UploadedFile $image,
        string $directory,
        string $purpose,
        array &$newImagePaths,
    ): array {
        $disk = 'public';
        $path = $image->store($directory, $disk);

        if (! $path) {
            throw new \RuntimeException('The product image could not be stored.');
        }

        $newImagePaths[$disk.'|'.$path] = ['disk' => $disk, 'path' => $path];

        return [
            'disk' => $disk,
            'path' => $path,
            'mime_type' => (string) ($image->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => max(0, (int) $image->getSize()),
            'purpose' => $purpose,
        ];
    }

    private function productImageUrl(ProductImage $image): string
    {
        return Storage::disk($image->disk ?: 'public')->url($image->path);
    }

    /** @param array<int, string> $usedSkus */
    private function generateVariantSku(Product $product, int $position, array $usedSkus): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $sku = 'BRLY-'.$product->id.'-'.str_pad((string) ($position + 1), 2, '0', STR_PAD_LEFT).'-'.Str::upper(Str::random(8));

            if (in_array($sku, $usedSkus, true)) {
                continue;
            }

            if (! ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
                return $sku;
            }
        }

        throw ValidationException::withMessages([
            'sku' => 'A unique SKU could not be generated. Please try saving again.',
        ]);
    }

    private function variantColor(ProductVariant $variant): string
    {
        foreach ((array) ($variant->options ?: []) as $name => $value) {
            if (in_array(Str::lower((string) $name), ['color', 'colour'], true)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function uniqueSlug(Store $store, string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $counter = 2;

        while ($store->products()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private function optionsForLabel(array $data, string $label): array
    {
        $parts = preg_split('/\s*\/\s*/', $label) ?: [];
        $firstName = trim((string) ($data['option_one_name'] ?? ''));
        $secondName = trim((string) ($data['option_two_name'] ?? ''));
        $options = [];

        if ($firstName && isset($parts[0])) {
            $options[$firstName] = trim($parts[0]);
        }

        if ($secondName && isset($parts[1])) {
            $options[$secondName] = trim($parts[1]);
        }

        return $options ?: ['label' => $label];
    }

    private function optionValues(Collection|EloquentCollection $variants, string $name): string
    {
        if ($name === '') {
            return '';
        }

        return $variants
            ->map(fn (ProductVariant $variant) => data_get($variant->options ?: [], $name))
            ->filter()
            ->unique()
            ->implode(', ');
    }

    private function moneyToMinor(mixed $value): int
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['price' => 'Enter a valid price with up to two decimal places.']);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function discountedMinor(int $regularMinor, int $discount): int
    {
        return intdiv($regularMinor * (100 - $discount) + 50, 100);
    }

    private function displayStatus(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'archived' => 'Archived',
            'flagged' => 'Needs Review',
            'blocked' => 'Blocked',
            'pending_review' => 'Pending Review',
            default => 'Draft',
        };
    }
}
