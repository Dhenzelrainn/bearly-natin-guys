<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search “{{ $searchTerm }}” | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
</head>
<body class="bh buyer-search-page">
    @include('buyer.partials.buyer-header', [
        'headerSearchInputId' => 'buyer-search-page-input',
        'headerSearchAction' => route('products.index'),
        'headerSearchValue' => $searchTerm,
        'headerShowCategorySelect' => false,
    ])

    <main class="buyer-search-shell">
        <nav class="buyer-search-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('home') }}">Home</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">Search results</span>
        </nav>

        <header class="buyer-search-heading">
            <p class="buyer-search-eyebrow">Bearly marketplace</p>
            <h1>Search results</h1>
            <p>Results for <strong>“{{ $searchTerm }}”</strong></p>
        </header>

        @if ($stores !== [])
            <section class="buyer-search-stores" aria-labelledby="buyer-search-stores-title">
                <div class="buyer-search-section-heading">
                    <div>
                        <p class="buyer-search-eyebrow">Find a seller</p>
                        <h2 id="buyer-search-stores-title">Stores matching your search</h2>
                    </div>
                    <span>{{ count($stores) }} {{ count($stores) === 1 ? 'store' : 'stores' }}</span>
                </div>

                <div class="buyer-search-store-grid">
                    @foreach ($stores as $store)
                        <article class="buyer-search-store-card">
                            <a class="buyer-search-store-identity" href="{{ route('stores.show', ['store' => $store['slug']]) }}">
                                <span class="buyer-search-store-logo">
                                    @if ($store['logo_url'])
                                        <img src="{{ $store['logo_url'] }}" alt="{{ $store['name'] }} logo" loading="lazy">
                                    @else
                                        <span aria-hidden="true">{{ strtoupper(substr($store['name'], 0, 1)) }}</span>
                                    @endif
                                </span>
                                <span class="buyer-search-store-copy">
                                    <strong>{{ $store['name'] }}</strong>
                                    <span>{{ $store['description'] ?: 'Browse published products from this Bearly store.' }}</span>
                                </span>
                            </a>
                            <a class="buyer-search-store-link" href="{{ route('stores.show', ['store' => $store['slug']]) }}">
                                View Store <span aria-hidden="true">→</span>
                            </a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="buyer-search-products" aria-labelledby="buyer-search-products-title">
            <div class="buyer-search-section-heading">
                <div>
                    <p class="buyer-search-eyebrow">Relevant finds</p>
                    <h2 id="buyer-search-products-title">Products</h2>
                </div>
                <span>{{ number_format($products->total()) }} {{ $products->total() === 1 ? 'result' : 'results' }}</span>
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
                                        'campaign_name' => $product['product_discount_name'] ?? null,
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

                @if ($products->hasPages())
                    <nav class="buyer-search-pagination" aria-label="Search result pages">
                        @if ($products->onFirstPage())
                            <span aria-disabled="true">Previous</span>
                        @else
                            <a href="{{ $products->previousPageUrl() }}" rel="prev">Previous</a>
                        @endif
                        <span>Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>
                        @if ($products->hasMorePages())
                            <a href="{{ $products->nextPageUrl() }}" rel="next">Next</a>
                        @else
                            <span aria-disabled="true">Next</span>
                        @endif
                    </nav>
                @endif
            @else
                <div class="buyer-search-empty">
                    <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                    <h3>No matching products yet</h3>
                    <p>Try a different product, category, or store name.</p>
                </div>
            @endif
        </section>
    </main>
</body>
</html>
