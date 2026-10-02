@extends('layouts.seller')

@section('title', 'Store Appearance')
@section('page-title', 'Store Appearance')

@section('content')
<form method="POST" action="{{ route('seller.store.appearance.save') }}" enctype="multipart/form-data" data-storefront-form>
    @csrf

<div class="page-heading storefront-page-heading">
    <div><span class="section-kicker">Buyer-facing store</span><h2>Store Appearance</h2><p>Manage the information and images buyers see when they visit your store.</p></div>
    <div class="storefront-heading-actions">
        <span class="storefront-save-state" data-storefront-save-state>All changes saved</span>
        <button class="seller-secondary-button" type="button" data-storefront-preview-toggle><i data-lucide="eye"></i>Preview store</button>
        <button class="seller-primary-button" type="submit" data-save-appearance><i data-lucide="save"></i>Save appearance</button>
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
                    <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" data-storefront-image="profile" hidden>
                </label>
                <label class="storefront-upload cover-upload" data-storefront-upload="cover">
                    <span class="storefront-field-label">Cover photo</span>
                    <span class="storefront-cover-drop" data-storefront-drop>
                        <span data-storefront-cover-placeholder>
                            @if ($store['cover_photo'])<img src="{{ asset('storage/'.$store['cover_photo']) }}" alt="Current store cover photo">@else<i data-lucide="image-up"></i>@endif
                        </span>
                        <strong>Choose cover photo</strong><small>Recommended 1600 × 500 · Up to 10MB</small>
                    </span>
                    <input type="file" name="cover_photo" accept="image/jpeg,image/png,image/webp" data-storefront-image="cover" hidden>
                </label>
            </div>
            <p class="storefront-guidance"><i data-lucide="info"></i>Avoid contact details, misleading promotions, or copyrighted brand assets in your store images.</p>
        </section>

        <section class="storefront-panel" aria-labelledby="promo-banner-title">
            <div class="storefront-panel-heading"><span class="storefront-panel-icon"><i data-lucide="megaphone"></i></span><div><h3 id="promo-banner-title">Promotional Banners</h3><p>Add multiple buyer-facing campaigns. Buyers can browse them using the carousel.</p></div><button class="seller-secondary-button storefront-add-promo" type="button" data-add-promo-banner><i data-lucide="plus"></i>Add banner</button></div>
            <div class="storefront-promo-grid" data-promo-banner-item>
                @if ($store['promo_banners'][0]['id'] ?? false)<input type="hidden" name="promo_banner_ids[]" value="{{ $store['promo_banners'][0]['id'] }}">@endif
                <label class="storefront-upload promo-upload">
                    <span class="storefront-field-label">Banner image</span>
                    <span class="storefront-promo-drop">
                        @if (filled($store['promo_banners'][0]['path'] ?? null))
                            <img src="{{ asset('storage/'.$store['promo_banners'][0]['path']) }}" alt="Current promotional banner" data-promo-image>
                        @else
                            <i data-lucide="image-plus"></i><strong>Choose promotional banner</strong>
                        @endif
                        <small>JPG, PNG, or WebP · Up to 20MB</small>
                    </span>
                    <input type="file" name="promo_banners[]" accept="image/jpeg,image/png,image/webp" data-storefront-image="promo" hidden>
                </label>
                <div class="storefront-promo-fields">
                    <label class="storefront-description-field"><span>Banner title</span><input type="text" name="promo_banner_titles[]" maxlength="160" value="{{ old('promo_banner_title', $store['promo_banners'][0]['title'] ?? '') }}" placeholder="Example: New arrivals are here"></label>
                    <label class="storefront-description-field"><span>Optional link</span><input type="url" name="promo_banner_links[]" maxlength="500" value="{{ old('promo_banner_link', $store['promo_banners'][0]['link'] ?? '') }}" placeholder="https://your-store-page.example"></label>
                </div>
                <button class="storefront-remove-promo" type="button" data-remove-promo-banner><i data-lucide="trash-2"></i><span>Remove banner</span></button>
            </div>
            @foreach (array_slice($store['promo_banners'], 1) as $banner)
                <div class="storefront-promo-grid" data-promo-banner-item>
                    <input type="hidden" name="promo_banner_ids[]" value="{{ $banner['id'] }}">
                    <label class="storefront-upload promo-upload">
                        <span class="storefront-field-label">Banner image</span>
                        <span class="storefront-promo-drop" data-promo-drop><img src="{{ asset('storage/'.$banner['path']) }}" alt="Current promotional banner" data-promo-image><strong>Replace banner</strong><small>JPG, PNG, or WebP · Up to 20MB</small></span>
                        <input type="file" name="promo_banners[]" accept="image/jpeg,image/png,image/webp" data-storefront-image="promo" hidden>
                    </label>
                    <div class="storefront-promo-fields">
                        <label class="storefront-description-field"><span>Banner title</span><input type="text" name="promo_banner_titles[]" maxlength="160" value="{{ $banner['title'] }}" placeholder="Example: New arrivals are here"></label>
                        <label class="storefront-description-field"><span>Optional link</span><input type="url" name="promo_banner_links[]" maxlength="500" value="{{ $banner['link'] }}" placeholder="https://your-store-page.example"></label>
                    </div>
                    <button class="storefront-remove-promo" type="button" data-remove-promo-banner><i data-lucide="trash-2"></i><span>Remove banner</span></button>
                </div>
            @endforeach
            <p class="storefront-promo-limit"><i data-lucide="info"></i>Add as many campaigns as your store needs. Keep each banner focused on one campaign or store announcement.</p>
        </section>

        <section class="storefront-panel" aria-labelledby="description-title">
            <div class="storefront-panel-heading"><span class="storefront-panel-icon"><i data-lucide="align-left"></i></span><div><h3 id="description-title">Store Description</h3><p>Briefly explain what you sell and what buyers can expect.</p></div></div>
            <label class="storefront-description-field">
                <span>Description</span>
                <textarea name="description" maxlength="500" rows="6" placeholder="Tell buyers about your products, store, and service." data-storefront-description>{{ old('description', $store['description']) }}</textarea>
                <small><span data-storefront-description-count>{{ strlen($store['description']) }}</span> / 500 characters</small>
            </label>
        </section>

        <section class="storefront-panel" aria-labelledby="store-details-title">
            <div class="storefront-panel-heading"><span class="storefront-panel-icon"><i data-lucide="megaphone"></i></span><div><h3 id="store-details-title">Store Details</h3><p>Give buyers quick information before they browse your products.</p></div></div>
            <div class="storefront-settings-grid">
                <label class="storefront-description-field">
                    <span>Store announcement</span>
                    <input type="text" name="announcement" maxlength="180" value="{{ old('announcement', $store['announcement']) }}" placeholder="Example: New arrivals every Friday" data-storefront-announcement>
                    <small>Shown near the top of your buyer-facing store.</small>
                </label>
                <label class="storefront-description-field">
                    <span>Operating hours</span>
                    <input type="text" name="operating_hours" maxlength="120" value="{{ old('operating_hours', $store['operating_hours']) }}" placeholder="Example: Monday-Saturday, 9:00 AM-6:00 PM" data-storefront-hours>
                    <small>Tell buyers when your store usually responds.</small>
                </label>
            </div>
        </section>

        <section class="storefront-panel" aria-labelledby="store-policies-title">
            <div class="storefront-panel-heading"><span class="storefront-panel-icon"><i data-lucide="scroll-text"></i></span><div><h3 id="store-policies-title">Store Policies</h3><p>Set clear expectations for buyers before they place an order.</p></div></div>
            <div class="storefront-policy-grid">
                <label class="storefront-description-field"><span>Shipping policy</span><textarea name="shipping_policy" maxlength="3000" rows="4" placeholder="Explain handling time, pickup, and delivery expectations." data-storefront-policy="shipping">{{ old('shipping_policy', $store['shipping_policy']) }}</textarea></label>
                <label class="storefront-description-field"><span>Return and refund policy</span><textarea name="return_policy" maxlength="3000" rows="4" placeholder="Explain eligible returns and refund conditions." data-storefront-policy="returns">{{ old('return_policy', $store['return_policy']) }}</textarea></label>
                <label class="storefront-description-field"><span>Warranty policy</span><textarea name="warranty_policy" maxlength="3000" rows="4" placeholder="Explain warranty coverage, if applicable." data-storefront-policy="warranty">{{ old('warranty_policy', $store['warranty_policy']) }}</textarea></label>
            </div>
        </section>
    </div>

    <aside class="storefront-preview-column">
        <section class="storefront-preview-card" aria-labelledby="buyer-preview-title">
            <div class="storefront-preview-heading"><div><span class="section-kicker">Live preview</span><h3 id="buyer-preview-title">Buyer View</h3></div><span>Desktop</span></div>
            <div class="buyer-store-preview">
                <div class="buyer-store-cover" data-storefront-preview-cover>
                    @if ($store['cover_photo'])
                        <img src="{{ asset('storage/'.$store['cover_photo']) }}" alt="{{ $store['name'] }} cover photo">
                    @else
                        <span>Store cover</span>
                    @endif
                </div>
                <div class="buyer-store-profile">
                    <span class="buyer-store-avatar" data-storefront-preview-profile>
                        @if ($store['profile_photo'])
                            <img src="{{ asset('storage/'.$store['profile_photo']) }}" alt="{{ $store['name'] }} profile photo">
                        @else
                            {{ strtoupper(substr($store['name'], 0, 1)) }}
                        @endif
                    </span>
                    <div><strong>{{ $store['name'] }}</strong><small>{{ $store['category'] }}</small></div>
                    <span class="buyer-store-status"><i></i>Active seller</span>
                </div>
                <p data-storefront-preview-description>{{ $store['description'] ?: 'Your store description will appear here.' }}</p>
                <div class="buyer-store-announcement" data-storefront-preview-announcement @if (!filled($store['announcement'])) hidden @endif>
                    <i data-lucide="megaphone"></i><span>{{ $store['announcement'] }}</span>
                </div>
                <small class="buyer-store-hours" data-storefront-preview-hours @if (!filled($store['operating_hours'])) hidden @endif>
                    <i data-lucide="clock-3"></i><span>{{ $store['operating_hours'] }}</span>
                </small>
                <div class="buyer-store-promo" data-storefront-preview-promo @if (count($store['promo_banners']) === 0) hidden @endif aria-label="Promotional banner carousel" aria-roledescription="carousel">
                    <div class="buyer-store-promo-track">
                        @foreach ($store['promo_banners'] as $index => $banner)
                            <div class="buyer-store-promo-slide{{ $index === 0 ? ' is-active' : '' }}" data-storefront-promo-slide data-slide-index="{{ $index }}" @if ($index > 0) hidden @endif>
                                <img src="{{ asset('storage/'.$banner['path']) }}" alt="{{ $banner['title'] ?: 'Promotional banner preview' }}">
                                <span class="buyer-store-promo-overlay" data-storefront-preview-promo-title @if (!filled($banner['title'])) hidden @endif>{{ $banner['title'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <button class="buyer-store-promo-arrow is-prev" type="button" aria-label="Previous promotional banner" data-promo-preview-prev><i data-lucide="chevron-left"></i></button>
                    <button class="buyer-store-promo-arrow is-next" type="button" aria-label="Next promotional banner" data-promo-preview-next><i data-lucide="chevron-right"></i></button>
                    <div class="buyer-store-promo-dots" aria-label="Promotional banner slides" data-promo-preview-dots>
                        @foreach ($store['promo_banners'] as $index => $banner)<button type="button" class="{{ $index === 0 ? 'is-active' : '' }}" aria-label="Show promotional banner {{ $index + 1 }}" data-slide-index="{{ $index }}"></button>@endforeach
                    </div>
                </div>
                <div class="buyer-store-tabs" role="tablist" aria-label="Store preview sections">
                    <button type="button" class="is-active" role="tab" aria-selected="true" data-preview-tab="products">Products</button>
                    <button type="button" role="tab" aria-selected="false" data-preview-tab="about">About</button>
                    <button type="button" role="tab" aria-selected="false" data-preview-tab="reviews">Reviews</button>
                </div>
                <div class="buyer-store-tab-panel" data-preview-panel="products"><div class="buyer-store-products"><i></i><i></i><i></i></div></div>
                <div class="buyer-store-tab-panel buyer-store-about" data-preview-panel="about" hidden>
                    <p class="buyer-store-about-intro">Store policies and buyer information</p>
                    <dl class="buyer-store-policy-list">
                        <div><dt><i data-lucide="truck"></i>Shipping</dt><dd data-preview-policy="shipping">{{ $store['shipping_policy'] ?: 'No shipping policy added yet.' }}</dd></div>
                        <div><dt><i data-lucide="rotate-ccw"></i>Returns and refunds</dt><dd data-preview-policy="returns">{{ $store['return_policy'] ?: 'No return and refund policy added yet.' }}</dd></div>
                        <div><dt><i data-lucide="shield-check"></i>Warranty</dt><dd data-preview-policy="warranty">{{ $store['warranty_policy'] ?: 'No warranty policy added yet.' }}</dd></div>
                    </dl>
                </div>
                <div class="buyer-store-tab-panel buyer-store-reviews" data-preview-panel="reviews" hidden>
                    <strong>Reviews</strong><p>Buyer reviews will appear here once your store receives orders.</p>
                </div>
            </div>
            <p class="storefront-preview-note"><i data-lucide="monitor"></i>This preview shows appearance only. Product listings are managed on the Products page.</p>
        </section>
    </aside>
</div>
</form>
@endsection
