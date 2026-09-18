@extends('layouts.seller')

@section('title', 'Pricing & Promotions')
@section('page-title', 'Pricing & Promotions')

@section('content')
@php
    $pricingDemoImages = [
        'Classic Linen Shirt' => asset('images/landing-page/products/cotton-tshirt.jpg'),
        'Canvas Tote Bag' => asset('images/landing-page/products/canvas-tote.jpg'),
        'Everyday Sneakers' => asset('images/landing-page/products/everyday-sneakers.jpg'),
    ];
@endphp

<div class="page-heading pricing-page-heading">
    <div class="pricing-heading-copy">
        <span class="section-kicker">Product growth</span>
        <h2>Pricing & Promotions</h2>
        <p>Control regular prices, product discounts, vouchers, and campaign performance.</p>
    </div>

    <button
        class="seller-primary-button pricing-create-button"
        type="button"
        data-pricing-open="campaign"
    >
        <i data-lucide="plus"></i>
        <span>Create Promotion</span>
    </button>
</div>

<section class="pricing-summary-grid" aria-label="Pricing and promotions summary">
    @foreach ($summary as $item)
        <article class="pricing-summary-card">
            <span class="pricing-summary-icon tone-{{ $item['tone'] }}" aria-hidden="true">
                <i data-lucide="{{ $item['icon'] }}"></i>
            </span>

            <div class="pricing-summary-copy">
                <span>{{ $item['label'] }}</span>
                <strong>{{ $item['value'] }}</strong>
                <small>{{ $item['note'] }}</small>
            </div>
        </article>
    @endforeach
</section>

<section class="pricing-workspace" data-pricing-workspace>
    <div class="pricing-tabs" role="tablist" aria-label="Pricing workspace">
        <button
            class="pricing-tab is-active"
            type="button"
            role="tab"
            aria-selected="true"
            data-pricing-tab="products"
        >
            Product Pricing
        </button>

        <button
            class="pricing-tab"
            type="button"
            role="tab"
            aria-selected="false"
            data-pricing-tab="campaigns"
        >
            Promotions
            <span>{{ count($campaigns) }}</span>
        </button>

        <button
            class="pricing-tab"
            type="button"
            role="tab"
            aria-selected="false"
            data-pricing-tab="vouchers"
        >
            Voucher Eligibility
        </button>
    </div>

    <section data-pricing-panel="products">
        <div class="pricing-toolbar">
            <label class="pricing-search">
                <i data-lucide="search" aria-hidden="true"></i>
                <span class="sr-only">Search product or SKU</span>
                <input
                    type="search"
                    placeholder="Search product or SKU"
                    autocomplete="off"
                    data-pricing-search
                >
            </label>

            <label class="pricing-status-filter">
                <span class="sr-only">Filter pricing status</span>
                <select data-pricing-status aria-label="Filter pricing status">
                    <option value="">All pricing statuses</option>
                    <option value="discounted">Discounted</option>
                    <option value="regular">Regular price</option>
                </select>
            </label>

            <button class="pricing-reset" type="button" data-pricing-reset>
                <i data-lucide="rotate-ccw" aria-hidden="true"></i>
                Reset
            </button>
        </div>

        <div class="pricing-table-wrap">
            <table class="pricing-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Regular Price</th>
                        <th>Sale Price</th>
                        <th>Discount</th>
                        <th>Voucher</th>
                        <th>Margin Check</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($products as $product)
                        @php
                            $salePrice = $product['price'] * (1 - ($product['discount_percent'] / 100));
                            $pricingState = $product['discount_percent'] > 0 ? 'discounted' : 'regular';
                            $demoImage = $pricingDemoImages[$product['name']] ?? null;
                        @endphp

                        <tr
                            data-pricing-row
                            data-state="{{ $pricingState }}"
                            data-search="{{ strtolower($product['name'].' '.$product['sku']) }}"
                        >
                            <td>
                                <div class="pricing-product-cell">
                                    <span class="pricing-product-image">
                                        @if ($product['image'])
                                            <img
                                                src="{{ asset('storage/'.$product['image']) }}"
                                                alt="{{ $product['name'] }}"
                                            >
                                        @elseif ($demoImage)
                                            <img
                                                src="{{ $demoImage }}"
                                                alt="{{ $product['name'] }}"
                                            >
                                        @else
                                            <i data-lucide="package"></i>
                                        @endif
                                    </span>

                                    <span class="pricing-product-copy">
                                        <strong>{{ $product['name'] }}</strong>
                                        <small>{{ $product['sku'] ?: 'No SKU assigned' }}</small>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <strong class="pricing-regular-value">
                                    ₱{{ number_format($product['price'], 2) }}
                                </strong>
                            </td>

                            <td>
                                <strong class="{{ $product['discount_percent'] > 0 ? 'pricing-sale-value' : 'pricing-regular-value' }}">
                                    ₱{{ number_format($salePrice, 2) }}
                                </strong>
                            </td>

                            <td>
                                @if ($product['discount_percent'] > 0)
                                    <span class="pricing-discount-badge">
                                        {{ $product['discount_percent'] }}% OFF
                                    </span>
                                @else
                                    <span class="pricing-muted">—</span>
                                @endif
                            </td>

                            <td>
                                <span class="pricing-voucher-state {{ $product['voucher_eligible'] ? 'is-eligible' : '' }}">
                                    {{ $product['voucher_eligible'] ? 'Eligible' : 'Not eligible' }}
                                </span>
                            </td>

                            <td>
                                <span class="pricing-margin-safe">
                                    <i data-lucide="shield-check" aria-hidden="true"></i>
                                    Healthy
                                </span>
                            </td>

                            <td>
                                <span class="product-status status-{{ strtolower($product['status']) }}">
                                    {{ $product['status'] }}
                                </span>
                            </td>

                            <td>
                                <button
                                    class="pricing-row-action"
                                    type="button"
                                    data-pricing-open="price"
                                    data-product='@json($product)'
                                >
                                    <i data-lucide="pencil" aria-hidden="true"></i>
                                    Edit Price
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="pricing-empty" data-pricing-empty hidden>
                <i data-lucide="search-x" aria-hidden="true"></i>
                <strong>No matching products</strong>
                <span>Try another search or pricing status.</span>
            </div>
        </div>

        <footer class="pricing-footer">
            <span>
                Showing <strong data-pricing-count>{{ count($products) }}</strong> products
            </span>
            <span>Sale price changes are reflected in product listings.</span>
        </footer>
    </section>

    <section data-pricing-panel="campaigns" hidden>
        <div class="promotion-panel-heading">
            <div>
                <span class="section-kicker">Campaign management</span>
                <h3>Promotion campaigns</h3>
                <p>Schedule discounts and review the sales contribution of each campaign.</p>
            </div>

            <button type="button" data-pricing-open="campaign">
                <i data-lucide="plus"></i>
                New Campaign
            </button>
        </div>

        <div class="campaign-grid">
            @foreach($campaigns as $campaign)
                <article class="campaign-card">
                    <div class="campaign-card-top">
                        <span class="campaign-type-icon">
                            <i data-lucide="badge-percent"></i>
                        </span>

                        <span class="campaign-status is-{{ $campaign['status_key'] }}">
                            {{ $campaign['status'] }}
                        </span>
                    </div>

                    <h3>{{ $campaign['name'] }}</h3>
                    <p>{{ $campaign['type'] }} · {{ $campaign['period'] }}</p>

                    <dl>
                        <div>
                            <dt>Products</dt>
                            <dd>{{ $campaign['products'] }}</dd>
                        </div>
                        <div>
                            <dt>Attributed sales</dt>
                            <dd>{{ $campaign['sales'] }}</dd>
                        </div>
                    </dl>

                    <button
                        type="button"
                        data-pricing-demo="{{ $campaign['status'] === 'Active' ? 'Campaign manager' : 'Campaign report' }} opened for {{ $campaign['name'] }}."
                    >
                        {{ $campaign['status'] === 'Active' ? 'Manage Campaign' : 'View Report' }}
                        <i data-lucide="arrow-right"></i>
                    </button>
                </article>
            @endforeach
        </div>
    </section>

    <section data-pricing-panel="vouchers" hidden>
        <div class="promotion-panel-heading">
            <div>
                <span class="section-kicker">Voucher rules</span>
                <h3>Voucher eligibility</h3>
                <p>Choose which active products buyers may use store vouchers on.</p>
            </div>

            <span class="pricing-info-pill">
                <i data-lucide="info"></i>
                Archived products cannot be eligible
            </span>
        </div>

        <div class="voucher-product-list">
            @foreach($products as $product)
                @php($demoImage = $pricingDemoImages[$product['name']] ?? null)

                <article>
                    <div class="pricing-product-cell">
                        <span class="pricing-product-image">
                            @if ($product['image'])
                                <img
                                    src="{{ asset('storage/'.$product['image']) }}"
                                    alt="{{ $product['name'] }}"
                                >
                            @elseif ($demoImage)
                                <img
                                    src="{{ $demoImage }}"
                                    alt="{{ $product['name'] }}"
                                >
                            @else
                                <i data-lucide="package"></i>
                            @endif
                        </span>

                        <span class="pricing-product-copy">
                            <strong>{{ $product['name'] }}</strong>
                            <small>
                                {{ $product['sku'] ?: 'No SKU assigned' }}
                                · ₱{{ number_format($product['price'], 2) }}
                            </small>
                        </span>
                    </div>

                    <label class="pricing-switch">
                        <input
                            type="checkbox"
                            {{ $product['voucher_eligible'] ? 'checked' : '' }}
                            {{ $product['status'] === 'Archived' ? 'disabled' : '' }}
                            data-voucher-toggle
                        >
                        <i aria-hidden="true"></i>
                        <span>{{ $product['voucher_eligible'] ? 'Eligible' : 'Not eligible' }}</span>
                    </label>
                </article>
            @endforeach
        </div>
    </section>
</section>

<div class="seller-modal pricing-modal" data-modal="pricing-price" hidden>
    <button
        class="modal-backdrop"
        type="button"
        data-modal-close
        aria-label="Close price editor"
    ></button>

    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pricing-modal-title"
    >
        <div class="modal-heading">
            <div>
                <span class="section-kicker">Product pricing</span>
                <h3 id="pricing-modal-title" data-price-product>Edit price</h3>
            </div>

            <button type="button" data-modal-close aria-label="Close">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="pricing-form-grid">
            <label>
                <span>Regular price</span>
                <div>
                    <b>₱</b>
                    <input type="number" min="0" step="0.01" data-price-regular>
                </div>
            </label>

            <label>
                <span>Discount</span>
                <div>
                    <input type="number" min="0" max="90" step="1" data-price-discount>
                    <b>%</b>
                </div>
            </label>
        </div>

        <div class="pricing-calculation">
            <div>
                <span>Buyer pays</span>
                <strong data-price-sale>₱0.00</strong>
            </div>
            <div>
                <span>Estimated platform fee (10%)</span>
                <strong data-price-fee>₱0.00</strong>
            </div>
            <div class="is-net">
                <span>Estimated net revenue</span>
                <strong data-price-net>₱0.00</strong>
            </div>
        </div>

        <p class="pricing-form-note">
            <i data-lucide="shield-check"></i>
            Review net revenue before publishing a discount.
        </p>

        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>
                Cancel
            </button>
            <button class="seller-primary-button" type="button" data-price-save>
                Save Price
            </button>
        </div>
    </section>
</div>

<div class="seller-modal pricing-modal" data-modal="pricing-campaign" hidden>
    <button
        class="modal-backdrop"
        type="button"
        data-modal-close
        aria-label="Close campaign creator"
    ></button>

    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="campaign-modal-title"
    >
        <div class="modal-heading">
            <div>
                <span class="section-kicker">New promotion</span>
                <h3 id="campaign-modal-title">Create promotion</h3>
            </div>

            <button type="button" data-modal-close aria-label="Close">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="campaign-form">
            <label>
                <span>Campaign name</span>
                <input
                    type="text"
                    placeholder="e.g. 9.9 Payday Sale"
                    required
                    data-campaign-name
                >
            </label>

            <label>
                <span>Promotion type</span>
                <select>
                    <option>Product Discount</option>
                    <option>Store Voucher</option>
                </select>
            </label>

            <div>
                <label>
                    <span>Start date</span>
                    <input type="date">
                </label>

                <label>
                    <span>End date</span>
                    <input type="date">
                </label>
            </div>

            <label>
                <span>Products</span>
                <select>
                    <option>Choose eligible products</option>
                    <option>All active products</option>
                </select>
            </label>
        </div>

        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>
                Cancel
            </button>
            <button class="seller-primary-button" type="button" data-campaign-save>
                Create Campaign
            </button>
        </div>
    </section>
</div>
@endsection
