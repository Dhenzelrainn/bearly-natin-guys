@extends('layouts.seller')

@section('title', 'Pricing & Promotions')
@section('page-title', 'Pricing & Promotions')
@section('topbar-subtitle', 'Manage prices, discounts and voucher eligibility.')

@section('content')
@php
    $pricingDemoImages = [
        'Classic Linen Shirt' => asset('images/landing-page/products/cotton-tshirt.jpg'),
        'Canvas Tote Bag' => asset('images/landing-page/products/canvas-tote.jpg'),
        'Everyday Sneakers' => asset('images/landing-page/products/everyday-sneakers.jpg'),
        'Minimalist Crossbody Bag' => asset('images/landing-page/products/canvas-tote.jpg'),
    ];

    $summaryItems = collect($summary ?? []);
    $activePromotions = $summaryItems->firstWhere('label', 'Active Promotions')['value'] ?? count($campaigns ?? []);
    $discountedProducts = $summaryItems->firstWhere('label', 'Discounted Products')['value'] ?? collect($products ?? [])->where('discount_percent', '>', 0)->count();
    $voucherEligible = $summaryItems->firstWhere('label', 'Voucher Eligible')['value'] ?? collect($products ?? [])->where('voucher_eligible', true)->count();
    $endingSoon = (int) ($summaryItems->firstWhere('label', 'Ending Soon')['value'] ?? 0);
@endphp

<div class="pricing-page">
    <section class="pricing-summary-row" aria-label="Pricing and promotions summary">
        <div class="pricing-summary-metrics">
            <div class="pricing-summary-metric">
                <strong>{{ $activePromotions }}</strong>
                <span>Active promotions</span>
            </div>
            <div class="pricing-summary-metric">
                <strong>{{ $discountedProducts }}</strong>
                <span>Discounted products</span>
            </div>
            <div class="pricing-summary-metric">
                <strong>{{ $voucherEligible }}</strong>
                <span>Voucher eligible</span>
            </div>
        </div>

        <a class="pricing-create-button" href="{{ route('seller.products.pricing.create') }}">
            <i data-lucide="plus" aria-hidden="true"></i>
            <span>Create promotion</span>
        </a>
    </section>

    @if ($endingSoon > 0)
        <section class="pricing-ending-notice" aria-label="Promotion ending soon">
            <div class="pricing-ending-copy">
                <i data-lucide="clock-3" aria-hidden="true"></i>
                <strong>{{ $endingSoon }} {{ $endingSoon === 1 ? 'promotion ends' : 'promotions end' }} within 48 hours</strong>
            </div>
            <a href="{{ route('seller.products.pricing', ['tab' => 'campaigns']) }}">
                <span>Review promotion</span>
                <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
        </section>
    @endif

    <section class="pricing-workspace" data-pricing-workspace>
        <div class="pricing-tabs" role="tablist" aria-label="Pricing workspace">
            <button class="pricing-tab is-active" type="button" role="tab" aria-selected="true" data-pricing-tab="products">
                Product pricing
            </button>
            <button class="pricing-tab" type="button" role="tab" aria-selected="false" data-pricing-tab="campaigns">
                Promotions <span class="pricing-tab-count">{{ count($campaigns) }}</span>
            </button>
            <button class="pricing-tab" type="button" role="tab" aria-selected="false" data-pricing-tab="vouchers">
                Voucher eligibility
            </button>
        </div>

        <section class="pricing-products-panel" data-pricing-panel="products">
            <div class="pricing-panel-surface">
            <div class="pricing-toolbar">
                <label class="pricing-search">
                    <i data-lucide="search" aria-hidden="true"></i>
                    <span class="sr-only">Search product or SKU</span>
                    <input type="search" placeholder="Search product or SKU" autocomplete="off" data-pricing-search>
                </label>

                <label class="pricing-status-filter">
                    <span class="sr-only">Filter pricing status</span>
                    <select data-pricing-status aria-label="Filter pricing status">
                        <option value="">All pricing statuses</option>
                        <option value="discounted">Discounted</option>
                        <option value="regular">Regular price</option>
                    </select>
                </label>

                <button class="pricing-reset" type="button" data-pricing-reset>Reset</button>
            </div>

            <div class="pricing-table-wrap">
                <table class="pricing-table">
                    <thead>
                        <tr>
                            <th>Product / SKU</th>
                            <th>Regular price</th>
                            <th>Sale price</th>
                            <th>Discount</th>
                            <th>Voucher</th>
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
                                $isVoucherEligible = (bool) $product['voucher_eligible'];
                                $statusKey = strtolower($product['status'] ?? 'Draft');
                            @endphp
                            <tr data-pricing-row data-state="{{ $pricingState }}" data-search="{{ strtolower($product['name'].' '.$product['sku']) }}">
                                <td>
                                    <div class="pricing-product-cell">
                                        <span class="pricing-product-image">
                                            @if ($product['image'])
                                                <img src="{{ asset('storage/'.$product['image']) }}" alt="{{ $product['name'] }}">
                                            @elseif ($demoImage)
                                                <img src="{{ $demoImage }}" alt="{{ $product['name'] }}">
                                            @else
                                                <i data-lucide="package" aria-hidden="true"></i>
                                            @endif
                                        </span>
                                        <span class="pricing-product-copy">
                                            <strong>{{ $product['name'] }}</strong>
                                            <small>{{ $product['sku'] ?: 'No SKU assigned' }}</small>
                                        </span>
                                    </div>
                                </td>
                                <td><span class="pricing-money">₱{{ number_format($product['price'], 2) }}</span></td>
                                <td><strong class="pricing-sale-value">₱{{ number_format($salePrice, 2) }}</strong></td>
                                <td>
                                    @if ($product['discount_percent'] > 0)
                                        <span class="pricing-discount-text">{{ $product['discount_percent'] }}% off</span>
                                    @else
                                        <span class="pricing-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="pricing-voucher-state {{ $isVoucherEligible ? 'is-eligible' : 'is-ineligible' }}">
                                        <i data-lucide="circle-check" aria-hidden="true"></i>
                                        <span>{{ $isVoucherEligible ? 'Eligible' : 'Not eligible' }}</span>
                                    </span>
                                </td>
                                <td>
                                    <span class="pricing-status-state is-{{ $statusKey }}">
                                        <i aria-hidden="true"></i>
                                        <span>{{ $product['status'] }}</span>
                                    </span>
                                </td>
                                <td>
                                    <button class="pricing-row-action" type="button" data-pricing-open="price" data-product='@json($product)'>
                                        <i data-lucide="pencil" aria-hidden="true"></i>
                                        <span>Edit price</span>
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
                <span><strong data-pricing-count>{{ count($products) }}</strong> products</span>
                <span>Prices before vouchers</span>
            </footer>
            </div>

            <p class="pricing-workspace-note">Voucher eligibility is managed in the Voucher eligibility tab.</p>
        </section>

        <section class="pricing-panel-surface pricing-secondary-panel" data-pricing-panel="campaigns" hidden>
            <div class="promotion-panel-heading">
                <div>
                    <h3>Promotion campaigns</h3>
                    <p>Track active, scheduled, and ended promotions without leaving the pricing workspace.</p>
                </div>
                <a class="promotion-row-action" href="{{ route('seller.products.pricing.create') }}">
                    <i data-lucide="plus" aria-hidden="true"></i>
                    <span>Create promotion</span>
                </a>
            </div>

            <div class="promotion-table-wrap">
                <table class="promotion-table">
                    <thead>
                        <tr>
                            <th>Promotion</th>
                            <th>Type</th>
                            <th>Schedule</th>
                            <th>Products</th>
                            <th>Attributed sales</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $campaign)
                            <tr>
                                <td>
                                    <span class="campaign-name">
                                        <strong>{{ $campaign['name'] }}</strong>
                                        <small>{{ $campaign['status'] === 'Active' ? 'Currently visible to eligible buyers' : 'Campaign period finished' }}</small>
                                    </span>
                                </td>
                                <td>{{ $campaign['type'] }}</td>
                                <td>{{ $campaign['period'] }}</td>
                                <td>{{ $campaign['products'] }}</td>
                                <td><strong>{{ $campaign['sales'] }}</strong></td>
                                <td><span class="campaign-status is-{{ $campaign['status_key'] }}">{{ $campaign['status'] }}</span></td>
                                <td>
                                    <button class="promotion-row-action" type="button" data-pricing-demo="{{ $campaign['status'] === 'Active' ? 'Campaign manager' : 'Campaign report' }} opened for {{ $campaign['name'] }}.">
                                        {{ $campaign['status'] === 'Active' ? 'Manage' : 'View report' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pricing-panel-surface pricing-secondary-panel" data-pricing-panel="vouchers" hidden>
            <div class="promotion-panel-heading">
                <div>
                    <h3>Voucher eligibility</h3>
                    <p>Control which active products buyers can use store vouchers on.</p>
                </div>
            </div>

            <div class="voucher-table-wrap">
                <table class="voucher-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Regular price</th>
                            <th>Product status</th>
                            <th>Voucher eligibility</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            @php($demoImage = $pricingDemoImages[$product['name']] ?? null)
                            <tr>
                                <td>
                                    <div class="pricing-product-cell">
                                        <span class="pricing-product-image">
                                            @if ($product['image'])
                                                <img src="{{ asset('storage/'.$product['image']) }}" alt="{{ $product['name'] }}">
                                            @elseif ($demoImage)
                                                <img src="{{ $demoImage }}" alt="{{ $product['name'] }}">
                                            @else
                                                <i data-lucide="package" aria-hidden="true"></i>
                                            @endif
                                        </span>
                                        <span class="pricing-product-copy">
                                            <strong>{{ $product['name'] }}</strong>
                                            <small>{{ $product['sku'] ?: 'No SKU assigned' }}</small>
                                        </span>
                                    </div>
                                </td>
                                <td>₱{{ number_format($product['price'], 2) }}</td>
                                <td>{{ $product['status'] }}</td>
                                <td>
                                    <label class="pricing-switch">
                                        <input type="checkbox" {{ $product['voucher_eligible'] ? 'checked' : '' }} {{ $product['status'] === 'Archived' ? 'disabled' : '' }} data-voucher-toggle>
                                        <i aria-hidden="true"></i>
                                        <span>{{ $product['voucher_eligible'] ? 'Eligible' : 'Not eligible' }}</span>
                                    </label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</div>

<div class="seller-modal pricing-modal" data-modal="pricing-price" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close price editor"></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="pricing-modal-title">
        <div class="modal-heading">
            <div>
                <span class="section-kicker">Product pricing</span>
                <h3 id="pricing-modal-title" data-price-product>Edit price</h3>
            </div>
            <button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button>
        </div>

        <div class="pricing-form-grid">
            <label>
                <span>Regular price</span>
                <div class="pricing-input-shell">
                    <span class="pricing-input-affix is-prefix" aria-hidden="true">₱</span>
                    <input type="number" min="0" step="0.01" inputmode="decimal" aria-label="Regular price" data-price-regular>
                </div>
            </label>
            <label>
                <span>Discount</span>
                <div class="pricing-input-shell">
                    <input type="number" min="0" max="90" step="1" inputmode="numeric" aria-label="Discount percentage" data-price-discount>
                    <span class="pricing-input-affix is-suffix" aria-hidden="true">%</span>
                </div>
            </label>
        </div>

        <div class="pricing-calculation">
            <div><span>Buyer pays</span><strong data-price-sale>₱0.00</strong></div>
            <div><span>Estimated platform fee (10%)</span><strong data-price-fee>₱0.00</strong></div>
            <div class="is-net"><span>Estimated net revenue</span><strong data-price-net>₱0.00</strong></div>
        </div>

        <p class="pricing-form-note"><i data-lucide="shield-check"></i>Review net revenue before publishing a discount.</p>

        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>Cancel</button>
            <button class="seller-primary-button" type="button" data-price-save>Save price</button>
        </div>
    </section>
</div>
@endsection
