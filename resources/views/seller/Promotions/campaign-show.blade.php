@extends('layouts.seller')

@section('title', $campaign['name'])
@section('page-title', $campaign['name'])
@section('topbar-subtitle', 'Bearly Campaign participation')

@section('content')
@php
    $isPlatformFunded = $campaign['funding_type'] === \App\Models\PlatformPromotion::FUNDING_PLATFORM;
    $participation = $participation ?? null;
    $canJoin = (bool) ($can_join ?? false);
    $minimumDiscount = (int) ($campaign['effective_minimum_discount_percent'] ?? 1);
    $minimumDiscountCopy = $campaign['minimum_discount_percent'] !== null
        ? 'Every product discount must be at least '.$minimumDiscount.'%.'
        : 'No Admin minimum is configured; submit at least a 1% discount.';
    $oldCampaignItemsByProduct = collect(old('items', []))
        ->filter(fn ($item) => is_array($item) && isset($item['product_id']))
        ->keyBy(fn ($item) => (int) $item['product_id']);
@endphp

<div class="campaign-detail-page">
    <div class="promotion-create-toolbar">
        <a class="promotion-back-link" href="{{ route('seller.products.pricing', ['tab' => 'campaigns']) }}">
            <i data-lucide="arrow-left" aria-hidden="true"></i>
            Bearly Campaigns
        </a>
        <span class="campaign-status is-{{ $campaign['status_key'] }}">{{ $campaign['status_label'] }}</span>
    </div>

    @if (session('success'))
        <div class="seller-alert is-success" role="status"><strong>Saved.</strong> {{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="seller-alert is-danger" role="alert">
            <strong>Check the campaign participation details.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="campaign-detail-hero">
        <div class="campaign-detail-media">
            @if ($campaign['banner_url'])
                <img src="{{ $campaign['banner_url'] }}" alt="{{ $campaign['name'] }} campaign banner">
            @else
                <span><i data-lucide="sparkles" aria-hidden="true"></i></span>
            @endif
        </div>
        <div class="campaign-detail-copy">
            <span class="section-kicker">{{ $campaign['type_label'] }}</span>
            <h1>{{ $campaign['name'] }}</h1>
            <p>{{ $campaign['description'] ?: 'Join this Bearly campaign with eligible products from your Store.' }}</p>
            <dl class="campaign-detail-facts">
                <div><dt>Campaign period</dt><dd>{{ $campaign['period'] }}</dd></div>
                <div><dt>Join deadline</dt><dd>{{ $campaign['join_deadline'] ? \Illuminate\Support\Carbon::parse($campaign['join_deadline'])->format('M j, Y') : 'Open until the campaign starts' }}</dd></div>
                <div><dt>Funding</dt><dd>{{ $campaign['funding_label'] }}</dd></div>
                <div><dt>Eligibility</dt><dd>{{ $campaign['scope'] }}</dd></div>
            </dl>
            <p class="campaign-funding-note {{ $isPlatformFunded ? 'is-platform' : 'is-seller' }}">
                <i data-lucide="{{ $isPlatformFunded ? 'badge-check' : 'wallet' }}" aria-hidden="true"></i>
                {{ $isPlatformFunded
                    ? ($minimumDiscountCopy.' Bearly funds the difference; your proceeds remain based on your effective Seller price.')
                    : ($minimumDiscountCopy.' You fund the discount, so your proceeds are based on the computed campaign price.') }}
            </p>
        </div>
    </section>

    @if ($participation)
        <section class="promotion-create-card">
            <div class="promotion-create-card-heading">
                <div>
                    <span class="section-kicker">Your participation</span>
                    <h2>{{ $participation->statusLabel() }}</h2>
                    <p>Submitted {{ $participation->submitted_at?->format('M j, Y g:i A') ?? '—' }}@if ($participation->review_reason) · {{ $participation->review_reason }}@endif</p>
                </div>
                @if (in_array($participation->status, ['pending_review', 'approved'], true))
                    <form method="POST" action="{{ route('seller.promotions.campaigns.withdraw', $participation) }}" onsubmit="return confirm('Withdraw this campaign participation?')">
                        @csrf
                        @method('PATCH')
                        <button class="promotion-row-action is-danger" type="submit">Withdraw participation</button>
                    </form>
                @endif
            </div>
            <div class="promotion-table-wrap">
                <table class="promotion-table">
                    <thead>
                        <tr>
                            <th>Product / SKU</th>
                            <th>Regular price</th>
                            <th>Discount</th>
                            <th>Campaign price</th>
                            <th>Stock limit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($participation->items as $item)
                            <tr>
                                <td><strong>{{ $item->product?->name }}</strong><br><small>{{ $item->variant?->sku }}</small></td>
                                <td>₱{{ number_format($item->regular_price_minor / 100, 2) }}</td>
                                <td>{{ $item->discount_percent !== null ? ((int) $item->discount_percent).'% off' : 'Legacy submission' }}</td>
                                <td><strong>₱{{ number_format($item->campaign_price_minor / 100, 2) }}</strong></td>
                                <td>{{ $item->stock_limit ?? 'No limit' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($canJoin)
        <form class="promotion-create-layout promotion-create-stack" method="POST" action="{{ route('seller.promotions.campaigns.join', $campaign['id']) }}" data-campaign-join-form data-funding="{{ $campaign['funding_type'] }}" data-minimum-discount="{{ $minimumDiscount }}">
            @csrf
            <section class="promotion-create-card">
                <div class="promotion-create-card-heading">
                    <div>
                        <span class="section-kicker">Products</span>
                        <h2>Select participating products</h2>
                        <p>Choose a whole discount percentage for each product. @if ($campaign['minimum_discount_percent'] !== null) Minimum {{ $minimumDiscount }}%; @else No Admin minimum is configured; @endif enter at least 1% and no more than 90%. Bearly computes a separate campaign price for every eligible active SKU. Stock and per-buyer limits apply per SKU.</p>
                    </div>
                    @if ($campaign['max_products_per_store'])
                        <span class="promotion-scope-note-compact">Up to {{ $campaign['max_products_per_store'] }} products</span>
                    @endif
                </div>

                <div class="campaign-product-list" data-campaign-products>
                    @forelse ($eligible_products as $product)
                        @php($oldItem = $oldCampaignItemsByProduct->get((int) $product['id']))
                        <div class="campaign-product-row" data-campaign-product>
                            <label class="campaign-product-select">
                                <input type="checkbox" value="{{ $product['id'] }}" data-campaign-product-checkbox @checked($oldItem !== null)>
                                <span>
                                    <strong>{{ $product['name'] }}</strong>
                                    <small>{{ count($product['variants']) }} eligible active SKU{{ count($product['variants']) === 1 ? '' : 's' }} with available stock</small>
                                </span>
                            </label>
                            <div class="campaign-product-fields">
                                <label>
                                    <span>Discount (%)</span>
                                    <input type="number" min="{{ $minimumDiscount }}" max="90" step="1" inputmode="numeric" data-campaign-discount value="{{ $oldItem['discount_percent'] ?? $minimumDiscount }}" aria-describedby="campaign-discount-help-{{ $product['id'] }}" @disabled($oldItem === null)>
                                    <small id="campaign-discount-help-{{ $product['id'] }}" data-campaign-discount-error aria-live="polite" @if ($oldItem === null || (int) ($oldItem['discount_percent'] ?? 0) >= $minimumDiscount) hidden @endif>Minimum {{ $minimumDiscount }}%; maximum 90%.</small>
                                </label>
                                <label>
                                    <span>Stock limit / SKU</span>
                                    <input type="number" min="1" step="1" inputmode="numeric" data-campaign-stock value="{{ $oldItem['stock_limit'] ?? '' }}" @disabled($oldItem === null) placeholder="No limit">
                                </label>
                                <label>
                                    <span>Per-buyer limit / SKU</span>
                                    <input type="number" min="1" max="1000" step="1" inputmode="numeric" data-campaign-buyer-limit value="{{ $oldItem['per_buyer_limit'] ?? '' }}" @disabled($oldItem === null) placeholder="No limit">
                                </label>
                            </div>
                            <div class="campaign-sku-previews" aria-live="polite">
                                @foreach ($product['variants'] as $variant)
                                    <div class="campaign-sku-preview" data-campaign-variant data-baseline-minor="{{ $variant['regular_price_minor'] }}">
                                        <strong>{{ $variant['sku'] ?: $variant['name'] }}</strong>
                                        <span>Current Seller price <b>₱{{ number_format($variant['regular_price_minor'] / 100, 2) }}</b></span>
                                        <span>Campaign price <b data-campaign-price-preview>—</b></span>
                                        <span>Seller proceeds before fees <b data-campaign-net>—</b></span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="promotion-empty-state">Your Store has no eligible products for this campaign.</div>
                    @endforelse
                </div>
            </section>

            <section class="promotion-create-card">
                <div class="promotion-create-card-heading">
                    <div>
                        <span class="section-kicker">Agreement</span>
                        <h2>Campaign terms</h2>
                    </div>
                </div>
                <div class="campaign-terms">{{ $campaign['terms'] ?: 'Campaign prices apply only while the campaign is active and approved. Historical orders keep their original price snapshots. Bearly may pause or end a campaign for operational reasons.' }}</div>
                <label class="campaign-terms-check">
                    <input type="checkbox" name="terms_accepted" value="1" required>
                    <span>I have reviewed the campaign schedule, minimum discount, funding, stock limits, and terms.</span>
                </label>
                <label class="promotion-field is-wide">
                    <span>Note to Bearly review (optional)</span>
                    <textarea name="seller_note" rows="3" maxlength="1000" placeholder="Add context for the campaign review team.">{{ old('seller_note') }}</textarea>
                </label>
                <div class="promotion-create-submit">
                    <div>
                        <strong>Submit for review</strong>
                        <p>{{ $campaign['requires_approval'] ? 'Bearly will review your selected products and submitted discount percentages.' : 'Your participation will be scheduled immediately after submission.' }}</p>
                    </div>
                    <button class="seller-primary-button" type="submit" @disabled($eligible_products === [])>
                        <i data-lucide="send" aria-hidden="true"></i>
                        {{ $campaign['requires_approval'] ? 'Submit participation' : 'Join campaign' }}
                    </button>
                </div>
            </section>
        </form>
    @elseif (! $participation && ! ($store_published ?? true))
        <div class="campaign-empty-state">
            <i data-lucide="store" aria-hidden="true"></i>
            <strong>Publish your Store before joining.</strong>
            <span>Your Store must be published before it can submit products to a Bearly Campaign.</span>
        </div>
    @elseif (! $participation && $campaign['status_key'] !== 'open')
        <div class="campaign-empty-state">
            <i data-lucide="calendar-x" aria-hidden="true"></i>
            <strong>Joining is closed for this campaign.</strong>
            <span>View the campaign schedule and check back for future Bearly events.</span>
        </div>
    @endif
</div>
@endsection
