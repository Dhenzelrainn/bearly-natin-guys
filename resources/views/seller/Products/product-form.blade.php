@extends('layouts.seller')

@php($isEdit = $mode === 'edit')

@section('title', $isEdit ? 'Edit Product' : 'Add Product')
@section('page-title', $isEdit ? 'Edit Product' : 'Add Product')
@section('content')
<section class="page-heading product-editor-heading">
    <div>
        <a class="back-link" href="{{ route('seller.products') }}"><i data-lucide="arrow-left"></i>Products</a>
        <h2>{{ $isEdit ? 'Edit Product' : 'Add New Product' }}</h2>
        <p>{{ $isEdit ? 'Update product details, pricing, and inventory.' : 'Create a complete product listing for your store.' }}</p>
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
                    <label class="seller-field product-description-field">
                        <span>Description</span>
                        <textarea name="description" maxlength="1000" placeholder="Describe the product, its material, purpose, or key features." data-product-description>{{ old('description', $product['description']) }}</textarea>
                        <small class="product-character-count"><span data-product-description-count>{{ strlen(old('description', $product['description'])) }}</span> / 1000</small>
                        @error('description')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                </div>
            </section>

            <section class="seller-panel product-form-section">
                <div class="product-form-section-heading"><span class="product-section-icon"><i data-lucide="images"></i></span><div><h3>Product Media</h3><p>Add a primary photo and optional gallery images.</p></div></div>
                <div class="product-media-grid">
                    <label class="product-primary-upload">
                        <span>Primary Product Image</span>
                        <span class="product-primary-preview" data-product-image-preview>
                            @if ($product['image'])
                                <img src="{{ asset('storage/'.$product['image']) }}" alt="Current product image">
                            @else
                                <i data-lucide="image-plus"></i><strong>Choose primary image</strong><small>JPG, PNG, or WEBP · up to 5MB</small>
                            @endif
                        </span>
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp" data-product-image-input>
                        @error('image')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="seller-field product-gallery-field">
                        <span>Gallery Images</span>
                        <input type="file" name="gallery_images[]" accept="image/png,image/jpeg,image/webp" multiple>
                        <small>Add up to four images. On edit, a new selection replaces the saved gallery.</small>
                        @if ($isEdit && count($product['gallery_images']))<span class="existing-gallery-count"><i data-lucide="images"></i>{{ count($product['gallery_images']) }} gallery image(s) saved</span>@endif
                        @error('gallery_images')<small class="field-error">{{ $message }}</small>@enderror
                        @error('gallery_images.*')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                </div>
            </section>

            <section class="seller-panel product-form-section">
                <div class="product-form-section-heading"><span class="product-section-icon"><i data-lucide="badge-percent"></i></span><div><h3>Pricing and Promotions</h3><p>Set the selling price and optional seller-funded discount.</p></div></div>
                <div class="product-form-grid product-pricing-grid">
                    <label class="seller-field">
                        <span>Regular Price (₱) <em>*</em></span>
                        <input type="number" name="price" value="{{ old('price', $product['price']) }}" min="0" step="0.01" placeholder="0.00" data-product-price required>
                        @error('price')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="seller-field">
                        <span>Seller Discount</span>
                        <span class="input-suffix"><input type="number" name="discount_percent" value="{{ old('discount_percent', $product['discount_percent']) }}" min="0" max="90" data-product-discount><b>%</b></span>
                        @error('discount_percent')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="sale-price-preview">
                        <span>Buyer price</span>
                        <strong data-sale-price>₱{{ number_format((float) $product['price'] * (1 - ((int) $product['discount_percent'] / 100)), 2) }}</strong>
                        <small>Automatically calculated from the seller discount.</small>
                    </div>
                </div>
                <div class="product-commission-preview">
                    <div><span>Estimated commissionable amount</span><strong data-product-commissionable>—</strong></div>
                    <div><span>Estimated platform commission (10%)</span><strong data-product-commission>—</strong></div>
                    <div><span>Estimated seller earnings</span><strong data-product-net>—</strong></div>
                    <p><i data-lucide="info"></i> Preview only. Commission is finalized when a successful order becomes <strong>COMPLETED</strong>; full refunds can reverse it.</p>
                </div>
                <label class="product-toggle-field">
                    <input type="hidden" name="voucher_eligible" value="0">
                    <input type="checkbox" name="voucher_eligible" value="1" @checked(old('voucher_eligible', $product['voucher_eligible']))>
                    <span><strong>Allow platform vouchers</strong><small>Buyers may apply eligible Bearly vouchers to this product.</small></span>
                </label>
            </section>

            <section class="seller-panel product-form-section">
                <div class="product-form-section-heading"><span class="product-section-icon"><i data-lucide="boxes"></i></span><div><h3>Inventory and Variations</h3><p>Track stock and define optional product choices.</p></div></div>
                <div class="product-form-grid product-inventory-grid">
                    <label class="seller-field"><span>SKU</span><input name="sku" value="{{ old('sku', $product['sku']) }}" maxlength="60" placeholder="Automatically generated if empty">@error('sku')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label class="seller-field"><span>Total Stock <em>*</em></span><input type="number" name="stock" value="{{ old('stock', $product['stock']) }}" min="0" data-product-total-stock required>@error('stock')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label class="seller-field"><span>Low-stock Alert At <em>*</em></span><input type="number" name="low_stock_threshold" value="{{ old('low_stock_threshold', $product['low_stock_threshold']) }}" min="0" required><small>You will be alerted when stock reaches this quantity.</small>@error('low_stock_threshold')<small class="field-error">{{ $message }}</small>@enderror</label>
                </div>
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
                        <div>
                            <span class="section-kicker">Variant inventory</span>
                            <h4>Stock per variation</h4>
                            <p>Generate sellable combinations so each size/color can have its own SKU, price, and stock.</p>
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
                                    <th>Stock</th>
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
                </section>

                <script type="application/json" data-existing-variants>@json(old('variants', $product['variants'] ?? []))</script>
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
            <section class="product-editor-note"><i data-lucide="shield-check"></i><p>Product category is enforced in both the form and controller so a seller cannot publish outside the registered line of business.</p></section>
        </aside>
    </div>
</form>
@endsection
