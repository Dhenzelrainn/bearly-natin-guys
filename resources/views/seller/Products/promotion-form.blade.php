@extends('layouts.seller')

@php($selectedPromotionProducts = collect(old('products', []))->map(fn ($id) => (string) $id)->all())

@section('title', 'Create Promotion')
@section('page-title', 'Create Promotion')
@section('topbar-subtitle', 'Create a Store Voucher campaign for your Seller Center.')

@section('content')
<div class="promotion-create-page">
    <div class="promotion-create-toolbar">
        <a class="promotion-back-link" href="{{ route('seller.products.pricing') }}">
            <i data-lucide="arrow-left" aria-hidden="true"></i>
            Pricing & Promotions
        </a>
        <div class="promotion-create-actions">
            <a class="promotion-cancel-link" href="{{ route('seller.products.pricing') }}">Cancel</a>
            <button class="seller-primary-button" type="submit" form="promotion-create-form" @disabled($products->isEmpty())>
                <i data-lucide="badge-percent" aria-hidden="true"></i>
                Save Promotion
            </button>
        </div>
    </div>

    <p class="promotion-scope-notice" role="note">Saved campaigns are currently for Seller Center tracking only. They do not change Buyer prices or redeem Store Vouchers yet.</p>

    <form id="promotion-create-form" class="promotion-create-layout" method="POST" action="{{ route('seller.products.pricing.store') }}" data-promotion-form>
        @csrf
        <div class="promotion-form-surface">
            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Campaign details</h2>
                    <p>Set up a Store Voucher here. Manage product discounts in Product Pricing.</p>
                </div>
                <div class="promotion-fields-grid">
                    <label class="promotion-field is-wide">
                        <span>Promotion name</span>
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="80" placeholder="e.g. September Payday Sale" required data-promotion-name>
                    </label>
                    <label class="promotion-field">
                        <span>Campaign type</span>
                        <input type="text" value="Store Voucher" readonly aria-label="Campaign type">
                        <input type="hidden" name="type" value="store_voucher">
                    </label>
                    <label class="promotion-field">
                        <span>Voucher amount</span>
                        <div class="promotion-value-control">
                            <input type="number" name="value" min="0.01" step="0.01" value="{{ old('value') }}" placeholder="0.00" required data-promotion-value>
                            <b>₱</b>
                        </div>
                    </label>
                </div>
            </section>

            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Schedule</h2>
                    <p>Set when the promotion becomes available and when it ends.</p>
                </div>
                <div class="promotion-fields-grid">
                    <label class="promotion-field"><span>Start date</span><input type="date" name="start_date" value="{{ old('start_date') }}" required data-promotion-start></label>
                    <label class="promotion-field"><span>End date</span><input type="date" name="end_date" value="{{ old('end_date') }}" required data-promotion-end></label>
                </div>
            </section>

            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Eligible products</h2>
                    <p>Select the products included in this promotion.</p>
                </div>
                <div class="promotion-product-picker">
                    <div class="promotion-product-picker-head">
                        <input type="checkbox" aria-label="Select all products" data-promotion-select-all>
                        <span>Product</span><span>Price</span><span>Status</span>
                    </div>
                    @forelse ($products as $product)
                        <label class="promotion-product-row">
                            <input type="checkbox" name="products[]" value="{{ $product['id'] }}" @checked(in_array((string) $product['id'], $selectedPromotionProducts, true)) data-promotion-product>
                            <span class="promotion-product-meta">
                                <span class="promotion-product-thumb">
                                    @if ($product['image_url'])
                                        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" loading="lazy">
                                    @else
                                        <i data-lucide="package"></i>
                                    @endif
                                </span>
                                <span><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] ?: 'No SKU assigned' }}</small></span>
                            </span>
                            <span class="promotion-product-price">₱{{ number_format($product['price'], 2) }}</span>
                            <span class="promotion-product-status">{{ $product['status'] }}</span>
                        </label>
                    @empty
                        <p class="promotion-product-empty">Add a product with an active SKU before creating a campaign.</p>
                    @endforelse
                </div>
            </section>

            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Redemption rules</h2>
                    <p>Keep limits simple so the promotion is easy to understand and manage.</p>
                </div>
                <div class="promotion-fields-grid">
                    <label class="promotion-field"><span>Minimum order spend (₱)</span><input type="number" name="minimum_spend" value="{{ old('minimum_spend') }}" min="0" step="0.01" placeholder="0.00"></label>
                    <label class="promotion-field"><span>Total redemption limit</span><input type="number" name="redemption_limit" value="{{ old('redemption_limit') }}" min="1" step="1" placeholder="No limit"></label>
                    <label class="promotion-field">
                        <span>Per buyer limit</span>
                        <select name="buyer_limit">
                            <option value="1" @selected(old('buyer_limit', '1') === '1')>1 redemption</option><option value="2" @selected(old('buyer_limit') === '2')>2 redemptions</option><option value="3" @selected(old('buyer_limit') === '3')>3 redemptions</option>
                        </select>
                    </label>
                </div>
            </section>
        </div>

        <aside class="promotion-summary" aria-label="Promotion summary">
            <h3>Promotion summary</h3>
            <p>Review the campaign details before saving.</p>
            <dl>
                <div><dt>Name</dt><dd data-promotion-summary-name>Untitled promotion</dd></div>
                <div><dt>Type</dt><dd>Store Voucher</dd></div>
                <div><dt>Offer</dt><dd data-promotion-summary-value>Enter voucher amount</dd></div>
                <div><dt>Products</dt><dd data-promotion-summary-products>0 selected</dd></div>
                <div><dt>Schedule</dt><dd data-promotion-summary-schedule>Not set</dd></div>
            </dl>
            <p class="promotion-summary-note">Campaigns are stored for Seller Center tracking. Buyer pricing and voucher redemption are not connected yet.</p>
        </aside>
    </form>
</div>
@endsection
