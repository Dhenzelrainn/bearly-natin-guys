@php
$homeCategories = json_decode(file_get_contents(resource_path('data/buyer-categories.json')), true, 512, JSON_THROW_ON_ERROR);

$categoryProductSources = [
    'pet-supplies' => ['file' => 'buyer-pet-supplies-products.json', 'name' => 'Pet Supplies', 'atlas' => 'pet-supplies-catalog-atlas.png'],
    'electronics-and-gadgets' => ['file' => 'buyer-electronics-gadgets-products.json', 'name' => 'Electronics and Gadgets', 'atlas' => 'electronics-gadgets-catalog-atlas.png'],
    'women-s-apparel' => ['file' => 'buyer-womens-products.json', 'name' => "Women's Apparel", 'atlas' => 'womens-catalog-atlas.png'],
    'men-s-apparel' => ['file' => 'buyer-mens-products (1).json', 'name' => "Men's Apparel", 'atlas' => 'mens-catalog-atlas.png', 'atlas_columns' => 4, 'atlas_rows' => 4],
    'kids-and-baby' => ['file' => 'buyer-kids-baby-products.json', 'name' => 'Kids and Baby', 'atlas' => 'kids-baby-catalog-atlas.png'],
    'home-and-garden' => ['file' => 'buyer-home-garden-products.json', 'name' => 'Home and Garden', 'atlas' => 'home-garden-catalog-atlas.png'],
    'sports-and-outdoors' => ['file' => 'buyer-sports-products.json', 'name' => 'Sports and Outdoors', 'atlas' => 'sports-outdoors-catalog-atlas.png'],
    'health-and-beauty' => ['file' => 'buyer-health-beauty-products.json', 'name' => 'Health and Beauty', 'atlas' => 'health-beauty-catalog-atlas.png'],
    'books-and-media' => ['file' => 'buyer-books-media-products.json', 'name' => 'Books and Media', 'atlas' => 'books-media-catalog-atlas.png'],
    'jewelry-and-watches' => ['file' => 'buyer-jewelry-watches-products.json', 'name' => 'Jewelry and Watches', 'atlas' => 'jewelry-watches-catalog-atlas.png'],
    'food-and-gourmet' => ['file' => 'buyer-foods-gourmet-products.json', 'name' => 'Foods and Gourmet', 'atlas' => 'foods-gourmet-catalog-atlas.png'],
    'furniture-and-office-equipment' => ['file' => 'buyer-furniture-office-products.json', 'name' => 'Furniture and Office Equipment', 'atlas' => 'furniture-office-catalog-atlas.png'],
];

$homeProductsByCategory = [];
foreach ($categoryProductSources as $slug => $source) {
    $categoryProducts = json_decode(file_get_contents(resource_path('data/' . $source['file'])), true, 512, JSON_THROW_ON_ERROR);
    $homeProductsByCategory[$slug] = [];

    foreach (array_slice($categoryProducts, 0, 5) as $product) {
        $product['id'] = 'home-' . $slug . '-' . $product['id'];
        $product['category'] = $source['name'];
        $product['category_slug'] = $slug;
        $product['atlas'] = asset('images/' . $source['atlas']);
        $product['atlas_columns'] = $source['atlas_columns'] ?? 5;
        $product['atlas_rows'] = $source['atlas_rows'] ?? 6;
        $homeProductsByCategory[$slug][] = $product;
    }
}

$homeProducts = [];
for ($round = 0; $round < 5; $round++) {
    foreach (array_keys($categoryProductSources) as $slug) {
        if (isset($homeProductsByCategory[$slug][$round])) {
            $homeProducts[] = $homeProductsByCategory[$slug][$round];
        }
    }
}

$featuredProducts = [];
$featuredProductsByKey = [];
foreach ($categoryProductSources as $slug => $source) {
    $allCategoryProducts = json_decode(
        file_get_contents(resource_path('data/' . $source['file'])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    foreach ($allCategoryProducts as $product) {
        $product['featured_key'] = $slug . ':' . $product['id'];
        $product['category'] = $source['name'];
        $product['category_slug'] = $slug;
        $product['atlas'] = asset('images/' . $source['atlas']);
        $product['atlas_columns'] = $source['atlas_columns'] ?? 5;
        $product['atlas_rows'] = $source['atlas_rows'] ?? 6;
        $featuredProducts[] = $product;
        $featuredProductsByKey[$product['featured_key']] = $product;
    }
}

$featurePhotoStyle = static function (array $product): string {
    $columns = max(1, (int) ($product['atlas_columns'] ?? 6));
    $rows = max(1, (int) ($product['atlas_rows'] ?? 6));
    $photoIndex = max(0, (int) ($product['photo'] ?? 0));
    $column = $photoIndex % $columns;
    $row = intdiv($photoIndex, $columns);
    $x = $columns > 1 ? ($column * 100) / ($columns - 1) : 0;
    $y = $rows > 1 ? ($row * 100) / ($rows - 1) : 0;

    return sprintf(
        'display:block;position:static;left:auto;top:auto;width:100%%;height:100%%;padding:0;border-radius:0;background-image:url("%s");background-size:%d00%% %d00%%;background-position:%s%% %s%%;background-repeat:no-repeat;background-color:#f7f3ee;color:transparent;font-size:0;font-weight:400;',
        $product['atlas'],
        $columns,
        $rows,
        number_format($x, 4, '.', ''),
        number_format($y, 4, '.', '')
    );
};

$flashDeals = [
    ['key' => 'flash-earbuds', 'product_key' => 'electronics-and-gadgets:7', 'price' => 599, 'original_price' => 799, 'discount' => '25% OFF', 'claimed' => 72],
    ['key' => 'flash-cleanser', 'product_key' => 'health-and-beauty:1', 'price' => 349, 'original_price' => 499, 'discount' => '30% OFF', 'claimed' => 64],
    ['key' => 'flash-tee', 'product_key' => 'men-s-apparel:1', 'price' => 329, 'original_price' => 449, 'discount' => '27% OFF', 'claimed' => 81],
    ['key' => 'flash-watch', 'product_key' => 'jewelry-and-watches:21', 'price' => 999, 'original_price' => 1499, 'discount' => '33% OFF', 'claimed' => 58],
];

$topProducts = [
    ['key' => 'top-electronics-and-gadgets-5', 'product_key' => 'electronics-and-gadgets:5', 'rank' => 1, 'sold' => '2.8k sold'],
    ['key' => 'top-men-s-apparel-4', 'product_key' => 'men-s-apparel:4', 'rank' => 2, 'sold' => '2.4k sold'],
    ['key' => 'top-health-and-beauty-5', 'product_key' => 'health-and-beauty:5', 'rank' => 3, 'sold' => '2.1k sold'],
    ['key' => 'top-home-and-garden-1', 'product_key' => 'home-and-garden:1', 'rank' => 4, 'sold' => '1.9k sold'],
    ['key' => 'top-women-s-apparel-6', 'product_key' => 'women-s-apparel:6', 'rank' => 5, 'sold' => '1.8k sold'],
    ['key' => 'top-sports-and-outdoors-11', 'product_key' => 'sports-and-outdoors:11', 'rank' => 6, 'sold' => '1.7k sold'],
];

$topSales = [
    ['key' => 'sales-sports-and-outdoors-11', 'product_key' => 'sports-and-outdoors:11', 'rank' => 1, 'sold' => '4.6k sold'],
    ['key' => 'sales-electronics-and-gadgets-5', 'product_key' => 'electronics-and-gadgets:5', 'rank' => 2, 'sold' => '4.2k sold'],
    ['key' => 'sales-kids-and-baby-2', 'product_key' => 'kids-and-baby:2', 'rank' => 3, 'sold' => '3.9k sold'],
    ['key' => 'sales-home-and-garden-1', 'product_key' => 'home-and-garden:1', 'rank' => 4, 'sold' => '3.5k sold'],
    ['key' => 'sales-food-and-gourmet-5', 'product_key' => 'food-and-gourmet:5', 'rank' => 5, 'sold' => '3.2k sold'],
    ['key' => 'sales-men-s-apparel-4', 'product_key' => 'men-s-apparel:4', 'rank' => 6, 'sold' => '2.9k sold'],
];

$buyer = auth()->user();
$buyerName = $buyer?->name ?: 'Buyer';
$buyerFirstName = $buyer?->first_name ?: (explode(' ', $buyerName)[0] ?? 'Buyer');

@endphp

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<meta name="description" content="Discover everyday finds across Bearly's twelve shopping categories.">

<title>Bearly — A find for everyone</title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">

@vite([
    'resources/css/buyer.css',
    'resources/css/bearly-promo-slider.css',
    'resources/js/buyer.js',
    'resources/js/bearly-promo-slider.js'
])

@include('partials.session-safety')

</head>
<body class="bh" data-buyer-name="{{ $buyerName }}" data-buyer-first-name="{{ $buyerFirstName }}" data-buyer-email="{{ $buyer?->email }}" style="--buyer-product-atlas: url('{{ asset('images/product-atlas.png') }}'); --buyer-outdoor-banner: url('{{ asset('images/outdoor-banner.png') }}')">

<a class="skip-link" href="#main">Skip to products</a>

<header class="header">

<button class="icon-button mobile-menu" id="menu-toggle" type="button" aria-label="Open categories" aria-expanded="false" aria-controls="sidebar">
    <span class="material-symbols-outlined" aria-hidden="true">menu</span>
</button>

<a class="brand" href="{{ route('home') }}" aria-label="Bearly home">
    <img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly" width="192" height="64">
</a>

<form class="search" id="search-form" role="search">
    <label class="sr-only" for="search-category">Search category</label>
    <select id="search-category">
        <option value="">All categories</option>
    </select>

    <label class="sr-only" for="search-input">Search products</label>
    <input id="search-input" type="search" placeholder="Search for anything on Bearly" maxlength="120" autocomplete="off">

    <button type="submit" aria-label="Search">
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
    </button>
</form>

<nav class="header-actions" aria-label="Buyer account">
    <button type="button" data-info="orders">
        <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
        <span>Orders</span>
    </button>

    <button type="button" data-info="chat" aria-controls="chat-drawer" aria-expanded="false">
        <span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>
        <span>Chat</span>
    </button>

    <a href="{{ url('/cart') }}" aria-controls="cart-drawer" aria-expanded="false">
        <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
        <span>Cart</span>
    </a>

    <a class="account-action" href="{{ route('home') }}" title="{{ $buyer?->email }}" aria-label="Signed in as {{ $buyerName }}">
        <span class="material-symbols-outlined" aria-hidden="true">person</span>
        <span>{{ $buyerName }}</span>
    </a>
</nav>

</header>

<div class="shell">
<button id="sidebar-backdrop" class="sidebar-backdrop" aria-label="Close categories" hidden></button>

<aside class="sidebar" id="sidebar" aria-label="Product categories">

<div class="sidebar-title"><span class="material-symbols-outlined" aria-hidden="true">menu</span><strong>Shop by category</strong><button id="menu-close" class="icon-button" aria-label="Close categories"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
<nav id="category-nav" class="sidebar-category-list" aria-label="Shop by category"></nav>

<button class="chat-button" type="button" data-info="chat" aria-controls="chat-drawer" aria-expanded="false">
    <span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>
    Chat
</button>

<div class="buyer-sidebar-footer">
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="seller-link buyer-sidebar-logout">
            <span class="material-symbols-outlined" aria-hidden="true">logout</span>
            <span>Logout</span>
        </button>
    </form>
</div>

</aside>

<main id="main" tabindex="-1">

<div id="editorial">

<section class="bearly-promo-slider" id="bearly-promo-slider" aria-roledescription="carousel" aria-label="Bearly promotions">
    <div class="bearly-promo-track">
        <article class="bearly-promo-slide is-active bearly-promo-welcome" aria-hidden="false">
            <img
                class="bearly-promo-bg"
                src="{{ asset('images/marketplace-hero.png') }}"
                alt="Headphones, sneakers, a tote bag and desk essentials on warm stone displays"
                fetchpriority="high"
                width="1536"
                height="1024"
            >
            <div class="bearly-promo-overlay"></div>

            <div class="bearly-promo-copy">
                <p class="eyebrow">Welcome to your everyday marketplace</p>
                <h1>A little of everything.<br>A find for everyone.</h1>
                <p>From daily essentials to your next favorite thing.</p>
            </div>
        </article>

        <article class="bearly-promo-slide bearly-promo-voucher" aria-hidden="true">
            <div class="bearly-promo-copy">
                <p class="eyebrow">A little thank-you from Bearly</p>
                <h2>₱100 off your<br>next good find.</h2>
                <p>Use your Bearly voucher on a minimum spend of ₱799.</p>

                <div class="bearly-code-row">
                    <span class="bearly-code-label">Voucher code</span>
                    <strong>BEARLY100</strong>
                </div>
            </div>

            <div class="bearly-promo-art" aria-hidden="true">
                <div class="bearly-ticket">
                    <div class="bearly-ticket-top">
                        <span class="material-symbols-outlined">confirmation_number</span>
                        <span>Bearly voucher</span>
                    </div>
                    <div class="bearly-ticket-value">₱100</div>
                    <div class="bearly-ticket-off">OFF</div>
                    <div class="bearly-ticket-rule"></div>
                    <div class="bearly-ticket-small">Min. spend ₱799</div>
                </div>
            </div>
        </article>

        <article class="bearly-promo-slide bearly-promo-shipping" aria-hidden="true">
            <div class="bearly-promo-copy">
                <p class="eyebrow">More finds, less checkout stress</p>
                <h2>Free shipping,<br>happier shopping.</h2>
                <p>Enjoy free shipping on selected Bearly finds and participating shops.</p>

                <div class="bearly-promo-pill">
                    <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
                    Selected orders
                </div>
            </div>

            <div class="bearly-promo-art" aria-hidden="true">
                <div class="bearly-package package-one">
                    <span class="material-symbols-outlined">inventory_2</span>
                </div>
                <div class="bearly-package package-two">
                    <span class="material-symbols-outlined">redeem</span>
                </div>
                <div class="bearly-shipping-badge">
                    <span class="material-symbols-outlined">local_shipping</span>
                    <strong>FREE</strong>
                    <small>shipping</small>
                </div>
            </div>
        </article>

        <article class="bearly-promo-slide bearly-promo-payday" aria-hidden="true">
            <div class="bearly-promo-copy">
                <p class="eyebrow">Bearly payday picks</p>
                <h2>Good finds feel<br>even better on sale.</h2>
                <p>Save up to 30% on selected everyday favorites while the promo lasts.</p>

                <div class="bearly-promo-pill">
                    <span class="material-symbols-outlined" aria-hidden="true">sell</span>
                    Up to 30% off
                </div>
            </div>

            <div class="bearly-promo-art" aria-hidden="true">
                <div class="bearly-sale-orbit orbit-one">10%</div>
                <div class="bearly-sale-orbit orbit-two">20%</div>
                <div class="bearly-sale-card">
                    <span>PAYDAY</span>
                    <strong>30%</strong>
                    <small>OFF</small>
                </div>
            </div>
        </article>
    </div>

    <button class="bearly-slider-arrow bearly-slider-prev" type="button" aria-label="Previous promotion">
        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
    </button>

    <button class="bearly-slider-arrow bearly-slider-next" type="button" aria-label="Next promotion">
        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
    </button>

    <div class="bearly-slider-dots" role="tablist" aria-label="Choose promotion">
        <button type="button" class="is-active" role="tab" aria-selected="true" aria-label="Show promotion 1" data-slide="0"></button>
        <button type="button" role="tab" aria-selected="false" aria-label="Show promotion 2" data-slide="1"></button>
        <button type="button" role="tab" aria-selected="false" aria-label="Show promotion 3" data-slide="2"></button>
        <button type="button" role="tab" aria-selected="false" aria-label="Show promotion 4" data-slide="3"></button>
    </div>

    <div class="bearly-slider-progress" aria-hidden="true">
        <span></span>
    </div>
</section>

<section class="promos" aria-label="Featured collections">
<button class="promo olive" data-category="electronics-and-gadgets"><div><h2>Electronics and Gadgets</h2><p>Tech for work, play and everything in between.</p><strong>Explore electronics <span aria-hidden="true">→</span></strong></div><span class="product-photo" style="--x:33.3333%;--y:0%" role="img" aria-label="Olive wireless headphones"></span></button>
<button class="promo sand" data-category="health-and-beauty"><div><h2>Everyday, upgraded.</h2><p>A little care for your everyday routine.</p><strong>Explore health and beauty <span aria-hidden="true">→</span></strong></div><span class="product-photo" style="--x:100%;--y:33.3333%" role="img" aria-label="Sunscreen bottle"></span></button>

</section>

</div>

<section class="flash-deals-preview" aria-labelledby="flash-deals-title">
    <div class="flash-deals-heading">
        <div>
            <p class="flash-kicker"><span class="material-symbols-outlined" aria-hidden="true">bolt</span> Limited-time offers</p>
            <h2 id="flash-deals-title">Flash Deals</h2>
        </div>
        <div class="flash-deals-actions">
            <div class="flash-countdown" data-flash-countdown aria-label="Flash deal countdown">
                <span data-hours>00</span><b>:</b><span data-minutes>00</span><b>:</b><span data-seconds>00</span>
            </div>
            <a class="text-button flash-view-all" href="{{ route('products.index') }}">Browse all <span aria-hidden="true">→</span></a>
        </div>
    </div>

    <div class="flash-deal-grid flash-deal-grid-preview">
        @foreach ($flashDeals as $deal)
            @php($featured = $featuredProductsByKey[$deal['product_key']] ?? null)
            @if ($featured)
                <article
                    class="flash-deal-card"
                    data-flash-product
                    data-key="{{ $deal['key'] }}"
                    data-product-id="{{ $featured['category_slug'] }}:{{ $featured['id'] }}"
                    data-name="{{ $featured['name'] }}"
                    data-price="{{ $deal['price'] }}"
                    data-original-price="{{ $deal['original_price'] }}"
                    data-flash-category="{{ $featured['category'] }}"
                >
                    <button type="button" class="flash-image" data-feature-open aria-label="Preview {{ $featured['name'] }}">
                        <span aria-hidden="true" style="{{ $featurePhotoStyle($featured) }}"></span>
                        <span>{{ $deal['discount'] }}</span>
                    </button>
                    <div class="flash-copy">
                        <small>{{ $featured['category'] }}</small>
                        <h3>{{ $featured['name'] }}</h3>
                        <div class="flash-prices"><strong>₱{{ number_format($deal['price']) }}</strong><del>₱{{ number_format($deal['original_price']) }}</del></div>
                        <div class="flash-stock"><span style="--sold:{{ $deal['claimed'] }}%"></span><small>{{ $deal['claimed'] }}% claimed</small></div>
                        <button type="button" class="flash-add" data-flash-add>Add to Cart</button>
                    </div>
                </article>
            @endif
        @endforeach
    </div>
</section>

<section class="top-products-preview" aria-labelledby="top-products-title">
    <div class="top-products-heading">
        <div>
            <p class="top-products-kicker"><span class="material-symbols-outlined" aria-hidden="true">workspace_premium</span> Popular on Bearly</p>
            <h2 id="top-products-title">Top Products</h2>
            <p>Highly rated finds buyers keep coming back to.</p>
        </div>
        <a class="text-button top-view-all" href="{{ route('products.index') }}">Browse all <span aria-hidden="true">→</span></a>
    </div>

    <div class="top-products-grid top-products-grid-preview">
        @foreach ($topProducts as $item)
            @php($featured = $featuredProductsByKey[$item['product_key']] ?? null)
            @if ($featured)
                <article
                    class="top-product-card{{ $item['rank'] <= 3 ? ' top-rank-podium' : '' }}"
                    data-top-product
                    data-key="{{ $item['key'] }}"
                    data-product-id="{{ $featured['category_slug'] }}:{{ $featured['id'] }}"
                    data-name="{{ $featured['name'] }}"
                    data-price="{{ $featured['price'] }}"
                    data-top-category="{{ $featured['category'] }}"
                >
                    <div class="top-product-image">
                        <span aria-hidden="true" style="{{ $featurePhotoStyle($featured) }}"></span>
                        <span class="top-rank">#{{ $item['rank'] }}</span>
                        <button type="button" class="top-like" data-top-like aria-label="Save {{ $featured['name'] }}">
                            <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
                        </button>
                    </div>
                    <div class="top-product-copy">
                        <small>{{ $featured['category'] }}</small>
                        <h3>{{ $featured['name'] }}</h3>
                        <div class="top-product-meta"><span><b>★ {{ number_format((float) ($featured['rating'] ?? 0), 1) }}</b></span><span>{{ $item['sold'] }}</span></div>
                        <div class="top-product-bottom"><strong>₱{{ number_format((int) $featured['price']) }}</strong></div>
                    </div>
                </article>
            @endif
        @endforeach
    </div>
</section>

<section class="top-products-preview top-sales-preview" aria-labelledby="top-sales-title">
    <div class="top-products-heading">
        <div>
            <p class="top-products-kicker"><span class="material-symbols-outlined" aria-hidden="true">trending_up</span> Best sellers</p>
            <h2 id="top-sales-title">Top Sales</h2>
            <p>Products moving fastest across Bearly right now.</p>
        </div>
        <a class="text-button top-view-all" href="{{ route('products.index') }}">Browse all <span aria-hidden="true">→</span></a>
    </div>

    <div class="top-products-grid top-products-grid-preview">
        @foreach ($topSales as $item)
            @php($featured = $featuredProductsByKey[$item['product_key']] ?? null)
            @if ($featured)
                <article
                    class="top-product-card{{ $item['rank'] <= 3 ? ' top-rank-podium' : '' }}"
                    data-top-product
                    data-key="{{ $item['key'] }}"
                    data-product-id="{{ $featured['category_slug'] }}:{{ $featured['id'] }}"
                    data-name="{{ $featured['name'] }}"
                    data-price="{{ $featured['price'] }}"
                    data-top-category="{{ $featured['category'] }}"
                >
                    <div class="top-product-image">
                        <span aria-hidden="true" style="{{ $featurePhotoStyle($featured) }}"></span>
                        <span class="top-rank">#{{ $item['rank'] }}</span>
                        <button type="button" class="top-like" data-top-like aria-label="Save {{ $featured['name'] }}">
                            <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
                        </button>
                    </div>
                    <div class="top-product-copy">
                        <small>{{ $featured['category'] }}</small>
                        <h3>{{ $featured['name'] }}</h3>
                        <div class="top-product-meta"><span><b>★ {{ number_format((float) ($featured['rating'] ?? 0), 1) }}</b></span><span>{{ $item['sold'] }}</span></div>
                        <div class="top-product-bottom"><strong>₱{{ number_format((int) $featured['price']) }}</strong></div>
                    </div>
                </article>
            @endif
        @endforeach
    </div>
</section>

<div class="flash-toast" data-flash-toast data-top-toast role="status" aria-live="polite"></div>

<section class="discover" id="results" aria-labelledby="results-title">
<div class="section-heading"><div><h2 id="results-title">Daily discoveries</h2><p id="results-caption">Find something good across Bearly.</p></div><button class="text-button" id="view-all">View all <span aria-hidden="true">→</span></button></div>
<div class="results-tools" id="results-tools" hidden><div id="active-filters"></div><label>Sort by <select id="sort"><option value="featured">Featured</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option><option value="name">Name: A–Z</option></select></label></div>

<p class="preview-note">Preview catalog · Illustrative products and sample prices</p>

<div class="product-grid" id="product-grid"></div>
<div class="empty" id="empty" hidden><span class="material-symbols-outlined" aria-hidden="true">search_off</span><h3>No matching finds yet</h3><p>Try another search or explore a different category.</p><button class="button" id="reset-search">Browse all products</button></div>

<noscript><p>Enable JavaScript to browse this homepage’s sample catalog.</p></noscript>

</section>
<section class="outdoor" id="outdoor" aria-labelledby="outdoor-title"><div><p class="eyebrow">Sports and Outdoors</p><h2 id="outdoor-title">Make more of<br>your outside time.</h2><p>A little movement. A fresh perspective.</p><button class="button gold" data-category="sports-and-outdoors">Explore the collection <span aria-hidden="true">→</span></button></div><img src="{{ asset('images/outdoor-banner.png') }}" alt="Outdoor essentials ready for a day away" width="1536" height="1024" loading="lazy"></section>
<section id="more-section" aria-labelledby="more-title"><div class="section-heading"><div><h2 id="more-title">More to explore</h2><p>More finds for every part of your day.</p></div></div><div class="product-grid" id="more-grid"></div></section>

<div class="load-area"><button class="button outline" id="load-more">Load more products <span class="material-symbols-outlined" aria-hidden="true">expand_more</span></button><p id="result-count" role="status" aria-live="polite"></p></div>

</main>

</div>
<footer class="footer buyer-footer">
    <div class="buyer-footer-brand">
        <a href="{{ url('/home') }}" aria-label="Bearly home">
        <img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly" width="132" height="44">
        </a>
        <p>A kinder marketplace<br>for everyone.</p>
    </div>

    <nav class="buyer-footer-column" aria-label="Shop">
        <strong>Shop</strong>
        <a href="{{ route('home') }}">All categories</a>
        <a href="{{ route('products.index') }}">All products</a>
    </nav>

    <nav class="buyer-footer-column" aria-label="Support">
        <strong>Support</strong>
        <a href="{{ route('contact') }}#help-topics-title">Help center</a>
        <a href="{{ route('contact') }}#help-topics-title">Delivery &amp; tracking</a>
        <a href="{{ route('contact') }}#contact-form">Returns &amp; refunds</a>
    </nav>

    <nav class="buyer-footer-column" aria-label="About Bearly">
        <strong>About</strong>
        <a href="{{ route('about') }}">About Bearly</a>
        <a href="{{ route('contact') }}">Contact us</a>
    </nav>

    <div class="buyer-footer-meta">
        <span>Shopping made Bearly a hassle.</span>
        <small>© {{ date('Y') }} Bearly. All rights reserved.</small>
    </div>
</footer>
<div class="chat-drawer-backdrop" id="chat-drawer-backdrop" data-chat-close hidden></div>
<aside class="chat-drawer" id="chat-drawer" aria-label="Chat with sellers" aria-hidden="true">
    <header class="chat-drawer-header">
        <div><p class="eyebrow">Buyer messages</p><h2>Chat</h2></div>
        <button class="icon-button" type="button" data-chat-close aria-label="Close chat"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </header>
    <div class="chat-drawer-body">
        <div class="chat-conversations" aria-label="Seller conversations">
            <button class="chat-conversation is-active" type="button" data-chat-conversation="Greenline Home">
                <span class="chat-store-avatar">GH</span><span><strong>Greenline Home</strong><small>Your desk lamp is on the way.</small></span><time>2m</time>
            </button>
            <button class="chat-conversation" type="button" data-chat-conversation="Sundays Market">
                <span class="chat-store-avatar is-sand">SM</span><span><strong>Sundays Market</strong><small>Thanks for your order!</small></span><time>Yesterday</time>
            </button>
        </div>
        <section class="chat-thread" aria-label="Current conversation">
            <div class="chat-thread-heading"><span class="chat-store-avatar">GH</span><div><strong>Greenline Home</strong><small>Usually replies within an hour</small></div></div>
            <div class="chat-messages" data-chat-messages>
                <p class="chat-date">Today</p>
                <div class="chat-bubble seller">Hi {{ $buyerFirstName }}! Your desk lamp has been handed to the courier.</div>
                <div class="chat-bubble buyer">Great, thank you for the update!</div>
            </div>
            <form class="chat-composer" data-chat-form>
                <label class="sr-only" for="chat-message">Message seller</label>
                <input id="chat-message" type="text" placeholder="Write a message..." autocomplete="off" maxlength="240">
                <button type="submit" aria-label="Send message"><span class="material-symbols-outlined" aria-hidden="true">send</span></button>
            </form>
        </section>
    </div>
</aside>

<div class="cart-drawer-backdrop" id="cart-drawer-backdrop" data-cart-close hidden></div>
<aside class="cart-drawer" id="cart-drawer" aria-label="Shopping cart" aria-hidden="true">
    <header class="cart-drawer-header"><div><p class="eyebrow">Your finds</p><h2>Cart</h2></div><button class="icon-button" type="button" data-cart-close aria-label="Close cart"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>
    <div class="cart-drawer-content" data-cart-content></div>
</aside>

<dialog id="product-dialog" aria-labelledby="product-title"><button class="dialog-close icon-button" data-close aria-label="Close product details"><span class="material-symbols-outlined" aria-hidden="true">close</span></button><div id="product-detail"></div></dialog>
<dialog id="info-dialog" aria-labelledby="info-title"><button class="dialog-close icon-button" data-close aria-label="Close"><span class="material-symbols-outlined" aria-hidden="true">close</span></button><h2 id="info-title"></h2><div id="info-copy"></div><div class="info-actions" id="info-actions"></div></dialog>
<script id="home-data" type="application/json">{!! json_encode(['categories' => $homeCategories, 'products' => $homeProducts], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
<script id="featured-product-data" type="application/json">{!! json_encode($featuredProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>

</body>

</html>
