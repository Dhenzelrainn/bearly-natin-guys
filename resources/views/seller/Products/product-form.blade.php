@extends('layouts.seller')

@php($isEdit = $mode === 'edit')
@php($productMode = $isEdit ? ($product['product_mode'] ?? 'single') : old('product_mode', $product['product_mode'] ?? 'single'))
@php($hasVariantMatrix = $productMode === 'with_variants')
@php($selectedPaymentMethods = old('payment_methods', $product['payment_methods'] ?? []))
@php($formPrice = (float) old('price', $product['price']))
@php($formDiscount = (int) ($product['discount_percent'] ?? 0))
@php($formSalePrice = $formPrice * (1 - ($formDiscount / 100)))
@php($savedMediaPreview = $product['media_preview'] ?? [])
@php($removedProductImageIds = collect(old('remove_product_images', []))->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all())
@php($visibleSavedMediaPreview = array_values(array_filter($savedMediaPreview, fn ($media) => ! in_array((int) ($media['image_id'] ?? 0), $removedProductImageIds, true))))
@php($initialMediaPreview = $visibleSavedMediaPreview[0] ?? null)

@section('title', $isEdit ? 'Edit Product' : 'Add Product')
@section('page-title', $isEdit ? 'Edit Product' : 'Add Product')
@section('content')
<section class="page-heading product-editor-heading">
    <div>
        <a class="back-link" href="{{ route('seller.products') }}"><i data-lucide="arrow-left"></i>Products</a>
        <h2>{{ $isEdit ? 'Edit Product' : 'Add New Product' }}</h2>
        <p>{{ $isEdit ? 'Update product details, regular pricing, and inventory.' : 'Create a complete product listing for your store.' }}</p>
    </div>
    @if ($isEdit)<span class="product-status status-{{ strtolower($product['status']) }}">{{ $product['status'] }}</span>@endif
</section>

@if ($errors->any())
    <div class="form-alert products-alert"><i data-lucide="circle-alert"></i><span>Please review the highlighted product fields.</span></div>
@endif

<section class="category-compliance-banner">
    <span><i data-lucide="shield-check"></i></span>
    <div>
        <strong>Approved business category: {{ $registeredCategory }}</strong>
        <p>Products published by this seller are restricted to the category approved during registration. A future category change should go through Admin review instead of bypassing seller compliance.</p>
    </div>
</section>

<form
    class="product-editor-form"
    method="POST"
    action="{{ $isEdit ? route('seller.products.update', $product['id']) : route('seller.products.add') }}"
    enctype="multipart/form-data"
    data-product-editor
    data-preview-reserved-stock="{{ (int) ($product['reserved_stock'] ?? 0) }}"
    data-product-discount-percent="{{ $formDiscount }}"
    data-platform-commission-rate="0.10"
>
    @csrf
    @if ($isEdit) @method('PUT') @endif
    <input type="hidden" name="category" value="{{ $registeredCategory }}">

    <div class="product-editor-layout">
        <div class="product-editor-main">
            <section class="seller-panel product-form-section">
                <div class="product-form-section-heading">
                    <span class="product-section-icon"><i data-lucide="file-text"></i></span>
                    <div><h3>Basic Information</h3><p>Use clear information buyers can understand quickly.</p></div>
                </div>
                <div class="product-form-fields">
                    <label class="seller-field">
                        <span>Product Name <em>*</em></span>
                        <input name="name" value="{{ old('name', $product['name']) }}" maxlength="120" placeholder="e.g. Classic Linen Shirt" required>
                        @error('name')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="seller-field locked-category-field">
                        <span>Category <em>*</em></span>
                        <div class="locked-category-value"><i data-lucide="lock-keyhole"></i><strong>{{ $registeredCategory }}</strong><span>Approved category</span></div>
                        <small>This is locked to your registered line of business. Contact platform support/Admin for a category review.</small>
                        @error('category')<small class="field-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="seller-field product-subcategory-field">
                        <label for="product-subcategory">Store subcategory</label>
                        <select id="product-subcategory" name="subcategory_id">
                            <option value="">No subcategory</option>
                            @foreach ($subcategories as $subcategory)
                                <option value="{{ $subcategory->id }}" @selected((string) old('subcategory_id', $product['subcategory_id'] ?? '') === (string) $subcategory->id)>{{ $subcategory->name }}</option>
                            @endforeach
                        </select>
                        <small>Organize this product within your store. Choose an existing option or add a new one below; this will not change your approved marketplace category.</small>
                        @error('subcategory_id')<small class="field-error">{{ $message }}</small>@enderror
                        <details class="seller-subcategory-create" @if (filled(old('new_subcategory_name'))) open @endif>
                            <summary>Add a new subcategory</summary>
                            <label class="seller-field" for="new-product-subcategory">
                                <span>New subcategory name</span>
                                <input id="new-product-subcategory" name="new_subcategory_name" value="{{ old('new_subcategory_name') }}" maxlength="120" placeholder="e.g. Summer Shorts">
                                <small>Saved for your store when you save this product. Existing matching names are reused.</small>
                                @error('new_subcategory_name')<small class="field-error">{{ $message }}</small>@enderror
                            </label>
                        </details>
                    </div>
                    <label class="seller-field product-description-field">
                        <span>Description</span>
                        <textarea name="description" maxlength="1000" placeholder="Describe the product, its material, purpose, or key features." data-product-description>{{ old('description', $product['description']) }}</textarea>
                        <small class="product-character-count"><span data-product-description-count>{{ strlen(old('description', $product['description'])) }}</span> / 1000</small>
                        @error('description')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                </div>
            </section>

            <section class="seller-panel product-form-section">
                <div class="product-form-section-heading"><span class="product-section-icon"><i data-lucide="images"></i></span><div><h3>Product Media</h3><p>Add a primary photo and gallery images.</p></div></div>
                <div class="product-media-grid">
                    <section class="product-media-gallery-preview" data-product-media-gallery aria-labelledby="product-media-gallery-title">
                        <div class="product-media-gallery-heading">
                            <div>
                                <strong id="product-media-gallery-title">Image gallery</strong>
                                <small>Click a thumbnail to view the image.</small>
                            </div>
                            <div class="product-media-gallery-actions">
                                <span data-product-media-count>{{ count($visibleSavedMediaPreview) }} {{ count($visibleSavedMediaPreview) === 1 ? 'image' : 'images' }}</span>
                            </div>
                        </div>
                        <div class="product-media-gallery-stage">
                            <img
                                data-product-media-stage-image
                                @if ($initialMediaPreview) src="{{ $initialMediaPreview['url'] }}" alt="{{ $initialMediaPreview['label'] }}" @else alt="" hidden @endif
                            >
                            <span data-product-media-empty @if ($initialMediaPreview) hidden @endif>No product images added yet.</span>
                        </div>
                        <div class="product-media-gallery-thumbnails" data-product-media-thumbnails role="group" aria-label="Product image thumbnails">
                            @foreach ($visibleSavedMediaPreview as $index => $media)
                                <div class="product-media-gallery-item" data-product-media-item>
                                    <button
                                        type="button"
                                        class="product-media-gallery-thumbnail {{ $index === 0 ? 'is-active' : '' }}"
                                        data-product-media-thumbnail
                                        data-media-id="{{ $media['id'] }}"
                                        data-image-id="{{ $media['image_id'] ?? '' }}"
                                        data-media-kind="{{ $media['kind'] }}"
                                        data-color="{{ $media['color'] ?? '' }}"
                                        data-variant-id="{{ $media['variant_id'] ?? '' }}"
                                        data-variant-label="{{ $media['variant_label'] ?? '' }}"
                                        data-legacy-color="{{ !empty($media['legacy_color']) ? 'true' : 'false' }}"
                                        aria-label="Show {{ $media['label'] }}"
                                        aria-pressed="{{ $index === 0 ? 'true' : 'false' }}"
                                    >
                                        <img src="{{ $media['url'] }}" alt="" loading="lazy">
                                        <span>{{ $media['label'] }}</span>
                                    </button>
                                    <button
                                        class="product-media-gallery-remove"
                                        type="button"
                                        data-product-media-remove
                                        data-media-id="{{ $media['id'] }}"
                                        aria-label="Remove {{ $media['label'] }}"
                                        title="Remove {{ $media['label'] }}"
                                    >×</button>
                                </div>
                            @endforeach
                        </div>
                        <div data-product-media-removals>
                            @foreach ($removedProductImageIds as $imageId)
                                <input type="hidden" name="remove_product_images[]" value="{{ $imageId }}">
                            @endforeach
                        </div>
                        <p class="product-media-gallery-status" data-product-media-status role="status" aria-live="polite" hidden></p>
                        <script type="application/json" data-saved-product-media>@json($product['media_preview'] ?? [])</script>
                    </section>
                    <div class="product-gallery-column">
                        <label class="product-primary-upload">
                            <span>Primary Product Image</span>
                            <input type="file" name="image" accept="image/png,image/jpeg,image/webp" data-product-image-input>
                            <small>JPG, PNG, or WEBP · up to 5MB. This image appears first to buyers.</small>
                            @error('image')<small class="field-error">{{ $message }}</small>@enderror
                        </label>
                        <label class="seller-field product-gallery-field">
                            <span>Gallery Images</span>
                            <input type="file" name="gallery_images[]" accept="image/png,image/jpeg,image/webp" multiple>
                            <small>Add up to four additional product photos. A new selection replaces the saved gallery.</small>
                            @if ($isEdit && count($product['gallery_images']))<span class="existing-gallery-count"><i data-lucide="images"></i>{{ count($product['gallery_images']) }} gallery image(s) saved</span>@endif
                            @error('gallery_images')<small class="field-error">{{ $message }}</small>@enderror
                            @error('gallery_images.*')<small class="field-error">{{ $message }}</small>@enderror
                        </label>
                    </div>
                </div>
            </section>

            <section class="seller-panel product-form-section">
                <div class="product-form-section-heading"><span class="product-section-icon"><i data-lucide="badge-percent"></i></span><div><h3>Pricing</h3><p>Set the regular price here. Manage seller discounts in Pricing &amp; Promotions.</p></div></div>
                <div class="product-form-grid product-pricing-grid">
                    <label class="seller-field">
                        <span>Regular Price (₱) <em>*</em></span>
                        <input type="number" name="price" value="{{ old('price', $product['price']) }}" min="0" step="0.01" placeholder="0.00" data-product-price required>
                        @error('price')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="sale-price-preview">
                        <span>Buyer price preview</span>
                        <strong data-sale-price>₱{{ number_format($formSalePrice, 2) }}</strong>
                        <small>
                            @if ($formDiscount > 0)
                                Existing seller discount: {{ $formDiscount }}%. It stays in place when you save this form; change it in Pricing &amp; Promotions.
                            @else
                                No seller discount is set. Add one in Pricing &amp; Promotions after saving.
                            @endif
                        </small>
                    </div>
                </div>
                <div class="product-commission-preview">
                    <div><span>Estimated commissionable amount</span><strong data-product-commissionable>—</strong></div>
                    <div><span>Estimated platform commission (10%)</span><strong data-product-commission>—</strong></div>
                    <div><span>Estimated seller earnings</span><strong data-product-net>—</strong></div>
                </div>
                <fieldset class="product-payment-methods" aria-describedby="payment-method-client-error">
                    <legend>Accepted payment methods</legend>
                    <input type="hidden" name="payment_methods_present" value="1">
                    <div class="product-payment-method-options">
                        <label>
                            <input type="checkbox" name="payment_methods[]" value="cod" @checked(in_array('cod', (array) $selectedPaymentMethods, true))>
                            <span><strong>Cash on Delivery</strong><small>COD</small></span>
                        </label>
                        <label>
                            <input type="checkbox" name="payment_methods[]" value="online" @checked(in_array('online', (array) $selectedPaymentMethods, true))>
                            <span><strong>Online</strong><small>Online payment</small></span>
                        </label>
                    </div>
                    <small class="field-error" id="payment-method-client-error" data-payment-method-client-error role="alert" hidden>Select at least one payment method before saving or publishing.</small>
                    @error('payment_methods')<small class="field-error">{{ $message }}</small>@enderror
                    @error('payment_methods.0')<small class="field-error">{{ $message }}</small>@enderror
                    @error('payment_methods.1')<small class="field-error">{{ $message }}</small>@enderror
                </fieldset>
                <label class="product-toggle-field">
                    <input type="hidden" name="voucher_eligible" value="0">
                    <input type="checkbox" name="voucher_eligible" value="1" @checked(old('voucher_eligible', $product['voucher_eligible']))>
                    <span><strong>Allow platform vouchers</strong><small>Buyers may apply eligible Bearly vouchers to this product.</small></span>
                </label>
            </section>

            <section class="seller-panel product-form-section">
                <div class="product-form-section-heading"><span class="product-section-icon"><i data-lucide="boxes"></i></span><div><h3>Inventory and Variations</h3><p>Track stock and define optional product choices.</p></div></div>
                <div class="product-mode-picker" data-product-mode-picker>
                    <span class="product-mode-picker-label">Product type</span>
                    @if ($isEdit)
                        <input type="hidden" name="product_mode" value="{{ $productMode }}" data-product-mode-value>
                        <div class="product-mode-current">
                            <strong>{{ $productMode === 'single' ? 'Single Product' : 'With Variants' }}</strong>
                            <small>{{ $productMode === 'single' ? 'One SKU, price, and stock balance.' : 'Separate SKU, price, and stock for each option combination.' }}</small>
                        </div>
                    @else
                        <div class="product-mode-options">
                        <label class="product-mode-option {{ $productMode === 'single' ? 'is-selected' : '' }}" data-product-mode-card="single">
                            <input type="radio" name="product_mode" value="single" data-product-mode-choice @checked($productMode === 'single')>
                            <span><strong>Single Product</strong><small>One SKU, price, and stock balance.</small></span>
                        </label>
                        <label class="product-mode-option {{ $productMode === 'with_variants' ? 'is-selected' : '' }}" data-product-mode-card="with_variants">
                            <input type="radio" name="product_mode" value="with_variants" data-product-mode-choice @checked($productMode === 'with_variants')>
                            <span><strong>With Variants</strong><small>Separate SKU, price, and stock for each option combination.</small></span>
                        </label>
                        </div>
                    @endif
                    @if ($isEdit)<small class="product-mode-lock-note">Product type stays fixed after creation to protect saved variants and stock history.</small>@endif
                    @error('product_mode')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="product-form-grid product-inventory-grid">
                    <label class="seller-field product-sku-field" data-product-sku-control>
                        <span data-product-sku-label>{{ $hasVariantMatrix ? 'SKU management' : 'Product SKU' }}</span>
                        <input
                            name="sku"
                            value="{{ old('sku', $product['sku']) }}"
                            maxlength="60"
                            placeholder="Automatically generated if empty"
                            data-product-sku
                            @if ($hasVariantMatrix) hidden @endif
                        >
                        <span class="product-sku-managed-value" data-product-sku-managed @unless ($hasVariantMatrix) hidden @endunless>
                            <i data-lucide="git-branch"></i>
                            <strong>Managed per variant</strong>
                        </span>
                        <small data-product-sku-help>{{ $hasVariantMatrix ? 'Each size/color combination has its own SKU in Variant Inventory below.' : 'Used when this product has no variants.' }}</small>
                        @error('sku')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="seller-field"><span>Total Stock <em>*</em></span><input type="number" name="stock" value="{{ old('stock', $product['stock']) }}" min="{{ (int) ($product['reserved_stock'] ?? 0) }}" data-product-total-stock required><small>Reserved: {{ (int) ($product['reserved_stock'] ?? 0) }} unit(s). On-hand stock cannot be set below this amount.</small>@error('stock')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label class="seller-field" data-product-global-threshold @if ($hasVariantMatrix) hidden @endif>
                        <span>Low-stock Alert At @unless ($isEdit)<em>*</em>@endunless</span>
                        <input
                            type="number"
                            name="low_stock_threshold"
                            value="{{ old('low_stock_threshold', $product['low_stock_threshold']) }}"
                            min="0"
                            max="4294967295"
                            placeholder="5"
                            @unless ($isEdit) required @endunless
                            @if ($hasVariantMatrix) disabled @endif
                        >
                        <small>
                            @if ($hasVariantMatrix)
                                Set each variant’s alert threshold in its row below.
                            @elseif ($isEdit)
                                Set the alert threshold for this SKU.
                            @else
                                Set the quantity that triggers a low-stock alert for this SKU.
                            @endif
                        </small>
                        @error('low_stock_threshold')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                </div>
                <div class="product-form-grid product-inventory-grid" data-single-package-details @if ($hasVariantMatrix) hidden @endif>
                    <label class="seller-field">
                        <span>Packaged weight (kg) <em>*</em></span>
                        <input type="number" name="package_weight_kg" value="{{ old('package_weight_kg', $product['package_weight_kg'] ?? '') }}" min="0.001" max="1000" step="0.001" placeholder="e.g. 0.350" @if ($hasVariantMatrix) disabled @else required @endif>
                        <small>Estimated weight of one packed unit.</small>
                        @error('package_weight_kg')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="seller-field">
                        <span>Packaged length (cm) <em>*</em></span>
                        <input type="number" name="package_length_cm" value="{{ old('package_length_cm', $product['package_length_cm'] ?? '') }}" min="0.01" max="1000" step="0.01" placeholder="e.g. 24" @if ($hasVariantMatrix) disabled @else required @endif>
                        @error('package_length_cm')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="seller-field">
                        <span>Packaged width (cm) <em>*</em></span>
                        <input type="number" name="package_width_cm" value="{{ old('package_width_cm', $product['package_width_cm'] ?? '') }}" min="0.01" max="1000" step="0.01" placeholder="e.g. 18" @if ($hasVariantMatrix) disabled @else required @endif>
                        @error('package_width_cm')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="seller-field">
                        <span>Packaged height (cm) <em>*</em></span>
                        <input type="number" name="package_height_cm" value="{{ old('package_height_cm', $product['package_height_cm'] ?? '') }}" min="0.01" max="1000" step="0.01" placeholder="e.g. 8" @if ($hasVariantMatrix) disabled @else required @endif>
                        @error('package_height_cm')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                </div>
                <p class="product-package-help">Bearly uses these packaged SKU estimates to quote shipping before checkout. Logistics may verify the actual parcel before dispatch.</p>
                <div data-variant-configuration @unless ($hasVariantMatrix) hidden @endunless>
                    <div class="variation-groups">
                        <div class="variation-group">
                            <div><strong>Option Group 1</strong><small>Example: Size</small></div>
                            <label class="seller-field"><span>Option Name</span><input name="option_one_name" value="{{ old('option_one_name', $product['option_one_name']) }}" placeholder="e.g. Size" data-variant-option-one-name></label>
                            <label class="seller-field"><span>Available Values</span><input name="option_one_values" value="{{ old('option_one_values', $product['option_one_values']) }}" placeholder="e.g. Small, Medium, Large" data-variant-option-one-values><small>Separate each value with a comma.</small></label>
                        </div>
                        <div class="variation-group">
                            <div><strong>Option Group 2</strong><small>Example: Color, Material, or Storage</small></div>
                            <label class="seller-field"><span>Option Name</span><input name="option_two_name" value="{{ old('option_two_name', $product['option_two_name']) }}" placeholder="e.g. Color" data-variant-option-two-name></label>
                            <label class="seller-field"><span>Available Values</span><input name="option_two_values" value="{{ old('option_two_values', $product['option_two_values']) }}" placeholder="e.g. Black, White, Olive" data-variant-option-two-values><small>Optional and adaptable to your approved product category.</small></label>
                        </div>
                    </div>

                    <section class="variant-matrix-card" data-variant-matrix>
                    <div class="variant-matrix-heading">
                        <div class="variant-matrix-heading-copy">
                            <span class="section-kicker">Variant inventory</span>
                            <h4>Stock and alert per variation</h4>
                            <p>Manage stock and shipping details for each SKU. Existing variants stay when you add values.</p>
                        </div>
                        <button class="seller-secondary-button" type="button" data-generate-variants>
                            <i data-lucide="git-branch-plus"></i> Generate variants
                        </button>
                    </div>

                    <div class="variant-matrix-empty" data-variant-empty>
                        <i data-lucide="rows-3"></i>
                        <strong>No variant rows yet</strong>
                        <span>Add option values above, then generate variants. If the product has no variants, keep using Total Stock.</span>
                    </div>

                    <div class="variant-matrix-table-wrap" data-variant-table-wrap hidden>
                        <table class="variant-matrix-table">
                            <thead>
                                <tr>
                                    <th>Variation</th>
                                    <th>Variant SKU</th>
                                    <th>Price (₱)</th>
                                    <th>Stock (on hand)</th>
                                    <th>Low-stock alert at</th>
                                </tr>
                            </thead>
                            <tbody data-variant-rows></tbody>
                        </table>
                    </div>

                    <footer class="variant-matrix-footer">
                        <span><i data-lucide="info"></i> When variants exist, Total Stock is automatically calculated from all variant rows.</span>
                        <strong data-variant-total>0 units</strong>
                    </footer>
                    @error('variants')<small class="field-error">{{ $message }}</small>@enderror
                    @error('variants.*.stock')<small class="field-error">{{ $message }}</small>@enderror
                    @error('variants.*.low_stock_threshold')<small class="field-error">{{ $message }}</small>@enderror
                    @foreach (['package_weight_kg' => 'Packaged weight', 'package_length_cm' => 'Packaged length', 'package_width_cm' => 'Packaged width', 'package_height_cm' => 'Packaged height'] as $packageField => $packageLabel)
                        @error("variants.*.{$packageField}")<small class="field-error">{{ $packageLabel }} is required for every SKU.</small>@enderror
                    @endforeach
                    <small class="field-error" id="variant-client-error" data-variant-client-error role="alert" hidden>Generate at least one variant row before saving or publishing.</small>
                    </section>

                    <script type="application/json" data-existing-variants>@json(old('variants', $product['variants'] ?? []))</script>
                </div>
            </section>
        </div>

        <aside class="product-editor-sidebar">
            <section class="seller-panel product-publish-card">
                <span class="section-kicker">Publishing</span>
                <h3>{{ $isEdit ? 'Update Product' : 'Product Status' }}</h3>
                <p>Save a draft while the listing is incomplete, or publish it when it is ready for buyers.</p>
                <div class="product-readiness-list">
                    <span><i data-lucide="circle-check-big"></i>Product information</span>
                    <span><i data-lucide="circle-check-big"></i>Pricing and stock</span>
                    <span><i data-lucide="shield-check"></i>Category matches registered business</span>
                    <span><i data-lucide="git-branch"></i>Variant stock is tracked per combination when variations are used</span>
                </div>
                <button class="seller-primary-button product-publish-button" type="submit" name="intent" value="publish"><i data-lucide="send"></i>{{ $isEdit ? 'Publish Changes' : 'Publish Product' }}</button>
                <button class="draft-button product-draft-button" type="submit" name="intent" value="draft"><i data-lucide="save"></i>Save as Draft</button>
                <a class="product-cancel-link" href="{{ route('seller.products') }}">Cancel and return</a>
            </section>
            <section class="seller-panel product-buyer-preview-card" aria-labelledby="product-buyer-preview-title">
                <div class="product-buyer-preview-heading">
                    <div><span class="section-kicker">Live preview</span><h3 id="product-buyer-preview-title">Product listing</h3></div>
                    <small>Updates as you edit</small>
                </div>
                <article class="product-buyer-preview" data-buyer-product-preview>
                    <div class="product-buyer-preview-row">
                        <div class="product-buyer-preview-image">
                            <img
                                data-buyer-preview-image
                                @if ($initialMediaPreview) src="{{ $initialMediaPreview['url'] }}" alt="{{ $product['name'] }} - {{ $initialMediaPreview['label'] }}" @else alt="Product image preview" hidden @endif
                            >
                            <span data-buyer-preview-image-empty @if ($initialMediaPreview) hidden @endif>No image</span>
                        </div>
                        <div class="product-buyer-preview-copy">
                            <strong data-buyer-preview-name>{{ old('name', $product['name']) ?: 'Product name' }}</strong>
                            <small data-buyer-preview-variant-summary>{{ old('variants.0.label', $product['variants'][0]['label'] ?? 'Single product') }}</small>
                            <div class="product-buyer-preview-unit-price">
                                <strong data-buyer-preview-price>₱{{ number_format($formSalePrice, 2) }}</strong>
                                <del data-buyer-preview-regular @if ($formDiscount <= 0) hidden @endif>₱{{ number_format($formPrice, 2) }}</del>
                                <span data-buyer-preview-discount @if ($formDiscount <= 0) hidden @endif>{{ $formDiscount }}% off</span>
                            </div>
                        </div>
                    </div>

                    <div class="product-buyer-preview-variant" data-buyer-preview-variant-field @unless ($hasVariantMatrix) hidden @endunless>
                        <label for="buyer-preview-variant">Preview variation</label>
                        <select id="buyer-preview-variant" data-buyer-preview-variant>
                            @forelse (old('variants', $product['variants'] ?? []) as $variant)
                                <option value="{{ $variant['label'] }}">{{ $variant['label'] }}</option>
                            @empty
                                <option value="">Generate variants to preview options</option>
                            @endforelse
                        </select>
                    </div>

                    <div class="product-buyer-preview-payment">
                        <span>Accepted payment methods</span>
                        <div class="product-buyer-preview-payments" data-buyer-preview-payment-methods>
                            @forelse ((array) $selectedPaymentMethods as $method)
                                @if ($method === 'cod')<span>Cash on Delivery</span>@elseif ($method === 'online')<span>Online</span>@endif
                            @empty
                                <span>No payment methods selected</span>
                            @endforelse
                        </div>
                    </div>
                </article>
            </section>
            <section class="product-editor-note"><i data-lucide="shield-check"></i><p>Product category is enforced in both the form and controller so a seller cannot publish outside the registered line of business.</p></section>
        </aside>
    </div>
</form>
@endsection
