<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\ComplianceScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    public function index(Request $request): View
    {
        [$store, $category] = $this->context($request);
        $products = $store->products()->with(['variants', 'images', 'category'])->latest()->get()
            ->map(fn (Product $product) => $this->productArray($product));

        return view('seller.Products.products', [
            'seller' => $this->seller($request, $store, $category), 'notifications' => [],
            'products' => $products, 'categories' => $products->pluck('category')->filter()->unique()->values(),
            'statuses' => $products->pluck('status')->filter()->unique()->values(),
            'counts' => ['all'=>$products->count(), 'active'=>$products->where('status', 'Active')->count(),
                'low'=>$products->filter(fn ($product) => $product['status'] === 'Active' && $product['stock'] <= $product['low_stock_threshold'])->count(),
                'archived'=>$products->where('status', 'Archived')->count()],
        ]);
    }

    public function createProduct(Request $request): View
    {
        [$store, $category] = $this->context($request);

        return view('seller.Products.product-form', [
            'seller' => $this->seller($request, $store, $category), 'notifications' => [],
            'categories' => [$category->name], 'registeredCategory' => $category->name,
            'product' => $this->emptyProduct($category->name), 'mode' => 'create',
        ]);
    }

    public function addProduct(Request $request, ComplianceScanner $scanner): RedirectResponse
    {
        [$store, $category] = $this->context($request);
        $data = $this->validateProduct($request, $category);
        $product = DB::transaction(function () use ($request, $store, $category, $data): Product {
            $product = Product::create([
                'store_id' => $store->id, 'category_id' => $category->id, 'name' => $data['name'],
                'slug' => $this->uniqueSlug($store, $data['name']), 'description' => $data['description'] ?? null,
                'product_status' => $data['intent'] === 'publish' ? 'active' : 'draft',
                'compliance_status' => 'pending_scan', 'voucher_eligible' => (bool) ($data['voucher_eligible'] ?? false),
                'published_at' => $data['intent'] === 'publish' ? now() : null,
            ]);
            $this->syncVariants($request, $product, $data);
            $this->syncImages($request, $product, false);

            return $product;
        }, 3);
        $scanner->scan($product->fresh(['store.sellerProfile']), 'create');

        return redirect()->route('seller.products')->with('success', $data['intent'] === 'publish'
            ? 'Product published in your approved business category.' : 'Product saved as a draft.');
    }

    public function editProduct(Request $request, Product $product): View
    {
        [$store, $category] = $this->context($request);
        $this->authorizeProduct($product, $store);

        return view('seller.Products.product-form', [
            'seller' => $this->seller($request, $store, $category), 'notifications' => [],
            'categories' => [$category->name], 'registeredCategory' => $category->name,
            'product' => $this->productArray($product->load(['variants', 'images', 'category'])), 'mode' => 'edit',
        ]);
    }

    public function updateProduct(Request $request, Product $product, ComplianceScanner $scanner): RedirectResponse
    {
        [$store, $category] = $this->context($request);
        $this->authorizeProduct($product, $store);
        $data = $this->validateProduct($request, $category);
        DB::transaction(function () use ($request, $product, $category, $data): void {
            $product->update([
                'category_id' => $category->id, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'product_status' => $data['intent'] === 'publish' ? 'active' : 'draft', 'compliance_status' => 'pending_scan',
                'voucher_eligible' => (bool) ($data['voucher_eligible'] ?? false),
                'published_at' => $data['intent'] === 'publish' ? ($product->published_at ?? now()) : null, 'archived_at' => null,
            ]);
            $this->syncVariants($request, $product, $data);
            $this->syncImages($request, $product, true);
        }, 3);
        $scanner->scan($product->fresh(['store.sellerProfile']), 'update');

        return redirect()->route('seller.products')->with('success', 'Product changes saved and checked for compliance.');
    }

    public function toggleProductArchive(Request $request, Product $product): RedirectResponse
    {
        [$store] = $this->context($request);
        $this->authorizeProduct($product, $store);
        $archiving = $product->product_status !== 'archived';
        $product->update(['product_status' => $archiving ? 'archived' : 'draft', 'archived_at' => $archiving ? now() : null]);

        return back()->with('success', 'Product status updated.');
    }

    private function context(Request $request): array
    {
        $profile = $request->user()->sellerProfile()->with(['store', 'approvedCategory'])->firstOrFail();
        abort_unless($profile->store && $profile->approvedCategory, 422, 'Complete the approved seller profile and store before managing products.');

        return [$profile->store, $profile->approvedCategory];
    }

    private function validateProduct(Request $request, Category $category): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'category' => ['required', Rule::in([$category->name])],
            'description' => ['nullable', 'string', 'max:1000'], 'sku' => ['nullable', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0'], 'discount_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
            'voucher_eligible' => ['nullable', 'boolean'], 'stock' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'], 'variants' => ['nullable', 'array', 'max:100'],
            'variants.*.label' => ['required_with:variants', 'string', 'max:120'], 'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'], 'variants.*.stock' => ['required_with:variants', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery_images' => ['nullable', 'array', 'max:4'], 'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'intent' => ['required', 'in:draft,publish'],
        ], ['category.in' => 'Products must match the seller’s approved business category.']);
    }

    private function syncVariants(Request $request, Product $product, array $data): void
    {
        $rows = collect($data['variants'] ?? [])->filter(fn ($row) => trim((string) ($row['label'] ?? '')) !== '')->values();
        if ($rows->isEmpty()) {
            $rows = collect([['label' => 'Default', 'sku' => $data['sku'] ?? null, 'price' => $data['price'], 'stock' => $data['stock']]]);
        }
        $existing = $product->variants()->get(); $kept = [];
        foreach ($rows as $position => $row) {
            $variant = $existing->get($position) ?? new ProductVariant(['product_id' => $product->id]);
            $oldStock = (int) ($variant->stock_on_hand ?? 0);
            $sku = trim((string) ($row['sku'] ?? '')) ?: 'PRD-'.$product->id.'-'.($position + 1);
            $variant->fill(['product_id' => $product->id, 'sku' => $sku, 'name' => $row['label'], 'options' => ['label' => $row['label']],
                'price_minor' => (int) round((float) ($row['price'] ?? $data['price']) * 100), 'stock_on_hand' => (int) $row['stock'],
                'low_stock_threshold' => (int) $data['low_stock_threshold'], 'is_active' => true, 'position' => $position])->save();
            $kept[] = $variant->id; $delta = (int) $row['stock'] - $oldStock;
            if ($delta !== 0) {
                InventoryMovement::create(['variant_id' => $variant->id, 'type' => $variant->wasRecentlyCreated ? 'initial' : 'adjustment',
                    'quantity_delta' => $delta, 'balance_after' => (int) $row['stock'], 'reason' => 'Seller product form update',
                    'created_by' => $request->user()->id, 'occurred_at' => now()]);
            }
        }
        $product->variants()->whereNotIn('id', $kept)->delete();
    }

    private function syncImages(Request $request, Product $product, bool $keepExisting): void
    {
        $files = collect();
        if ($request->hasFile('image')) $files->push($request->file('image'));
        foreach ($request->file('gallery_images', []) as $file) $files->push($file);
        if ($files->isEmpty() && $keepExisting) return;
        if (!$files->isEmpty()) $product->images()->delete();
        foreach ($files as $position => $file) {
            ProductImage::create(['product_id' => $product->id, 'path' => $file->store('seller-products', 'public'),
                'alt_text' => $product->name, 'position' => $position, 'is_primary' => $position === 0]);
        }
    }

    private function productArray(Product $product): array
    {
        $variant = $product->variants->first();
        return array_replace($this->emptyProduct($product->category->name), [
            'id' => (string) $product->id, 'name' => $product->name, 'description' => $product->description ?? '',
            'sku' => $variant?->sku ?? '', 'price' => ($variant?->price_minor ?? 0) / 100,
            'stock' => $product->variants->sum('stock_on_hand'), 'low_stock_threshold' => $variant?->low_stock_threshold ?? 5,
            'voucher_eligible' => $product->voucher_eligible,
            'variants' => $product->variants->map(fn ($v) => ['label'=>$v->name,'sku'=>$v->sku,'price'=>$v->price_minor / 100,'stock'=>$v->stock_on_hand])->all(),
            'status' => str($product->product_status)->title()->toString(), 'image' => $product->images->firstWhere('is_primary', true)?->path,
            'gallery_images' => $product->images->where('is_primary', false)->pluck('path')->all(),
        ]);
    }

    private function emptyProduct(string $category): array
    {
        return ['id'=>'','name'=>'','category'=>$category,'description'=>'','sku'=>'','price'=>0,'discount_percent'=>0,'voucher_eligible'=>false,
            'stock'=>0,'low_stock_threshold'=>5,'option_one_name'=>'','option_one_values'=>'','option_two_name'=>'','option_two_values'=>'',
            'variants'=>[],'status'=>'Draft','previous_status'=>'Draft','image'=>null,'gallery_images'=>[]];
    }

    private function seller(Request $request, Store $store, Category $category): array
    {
        $user = $request->user();
        return ['name'=>$user->name,'first_name'=>$user->first_name,
            'initials'=>str($user->first_name)->substr(0,1)->append(str($user->last_name)->substr(0,1))->upper(),
            'email'=>$user->email,'store'=>$store->name,'business_category'=>$category->name];
    }

    private function uniqueSlug(Store $store, string $name): string
    {
        $base = Str::slug($name) ?: 'product'; $slug = $base; $i = 2;
        while ($store->products()->withTrashed()->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
        return $slug;
    }

    private function authorizeProduct(Product $product, Store $store): void
    {
        abort_unless($product->store_id === $store->id, 404);
    }
}
