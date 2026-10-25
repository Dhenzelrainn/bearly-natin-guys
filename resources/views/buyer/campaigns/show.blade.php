<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $campaign['name'] }} | Bearly</title>
    <meta name="description" content="{{ Str::limit(strip_tags((string) ($campaign['description'] ?? '')), 150) ?: $campaign['name'].' campaign on Bearly' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
</head>
<body class="bh buyer-search-page buyer-campaign-page">
    @include('buyer.partials.buyer-header', [
        'headerSearchInputId' => 'buyer-campaign-page-input',
        'headerSearchAction' => route('products.index'),
        'headerSearchValue' => '',
        'headerShowCategorySelect' => false,
    ])

    <main class="buyer-search-shell">
        <nav class="buyer-search-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('home') }}">Home</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('flash-deals') }}">Deals</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $campaign['name'] }}</span>
        </nav>

        <header class="campaign-hero{{ $campaign['banner_url'] ? ' has-banner' : '' }}">
            @if ($campaign['banner_url'])
                <img class="campaign-hero-banner" src="{{ $campaign['banner_url'] }}" alt="{{ $campaign['banner_title'] ?: $campaign['name'].' campaign banner' }}">
            @endif
            <div class="campaign-hero-copy">
                <p class="buyer-search-eyebrow">{{ $campaign['type_label'] }}</p>
                <h1>{{ $campaign['name'] }}</h1>
                <p class="campaign-hero-period">{{ $campaign['period'] }}</p>

                <div class="campaign-hero-meta">
                    <span class="campaign-hero-funding">{{ $campaign['funding_label'] }}</span>
                    @if (! empty($campaign['store_names']))
                        <span>Participating {{ Str::plural('store', count($campaign['store_names'])) }}: {{ implode(', ', $campaign['store_names']) }}</span>
                    @endif
                </div>

                @if ($campaign['description'])
                    <p class="campaign-hero-description">{{ $campaign['description'] }}</p>
                @endif
            </div>
        </header>

        <section class="buyer-search-products campaign-products" aria-labelledby="campaign-products-title">
            <div class="buyer-search-section-heading">
                <div>
                    <p class="buyer-search-eyebrow">Campaign picks</p>
                    <h2 id="campaign-products-title">Products</h2>
                </div>
                <span>{{ number_format(count($productCards)) }} {{ Str::plural('product', count($productCards)) }}</span>
            </div>

            @if ($productCards !== [])
                <div class="buyer-search-product-grid">
                    @foreach ($productCards as $product)
                        <article class="buyer-search-product-card">
                            <a class="buyer-search-product-image" href="{{ route('products.show', ['product' => $product['id']]) }}">
                                @if ($product['image'])
                                    <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy">
                                @else
                                    <span class="material-symbols-outlined" aria-hidden="true">image</span>
                                @endif
                            </a>
                            <div class="buyer-search-product-copy">
                                <p>{{ $product['category'] }}</p>
                                <h3><a href="{{ route('products.show', ['product' => $product['id']]) }}">{{ $product['name'] }}</a></h3>
                                <div class="buyer-search-price-line">
                                    <strong class="buyer-search-price">₱{{ number_format((float) $product['price'], 2) }}</strong>
                                    @include('buyer.partials.product-price-meta', [
                                        'current' => (float) $product['price'],
                                        'regular' => (float) ($product['regular_price'] ?? $product['price']),
                                        'discount' => (int) ($product['discount_percent'] ?? 0),
                                        'price_source' => $product['price_source'] ?? null,
                                        'campaign_name' => $product['campaign_name'] ?? null,
                                        'product_discount_name' => $product['product_discount_name'] ?? null,
                                    ])
                                </div>
                                @if ($product['store_slug'])
                                    <a class="buyer-search-seller" href="{{ route('stores.show', ['store' => $product['store_slug']]) }}">
                                        Sold by {{ $product['seller_name'] }}
                                    </a>
                                @else
                                    <span class="buyer-search-seller">Sold by {{ $product['seller_name'] }}</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="buyer-search-empty">
                    <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                    <h3>This campaign has no available products right now</h3>
                    <p>Check back soon, or browse the other live Bearly campaigns from the home page.</p>
                    <a class="campaign-empty-link" href="{{ route('home') }}">Browse live campaigns</a>
                </div>
            @endif
        </section>
    </main>
</body>
</html>
