<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $profile = $user->sellerProfile;

        if (! $profile) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)
                ->setModel(SellerProfile::class, [$user->id]);
        }

        $store = $profile->store;

        if ($store) {
            return $store;
        }

        $name = trim((string) ($user->business_name ?: $profile->legal_business_name ?: 'Bearly Store'));
        $baseSlug = Str::slug($name) ?: 'bearly-store';
        $slug = $baseSlug.'-'.$profile->id;

        return $profile->store()->create([
            'name' => $name,
            'slug' => $slug,
            'contact_email' => $user->email,
            'contact_phone' => $user->contact_number ?: $user->phone,
            'publication_status' => 'draft',
        ]);
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

    public function save(
        User $user,
        array $data,
        ?UploadedFile $primaryImage,
        array $galleryImages,
        ?Product $product = null,
    ): Product {
        return DB::transaction(function () use ($user, $data, $primaryImage, $galleryImages, $product): Product {
            $store = $this->storeFor($user);
            $category = $this->categoryFor($user);
            $isUpdate = $product !== null;

            if ($product && (int) $product->store_id !== (int) $store->id) {
                abort(404);
            }

            $product ??= new Product;
            $product->store_id = $store->id;
            $product->category_id = $category->id;

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
                'published_at' => null,
                'archived_at' => null,
            ]);
            $product->save();

            $this->syncVariants($product, $data, $user);
            $this->syncImages($product, $primaryImage, $galleryImages);

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

            return $product->fresh(['category', 'variants', 'images', 'store']);
        });
    }

    public function findForSeller(User $user, string|int $id): Product
    {
        $store = $this->storeFor($user);

        return Product::query()
            ->where('store_id', $store->id)
            ->with(['category', 'variants', 'images', 'store'])
            ->findOrFail($id);
    }

    public function productsForSeller(User $user): Collection
    {
        $store = $this->storeFor($user);

        return $store->products()
            ->with(['category', 'variants', 'images'])
            ->latest('id')
            ->get()
            ->map(fn (Product $product): array => $this->formPayload($product));
    }

    public function formPayload(Product $product): array
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants->where('is_active', true)->values()
            : $product->variants()->where('is_active', true)->get();
        $images = $product->relationLoaded('images')
            ? $product->images
            : $product->images()->get();
        $primary = $images->firstWhere('is_primary', true) ?: $images->first();
        $firstVariant = $variants->first();
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

        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'category' => $product->category?->name ?? '',
            'description' => $product->description ?? '',
            'sku' => $firstVariant?->sku ?? '',
            'price' => $regularMinor / 100,
            'discount_percent' => $discount,
            'voucher_eligible' => (bool) $product->voucher_eligible,
            'stock' => (int) $variants->where('is_active', true)->sum(fn (ProductVariant $variant) => $variant->available_stock),
            'low_stock_threshold' => (int) ($firstVariant?->low_stock_threshold ?? 5),
            'option_one_name' => (string) ($optionNames->get(0) ?? ''),
            'option_one_values' => $this->optionValues($variants, (string) ($optionNames->get(0) ?? '')),
            'option_two_name' => (string) ($optionNames->get(1) ?? ''),
            'option_two_values' => $this->optionValues($variants, (string) ($optionNames->get(1) ?? '')),
            'variants' => $variants->map(function (ProductVariant $variant): array {
                $regular = (int) ($variant->compare_at_price_minor ?: $variant->price_minor);

                return [
                    'label' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $regular / 100,
                    'stock' => (int) $variant->stock_on_hand,
                    'options' => $variant->options ?: [],
                ];
            })->values()->all(),
            'status' => $this->displayStatus((string) $product->product_status),
            'previous_status' => $this->displayStatus((string) $product->product_status),
            'image' => $primary?->path,
            'gallery_images' => $images
                ->reject(fn (ProductImage $image) => (bool) $image->is_primary)
                ->pluck('path')
                ->values()
                ->all(),
        ];
    }

    public function archive(User $user, string|int $id): Product
    {
        return DB::transaction(function () use ($user, $id): Product {
            $product = $this->findForSeller($user, $id);

            if ($product->product_status === 'archived') {
                $product->update([
                    'product_status' => 'draft',
                    'archived_at' => null,
                    'published_at' => null,
                ]);
            } else {
                $product->update([
                    'product_status' => 'archived',
                    'archived_at' => now(),
                    'published_at' => null,
                ]);
            }

            return $product->fresh();
        });
    }

    private function syncVariants(Product $product, array $data, User $user): void
    {
        $rows = collect($data['variants'] ?? [])
            ->filter(fn (array $variant) => trim((string) ($variant['label'] ?? '')) !== '')
            ->values()
            ->all();

        if ($rows === []) {
            $existing = $product->variants()->where('is_active', true)->first();
            $rows = [[
                'label' => 'Default',
                'sku' => (string) ($data['sku'] ?? '') ?: ($existing?->sku ?: null),
                'price' => $data['price'],
                'stock' => $data['stock'],
            ]];
        }

        $oldVariants = $product->variants()->get()->keyBy('sku');
        $activeSkus = [];
        $discount = (int) ($data['discount_percent'] ?? 0);

        foreach ($rows as $index => $row) {
            $label = trim((string) ($row['label'] ?? 'Variant '.($index + 1)));
            $sku = trim((string) ($row['sku'] ?? ''));
            $sku = $sku !== '' ? $sku : 'BRLY-'.$product->id.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-'.Str::upper(Str::random(4));

            if (in_array($sku, $activeSkus, true)) {
                throw ValidationException::withMessages([
                    'sku' => "SKU {$sku} is repeated in this product's variants.",
                ]);
            }

            $conflict = ProductVariant::withTrashed()
                ->where('sku', $sku)
                ->where('product_id', '!=', $product->id)
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
            $variant = $oldVariants->get($sku) ?: new ProductVariant;
            $wasExisting = $variant->exists;
            $oldStock = $wasExisting ? (int) $variant->stock_on_hand : 0;
            $variant->fill([
                'product_id' => $product->id,
                'sku' => $sku,
                'name' => $label,
                'options' => $this->optionsForLabel($data, $label),
                'price_minor' => $saleMinor,
                'compare_at_price_minor' => $discount > 0 ? $regularMinor : null,
                'stock_on_hand' => $stock,
                'low_stock_threshold' => max(0, (int) ($data['low_stock_threshold'] ?? 5)),
                'is_active' => true,
                'position' => $index + 1,
            ]);
            $variant->save();
            $activeSkus[] = $sku;

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

    private function syncImages(Product $product, ?UploadedFile $primaryImage, array $galleryImages): void
    {
        if ($primaryImage) {
            $oldPrimary = $product->images()->where('is_primary', true)->first();
            $path = $primaryImage->store('seller-products', 'public');

            if ($oldPrimary) {
                if ($oldPrimary->path !== $path) {
                    Storage::disk('public')->delete($oldPrimary->path);
                }
                $oldPrimary->update(['path' => $path, 'alt_text' => $product->name]);
            } else {
                $product->images()->create([
                    'path' => $path,
                    'alt_text' => $product->name,
                    'position' => 0,
                    'is_primary' => true,
                ]);
            }
        }

        if ($galleryImages === []) {
            return;
        }

        $oldGallery = $product->images()->where('is_primary', false)->get();
        foreach ($oldGallery as $image) {
            Storage::disk('public')->delete($image->path);
            $image->delete();
        }

        foreach ($galleryImages as $position => $image) {
            if (! $image instanceof UploadedFile) {
                continue;
            }

            $product->images()->create([
                'path' => $image->store('seller-products', 'public'),
                'alt_text' => $product->name,
                'position' => $position + 1,
                'is_primary' => false,
            ]);
        }
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
