@php
    $headerSearchCategories = collect(json_decode(file_get_contents(resource_path('data/buyer-categories.json')), true, 512, JSON_THROW_ON_ERROR))->mapWithKeys(fn (array $category): array => [$category['slug'] => $category['name']])->all();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Flash Deals | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js', 'resources/js/buyer-flash-deals.js'])
    @include('buyer.partials.account-context-script')
</head>
<body class="bh flash-page">
@include('buyer.partials.buyer-header', [
    'headerSearchFormId' => 'search-form',
    'headerSearchInputId' => 'search-input',
    'headerSearchCategories' => $headerSearchCategories,
    'headerSearchPlaceholder' => 'Search for products, brands or sellers...',
])
<main class="flash-page-main">
    <a class="flash-back" href="{{ url('/home') }}"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Back to Home</a>
    @if (($otherOffers ?? collect())->isNotEmpty())
        @include('buyer.partials.live-offers', [
            'promotions' => $otherOffers,
            'eyebrow' => 'Vouchers, discounts, shipping & campaigns',
            'heading' => 'Other live offers',
        ])
    @endif
    <section class="flash-hero">
        <div>
            <p class="flash-kicker"><span class="material-symbols-outlined" aria-hidden="true">bolt</span> Bearly Flash Deals</p>
            <h1>Live product deals</h1>
            <p>Approved products from active Flash Deal campaigns. Prices reflect the current eligible campaign price.</p>
        </div>
    </section>
    <section class="flash-campaign-list" data-flash-deal-listing aria-label="Live Flash Deal products">
        @forelse ($flashDealGroups ?? [] as $group)
            @php($campaign = $group['campaign'])
            <article class="flash-campaign-group" data-flash-campaign-id="{{ $campaign['id'] }}">
                <header class="flash-campaign-heading">
                    <div>
                        <p class="flash-campaign-kicker">{{ $campaign['type_label'] }} · {{ $campaign['funding_label'] }}</p>
                        <h2>{{ $campaign['name'] }}</h2>
                        <p class="flash-campaign-schedule">
                            <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
                            {{ $campaign['period'] }}
                        </p>
                        @if (filled($campaign['description'] ?? null))
                            <p class="flash-campaign-description">{{ $campaign['description'] }}</p>
                        @endif
                    </div>
                    <a class="flash-campaign-details" href="{{ route('campaigns.show', $campaign['id']) }}">
                        Campaign details
                        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                    </a>
                </header>
                <p class="flash-campaign-list-note">Showing up to 12 eligible products. Open campaign details to browse the full list.</p>

                <div class="flash-campaign-products">
                    @foreach ($group['products'] as $product)
                        <article class="flash-product-card">
                            <div class="flash-product-media">
                                <a class="flash-product-image" href="{{ route('products.show', ['product' => $product['id']]) }}" aria-label="View {{ $product['name'] }}">
                                    @if (filled($product['image'] ?? null))
                                        <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy">
                                    @else
                                        <span class="material-symbols-outlined" aria-hidden="true">image</span>
                                    @endif
                                </a>
                                <button
                                    type="button"
                                    class="flash-product-like{{ ($product['is_wishlisted'] ?? false) ? ' is-liked' : '' }}"
                                    data-flash-wishlist
                                    data-product-id="{{ (int) $product['id'] }}"
                                    data-product-name="{{ $product['name'] }}"
                                    aria-pressed="{{ ($product['is_wishlisted'] ?? false) ? 'true' : 'false' }}"
                                    aria-label="{{ ($product['is_wishlisted'] ?? false) ? 'Remove' : 'Save' }} {{ $product['name'] }} {{ ($product['is_wishlisted'] ?? false) ? 'from' : 'to' }} My Likes"
                                >
                                    <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
                                </button>
                            </div>
                            <div class="flash-product-copy">
                                <span class="flash-product-category">{{ $product['category'] }}</span>
                                <h3><a href="{{ route('products.show', ['product' => $product['id']]) }}">{{ $product['name'] }}</a></h3>
                                <div class="flash-product-price-line">
                                    <strong>₱{{ number_format(((int) $product['price_minor']) / 100, 2) }}</strong>
                                    @include('buyer.partials.product-price-meta', [
                                        'current' => ((int) $product['price_minor']) / 100,
                                        'regular' => ((int) ($product['regular_price_minor'] ?? $product['price_minor'])) / 100,
                                        'discount' => (int) ($product['discount_percent'] ?? 0),
                                        'price_source' => $product['price_source'] ?? null,
                                        'campaign_name' => $product['campaign_name'] ?? null,
                                        'product_discount_name' => $product['product_discount_name'] ?? null,
                                    ])
                                </div>
                                @if (filled($product['store_slug'] ?? null))
                                    <a class="flash-product-store" href="{{ route('stores.show', ['store' => $product['store_slug']]) }}">Sold by {{ $product['seller_name'] }}</a>
                                @else
                                    <span class="flash-product-store">Sold by {{ $product['seller_name'] }}</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </article>
        @empty
            <div class="top-products-empty flash-live-empty" role="status">
                <span class="material-symbols-outlined" aria-hidden="true">local_offer</span>
                <h2>No live Flash Deal products right now</h2>
                <p>Approved, in-stock COD products will appear here while their Flash Deal campaign price is active.</p>
                <a href="{{ route('products.index') }}">Browse live products</a>
            </div>
        @endforelse
    </section>
    <p id="flash-likes-status" class="flash-likes-status" role="status" aria-live="polite" hidden></p>
</main>
<footer class="footer"><a href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly home" width="110" height="37"></a><p>Good finds. Happy spaces.</p><span>Flash Deals</span></footer>
</body>
</html>
