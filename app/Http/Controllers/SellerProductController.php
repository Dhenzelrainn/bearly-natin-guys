<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\SellerIdentityService;
use App\Services\SellerNotificationService;
use App\Services\SellerProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    private function seller(): array
    {
        return app(
            SellerIdentityService::class
        )->current();
    }

    private function notifications(): array
    {
        return app(SellerNotificationService::class)->recentFor(auth()->user());
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
            'subcategory_id' => null,
            'subcategory' => '',
            'description' => '',
            'sku' => '',
            'price' => 0,
            'discount_percent' => 0,
            'voucher_eligible' => false,
            'stock' => 0,
            'reserved_stock' => 0,
            'low_stock_threshold' => 5,
            'package_weight_kg' => '',
            'package_length_cm' => '',
            'package_width_cm' => '',
            'package_height_cm' => '',
            'option_one_name' => '',
            'option_one_values' => '',
            'option_two_name' => '',
            'option_two_values' => '',
            'variants' => [],
            'payment_methods' => [],
            'product_mode' => 'single',
            'status' => 'Draft',
            'previous_status' => 'Active',
            'image' => null,
            'image_url' => null,
            'gallery_images' => [],
            'gallery_image_urls' => [],
            'media_preview' => [],
        ], $product);
    }

    private function validateProduct(
        Request $request,
        SellerProductService $products,
        ?string $lockedProductMode = null,
        ?Product $existingProduct = null,
    ): array {
        $registeredCategory = $this->registeredCategory($request);
        $storeId = $products->storeFor($request->user())->id;
        $categoryId = $products->categoryFor($request->user())->id;
        $productMode = $lockedProductMode ?? $request->input('product_mode');

        if (! in_array($productMode, ['single', 'with_variants'], true)) {
            $submittedVariants = collect($request->input('variants', []))
                ->filter(fn ($variant): bool => is_array($variant)
                    && is_scalar($variant['label'] ?? null)
                    && trim((string) $variant['label']) !== '');
            $hasVariantInputs = filled($request->input('option_one_values'))
                || filled($request->input('option_two_values'))
                || $submittedVariants->count() > 1
                || $submittedVariants->contains(fn (array $variant): bool => strtolower(trim((string) ($variant['label'] ?? ''))) !== 'default');
            $productMode = $hasVariantInputs ? 'with_variants' : 'single';
        }

        // Product type is chosen on creation and derived from persisted variants on edit.
        $request->merge(['product_mode' => $productMode]);

        if (! array_key_exists('payment_methods', $request->all())) {
            $request->merge([
                'payment_methods' => $request->boolean('payment_methods_present') ? [] : ['cod', 'online'],
            ]);
        }

        $validated = $request->validate([
            'product_mode' => ['required', Rule::in(['single', 'with_variants'])],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', Rule::in([$registeredCategory])],
            'subcategory_id' => [
                'nullable',
                'integer',
                Rule::exists('store_subcategories', 'id')
                    ->where('store_id', $storeId)
                    ->where('parent_category_id', $categoryId)
                    ->where('is_active', true),
            ],
            'new_subcategory_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sku' => ['nullable', 'string', 'max:60'],
            'price' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'min:0'],
            'package_weight_kg' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'gt:0', 'max:1000'],
            'package_length_cm' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'gt:0', 'max:1000'],
            'package_width_cm' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'gt:0', 'max:1000'],
            'package_height_cm' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'gt:0', 'max:1000'],
            'voucher_eligible' => ['sometimes', 'boolean'],
            'payment_methods_present' => ['nullable', 'boolean'],
            'payment_methods' => ['required', 'array', 'min:1', 'max:2'],
            'payment_methods.*' => ['required', 'string', Rule::in(['cod', 'online']), 'distinct:strict'],
            'stock' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => [
                ! $existingProduct && $productMode === 'single' ? 'required' : 'nullable',
                'integer',
                'min:0',
                'max:4294967295',
            ],
            'option_one_name' => ['exclude_if:product_mode,single', 'nullable', 'string', 'max:40'],
            'option_one_values' => ['exclude_if:product_mode,single', 'nullable', 'string', 'max:250'],
            'option_two_name' => ['exclude_if:product_mode,single', 'nullable', 'string', 'max:40'],
            'option_two_values' => ['exclude_if:product_mode,single', 'nullable', 'string', 'max:250'],
            'variants' => ['exclude_if:product_mode,single', 'required_if:product_mode,with_variants', 'array', 'min:1', 'max:100'],
            'variants.*.label' => ['required_with:variants', 'string', 'max:120', 'distinct:ignore_case'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.stock' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'variants.*.package_weight_kg' => ['required_with:variants', 'numeric', 'gt:0', 'max:1000'],
            'variants.*.package_length_cm' => ['required_with:variants', 'numeric', 'gt:0', 'max:1000'],
            'variants.*.package_width_cm' => ['required_with:variants', 'numeric', 'gt:0', 'max:1000'],
            'variants.*.package_height_cm' => ['required_with:variants', 'numeric', 'gt:0', 'max:1000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery_images' => ['nullable', 'array', 'max:4'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'variant_images' => ['exclude_if:product_mode,single', 'nullable', 'array', 'max:100'],
            'variant_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'variant_color_images' => ['exclude_if:product_mode,single', 'nullable', 'array', 'max:100'],
            'variant_color_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'variant_image_colors' => ['exclude_if:product_mode,single', 'nullable', 'array', 'max:100'],
            'variant_image_colors.*' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'remove_product_images' => ['nullable', 'array', 'max:25'],
            'remove_product_images.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('product_images', 'id')
                    ->where('product_id', $existingProduct?->id ?? 0),
            ],
            'intent' => ['required', 'in:draft,publish'],
        ], [
            'category.in' => 'Products must match the seller’s approved business category.',
            'subcategory_id.exists' => 'Choose a subcategory belonging to your store and approved category.',
        ]);

        if (filled($validated['subcategory_id'] ?? null)
            && filled(trim((string) ($validated['new_subcategory_name'] ?? '')))) {
            throw ValidationException::withMessages([
                'new_subcategory_name' => 'Choose an existing subcategory or create a new one, not both.',
            ]);
        }

        return $validated;
    }

    public function createProduct(Request $request, SellerProductService $products): View
    {
        $registeredCategory = $this->registeredCategory($request);

        return view('seller.Products.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => [$registeredCategory],
            'registeredCategory' => $registeredCategory,
            'subcategories' => $products->subcategoriesFor($request->user()),
            'product' => $this->normalizeProduct([
                'category' => $registeredCategory,
                'subcategory_id' => null,
                'subcategory' => '',
            ]),
            'mode' => 'create',
        ]);
    }

    public function addProduct(Request $request, SellerProductService $products): RedirectResponse
    {
        $validated = $this->validateProduct($request, $products);
        $savedProduct = $products->save(
            $request->user(),
            $validated,
            $request->file('image'),
            $request->file('gallery_images', []),
            null,
            $request->file('variant_images', []),
            $validated['remove_product_images'] ?? [],
            $request->file('variant_color_images', []),
            $validated['variant_image_colors'] ?? [],
        );

        if (in_array($savedProduct->product_status, ['blocked', 'flagged'], true)) {
            return redirect()->route('seller.products')->with(
                'compliance_warning',
                $savedProduct->product_status === 'blocked'
                    ? 'Compliance warning: this product is blocked and hidden from Buyers. Review the reason beside the product before making changes.'
                    : 'Compliance warning: this product is held for Admin review and hidden from Buyers. Review the reason beside the product before making changes.'
            );
        }

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
            'subcategories' => $products->subcategoriesFor($request->user()),
            'product' => $item,
            'mode' => 'edit',
        ]);
    }

    public function updateProduct(Request $request, string $product, SellerProductService $products): RedirectResponse
    {
        $existing = $products->findForSeller($request->user(), $product);
        $validated = $this->validateProduct($request, $products, $products->productMode($existing), $existing);
        $savedProduct = $products->save(
            $request->user(),
            $validated,
            $request->file('image'),
            $request->file('gallery_images', []),
            $existing,
            $request->file('variant_images', []),
            $validated['remove_product_images'] ?? [],
            $request->file('variant_color_images', []),
            $validated['variant_image_colors'] ?? [],
        );

        if (in_array($savedProduct->product_status, ['blocked', 'flagged'], true)) {
            return redirect()->route('seller.products')->with(
                'compliance_warning',
                $savedProduct->product_status === 'blocked'
                    ? 'Compliance warning: this product is blocked and hidden from Buyers. Review the reason beside the product before making changes.'
                    : 'Compliance warning: this product is held for Admin review and hidden from Buyers. Review the reason beside the product before making changes.'
            );
        }

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish'
                ? 'Product changes published in your approved business category.'
                : 'Product changes saved as a draft.'
        );
    }

    public function toggleProductArchive(Request $request, string $product, SellerProductService $products): RedirectResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'subcategory' => ['nullable', 'regex:/^(none|[1-9][0-9]*)$/'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);
        $filters = array_filter($filters, fn ($value): bool => $value !== null && $value !== '');
        $updatedProduct = $products->archive($request->user(), $product);
        $message = $updatedProduct->product_status === 'archived'
            ? 'Product archived.'
            : 'Product restored to its previous status.';

        return redirect()->route('seller.products', $filters)->with('success', $message);
    }
}
