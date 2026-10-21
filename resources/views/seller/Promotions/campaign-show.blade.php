@extends('layouts.seller')

@section('title', $campaign['name'])
@section('page-title', $campaign['name'])
@section('topbar-subtitle', 'Bearly Campaign participation')

@section('content')
@php
    $isPlatformFunded = $campaign['funding_type'] === \App\Models\PlatformPromotion::FUNDING_PLATFORM;
    $participation = $participation ?? null;
    $canJoin = (bool) ($can_join ?? false);
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
                    ? 'Bearly funds the campaign price difference. Your Seller proceeds stay based on your regular selling price.'
                    : 'You fund the campaign price. Your Seller proceeds are based on the campaign price during the campaign.' }}
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
                            <th>Campaign price</th>
                            <th>Stock limit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($participation->items as $item)
                            <tr>
                                <td><strong>{{ $item->product?->name }}</strong><br><small>{{ $item->variant?->sku }}</small></td>
                                <td>₱{{ number_format($item->regular_price_minor / 100, 2) }}</td>
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
        <form class="promotion-create-layout promotion-create-stack" method="POST" action="{{ route('seller.promotions.campaigns.join', $campaign['id']) }}" data-campaign-join-form data-funding="{{ $campaign['funding_type'] }}">
            @csrf
            <section class="promotion-create-card">
                <div class="promotion-create-card-heading">
                    <div>
                        <span class="section-kicker">Products</span>
                        <h2>Select participating products</h2>
                        <p>Enter a campaign price lower than your current selling price. Each selected product can include its active SKUs.</p>
                    </div>
                    @if ($campaign['max_products_per_store'])
                        <span class="promotion-scope-note-compact">Up to {{ $campaign['max_products_per_store'] }} products</span>
                    @endif
                </div>

                <div class="campaign-product-list" data-campaign-products>
                    @forelse ($eligible_products as $product)
                        @php($campaignBaseMinor = collect($product['variants'])->min('regular_price_minor') ?? 0)
                        <div class="campaign-product-row" data-campaign-product data-regular-minor="{{ $campaignBaseMinor }}">
                            <label class="campaign-product-select">
                                <input type="checkbox" value="{{ $product['id'] }}" data-campaign-product-checkbox>
                                <span>
                                    <strong>{{ $product['name'] }}</strong>
                                    <small>{{ $product['sku'] ?: 'No SKU' }} · {{ count($product['variants']) }} active SKU variant{{ count($product['variants']) === 1 ? '' : 's' }}</small>
                                </span>
                            </label>
                            <div class="campaign-product-fields">
                                <label>
                                    <span>Campaign price</span>
                                    <span class="promotion-input-shell">
                                        <span class="promotion-input-prefix">₱</span>
                                        <input type="number" min="0.01" step="0.01" inputmode="decimal" data-campaign-price disabled placeholder="{{ number_format(($campaignBaseMinor / 100) * .9, 2, '.', '') }}">
                                    </span>
                                </label>
                                <label>
                                    <span>Stock limit</span>
                                    <input type="number" min="1" step="1" inputmode="numeric" data-campaign-stock disabled placeholder="No limit">
                                </label>
                                <label>
                                    <span>Per-buyer limit</span>
                                    <input type="number" min="1" step="1" inputmode="numeric" data-campaign-buyer-limit disabled placeholder="No limit">
                                </label>
                                <div class="campaign-net-preview">
                                    <span>Your net per unit</span>
                                    <strong data-campaign-net>₱{{ number_format($campaignBaseMinor / 100, 2) }}</strong>
                                </div>
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
                    <span>I have reviewed the campaign schedule, funding, product prices, and terms.</span>
                </label>
                <label class="promotion-field is-wide">
                    <span>Note to Bearly review (optional)</span>
                    <textarea name="seller_note" rows="3" maxlength="1000" placeholder="Add context for the campaign review team.">{{ old('seller_note') }}</textarea>
                </label>
                <div class="promotion-create-submit">
                    <div>
                        <strong>Submit for review</strong>
                        <p>{{ $campaign['requires_approval'] ? 'Bearly will review your selected products and campaign prices.' : 'Your participation will be scheduled immediately after submission.' }}</p>
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
