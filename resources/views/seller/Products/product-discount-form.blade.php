@extends('layouts.seller')

@php
    $selected = collect(old('products', $selectedProducts ?? []))->map(fn ($id) => (string) $id)->all();
    $startsOn = old('start_date', $promotion?->starts_on?->toDateString() ?? now('Asia/Manila')->toDateString());
    $endsOn = old('end_date', $promotion?->ends_on?->toDateString() ?? now('Asia/Manila')->addDays(7)->toDateString());
    $startsTime = old('start_time', $promotion ? substr((string) $promotion->starts_time, 0, 5) : now('Asia/Manila')->format('H:i'));
    $endsTime = old('end_time', $promotion ? substr((string) $promotion->ends_time, 0, 5) : '23:59');
    $discountPercent = old('discount_percent', $promotion?->discount_percent ?? 20);
@endphp

@section('title', $promotion ? 'Edit Product Discount' : 'Create Product Discount')
@section('page-title', $promotion ? 'Edit Product Discount' : 'Create Product Discount')
@section('topbar-subtitle', 'Schedule a Seller-funded automatic price reduction.')

@section('content')
<div class="promotion-create-page" data-product-discount-form>
    <div class="promotion-create-toolbar">
        <a class="promotion-back-link" href="{{ route('seller.products.pricing', ['tab' => 'product-discounts']) }}">
            <i data-lucide="arrow-left" aria-hidden="true"></i>
            Pricing & Promotions
        </a>
        <div class="promotion-create-actions">
            <a class="promotion-cancel-link" href="{{ route('seller.products.pricing', ['tab' => 'product-discounts']) }}">Cancel</a>
            <button class="seller-primary-button" type="submit" form="product-discount-form" @disabled($products->isEmpty())>
                <i data-lucide="badge-percent" aria-hidden="true"></i>
                {{ $promotion ? 'Save changes' : 'Schedule discount' }}
            </button>
        </div>
    </div>

    <p class="promotion-scope-notice">
        <i data-lucide="store" aria-hidden="true"></i>
        This discount belongs only to your Store. Active orders keep their original price and discount snapshots.
    </p>

    @if ($errors->any())
        <div class="seller-alert is-danger" role="alert">
            <strong>Check the product discount details.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="product-discount-form" class="promotion-create-layout promotion-create-stack" method="POST" action="{{ $formAction }}">
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <section class="promotion-create-card">
            <div class="promotion-create-card-heading">
                <div>
                    <span class="section-kicker">Offer</span>
                    <h2>Discount details</h2>
                </div>
            </div>

            <div class="promotion-form-grid">
                <label class="promotion-field is-wide">
                    <span>Discount name</span>
                    <input type="text" name="name" maxlength="80" required value="{{ old('name', $promotion?->name ?? '') }}" placeholder="e.g. Weekend markdown">
                </label>
                <label class="promotion-field is-wide">
                    <span>Buyer-facing sale name <small class="promotion-field-optional">Optional</small></span>
                    <input type="text" name="buyer_campaign_name" maxlength="80" value="{{ old('buyer_campaign_name', $promotion?->buyer_campaign_name ?? '') }}" placeholder="e.g. MacBook Drop Sale">
                    <small>Shown on eligible product pages while this discount is active. Leave blank to show the price and discount badge only.</small>
                </label>
                <label class="promotion-field">
                    <span>Discount percentage</span>
                    <div class="promotion-input-shell">
                        <input type="number" name="discount_percent" min="1" max="90" step="1" required value="{{ $discountPercent }}" data-discount-percent>
                        <span class="promotion-input-suffix">%</span>
                    </div>
                    <small>1–90%. While active, this scheduled discount replaces existing Seller sale pricing; it never stacks with another Product Discount.</small>
                </label>
                <label class="promotion-field">
                    <span>Starts on</span>
                    <input type="date" name="start_date" required min="{{ $promotion?->starts_on?->toDateString() ?? now('Asia/Manila')->toDateString() }}" value="{{ $startsOn }}">
                </label>
                <label class="promotion-field">
                    <span>Start time (PHT)</span>
                    <input type="time" name="start_time" required value="{{ $startsTime }}">
                </label>
                <label class="promotion-field">
                    <span>Ends on</span>
                    <input type="date" name="end_date" required min="{{ $startsOn }}" value="{{ $endsOn }}">
                </label>
                <label class="promotion-field">
                    <span>End time (PHT)</span>
                    <input type="time" name="end_time" required value="{{ $endsTime }}">
                </label>
                <p class="promotion-form-schedule-note">Times use Philippines time (PHT). The selected end minute is included.</p>
            </div>
        </section>

        <section class="promotion-create-card">
            <div class="promotion-create-card-heading">
                <div>
                    <span class="section-kicker">Scope</span>
                    <h2>Select products</h2>
                    <p>Only products from your Store with active SKUs can receive this discount.</p>
                </div>
                <span class="promotion-scope-note-compact"><strong data-selected-product-count>{{ count($selected) }}</strong> selected</span>
            </div>

            <div class="promotion-product-list" data-discount-product-list>
                @forelse ($products as $product)
                    @php($checked = in_array((string) $product['id'], $selected, true))
                    <label class="promotion-product-option" data-discount-product data-price="{{ $product['price'] }}" data-discount="{{ $product['discount_percent'] }}">
                        <input type="checkbox" name="products[]" value="{{ $product['id'] }}" @checked($checked)>
                        <span class="promotion-product-copy">
                            <strong>{{ $product['name'] }}</strong>
                            <small>{{ $product['sku'] ?: 'No SKU assigned' }} · {{ count($product['variants']) }} active SKU variant{{ count($product['variants']) === 1 ? '' : 's' }}</small>
                        </span>
                        <span class="promotion-product-price">
                            <span>Regular</span>
                            <strong>₱{{ number_format($product['price'], 2) }}</strong>
                            <small data-discount-preview>Promo ₱{{ number_format($product['price'] * (1 - ((int) $discountPercent / 100)), 2) }}</small>
                        </span>
                    </label>
                @empty
                    <div class="promotion-empty-state">Add a product with an active SKU before creating a product discount.</div>
                @endforelse
            </div>
        </section>

        <section class="promotion-create-card promotion-create-submit">
            <div>
                <strong>Net preview</strong>
                <p>Product discounts reduce the amount you earn before commission. Buyer discounts are applied automatically at checkout.</p>
            </div>
            <button class="seller-primary-button" type="submit" @disabled($products->isEmpty())>
                <i data-lucide="{{ $promotion ? 'save' : 'calendar-plus' }}" aria-hidden="true"></i>
                {{ $promotion ? 'Save changes' : 'Schedule discount' }}
            </button>
        </section>
    </form>
</div>
@endsection
