@extends('layouts.seller')

@section('title', 'Create Promotion')
@section('page-title', 'Create Promotion')
@section('topbar-subtitle', 'Set the offer, schedule, eligible products, and redemption rules.')

@section('content')
@php
    $pricingDemoImages = [
        'Classic Linen Shirt' => asset('images/landing-page/products/cotton-tshirt.jpg'),
        'Canvas Tote Bag' => asset('images/landing-page/products/canvas-tote.jpg'),
        'Everyday Sneakers' => asset('images/landing-page/products/everyday-sneakers.jpg'),
    ];
@endphp

<div class="promotion-create-page">
    <div class="promotion-create-toolbar">
        <a class="promotion-back-link" href="{{ route('seller.products.pricing') }}">
            <i data-lucide="arrow-left" aria-hidden="true"></i>
            Pricing & Promotions
        </a>
        <div class="promotion-create-actions">
            <a class="promotion-cancel-link" href="{{ route('seller.products.pricing') }}">Cancel</a>
            <button class="seller-primary-button" type="submit" form="promotion-create-form">
                <i data-lucide="badge-percent" aria-hidden="true"></i>
                Create Promotion
            </button>
        </div>
    </div>

    <form id="promotion-create-form" class="promotion-create-layout" data-promotion-form data-success-url="{{ route('seller.products.pricing', ['tab' => 'campaigns', 'created' => 1]) }}">
        <div class="promotion-form-surface">
            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Campaign details</h2>
                    <p>Use a clear campaign name and choose the offer buyers will receive.</p>
                </div>
                <div class="promotion-fields-grid">
                    <label class="promotion-field is-wide">
                        <span>Promotion name</span>
                        <input type="text" name="name" maxlength="80" placeholder="e.g. September Payday Sale" required data-promotion-name>
                    </label>
                    <label class="promotion-field">
                        <span>Promotion type</span>
                        <select name="type" required data-promotion-type>
                            <option value="Product Discount">Product Discount</option>
                            <option value="Store Voucher">Store Voucher</option>
                        </select>
                    </label>
                    <label class="promotion-field">
                        <span data-promotion-value-label>Discount percentage</span>
                        <div class="promotion-value-control">
                            <input type="number" name="value" min="1" max="90" step="1" value="10" required data-promotion-value>
                            <b data-promotion-value-suffix>%</b>
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
                    <label class="promotion-field"><span>Start date</span><input type="date" name="start_date" required data-promotion-start></label>
                    <label class="promotion-field"><span>End date</span><input type="date" name="end_date" required data-promotion-end></label>
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
                    @foreach ($products as $product)
                        @php($demoImage = $pricingDemoImages[$product['name']] ?? null)
                        <label class="promotion-product-row">
                            <input type="checkbox" name="products[]" value="{{ $product['id'] }}" data-promotion-product>
                            <span class="promotion-product-meta">
                                <span class="promotion-product-thumb">
                                    @if ($product['image'])
                                        <img src="{{ asset('storage/'.$product['image']) }}" alt="{{ $product['name'] }}">
                                    @elseif ($demoImage)
                                        <img src="{{ $demoImage }}" alt="{{ $product['name'] }}">
                                    @else
                                        <i data-lucide="package"></i>
                                    @endif
                                </span>
                                <span><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] ?: 'No SKU assigned' }}</small></span>
                            </span>
                            <span class="promotion-product-price">₱{{ number_format($product['price'], 2) }}</span>
                            <span class="promotion-product-status">{{ $product['status'] }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="promotion-form-section">
                <div class="promotion-section-heading">
                    <h2>Redemption rules</h2>
                    <p>Keep limits simple so the promotion is easy to understand and manage.</p>
                </div>
                <div class="promotion-fields-grid">
                    <label class="promotion-field"><span>Minimum order spend</span><input type="number" name="minimum_spend" min="0" step="1" placeholder="0"></label>
                    <label class="promotion-field"><span>Total redemption limit</span><input type="number" name="redemption_limit" min="1" step="1" placeholder="No limit"></label>
                    <label class="promotion-field">
                        <span>Per buyer limit</span>
                        <select name="buyer_limit">
                            <option value="1">1 redemption</option><option value="2">2 redemptions</option><option value="3">3 redemptions</option>
                        </select>
                    </label>
                </div>
            </section>
        </div>

        <aside class="promotion-summary" aria-label="Promotion summary">
            <h3>Promotion summary</h3>
            <p>Review the main rules before creating the promotion.</p>
            <dl>
                <div><dt>Name</dt><dd data-promotion-summary-name>Untitled promotion</dd></div>
                <div><dt>Type</dt><dd data-promotion-summary-type>Product Discount</dd></div>
                <div><dt>Offer</dt><dd data-promotion-summary-value>10% off</dd></div>
                <div><dt>Products</dt><dd data-promotion-summary-products>0 selected</dd></div>
                <div><dt>Schedule</dt><dd data-promotion-summary-schedule>Not set</dd></div>
            </dl>
            <p class="promotion-summary-note">Pricing and promotions belong to the seller inventory workflow, so product eligibility and stock should be checked before a campaign is published.</p>
        </aside>
    </form>
</div>
@endsection