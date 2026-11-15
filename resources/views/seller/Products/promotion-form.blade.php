@extends('layouts.seller')

@php
    $selectedPromotionProducts = collect(old('products', []))->map(fn ($id) => (string) $id)->all();
    $startDate = old('start_date', now('Asia/Manila')->toDateString());
    $endDate = old('end_date', now('Asia/Manila')->addDays(7)->toDateString());
    $startTime = old('start_time', now('Asia/Manila')->format('H:i'));
    $endTime = old('end_time', '23:59');
    $eligibleVoucherProductCount = $products->where('voucher_eligible', true)->count();
@endphp

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
            <button class="seller-primary-button" type="submit" form="promotion-create-form" @disabled($eligibleVoucherProductCount === 0)>
                <i data-lucide="badge-percent" aria-hidden="true"></i>
                Save Promotion
            </button>
        </div>
    </div>

    <p class="promotion-scope-notice" role="note">Store Vouchers are funded by your Store. Public claimable vouchers appear in My Vouchers; code-only vouchers can be applied at checkout. One store voucher can stack with one Bearly-funded platform voucher.</p>

    <form id="promotion-create-form" class="promotion-create-layout" method="POST" action="{{ $formAction ?? route('seller.products.pricing.store') }}" data-promotion-form>
        @csrf
        <div class="promotion-form-surface">
            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Campaign details</h2>
                    <p>Create a Store Voucher here. Its name is generated from the offer; manage scheduled Product Discounts on the Product Discounts tab in Pricing &amp; Promotions.</p>
                </div>
                <div class="promotion-fields-grid">
                    <label class="promotion-field">
                        <span>Campaign type</span>
                        <input type="text" value="Store Voucher" readonly aria-label="Campaign type">
                        <input type="hidden" name="type" value="store_voucher">
                    </label>
                    <label class="promotion-field">
                        <span>Voucher code <small class="promotion-field-optional">Optional</small></span>
                        <input type="text" name="code" value="{{ old('code') }}" maxlength="40" placeholder="e.g. SAVE50" autocomplete="off">
                    </label>
                    <label class="promotion-field">
                        <span>Discount type</span>
                        <select name="discount_type" data-promotion-discount-type>
                            <option value="fixed" @selected(old('discount_type', 'fixed') === 'fixed')>Fixed amount</option>
                            <option value="percent" @selected(old('discount_type') === 'percent')>Percentage</option>
                        </select>
                    </label>
                    <label class="promotion-field" data-promotion-fixed-field>
                        <span>Voucher amount</span>
                        <div class="promotion-value-control">
                            <input type="number" name="value" min="0.01" step="0.01" value="{{ old('value') }}" placeholder="0.00" data-promotion-value>
                            <b>₱</b>
                        </div>
                    </label>
                    <label class="promotion-field" data-promotion-percent-field hidden>
                        <span>Discount percentage</span>
                        <div class="promotion-value-control">
                            <input type="number" name="discount_percent" min="1" max="100" step="1" value="{{ old('discount_percent') }}" placeholder="15" data-promotion-percent>
                            <b>%</b>
                        </div>
                    </label>
                    <label class="promotion-field">
                        <span>Maximum discount <small class="promotion-field-optional">Optional for percentage</small></span>
                        <div class="promotion-value-control">
                            <input type="number" name="max_discount" min="0" step="0.01" value="{{ old('max_discount') }}" placeholder="0.00">
                            <b>₱</b>
                        </div>
                    </label>
                </div>
            </section>

            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Schedule</h2>
                    <p>Times use Philippines time (PHT). The selected end minute is included.</p>
                </div>
                <div class="promotion-fields-grid">
                    <label class="promotion-field"><span>Start date</span><input type="date" name="start_date" value="{{ $startDate }}" required data-promotion-start></label>
                    <label class="promotion-field"><span>Start time (PHT)</span><input type="time" name="start_time" value="{{ $startTime }}" required data-promotion-start-time></label>
                    <label class="promotion-field"><span>End date</span><input type="date" name="end_date" value="{{ $endDate }}" required data-promotion-end></label>
                    <label class="promotion-field"><span>End time (PHT)</span><input type="time" name="end_time" value="{{ $endTime }}" required data-promotion-end-time></label>
                </div>
            </section>

            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Eligible products</h2>
                    <p>Select products enabled for Store Vouchers in Product Management. Ineligible products are shown but cannot be selected.</p>
                </div>
                <div class="promotion-product-picker">
                    <div class="promotion-product-picker-head">
                        <input type="checkbox" aria-label="Select all Store Voucher-eligible products" data-promotion-select-all @disabled($eligibleVoucherProductCount === 0)>
                        <span>Product</span><span>Price</span><span>Status</span>
                    </div>
                    @forelse ($products as $product)
                        @php($voucherEligible = (bool) ($product['voucher_eligible'] ?? false))
                        <label class="promotion-product-row {{ $voucherEligible ? '' : 'is-unavailable' }}" @unless ($voucherEligible) title="Enable Store Voucher eligibility in Product Management to include this product." @endunless>
                            <input type="checkbox" name="products[]" value="{{ $product['id'] }}" @checked($voucherEligible && in_array((string) $product['id'], $selectedPromotionProducts, true)) @disabled(! $voucherEligible) data-promotion-product>
                            <span class="promotion-product-meta">
                                <span class="promotion-product-thumb">
                                    @if ($product['image_url'])
                                        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" loading="lazy">
                                    @else
                                        <i data-lucide="package"></i>
                                    @endif
                                </span>
                                <span>
                                    <strong>{{ $product['name'] }}</strong>
                                    <small>{{ $product['sku'] ?: 'No SKU assigned' }}</small>
                                    @unless ($voucherEligible)<small class="promotion-product-unavailable-note">Not eligible for Store Vouchers · Enable in Product Management</small>@endunless
                                </span>
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
                            @for ($limit = 1; $limit <= 10; $limit++)
                                <option value="{{ $limit }}" @selected((int) old('buyer_limit', 1) === $limit)>{{ $limit }} redemption{{ $limit > 1 ? 's' : '' }}</option>
                            @endfor
                        </select>
                    </label>
                    <label class="promotion-field is-wide">
                        <span>Terms and conditions <small class="promotion-field-optional">Optional</small></span>
                        <textarea name="terms" rows="3" maxlength="2000" placeholder="Explain eligible items, stacking, and redemption rules.">{{ old('terms') }}</textarea>
                    </label>
                    <input type="hidden" name="is_public" value="0">
                    <label class="promotion-field is-wide promotion-visibility-option">
                        <input type="checkbox" name="is_public" value="1" @checked((bool) old('is_public', true))>
                        <span>Show this Store Voucher publicly in My Vouchers and Buyer offers.</span>
                    </label>
                    <input type="hidden" name="is_claimable" value="0">
                    <label class="promotion-field is-wide promotion-visibility-option">
                        <input type="checkbox" name="is_claimable" value="1" @checked((bool) old('is_claimable', true))>
                        <span>Allow Buyers to claim it in their voucher wallet. Turn off for code-only vouchers.</span>
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
            <p class="promotion-summary-note">Visibility and wallet claiming follow your selected settings. Redemptions are deducted from your Seller earnings and appear in your reports.</p>
        </aside>
    </form>
</div>
@endsection
