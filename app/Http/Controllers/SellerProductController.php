<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceSubcategory;
use App\Models\Product;
use App\Models\SellerProductDraft;
use App\Services\MarketplaceTaxonomyService;
use App\Services\SellerIdentityService;
use App\Services\SellerNotificationService;
use App\Services\SellerProductDraftService;
use App\Services\SellerProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    private const MAX_PRODUCT_IMAGE_UPLOAD_BYTES = 50 * 1024 * 1024;

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
        $normalized = array_merge([
            'id' => '',
            'name' => '',
            'category' => '',
            'subcategory_id' => null,
            'subcategory' => '',
            'marketplace_subcategory_id' => null,
            'marketplace_subcategory' => '',
            'marketplace_category' => '',
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
            'package_shared' => false,
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

        foreach (['voucher_eligible', 'package_shared'] as $booleanField) {
            $normalized[$booleanField] = in_array($normalized[$booleanField], [true, 1, '1'], true);
        }

        return $normalized;
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
            $request->merge(['payment_methods' => ['cod']]);
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
            'marketplace_subcategory_id' => ['nullable', 'integer', 'min:1'],
            'new_subcategory_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sku' => ['nullable', 'string', 'max:60'],
            'price' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'min:0'],
            'package_weight_kg' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'gt:0', 'max:1000'],
            'package_length_cm' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'decimal:0,3', 'gt:0', 'max:1000'],
            'package_width_cm' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'decimal:0,3', 'gt:0', 'max:1000'],
            'package_height_cm' => ['exclude_if:product_mode,with_variants', 'required_if:product_mode,single', 'numeric', 'decimal:0,3', 'gt:0', 'max:1000'],
            'voucher_eligible' => ['sometimes', 'boolean'],
            'payment_methods_present' => ['nullable', 'boolean'],
            'payment_methods' => ['required', 'array', 'size:1'],
            'payment_methods.*' => ['required', 'string', Rule::in(['cod']), 'distinct:strict'],
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
            'variants.*.package_length_cm' => ['required_with:variants', 'numeric', 'decimal:0,3', 'gt:0', 'max:1000'],
            'variants.*.package_width_cm' => ['required_with:variants', 'numeric', 'decimal:0,3', 'gt:0', 'max:1000'],
            'variants.*.package_height_cm' => ['required_with:variants', 'numeric', 'decimal:0,3', 'gt:0', 'max:1000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'gallery_images' => ['nullable', 'array', 'max:4'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'variant_images' => ['exclude_if:product_mode,single', 'nullable', 'array', 'max:100'],
            'variant_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'variant_color_images' => ['exclude_if:product_mode,single', 'nullable', 'array', 'max:100'],
            'variant_color_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
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

        $productImages = [];
        $imageFields = ['image', 'gallery_images'];
        if ($productMode === 'with_variants') {
            $imageFields = [...$imageFields, 'variant_images', 'variant_color_images'];
        }

        foreach ($imageFields as $field) {
            $files = $request->file($field);
            foreach (is_array($files) ? $files : [$files] as $file) {
                if ($file instanceof UploadedFile) {
                    $productImages[] = $file;
                }
            }
        }

        $totalProductImageBytes = array_sum(array_map(
            static fn (UploadedFile $file): int => max(0, (int) $file->getSize()),
            $productImages,
        ));
        if ($totalProductImageBytes > self::MAX_PRODUCT_IMAGE_UPLOAD_BYTES) {
            throw ValidationException::withMessages([
                'image' => 'All product images in one save must total 50 MB or less. Remove or compress images and try again.',
            ]);
        }

        if (filled($validated['subcategory_id'] ?? null)
            && filled(trim((string) ($validated['new_subcategory_name'] ?? '')))) {
            throw ValidationException::withMessages([
                'new_subcategory_name' => 'Choose an existing subcategory or create a new one, not both.',
            ]);
        }

        return $validated;
    }

    public function createProduct(
        Request $request,
        SellerProductService $products,
        MarketplaceTaxonomyService $taxonomy,
        SellerProductDraftService $drafts,
    ): View {
        $registeredCategory = $this->registeredCategory($request);
        $storeSubcategories = $products->subcategoriesFor($request->user());
        $draft = $drafts->forStore($products->storeFor($request->user()));
        $draftPayload = $draft->payload ?? [];
        $draftFields = is_array($draftPayload['fields'] ?? null) ? $draftPayload['fields'] : [];
        $draftProduct = array_merge($draftFields, [
            'category' => $registeredCategory,
            'subcategory' => '',
            'variants' => is_array($draftPayload['variants'] ?? null) ? $draftPayload['variants'] : [],
            'media_preview' => $drafts->imagesForView($draft),
        ]);

        return view('seller.Products.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => [$registeredCategory],
            'registeredCategory' => $registeredCategory,
            'subcategories' => $storeSubcategories,
            'marketplaceSubcategories' => $taxonomy->marketplaceSubcategoriesForSeller($request->user()),
            'product' => $this->normalizeProduct($draftProduct),
            'productDraft' => $draft,
            'mode' => 'create',
        ]);
    }

    public function addProduct(
        Request $request,
        SellerProductService $products,
        SellerProductDraftService $drafts,
    ): RedirectResponse {
        $draft = $drafts->forStore($products->storeFor($request->user()));
        $this->restoreTemporaryImages($request, $draft, $drafts);
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
        $draftCleanupSucceeded = $drafts->forget($draft);
        $draftCleanupNotice = $draftCleanupSucceeded
            ? ''
            : ' Temporary product photos could not be fully deleted and will be retried.';

        if (in_array($savedProduct->product_status, ['blocked', 'flagged'], true)) {
            $statusFilter = $savedProduct->product_status === 'blocked' ? 'Blocked' : 'Needs Review';

            return redirect()->route('seller.products', ['status' => $statusFilter])->with(
                'compliance_warning',
                $savedProduct->product_status === 'blocked'
                    ? 'Compliance warning: this product is blocked and hidden from Buyers. Review the reason beside the product before making changes.'.$draftCleanupNotice
                    : 'Compliance warning: this product is held for Admin review and hidden from Buyers. Review the reason beside the product before making changes.'.$draftCleanupNotice
            );
        }

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish'
                ? 'Product published in your approved business category.'.$draftCleanupNotice
                : 'Product saved as a draft.'.$draftCleanupNotice
        );
    }

    public function saveProductDraft(Request $request, SellerProductService $products, SellerProductDraftService $drafts): JsonResponse
    {
        $validated = $request->validate([
            'fields' => ['required', 'array', 'max:25'],
            'fields.name' => ['nullable', 'string', 'max:120'],
            'fields.marketplace_subcategory_id' => ['nullable', 'string', 'max:20'],
            'fields.subcategory_id' => ['nullable', 'string', 'max:20'],
            'fields.new_subcategory_name' => ['nullable', 'string', 'max:120'],
            'fields.description' => ['nullable', 'string', 'max:1000'],
            'fields.sku' => ['nullable', 'string', 'max:60'],
            'fields.price' => ['nullable', 'string', 'max:30'],
            'fields.voucher_eligible' => ['nullable', 'string', 'in:0,1'],
            'fields.product_mode' => ['nullable', 'string', 'in:single,with_variants'],
            'fields.stock' => ['nullable', 'string', 'max:20'],
            'fields.low_stock_threshold' => ['nullable', 'string', 'max:20'],
            'fields.package_weight_kg' => ['nullable', 'string', 'max:30'],
            'fields.package_length_cm' => ['nullable', 'string', 'max:30'],
            'fields.package_width_cm' => ['nullable', 'string', 'max:30'],
            'fields.package_height_cm' => ['nullable', 'string', 'max:30'],
            'fields.option_one_name' => ['nullable', 'string', 'max:40'],
            'fields.option_one_values' => ['nullable', 'string', 'max:250'],
            'fields.option_two_name' => ['nullable', 'string', 'max:40'],
            'fields.option_two_values' => ['nullable', 'string', 'max:250'],
            'fields.package_shared' => ['nullable', 'string', 'in:0,1'],
            'fields.shared_package' => ['nullable', 'array', 'max:4'],
            'fields.shared_package.*' => ['nullable', 'string', 'max:30'],
            'variants' => ['required', 'array', 'max:100'],
            'variants.*' => ['required', 'array', 'max:16'],
            'variants.*.label' => ['nullable', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.price' => ['nullable', 'string', 'max:30'],
            'variants.*.stock' => ['nullable', 'string', 'max:20'],
            'variants.*.low_stock_threshold' => ['nullable', 'string', 'max:20'],
            'variants.*.package_weight_kg' => ['nullable', 'string', 'max:30'],
            'variants.*.package_length_cm' => ['nullable', 'string', 'max:30'],
            'variants.*.package_width_cm' => ['nullable', 'string', 'max:30'],
            'variants.*.package_height_cm' => ['nullable', 'string', 'max:30'],
            'variants.*.package_override' => ['nullable', 'boolean'],
            'variants.*.package_selected' => ['nullable', 'boolean'],
            'variants.*.packageDetailsOpen' => ['nullable', 'boolean'],
            'variant_image_colors' => ['nullable', 'array', 'max:100'],
            'variant_image_colors.*' => ['nullable', 'string', 'max:120'],
            'draft_image_colors' => ['nullable', 'array', 'max:100'],
            'draft_image_colors.*' => ['nullable', 'string', 'max:120'],
        ]);

        $draft = $drafts->save(
            $products->storeFor($request->user()),
            $validated['fields'],
            $validated['variants'],
            $validated['variant_image_colors'] ?? [],
            $validated['draft_image_colors'] ?? [],
        );

        return response()->json([
            'data' => ['expires_at' => $draft->expires_at->toIso8601String()],
            'message' => 'Product details saved privately for 7 days.',
        ]);
    }

    public function uploadDraftImages(Request $request, SellerProductService $products, SellerProductDraftService $drafts): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(['primary', 'gallery', 'variant_color'])],
            'images' => ['required', 'array', 'min:1', 'max:100'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'colors' => ['nullable', 'array', 'max:100'],
            'colors.*' => ['nullable', 'string', 'max:120'],
        ]);

        $store = $products->storeFor($request->user());
        $draftImages = $drafts->replaceImages(
            $store,
            $validated['purpose'],
            $validated['images'],
            $validated['colors'] ?? [],
        );

        $draft = $draftImages[0]->draft->load('images');

        $cleanupNotice = $drafts->cleanupFailureCount() > 0
            ? ' Previous temporary product photos could not be fully deleted and will be retried.'
            : '';

        return response()->json([
            'data' => $drafts->imagesForView($draft),
            'message' => 'Product photos are stored privately for 7 days.'.$cleanupNotice,
        ]);
    }

    public function removeDraftImage(Request $request, int $image, SellerProductService $products, SellerProductDraftService $drafts): JsonResponse
    {
        $draft = $drafts->removeImage($products->storeFor($request->user()), $image);

        return response()->json([
            'data' => $drafts->imagesForView($draft),
            'message' => 'Temporary product photo removed.',
        ]);
    }

    public function clearDraftImages(Request $request, SellerProductService $products, SellerProductDraftService $drafts): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(['primary', 'gallery', 'variant_color'])],
        ]);
        $draft = $drafts->removeImages($products->storeFor($request->user()), $validated['purpose']);

        return response()->json([
            'data' => $draft ? $drafts->imagesForView($draft) : [],
            'message' => 'Temporary product photos removed.',
        ]);
    }

    public function showDraftImage(Request $request, int $image, SellerProductService $products, SellerProductDraftService $drafts)
    {
        $draftImage = $drafts->imageForStore($products->storeFor($request->user()), $image);
        $disk = Storage::disk($draftImage->disk);

        abort_unless($disk->exists($draftImage->path), 404);

        return response()->file($disk->path($draftImage->path), [
            'Content-Type' => $draftImage->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function restoreTemporaryImages(
        Request $request,
        SellerProductDraft $draft,
        SellerProductDraftService $drafts,
    ): void {
        $temporaryImageFields = [
            'image' => 'primary',
            'gallery_images' => 'gallery',
        ];

        if ($request->input('product_mode') === 'with_variants') {
            $temporaryImageFields['variant_color_images'] = 'variant_color';
        }

        foreach ($temporaryImageFields as $field => $purpose) {
            // Read the raw FileBag here instead of Request::hasFile(). The
            // latter caches converted files, so adding a restored file after
            // that check would leave validateProduct() seeing the old null.
            if ($request->files->has($field)) {
                continue;
            }

            $files = $drafts->uploadedFiles($draft, $purpose);
            if ($files === []) {
                continue;
            }

            $request->files->set($field, $field === 'image' ? $files[0] : $files);
        }

        if ($request->input('product_mode') === 'with_variants'
            && ! $request->hasFile('variant_color_images')) {
            $submittedColors = $request->input('draft_image_colors', []);
            $colors = $draft->images
                ->where('purpose', 'variant_color')
                ->sortBy('position')
                ->map(function ($image) use ($submittedColors): ?string {
                    $color = is_array($submittedColors) && array_key_exists((string) $image->id, $submittedColors)
                        ? $submittedColors[$image->id]
                        : $image->color;

                    return is_scalar($color) && trim((string) $color) !== ''
                        ? trim((string) $color)
                        : null;
                })
                ->values()
                ->all();

            if ($colors !== []) {
                $request->merge(['variant_image_colors' => $colors]);
            }
        }
    }

    public function editProduct(
        Request $request,
        string $product,
        SellerProductService $products,
        MarketplaceTaxonomyService $taxonomy,
    ): View {
        $item = $products->findForSeller($request->user(), $product);
        $registeredCategory = $products->registeredCategoryName($request->user());
        $item = $products->formPayload($item);
        $storeSubcategories = $products->subcategoriesFor($request->user());
        $marketplaceSubcategories = $taxonomy->marketplaceSubcategoriesForSeller($request->user());
        $inactiveMarketplaceSubcategory = null;
        if (filled($item['marketplace_subcategory_id'] ?? null)
            && ! $marketplaceSubcategories->contains('id', (int) $item['marketplace_subcategory_id'])) {
            $inactiveMarketplaceSubcategory = MarketplaceSubcategory::query()
                ->with('marketplaceCategory')
                ->find($item['marketplace_subcategory_id']);
        }

        return view('seller.Products.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => [$registeredCategory],
            'registeredCategory' => $registeredCategory,
            'subcategories' => $storeSubcategories,
            'marketplaceSubcategories' => $marketplaceSubcategories,
            'inactiveMarketplaceSubcategory' => $inactiveMarketplaceSubcategory,
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
            $statusFilter = $savedProduct->product_status === 'blocked' ? 'Blocked' : 'Needs Review';

            return redirect()->route('seller.products', ['status' => $statusFilter])->with(
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
