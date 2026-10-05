<?php

namespace App\Http\Controllers;

use App\Services\SellerProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    private function seller(): array
    {
        return app(
            \App\Services\SellerIdentityService::class
        )->current();
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
        return app(SellerProductService::class)->registeredCategoryName($request->user());
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

    public function addProduct(Request $request, SellerProductService $products): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $products->save(
            $request->user(),
            $validated,
            $request->file('image'),
            $request->file('gallery_images', []),
        );

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish'
                ? 'Product published in your approved business category.'
                : 'Product saved as a draft.'
        );
    }

    public function editProduct(Request $request, string $product, SellerProductService $products): View
    {
        $item = $products->findForSeller($request->user(), $product);
        $registeredCategory = $products->registeredCategoryName($request->user());
        $item = $products->formPayload($item);

        return view('seller.Products.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => [$registeredCategory],
            'registeredCategory' => $registeredCategory,
            'product' => $item,
            'mode' => 'edit',
        ]);
    }

    public function updateProduct(Request $request, string $product, SellerProductService $products): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $existing = $products->findForSeller($request->user(), $product);
        $products->save(
            $request->user(),
            $validated,
            $request->file('image'),
            $request->file('gallery_images', []),
            $existing,
        );

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish'
                ? 'Product changes published in your approved business category.'
                : 'Product changes saved as a draft.'
        );
    }

    public function toggleProductArchive(Request $request, string $product, SellerProductService $products): RedirectResponse
    {
        $products->archive($request->user(), $product);

        return redirect()->route('seller.products')->with('success', 'Product status updated.');
    }
}
