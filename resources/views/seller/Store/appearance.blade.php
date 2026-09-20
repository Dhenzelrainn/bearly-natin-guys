@extends('layouts.seller')

@section('title', 'Store Appearance')
@section('page-title', 'Store Appearance')

@section('content')
<div class="page-heading storefront-page-heading">
    <div><span class="section-kicker">Buyer-facing store</span><h2>Store Appearance</h2><p>Manage the information and images buyers see when they visit your store.</p></div>
    <div class="storefront-heading-actions">
        <button class="seller-secondary-button" type="button" data-storefront-preview-toggle><i data-lucide="eye"></i>Preview store</button>
        <button class="seller-primary-button" type="button" data-save-appearance><i data-lucide="save"></i>Save appearance</button>
    </div>
</div>

<div class="storefront-layout" data-storefront-appearance>
    <div class="storefront-editor-column">
        <section class="storefront-panel" aria-labelledby="store-images-title">
            <div class="storefront-panel-heading"><span class="storefront-panel-icon"><i data-lucide="images"></i></span><div><h3 id="store-images-title">Store Images</h3><p>Use clear, original images that represent your actual store.</p></div></div>
            <div class="storefront-upload-grid">
                <label class="storefront-upload profile-upload" data-storefront-upload="profile">
                    <span class="storefront-field-label">Profile photo</span>
                    <span class="storefront-profile-drop" data-storefront-drop>
                        <span class="storefront-profile-placeholder" data-storefront-profile-placeholder>
                            @if ($store['profile_photo'])<img src="{{ asset('storage/'.$store['profile_photo']) }}" alt="Current store profile photo">@else{{ strtoupper(substr($store['name'], 0, 1)) }}@endif
                        </span>
                        <strong>Choose profile photo</strong><small>Square JPG, PNG, or WebP · Up to 5MB</small>
                    </span>
                    <input type="file" accept="image/jpeg,image/png,image/webp" data-storefront-image="profile" hidden>
                </label>
                <label class="storefront-upload cover-upload" data-storefront-upload="cover">
                    <span class="storefront-field-label">Cover photo</span>
                    <span class="storefront-cover-drop" data-storefront-drop>
                        <span data-storefront-cover-placeholder>
                            @if ($store['cover_photo'])<img src="{{ asset('storage/'.$store['cover_photo']) }}" alt="Current store cover photo">@else<i data-lucide="image-up"></i>@endif
                        </span>
                        <strong>Choose cover photo</strong><small>Recommended 1600 × 500 · Up to 10MB</small>
                    </span>
                    <input type="file" accept="image/jpeg,image/png,image/webp" data-storefront-image="cover" hidden>
                </label>
            </div>
            <p class="storefront-guidance"><i data-lucide="info"></i>Avoid contact details, misleading promotions, or copyrighted brand assets in your store images.</p>
        </section>

        <section class="storefront-panel" aria-labelledby="description-title">
            <div class="storefront-panel-heading"><span class="storefront-panel-icon"><i data-lucide="align-left"></i></span><div><h3 id="description-title">Store Description</h3><p>Briefly explain what you sell and what buyers can expect.</p></div></div>
            <label class="storefront-description-field">
                <span>Description</span>
                <textarea maxlength="500" rows="6" placeholder="Tell buyers about your products, store, and service." data-storefront-description>{{ $store['description'] }}</textarea>
                <small><span data-storefront-description-count>{{ strlen($store['description']) }}</span> / 500 characters</small>
            </label>
        </section>
    </div>

    <aside class="storefront-preview-column">
        <section class="storefront-preview-card" aria-labelledby="buyer-preview-title">
            <div class="storefront-preview-heading"><div><span class="section-kicker">Live preview</span><h3 id="buyer-preview-title">Buyer View</h3></div><span>Desktop</span></div>
            <div class="buyer-store-preview">
                <div class="buyer-store-cover" data-storefront-preview-cover><span>Store cover</span></div>
                <div class="buyer-store-profile">
                    <span class="buyer-store-avatar" data-storefront-preview-profile>{{ strtoupper(substr($store['name'], 0, 1)) }}</span>
                    <div><strong>{{ $store['name'] }}</strong><small>{{ $store['category'] }}</small></div>
                    <span class="buyer-store-status"><i></i>Active seller</span>
                </div>
                <p data-storefront-preview-description>{{ $store['description'] ?: 'Your store description will appear here.' }}</p>
                <div class="buyer-store-tabs"><span class="is-active">Products</span><span>About</span><span>Reviews</span></div>
                <div class="buyer-store-products"><i></i><i></i><i></i></div>
            </div>
            <p class="storefront-preview-note"><i data-lucide="monitor"></i>This preview shows appearance only. Product listings are managed on the Products page.</p>
        </section>
    </aside>
</div>
@endsection
