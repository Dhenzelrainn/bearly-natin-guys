@extends('layouts.seller')

@section('title', 'Pricing & Promotions')
@section('page-title', 'Pricing & Promotions')

@section('content')
<div class="page-heading pricing-page-heading">
    <div><span class="section-kicker">Product growth</span><h2>Pricing & Promotions</h2><p>Control regular prices, product discounts, vouchers, and campaign performance.</p></div>
    <button class="seller-primary-button" type="button" data-pricing-open="campaign"><i data-lucide="plus"></i>Create Promotion</button>
</div>

<section class="pricing-summary-grid" aria-label="Pricing and promotions summary">
    @foreach ($summary as $item)
        <article class="pricing-summary-card"><span class="pricing-summary-icon tone-{{ $item['tone'] }}"><i data-lucide="{{ $item['icon'] }}"></i></span><div><small>{{ $item['label'] }}</small><strong>{{ $item['value'] }}</strong><p>{{ $item['note'] }}</p></div></article>
    @endforeach
</section>

<section class="pricing-workspace" data-pricing-workspace>
    <div class="pricing-tabs" role="tablist">
        <button class="pricing-tab is-active" type="button" data-pricing-tab="products" aria-selected="true">Product Pricing</button>
        <button class="pricing-tab" type="button" data-pricing-tab="campaigns" aria-selected="false">Promotions <span>{{ count($campaigns) }}</span></button>
        <button class="pricing-tab" type="button" data-pricing-tab="vouchers" aria-selected="false">Voucher Eligibility</button>
    </div>

    <section data-pricing-panel="products">
        <div class="pricing-toolbar"><label class="pricing-search"><i data-lucide="search"></i><input type="search" placeholder="Search product or SKU" data-pricing-search></label><select data-pricing-status aria-label="Filter pricing status"><option value="">All pricing statuses</option><option value="discounted">Discounted</option><option value="regular">Regular price</option></select><button type="button" data-pricing-reset><i data-lucide="rotate-ccw"></i>Reset</button></div>
        <div class="pricing-table-wrap"><table class="pricing-table"><thead><tr><th>Product</th><th>Regular Price</th><th>Sale Price</th><th>Discount</th><th>Voucher</th><th>Margin Check</th><th>Status</th><th>Action</th></tr></thead><tbody>
        @foreach ($products as $product)
            @php($salePrice = $product['price'] * (1 - ($product['discount_percent'] / 100)))
            @php($pricingState = $product['discount_percent'] > 0 ? 'discounted' : 'regular')
            <tr data-pricing-row data-state="{{ $pricingState }}" data-search="{{ strtolower($product['name'].' '.$product['sku']) }}">
                <td><div class="pricing-product-cell"><span><i data-lucide="package"></i></span><div><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }}</small></div></div></td>
                <td><strong>₱{{ number_format($product['price'], 2) }}</strong></td>
                <td><strong class="{{ $product['discount_percent'] > 0 ? 'pricing-sale-value' : '' }}">₱{{ number_format($salePrice, 2) }}</strong></td>
                <td>@if($product['discount_percent'] > 0)<span class="pricing-discount-badge">{{ $product['discount_percent'] }}% OFF</span>@else<span class="pricing-muted">—</span>@endif</td>
                <td><span class="pricing-voucher-state {{ $product['voucher_eligible'] ? 'is-eligible' : '' }}">{{ $product['voucher_eligible'] ? 'Eligible' : 'Not eligible' }}</span></td>
                <td><span class="pricing-margin-safe"><i data-lucide="shield-check"></i>Healthy</span></td>
                <td><span class="product-status status-{{ strtolower($product['status']) }}">{{ $product['status'] }}</span></td>
                <td><button class="pricing-row-action" type="button" data-pricing-open="price" data-product='@json($product)'><i data-lucide="pencil"></i>Edit Price</button></td>
            </tr>
        @endforeach
        </tbody></table><div class="pricing-empty" data-pricing-empty hidden><i data-lucide="search-x"></i><strong>No matching products</strong><span>Try another search or pricing status.</span></div></div>
        <footer class="pricing-footer"><span>Showing <strong data-pricing-count>{{ count($products) }}</strong> products</span><span>Sale price changes are reflected in product listings.</span></footer>
    </section>

    <section data-pricing-panel="campaigns" hidden>
        <div class="promotion-panel-heading"><div><h3>Promotion campaigns</h3><p>Schedule product discounts and monitor their sales contribution.</p></div><button type="button" data-pricing-open="campaign"><i data-lucide="plus"></i>New Campaign</button></div>
        <div class="campaign-grid">@foreach($campaigns as $campaign)<article class="campaign-card"><div class="campaign-card-top"><span class="campaign-type-icon"><i data-lucide="badge-percent"></i></span><span class="campaign-status is-{{ $campaign['status_key'] }}">{{ $campaign['status'] }}</span></div><h3>{{ $campaign['name'] }}</h3><p>{{ $campaign['type'] }} · {{ $campaign['period'] }}</p><dl><div><dt>Products</dt><dd>{{ $campaign['products'] }}</dd></div><div><dt>Attributed sales</dt><dd>{{ $campaign['sales'] }}</dd></div></dl><button type="button" data-pricing-demo="{{ $campaign['status'] === 'Active' ? 'Campaign manager' : 'Campaign report' }} opened for {{ $campaign['name'] }}.">{{ $campaign['status'] === 'Active' ? 'Manage Campaign' : 'View Report' }}<i data-lucide="arrow-right"></i></button></article>@endforeach</div>
    </section>

    <section data-pricing-panel="vouchers" hidden>
        <div class="promotion-panel-heading"><div><h3>Voucher eligibility</h3><p>Choose which active products buyers may use store vouchers on.</p></div><span class="pricing-info-pill"><i data-lucide="info"></i>Archived products cannot be eligible</span></div>
        <div class="voucher-product-list">@foreach($products as $product)<article><div class="pricing-product-cell"><span><i data-lucide="package"></i></span><div><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }} · ₱{{ number_format($product['price'], 2) }}</small></div></div><label class="pricing-switch"><input type="checkbox" {{ $product['voucher_eligible'] ? 'checked' : '' }} {{ $product['status'] === 'Archived' ? 'disabled' : '' }} data-voucher-toggle><i></i><span>{{ $product['voucher_eligible'] ? 'Eligible' : 'Not eligible' }}</span></label></article>@endforeach</div>
    </section>
</section>

<div class="seller-modal pricing-modal" data-modal="pricing-price" hidden><button class="modal-backdrop" type="button" data-modal-close aria-label="Close price editor"></button><section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="pricing-modal-title"><div class="modal-heading"><div><span class="section-kicker">Product pricing</span><h3 id="pricing-modal-title" data-price-product>Edit price</h3></div><button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button></div><div class="pricing-form-grid"><label><span>Regular price</span><div><b>₱</b><input type="number" min="0" step="0.01" data-price-regular></div></label><label><span>Discount</span><div><input type="number" min="0" max="90" step="1" data-price-discount><b>%</b></div></label></div><div class="pricing-calculation"><div><span>Buyer pays</span><strong data-price-sale>₱0.00</strong></div><div><span>Estimated platform fee (10%)</span><strong data-price-fee>₱0.00</strong></div><div class="is-net"><span>Estimated net revenue</span><strong data-price-net>₱0.00</strong></div></div><p class="pricing-form-note"><i data-lucide="shield-check"></i>Review net revenue before publishing a discount.</p><div class="modal-actions"><button class="draft-button" type="button" data-modal-close>Cancel</button><button class="seller-primary-button" type="button" data-price-save>Save Price</button></div></section></div>

<div class="seller-modal pricing-modal" data-modal="pricing-campaign" hidden><button class="modal-backdrop" type="button" data-modal-close aria-label="Close campaign creator"></button><section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="campaign-modal-title"><div class="modal-heading"><div><span class="section-kicker">New promotion</span><h3 id="campaign-modal-title">Create promotion</h3></div><button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button></div><div class="campaign-form"><label><span>Campaign name</span><input type="text" placeholder="e.g. 9.9 Payday Sale" data-campaign-name></label><label><span>Promotion type</span><select><option>Product Discount</option><option>Store Voucher</option></select></label><div><label><span>Start date</span><input type="date"></label><label><span>End date</span><input type="date"></label></div><label><span>Products</span><select><option>Choose eligible products</option><option>All active products</option></select></label></div><div class="modal-actions"><button class="draft-button" type="button" data-modal-close>Cancel</button><button class="seller-primary-button" type="button" data-campaign-save>Create Campaign</button></div></section></div>
@endsection
