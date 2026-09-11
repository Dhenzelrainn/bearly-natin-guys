<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    private function seller(): array
    {
        return [
            'name' => 'Bea Rivera',
            'first_name' => 'Bea',
            'initials' => 'BR',
            'email' => 'bea@juansclothing.test',
            'store' => "Juan's Clothing Shop",
            'business_category' => 'Fashion and Apparel',
        ];
    }

    private function notifications(): array
    {
        return [
            ['title' => '2 new orders need confirmation', 'time' => '5 minutes ago', 'type' => 'warning'],
            ['title' => 'Classic Linen Shirt is low in stock', 'time' => '42 minutes ago', 'type' => 'danger'],
            ['title' => '2 parcels are ready for pickup request', 'time' => '1 hour ago', 'type' => 'success'],
        ];
    }

    private function registeredCategory(Request $request): string
    {
        return (string) data_get($request->session()->get('seller.store', []), 'category', 'Fashion and Apparel');
    }

    private function normalizeProduct(array $product): array
    {
        return array_merge([
            'id' => '',
            'name' => '',
            'category' => '',
            'description' => '',
            'sku' => '',
            'price' => 0,
            'discount_percent' => 0,
            'voucher_eligible' => false,
            'stock' => 0,
            'low_stock_threshold' => 5,
            'option_one_name' => '',
            'option_one_values' => '',
            'option_two_name' => '',
            'option_two_values' => '',
            'variants' => [],
            'status' => 'Draft',
            'previous_status' => 'Active',
            'image' => null,
            'gallery_images' => [],
        ], $product);
    }

    private function validateProduct(Request $request): array
    {
        $registeredCategory = $this->registeredCategory($request);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', Rule::in([$registeredCategory])],
            'description' => ['nullable', 'string', 'max:1000'],
            'sku' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
            'voucher_eligible' => ['nullable', 'boolean'],
            'stock' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
            'option_one_name' => ['nullable', 'string', 'max:40'],
            'option_one_values' => ['nullable', 'string', 'max:250'],
            'option_two_name' => ['nullable', 'string', 'max:40'],
            'option_two_values' => ['nullable', 'string', 'max:250'],
            'variants' => ['nullable', 'array', 'max:100'],
            'variants.*.label' => ['required_with:variants', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['required_with:variants', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery_images' => ['nullable', 'array', 'max:4'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'intent' => ['required', 'in:draft,publish'],
        ], [
            'category.in' => 'Products must match the seller’s approved business category.',
        ]);
    }

    private function normalizedVariants(array $validated, float $basePrice): array
    {
        return collect($validated['variants'] ?? [])
            ->filter(fn (array $variant) => trim((string) ($variant['label'] ?? '')) !== '')
            ->values()
            ->map(function (array $variant, int $index) use ($basePrice) {
                return [
                    'label' => trim((string) $variant['label']),
                    'sku' => trim((string) ($variant['sku'] ?? '')),
                    'price' => isset($variant['price']) && $variant['price'] !== ''
                        ? (float) $variant['price']
                        : $basePrice,
                    'stock' => max(0, (int) ($variant['stock'] ?? 0)),
                    'position' => $index + 1,
                ];
            })
            ->all();
    }

    public function createProduct(Request $request): View
    {
        $registeredCategory = $this->registeredCategory($request);

        return view('seller.Products.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => [$registeredCategory],
            'registeredCategory' => $registeredCategory,
            'product' => $this->normalizeProduct(['category' => $registeredCategory]),
            'mode' => 'create',
        ]);
    }

    public function addProduct(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $basePrice = (float) $validated['price'];
        $variants = $this->normalizedVariants($validated, $basePrice);
        $effectiveStock = count($variants) > 0
            ? collect($variants)->sum('stock')
            : (int) $validated['stock'];

        $products = $request->session()->get('seller.products', []);
        $id = (string) ((int) collect($products)->max(fn ($item) => (int) ($item['id'] ?? 0)) + 1);

        $galleryImages = [];
        foreach ($request->file('gallery_images', []) as $image) {
            $galleryImages[] = $image->store('seller-products', 'public');
        }

        $products[] = $this->normalizeProduct([
            'id' => $id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? '',
            'sku' => ($validated['sku'] ?? '') ?: 'BR-'.str_pad($id, 4, '0', STR_PAD_LEFT),
            'price' => $basePrice,
            'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
            'voucher_eligible' => (bool) ($validated['voucher_eligible'] ?? false),
            'stock' => $effectiveStock,
            'low_stock_threshold' => (int) $validated['low_stock_threshold'],
            'option_one_name' => $validated['option_one_name'] ?? '',
            'option_one_values' => $validated['option_one_values'] ?? '',
            'option_two_name' => $validated['option_two_name'] ?? '',
            'option_two_values' => $validated['option_two_values'] ?? '',
            'variants' => $variants,
            'status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
            'previous_status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
            'image' => $request->hasFile('image')
                ? $request->file('image')->store('seller-products', 'public')
                : null,
            'gallery_images' => $galleryImages,
        ]);

        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish'
                ? 'Product published in your approved business category.'
                : 'Product saved as a draft.'
        );
    }

    public function editProduct(Request $request, string $product): View
    {
        $item = collect($request->session()->get('seller.products', []))
            ->first(fn (array $item) => (string) ($item['id'] ?? '') === $product);

        abort_if(!$item, 404);
        $registeredCategory = $this->registeredCategory($request);
        $item = $this->normalizeProduct($item);

        if ($item['category'] !== $registeredCategory) {
            $item['category'] = $registeredCategory;
        }

        return view('seller.Products.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => [$registeredCategory],
            'registeredCategory' => $registeredCategory,
            'product' => $item,
            'mode' => 'edit',
        ]);
    }

    public function updateProduct(Request $request, string $product): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $basePrice = (float) $validated['price'];
        $variants = $this->normalizedVariants($validated, $basePrice);
        $effectiveStock = count($variants) > 0
            ? collect($variants)->sum('stock')
            : (int) $validated['stock'];

        $found = false;

        $products = collect($request->session()->get('seller.products', []))
            ->map(function (array $item) use ($request, $validated, $basePrice, $variants, $effectiveStock, $product, &$found) {
                if ((string) ($item['id'] ?? '') !== $product) {
                    return $item;
                }

                $found = true;
                $item = $this->normalizeProduct($item);
                $newGalleryImages = $request->file('gallery_images', []);
                $galleryImages = $item['gallery_images'];

                if (count($newGalleryImages) > 0) {
                    $galleryImages = [];
                    foreach ($newGalleryImages as $image) {
                        $galleryImages[] = $image->store('seller-products', 'public');
                    }
                }

                return array_merge($item, [
                    'name' => $validated['name'],
                    'category' => $validated['category'],
                    'description' => $validated['description'] ?? '',
                    'sku' => ($validated['sku'] ?? '') ?: $item['sku'],
                    'price' => $basePrice,
                    'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
                    'voucher_eligible' => (bool) ($validated['voucher_eligible'] ?? false),
                    'stock' => $effectiveStock,
                    'low_stock_threshold' => (int) $validated['low_stock_threshold'],
                    'option_one_name' => $validated['option_one_name'] ?? '',
                    'option_one_values' => $validated['option_one_values'] ?? '',
                    'option_two_name' => $validated['option_two_name'] ?? '',
                    'option_two_values' => $validated['option_two_values'] ?? '',
                    'variants' => $variants,
                    'status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
                    'previous_status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
                    'image' => $request->hasFile('image')
                        ? $request->file('image')->store('seller-products', 'public')
                        : $item['image'],
                    'gallery_images' => $galleryImages,
                ]);
            })->values()->all();

        abort_unless($found, 404);
        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish'
                ? 'Product changes published in your approved business category.'
                : 'Product changes saved as a draft.'
        );
    }

    public function toggleProductArchive(Request $request, string $product): RedirectResponse
    {
        $products = collect($request->session()->get('seller.products', []))
            ->map(function (array $item) use ($product) {
                if ((string) ($item['id'] ?? '') === $product) {
                    $item = $this->normalizeProduct($item);
                    if ($item['status'] === 'Archived') {
                        $item['status'] = $item['previous_status'] ?: 'Draft';
                    } else {
                        $item['previous_status'] = $item['status'];
                        $item['status'] = 'Archived';
                    }
                }
                return $item;
            })->values()->all();

        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with('success', 'Product status updated.');
    }
}
