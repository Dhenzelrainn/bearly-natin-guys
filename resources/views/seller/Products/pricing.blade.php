@extends('layouts.seller')

@section('title', 'Pricing & Promotions')
@section('page-title', 'Pricing & Promotions')
@section('topbar-subtitle', 'Manage product discounts, store vouchers, and Bearly campaigns.')

@section('content')
@php
    $productDiscountSummary = $discountListing['summary'] ?? [
        'active' => 0,
        'scheduled' => 0,
        'discounted_products' => 0,
        'ending_soon' => 0,
    ];
    $voucherSummary = $voucherListing ?? [
        'campaigns' => collect(),
        'active_count' => 0,
        'scheduled_count' => 0,
        'claimed_count' => 0,
        'redeemed_count' => 0,
        'ending_soon_count' => 0,
    ];
    $bearlyCampaigns = collect($bearlyCampaigns ?? []);
    $voucherRows = collect($voucherSummary['campaigns'] ?? []);
    $discountRows = collect($discountListing['products'] ?? []);
    $requestedTab = in_array(request()->query('tab'), ['product-discounts', 'store-vouchers', 'campaigns'], true)
        ? request()->query('tab')
        : 'product-discounts';
    $promoPrice = static fn (array $product): float => (float) $product['promo_price'];

    $discountStatusRank = ['active' => 1, 'scheduled' => 2, 'paused' => 3, 'ended' => 4, 'none' => 5];
    $discountCategories = $discountRows
        ->map(fn (array $product): string => trim((string) ($product['category'] ?? '')))
        ->filter()
        ->unique()
        ->sort()
        ->values();
    $discountStatuses = $discountRows
        ->map(fn (array $product): array => [
            'key' => $product['promotion_status_key'] ?? 'none',
            'label' => $product['promotion_status'] ?? 'No product discount',
        ])
        ->unique('key')
        ->sortBy(fn (array $status): int => $discountStatusRank[$status['key']] ?? 9)
        ->values();
@endphp

<div class="pricing-page" data-pricing-workspace>
    <section class="pricing-summary-row" aria-label="Pricing and promotions summary">
        <article class="pricing-summary-metric is-active">
            <span class="pricing-summary-icon" aria-hidden="true"><i data-lucide="ticket-percent"></i></span>
            <span class="pricing-summary-copy">
                <strong>{{ (int) $productDiscountSummary['active'] }}</strong>
                <span>Active discounts</span>
            </span>
        </article>
        <article class="pricing-summary-metric is-scheduled">
            <span class="pricing-summary-icon" aria-hidden="true"><i data-lucide="calendar-days"></i></span>
            <span class="pricing-summary-copy">
                <strong>{{ (int) $productDiscountSummary['scheduled'] }}</strong>
                <span>Scheduled discounts</span>
            </span>
        </article>
        <article class="pricing-summary-metric is-discounted">
            <span class="pricing-summary-icon" aria-hidden="true"><i data-lucide="package"></i></span>
            <span class="pricing-summary-copy">
                <strong data-pricing-summary="discounted">{{ (int) $productDiscountSummary['discounted_products'] }}</strong>
                <span>Discounted products</span>
            </span>
        </article>
        <article class="pricing-summary-metric is-ending">
            <span class="pricing-summary-icon" aria-hidden="true"><i data-lucide="clock-3"></i></span>
            <span class="pricing-summary-copy">
                <strong>{{ (int) $productDiscountSummary['ending_soon'] }}</strong>
                <span>Ending soon</span>
            </span>
        </article>

    </section>

    <section class="pricing-workspace">
        <div class="pricing-tabs" role="tablist" aria-label="Pricing and promotions workspace">
            <button class="pricing-tab {{ $requestedTab === 'product-discounts' ? 'is-active' : '' }}" type="button" role="tab" aria-selected="{{ $requestedTab === 'product-discounts' ? 'true' : 'false' }}" data-pricing-tab="product-discounts">
                Product Discounts <span class="pricing-tab-count">{{ $discountRows->count() }}</span>
            </button>
            <button class="pricing-tab {{ $requestedTab === 'store-vouchers' ? 'is-active' : '' }}" type="button" role="tab" aria-selected="{{ $requestedTab === 'store-vouchers' ? 'true' : 'false' }}" data-pricing-tab="store-vouchers">
                Store Vouchers <span class="pricing-tab-count">{{ $voucherRows->count() }}</span>
            </button>
            <button class="pricing-tab {{ $requestedTab === 'campaigns' ? 'is-active' : '' }}" type="button" role="tab" aria-selected="{{ $requestedTab === 'campaigns' ? 'true' : 'false' }}" data-pricing-tab="campaigns">
                Bearly Campaigns <span class="pricing-tab-count">{{ $bearlyCampaigns->count() }}</span>
            </button>
        </div>

        <section class="pricing-panel-surface" data-pricing-panel="product-discounts" @if ($requestedTab !== 'product-discounts') hidden @endif>
            <div class="pricing-panel-head">
                <div class="pricing-panel-copy">
                    <span class="pricing-kicker">Seller-funded</span>
                    <h3>Product discounts</h3>
                    <p>Schedule an automatic price reduction for selected products. A product discount replaces the regular Seller discount and never stacks with another active product discount.</p>
                </div>
                <a class="pricing-panel-action" href="{{ route('seller.promotions.product-discounts.create') }}">
                    <i data-lucide="plus" aria-hidden="true"></i>
                    <span>Create product discount</span>
                </a>
            </div>

            <div class="pricing-filters">
                <label class="pricing-search-control">
                    <span class="sr-only">Search product name, SKU, or category</span>
                    <input type="search" placeholder="Search product name, SKU, or category…" autocomplete="off" data-discount-search>
                    <i data-lucide="search" aria-hidden="true"></i>
                </label>

                <label class="pricing-select">
                    <span class="sr-only">Filter by category</span>
                    <select data-discount-category aria-label="Filter by category">
                        <option value="">All categories</option>
                        @foreach ($discountCategories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="pricing-select">
                    <span class="sr-only">Filter by discount status</span>
                    <select data-discount-status aria-label="Filter by discount status">
                        <option value="">All status</option>
                        @foreach ($discountStatuses as $status)
                            <option value="{{ $status['key'] }}">{{ $status['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="promotion-table-wrap">
                <table class="pricing-discount-table">
                    <colgroup>
                        <col class="pricing-col-product">
                        <col class="pricing-col-regular">
                        <col class="pricing-col-promo">
                        <col class="pricing-col-discount">
                        <col class="pricing-col-schedule">
                        <col class="pricing-col-status">
                        <col class="pricing-col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" aria-sort="none">
                                <button class="pricing-sort" type="button" data-discount-sort="name" aria-label="Sort by product name">
                                    <span>Product / SKU</span>
                                    <span class="pricing-sort-icon" aria-hidden="true">
                                        <i data-lucide="chevron-up"></i>
                                        <i data-lucide="chevron-down"></i>
                                    </span>
                                </button>
                            </th>
                            <th scope="col">Regular price</th>
                            <th scope="col" aria-sort="none">
                                <button class="pricing-sort" type="button" data-discount-sort="price" aria-label="Sort by promotional price">
                                    <span>Promotional price</span>
                                    <span class="pricing-sort-icon" aria-hidden="true">
                                        <i data-lucide="chevron-up"></i>
                                        <i data-lucide="chevron-down"></i>
                                    </span>
                                </button>
                            </th>
                            <th scope="col">Discount</th>
                            <th scope="col">Schedule</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($discountRows as $product)
                            @php
                                $promotionId = $product['promotion_id'] ?? null;
                                $statusKey = $product['promotion_status_key'] ?? 'none';
                                $hasProductDiscount = (bool) $product['promo_discount_percent'];
                                $promoPlanned = in_array($statusKey, ['active', 'scheduled'], true) && $hasProductDiscount;
                                $sellerNote = (float) $product['seller_price'] < (float) $product['regular_price'];
                            @endphp
                            <tr
                                data-product-discount-row
                                data-product-id="{{ $product['id'] }}"
                                data-status="{{ $statusKey }}"
                                data-price-state="{{ (($product['promotion_is_active'] ?? false) || (int) ($product['discount_percent'] ?? 0) > 0) ? 'discounted' : 'regular' }}"
                                data-category="{{ $product['category'] ?? '' }}"
                                data-search="{{ strtolower(trim(($product['name'] ?? '').' '.($product['sku'] ?? '').' '.($product['category'] ?? ''))) }}"
                                data-name="{{ strtolower($product['name'] ?? '') }}"
                                data-price="{{ $promoPrice($product) }}"
                                data-promo-percent="{{ $hasProductDiscount ? $product['promo_discount_percent'] : '' }}"
                                data-promo-planned="{{ $promoPlanned ? '1' : '0' }}"
                            >
                                <td>
                                    <div class="pricing-product-cell">
                                        <span class="pricing-product-image">
                                            @if ($product['image_url'])
                                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" loading="lazy">
                                            @else
                                                <i data-lucide="package" aria-hidden="true"></i>
                                            @endif
                                        </span>
                                        <span class="pricing-product-copy">
                                            <strong title="{{ $product['name'] }}">{{ $product['name'] }}</strong>
                                            <small title="{{ $product['sku'] ?: 'No SKU assigned' }}">{{ $product['sku'] ?: 'No SKU assigned' }}@if ($product['promotion_name']) · {{ $product['promotion_name'] }}@endif</small>
                                            @if ($product['buyer_campaign_name'])<small>Buyer label: {{ $product['buyer_campaign_name'] }}</small>@endif
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="pricing-money" data-discount-regular>₱{{ number_format((float) $product['regular_price'], 2) }}</span>
                                </td>
                                <td>
                                    <span class="pricing-promo-cell">
                                        <strong class="pricing-sale-value" data-discount-promo>₱{{ number_format($promoPrice($product), 2) }}</strong>
                                        <small class="pricing-seller-note" @if (! $sellerNote) hidden @endif data-discount-seller-note>Seller price ₱{{ number_format((float) $product['seller_price'], 2) }}</small>
                                    </span>
                                </td>
                                <td>
                                    <span class="{{ $hasProductDiscount ? 'pricing-discount-value' : 'pricing-dash' }}" data-discount-percent>
                                        {{ $hasProductDiscount ? $product['promo_discount_percent'].'% off' : '—' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="pricing-schedule">{{ $product['promotion_period'] ?? '—' }}</span>
                                </td>
                                <td>
                                    <span class="pricing-status-pill is-{{ $statusKey }}">{{ $product['promotion_status'] }}</span>
                                </td>
                                <td>
                                    <div class="pricing-row-actions">
                                        @if ($product['status'] === 'Archived')
                                            <button class="pricing-row-button" type="button" disabled title="Restore this product before managing its discount.">
                                                <i data-lucide="archive" aria-hidden="true"></i>
                                                <span>Restore to edit</span>
                                            </button>
                                        @elseif ($promotionId)
                                            <a class="pricing-row-button" href="{{ route('seller.promotions.product-discounts.edit', $promotionId) }}">
                                                <i data-lucide="pencil" aria-hidden="true"></i>
                                                <span>Edit discount</span>
                                            </a>
                                        @else
                                            <a class="pricing-row-button" href="{{ route('seller.promotions.product-discounts.create', ['product' => $product['id']]) }}">
                                                <i data-lucide="plus" aria-hidden="true"></i>
                                                <span>Create discount</span>
                                            </a>
                                        @endif

                                        @if ($product['status'] !== 'Archived' && $promotionId)
                                            <details class="pricing-row-menu" data-pricing-menu>
                                                <summary class="pricing-row-menu-trigger" aria-label="More actions for {{ $product['name'] }}">
                                                    <i data-lucide="ellipsis-vertical" aria-hidden="true"></i>
                                                </summary>
                                                <div class="pricing-row-menu-panel">
                                                    @if ($promotionId)
                                                        <span class="pricing-row-menu-divider" aria-hidden="true"></span>
                                                        @if ($statusKey === 'paused')
                                                            <form method="POST" action="{{ route('seller.promotions.product-discounts.resume', $promotionId) }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button class="pricing-row-menu-item" type="submit">
                                                                    <i data-lucide="play" aria-hidden="true"></i>
                                                                    <span>Resume discount</span>
                                                                </button>
                                                            </form>
                                                        @elseif (in_array($statusKey, ['active', 'scheduled'], true))
                                                            <form method="POST" action="{{ route('seller.promotions.product-discounts.pause', $promotionId) }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button class="pricing-row-menu-item" type="submit">
                                                                    <i data-lucide="pause" aria-hidden="true"></i>
                                                                    <span>Pause discount</span>
                                                                </button>
                                                            </form>
                                                        @endif
                                                        <form method="POST" action="{{ route('seller.promotions.product-discounts.duplicate', $promotionId) }}">
                                                            @csrf
                                                            <button class="pricing-row-menu-item" type="submit">
                                                                <i data-lucide="copy" aria-hidden="true"></i>
                                                                <span>Duplicate discount</span>
                                                            </button>
                                                        </form>
                                                        @if ($statusKey !== 'ended')
                                                            <form method="POST" action="{{ route('seller.promotions.product-discounts.end', $promotionId) }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button class="pricing-row-menu-item is-danger" type="submit">
                                                                    <i data-lucide="square" aria-hidden="true"></i>
                                                                    <span>End discount</span>
                                                                </button>
                                                            </form>
                                                        @endif
                                                    @endif
                                                </div>
                                            </details>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="pricing-empty-row">
                                <td colspan="7">
                                    <div class="pricing-empty-state">
                                        <strong>No products to price yet</strong>
                                        <span>Add an active product with an SKU before creating a Product Discount.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        <tr class="pricing-empty-row" data-discount-empty hidden>
                            <td colspan="7">
                                <div class="pricing-empty-state">
                                    <strong>No matching products</strong>
                                    <span>Try another search, category, or status.</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="pricing-table-footer">
                <span data-discount-range>Showing {{ $discountRows->isEmpty() ? 0 : 1 }} to {{ $discountRows->count() }} of {{ $discountRows->count() }} products</span>
                <nav class="pricing-pagination" data-discount-pagination aria-label="Product discount pages"></nav>
            </footer>
        </section>

        <section class="pricing-panel-surface pricing-secondary-panel" data-pricing-panel="store-vouchers" @if ($requestedTab !== 'store-vouchers') hidden @endif>
            <div class="pricing-panel-head">
                <div class="pricing-panel-copy">
                    <span class="pricing-kicker">Seller-funded</span>
                    <h3>Store vouchers</h3>
                    <p>Create a claimable or code-based voucher for your Store. Store Vouchers stack with one Bearly platform voucher at checkout.</p>
                </div>
                <a class="pricing-panel-action" href="{{ route('seller.promotions.store-vouchers.create') }}">
                    <i data-lucide="plus" aria-hidden="true"></i>
                    <span>Create store voucher</span>
                </a>
            </div>

            <div class="promotion-table-wrap">
                <table class="promotion-table pricing-voucher-table">
                    <thead>
                        <tr>
                            <th>Voucher</th>
                            <th>Offer</th>
                            <th>Minimum spend</th>
                            <th>Eligible products</th>
                            <th>Usage</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($voucherRows as $voucher)
                            <tr>
                                <td>
                                    <span class="campaign-name">
                                        <strong>{{ $voucher['name'] }}</strong>
                                        <small>{{ $voucher['code'] ? 'Code '.$voucher['code'] : 'Claim in My Vouchers' }}</small>
                                    </span>
                                </td>
                                <td><strong>{{ $voucher['offer'] }}</strong></td>
                                <td>₱{{ number_format($voucher['minimum_spend_minor'] / 100, 2) }}</td>
                                <td>{{ $voucher['product_names'] ?: $voucher['product_count'].' products' }}</td>
                                <td>{{ $voucher['used_count'] }} used / {{ $voucher['redemption_limit'] ?? '∞' }}</td>
                                <td><span class="pricing-schedule">{{ $voucher['period'] }}</span></td>
                                <td><span class="pricing-status-pill is-{{ $voucher['status_key'] }}">{{ $voucher['status'] }}</span></td>
                                <td>
                                    <div class="promotion-row-actions">
                                        @if ($voucher['status_key'] === 'paused')
                                            <form method="POST" action="{{ route('seller.promotions.store-vouchers.status', $voucher['id']) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="active">
                                                <button class="promotion-row-action" type="submit">Resume</button>
                                            </form>
                                        @elseif (in_array($voucher['status_key'], ['active', 'scheduled'], true))
                                            <form method="POST" action="{{ route('seller.promotions.store-vouchers.status', $voucher['id']) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="paused">
                                                <button class="promotion-row-action" type="submit">Pause</button>
                                            </form>
                                        @endif
                                        @if ($voucher['status_key'] !== 'ended')
                                            <form method="POST" action="{{ route('seller.promotions.store-vouchers.status', $voucher['id']) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="ended">
                                                <button class="promotion-row-action is-danger" type="submit">End</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="pricing-empty-row">
                                <td colspan="8">
                                    <div class="pricing-empty-state">
                                        <strong>No Store Vouchers yet.</strong>
                                        <span>Create a Store Voucher to offer a checkout discount on eligible products.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </section>

        <section class="pricing-panel-surface pricing-secondary-panel" data-pricing-panel="campaigns" @if ($requestedTab !== 'campaigns') hidden @endif>
            <div class="pricing-panel-head">
                <div class="pricing-panel-copy">
                    <span class="pricing-kicker">Bearly events</span>
                    <h3>Bearly campaigns</h3>
                    <p>Join platform events with eligible products. Bearly-funded campaigns do not reduce your product proceeds; Seller-funded campaign prices do.</p>
                </div>
            </div>

            <div class="campaign-grid" data-campaign-grid>
                @forelse ($bearlyCampaigns as $campaign)
                    @php($participation = $campaign['participation'] ?? null)
                    <article class="campaign-card" data-campaign-card data-status="{{ $campaign['status_key'] }}" data-joined="{{ $participation ? 'true' : 'false' }}" data-search="{{ strtolower($campaign['name'].' '.$campaign['type_label']) }}">
                        <div class="campaign-card-media">
                            @if ($campaign['banner_url'])
                                <img src="{{ $campaign['banner_url'] }}" alt="{{ $campaign['name'] }} campaign banner" loading="lazy">
                            @else
                                <span class="campaign-card-placeholder"><i data-lucide="sparkles" aria-hidden="true"></i></span>
                            @endif
                            <span class="campaign-type-badge">{{ $campaign['type_label'] }}</span>
                        </div>
                        <div class="campaign-card-body">
                            <div class="campaign-card-heading">
                                <div>
                                    <h4>{{ $campaign['name'] }}</h4>
                                    <small>{{ $campaign['period'] }}</small>
                                </div>
                                <span class="pricing-status-pill is-{{ $campaign['status_key'] }}">{{ $campaign['status_label'] }}</span>
                            </div>
                            <p>{{ \Illuminate\Support\Str::limit($campaign['description'] ?: 'Join this Bearly campaign with selected products.', 140) }}</p>
                            <dl class="campaign-facts">
                                <div><dt>Funding</dt><dd>{{ $campaign['funding_label'] }}</dd></div>
                                <div><dt>Join by</dt><dd>{{ $campaign['join_deadline'] ? \Illuminate\Support\Carbon::parse($campaign['join_deadline'])->format('M j, Y') : 'Open' }}</dd></div>
                                <div><dt>Eligibility</dt><dd>{{ $campaign['scope'] }}</dd></div>
                            </dl>
                            @if ($participation)
                                <p class="campaign-participation-note is-{{ $participation['status'] }}">
                                    <i data-lucide="{{ $participation['status'] === 'approved' ? 'circle-check' : 'clock-3' }}" aria-hidden="true"></i>
                                    {{ $participation['status_label'] }} · {{ $participation['product_count'] }} product(s)
                                </p>
                            @endif
                            <a class="seller-primary-button campaign-card-action" href="{{ route('seller.promotions.campaigns.show', $campaign['id']) }}">
                                <span>{{ $participation ? 'View participation' : 'View campaign' }}</span>
                                <i data-lucide="arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                @empty
                    <div class="campaign-empty-state">
                        <i data-lucide="calendar-clock" aria-hidden="true"></i>
                        <strong>No Bearly campaigns are open right now.</strong>
                        <span>Check back for Flash Deals, Piso Sales, Payday Sales, and monthly events.</span>
                    </div>
                @endforelse
            </div>
        </section>
    </section>
</div>

@endsection
