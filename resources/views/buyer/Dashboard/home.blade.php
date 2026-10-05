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

        $originalProductId = $product['id'];
        $product['id'] = 'home-' . $slug . '-' . $product['id'];

        $product['category'] = $source['name'];

        $product['category_slug'] = $slug;
        $product['wishlist_key'] = $slug . ':' . $originalProductId;

        $product['atlas'] = asset('images/' . $source['atlas']);

        $product['atlas_columns'] = $source['atlas_columns'] ?? 5;

        $product['atlas_rows'] = $source['atlas_rows'] ?? 6;

        $homeProductsByCategory[$slug][] = $product;

    }

}



$featuredProducts = [];
foreach ($categoryProductSources as $slug => $source) {
    $allCategoryProducts = json_decode(file_get_contents(resource_path('data/' . $source['file'])), true, 512, JSON_THROW_ON_ERROR);
    foreach ($allCategoryProducts as $product) {
        $product['featured_key'] = $slug . ':' . $product['id'];
        $product['category'] = $source['name'];
        $product['category_slug'] = $slug;
        $featuredProducts[] = $product;
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




$homeFlashDeals = [
    ['key' => 'flash-earbuds', 'product_id' => 'electronics-and-gadgets:7', 'name' => 'Wireless Earbuds', 'price' => 599, 'original_price' => 799, 'image' => 'electronics/electronics-07-01.jpg', 'category' => 'Electronics & Gadgets', 'discount' => 25, 'rating' => 4.8, 'sold' => '2.8k', 'claimed' => 72],
    ['key' => 'flash-cleanser', 'product_id' => 'health-and-beauty:1', 'name' => 'Gentle Facial Cleanser', 'price' => 349, 'original_price' => 499, 'image' => 'health-beauty/health-beauty-01-01.jpg', 'category' => 'Health & Beauty', 'discount' => 30, 'rating' => 4.9, 'sold' => '2.1k', 'claimed' => 64],
    ['key' => 'flash-tee', 'product_id' => 'mens-apparel:1', 'name' => 'Everyday Basic Tee', 'price' => 329, 'original_price' => 449, 'image' => 'men/mens-01-01.jpg', 'category' => "Men's Apparel", 'discount' => 27, 'rating' => 4.8, 'sold' => '1.9k', 'claimed' => 81],
    ['key' => 'flash-watch', 'product_id' => 'jewelry-and-watches:21', 'name' => "Men's Silver Classic Watch", 'price' => 999, 'original_price' => 1499, 'image' => 'jewelry-watches/jewelry-watches-21-01.jpg', 'category' => 'Jewelry & Watches', 'discount' => 33, 'rating' => 4.9, 'sold' => '1.7k', 'claimed' => 58],
    ['key' => 'flash-headphones', 'product_id' => 'electronics-and-gadgets:6', 'name' => 'Wireless Headphones', 'price' => 1299, 'original_price' => 1799, 'image' => 'electronics/electronics-06-01.jpg', 'category' => 'Audio & Entertainment', 'discount' => 28, 'rating' => 4.7, 'sold' => '1.5k', 'claimed' => 69],
    ['key' => 'flash-smartwatch', 'product_id' => 'electronics-and-gadgets:8', 'name' => 'Smart Watch', 'price' => 1499, 'original_price' => 2199, 'image' => 'electronics/electronics-08-01.jpg', 'category' => 'Smart Devices & Power', 'discount' => 32, 'rating' => 4.8, 'sold' => '1.3k', 'claimed' => 61],
];

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

@include('buyer.partials.account-context-script')



@vite([

    'resources/css/buyer.css',

    'resources/css/bearly-promo-slider.css',
    'resources/css/bearly-chat.css',

    'resources/js/buyer.js',
    'resources/js/buyer-notifications.js',

    'resources/js/bearly-promo-slider.js',
    'resources/js/bearly-chat.js'

])



</head>

<body class="bh" style="--buyer-product-atlas: url('{{ asset('images/product-atlas.png') }}')">



<a class="skip-link" href="#main">Skip to products</a>



<header class="header">



<button class="icon-button mobile-menu" id="menu-toggle" aria-label="Open categories" aria-expanded="false" aria-controls="sidebar"><span class="material-symbols-outlined" aria-hidden="true">menu</span></button>

<a class="brand" href="{{ url('/home') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly" width="192" height="64"></a>



<form class="search" id="search-form" role="search">



<label class="sr-only" for="search-category">Search category</label>



<select id="search-category"><option value="">All categories</option></select>



<label class="sr-only" for="search-input">Search products</label>

<input id="search-input" type="search" placeholder="Search for products, brands or sellers..." maxlength="120" autocomplete="off">



<button type="submit" aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button>



</form>



<nav class="header-actions" aria-label="Account">



@include('buyer.partials.buyer-notification-popover')

<button type="button" onclick="window.location.href='{{ url('/profile#purchases') }}'"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><span>Orders</span></button>

<a href="{{ url('/chat') }}"><span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span><span>Chat</span></a>



<a href="{{ url('/cart') }}"><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span><span>Cart</span></a>



<a class="account-action" href="{{ url('/profile') }}" aria-label="Open {{ $buyerName }} profile"><span class="account-avatar" aria-hidden="true"><span class="material-symbols-outlined">person</span></span><span class="account-name">{{ $buyerName }}</span><span class="material-symbols-outlined account-chevron" aria-hidden="true">expand_more</span></a>



</nav>



</header>



<div class="shell">

<button id="sidebar-backdrop" class="sidebar-backdrop" aria-label="Close categories" hidden></button>



<aside class="sidebar" id="sidebar" aria-label="Product categories">



<div class="sidebar-categories-card">
<div class="sidebar-title"><strong>Shop by category</strong><button id="menu-close" class="icon-button" aria-label="Close categories"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>

<nav id="category-nav" aria-label="Shop by category"></nav>

</div>

<section class="sidebar-perks" aria-labelledby="sidebar-perks-title">
    <div class="sidebar-perks-title" id="sidebar-perks-title">
        <strong>Deals &amp; Perks</strong>
    </div>

    <a class="sidebar-perk-row" href="{{ url('/flash-deals') }}">
        <span class="material-symbols-outlined" aria-hidden="true">bolt</span>
        <span class="sidebar-perk-copy"><strong>Flash Deals</strong></span>
        <span class="material-symbols-outlined sidebar-perk-chevron" aria-hidden="true">chevron_right</span>
    </a>

    <a class="sidebar-perk-row" href="{{ url('/profile?voucher=shipping#vouchers') }}" aria-label="Open shipping vouchers in My Vouchers">
        <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
        <span class="sidebar-perk-copy"><strong>Free Shipping</strong></span>
        <span class="material-symbols-outlined sidebar-perk-chevron" aria-hidden="true">chevron_right</span>
    </a>

    <a class="sidebar-perk-row" href="{{ url('/profile#vouchers') }}" aria-label="Open My Vouchers">
        <span class="material-symbols-outlined" aria-hidden="true">confirmation_number</span>
        <span class="sidebar-perk-copy"><strong>Vouchers</strong></span>
        <span class="material-symbols-outlined sidebar-perk-chevron" aria-hidden="true">chevron_right</span>
    </a>
</section>


</aside>



<main id="main" tabindex="-1">



<div id="editorial">



<section class="bearly-promo-slider" id="bearly-promo-slider" aria-roledescription="carousel" aria-label="Bearly promotions">

    <div class="bearly-promo-track">

        <article class="bearly-promo-slide is-active bearly-promo-payday" aria-hidden="false">
            <img
                class="bearly-promo-bg bearly-payday-bg"
                src="{{ asset('images/marketplace-hero.png') }}"
                alt="Warm Bearly marketplace finds displayed together"
                fetchpriority="high"
                width="1536"
                height="1024"
            >
            <div class="bearly-promo-overlay bearly-payday-overlay"></div>

            <div class="bearly-promo-copy">
                <p class="eyebrow">BEARLY PICKS</p>
                <h2>Good finds<br>feel even better<br>on sale.</h2>
                <p>Save up to 30% on selected everyday favorites while the promo lasts.</p>
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

        <article class="bearly-promo-slide bearly-promo-welcome" aria-hidden="true">

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

        <article class="bearly-promo-slide bearly-promo-voucher bearly-promo-link" aria-hidden="true" data-voucher-promo data-voucher-target="{{ url('/profile#vouchers') }}" tabindex="-1" role="link" aria-label="Open My Vouchers">

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

        <article class="bearly-promo-slide bearly-promo-shipping bearly-promo-link" aria-hidden="true" data-voucher-promo data-voucher-target="{{ url('/profile?voucher=shipping#vouchers') }}" tabindex="-1" role="link" aria-label="Open shipping vouchers in My Vouchers">

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







</div>







<section class="flash-deals-preview home-flash-deals home-featured-section" aria-labelledby="home-flash-title">
    <div class="flash-deals-heading">
        <div class="flash-deals-title">
            <h2 id="home-flash-title">Flash Deals</h2>
            <div class="flash-countdown" data-flash-countdown aria-label="Flash deals countdown">
                <span data-hours>00</span><b aria-hidden="true">:</b><span data-minutes>00</span><b aria-hidden="true">:</b><span data-seconds>00</span>
            </div>
        </div>
        <a class="text-button top-view-all" href="{{ url('/flash-deals') }}">View all <span aria-hidden="true">→</span></a>
    </div>

    <div class="flash-deal-grid" aria-label="Featured flash deals">
        @foreach($homeFlashDeals as $deal)
            <article
                class="flash-deal-card"
                data-flash-product
                data-key="{{ $deal['key'] }}"
                data-product-id="{{ $deal['product_id'] }}"
                data-name="{{ $deal['name'] }}"
                data-price="{{ $deal['price'] }}"
                data-original-price="{{ $deal['original_price'] }}"
                data-image="{{ asset('images/products/'.$deal['image']) }}"
                data-flash-category="{{ $deal['category'] }}"
            >
                <div class="flash-image-wrap">
                    <button type="button" class="flash-image" aria-label="View {{ $deal['name'] }}">
                        <img src="{{ asset('images/products/'.$deal['image']) }}" alt="{{ $deal['name'] }}" loading="lazy">
                        <span>{{ $deal['discount'] }}% OFF</span>
                    </button>
                    <button type="button" class="flash-like" data-flash-like aria-label="Save {{ $deal['name'] }}"><span class="material-symbols-outlined">favorite</span></button>
                </div>
                <div class="flash-copy">
                    <small>{{ $deal['category'] }}</small>
                    <h3>{{ $deal['name'] }}</h3>
                    <div class="flash-meta"><span><b>★ {{ number_format($deal['rating'], 1) }}</b></span><span>({{ $deal['sold'] }} sold)</span></div>
                    <div class="flash-prices"><strong>₱{{ number_format($deal['price']) }}</strong><del>₱{{ number_format($deal['original_price']) }}</del></div>
                    <div class="flash-claimed" aria-label="{{ $deal['claimed'] }}% claimed; selling fast">
                        <span class="flash-claimed-track"><i style="--claimed:{{ $deal['claimed'] }}%"></i></span>
                        <small>SELLING FAST</small>
                    </div>
                    <button type="button" class="flash-add" data-flash-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button>
                </div>
            </article>
        @endforeach
    </div>
</section>

<section class="top-products-preview home-trending-shell" data-trending-shell aria-labelledby="top-products-title">
    <div class="top-products-heading">
        <div class="top-products-title-wrap">
            <h2 id="top-products-title">Trending on Bearly</h2>
            <div class="top-ranking-tabs" role="tablist" aria-label="Trending product lists">
                <button id="trending-popular-tab" type="button" role="tab" aria-selected="true" aria-controls="trending-popular-panel" data-trending-tab="popular">Popular Picks</button>
                <button id="trending-sales-tab" type="button" role="tab" aria-selected="false" aria-controls="trending-sales-panel" data-trending-tab="sales">Best Sellers</button>
            </div>
        </div>
        <a class="text-button top-view-all" data-trending-view-all data-popular-url="{{ url('/top-products') }}" data-sales-url="{{ url('/top-sales') }}" href="{{ url('/top-products') }}">View all <span aria-hidden="true">→</span></a>
    </div>
    <div class="top-products-grid top-products-grid-preview" id="trending-popular-panel" data-trending-panel="popular" role="tabpanel" aria-labelledby="trending-popular-tab">
        <article class="top-product-card" data-top-product data-key="top-electronics-and-gadgets-5" data-product-id="electronics-and-gadgets:5" data-name="Wireless Mouse" data-price="499" data-image="/images/products/electronics/electronics-05-01.jpg" data-top-category="Electronics and Gadgets">
            <div class="top-product-image"><img src="{{ asset('images/products/electronics/electronics-05-01.jpg') }}" alt="Wireless Mouse"><span class="top-home-rank" aria-hidden="true">TOP 1</span><span class="top-home-metric" aria-hidden="true">★ 4.9 &nbsp;TOP RATED</span><button type="button" class="top-like" data-top-like aria-label="Save Wireless Mouse"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Electronics and Gadgets</small><h3>Wireless Mouse</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(2.8k sold)</span></div><div class="top-product-bottom"><strong>₱499</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="top-mens-apparel-4" data-product-id="mens-apparel:4" data-name="Classic Piqué Polo" data-price="549" data-image="/images/products/men/mens-04-01.jpg" data-top-category="Men's Apparel">
            <div class="top-product-image"><img src="{{ asset('images/products/men/mens-04-01.jpg') }}" alt="Classic Piqué Polo"><span class="top-home-rank" aria-hidden="true">TOP 2</span><span class="top-home-metric" aria-hidden="true">★ 4.9 &nbsp;TOP RATED</span><button type="button" class="top-like" data-top-like aria-label="Save Classic Piqué Polo"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Men's Apparel</small><h3>Classic Piqué Polo</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(2.4k sold)</span></div><div class="top-product-bottom"><strong>₱549</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="top-health-and-beauty-5" data-product-id="health-and-beauty:5" data-name="Sunscreen SPF 50+" data-price="499" data-image="/images/products/health-beauty/health-beauty-05-01.jpg" data-top-category="Health and Beauty">
            <div class="top-product-image"><img src="{{ asset('images/products/health-beauty/health-beauty-05-01.jpg') }}" alt="Sunscreen SPF 50+"><span class="top-home-rank" aria-hidden="true">TOP 3</span><span class="top-home-metric" aria-hidden="true">★ 4.9 &nbsp;TOP RATED</span><button type="button" class="top-like" data-top-like aria-label="Save Sunscreen SPF 50+"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Health and Beauty</small><h3>Sunscreen SPF 50+</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(2.1k sold)</span></div><div class="top-product-bottom"><strong>₱499</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="top-home-and-garden-1" data-product-id="home-and-garden:1" data-name="Decorative Indoor Plant" data-price="599" data-image="/images/products/home-garden/home-garden-01-01.jpg" data-top-category="Home and Garden">
            <div class="top-product-image"><img src="{{ asset('images/products/home-garden/home-garden-01-01.jpg') }}" alt="Decorative Indoor Plant"><span class="top-home-rank" aria-hidden="true">TOP 4</span><span class="top-home-metric" aria-hidden="true">★ 4.8 &nbsp;TOP RATED</span><button type="button" class="top-like" data-top-like aria-label="Save Decorative Indoor Plant"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Home and Garden</small><h3>Decorative Indoor Plant</h3><div class="top-product-meta"><span><b>★ 4.8</b></span><span>(1.9k sold)</span></div><div class="top-product-bottom"><strong>₱599</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="top-womens-apparel-6" data-product-id="womens-apparel:6" data-name="Classic White Blouse" data-price="499" data-image="/images/products/women/womens-06-01.jpg" data-top-category="Women's Apparel">
            <div class="top-product-image"><img src="{{ asset('images/products/women/womens-06-01.jpg') }}" alt="Classic White Blouse"><span class="top-home-rank" aria-hidden="true">TOP 5</span><span class="top-home-metric" aria-hidden="true">★ 4.9 &nbsp;TOP RATED</span><button type="button" class="top-like" data-top-like aria-label="Save Classic White Blouse"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Women's Apparel</small><h3>Classic White Blouse</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(1.8k sold)</span></div><div class="top-product-bottom"><strong>₱499</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="top-sports-and-outdoors-11" data-product-id="sports-and-outdoors:11" data-name="Resistance Band Set" data-price="499" data-image="/images/products/sports-outdoors/sports-outdoors-11-01.jpg" data-top-category="Sports and Outdoors">
            <div class="top-product-image"><img src="{{ asset('images/products/sports-outdoors/sports-outdoors-11-01.jpg') }}" alt="Resistance Band Set"><span class="top-home-rank" aria-hidden="true">TOP 6</span><span class="top-home-metric" aria-hidden="true">★ 4.9 &nbsp;TOP RATED</span><button type="button" class="top-like" data-top-like aria-label="Save Resistance Band Set"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Sports and Outdoors</small><h3>Resistance Band Set</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(1.7k sold)</span></div><div class="top-product-bottom"><strong>₱499</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
    </div>
</section>

<section class="top-products-preview top-sales-preview home-trending-secondary" data-trending-secondary aria-label="Best Sellers" hidden>
    <div class="top-products-heading">
        <div>
            <h2 id="top-sales-title">Top Sales</h2>
        </div>
        <a class="text-button top-view-all" href="{{ url('/top-sales') }}">View all <span aria-hidden="true">→</span></a>
    </div>
    <div class="top-products-grid top-products-grid-preview" id="trending-sales-panel" data-trending-panel="sales" role="tabpanel" aria-labelledby="trending-sales-tab">
        <article class="top-product-card" data-top-product data-key="sales-sports-and-outdoors-11" data-product-id="sports-and-outdoors:11" data-name="Resistance Band Set" data-price="499" data-image="/images/products/sports-outdoors/sports-outdoors-11-01.jpg" data-top-category="Sports and Outdoors">
            <div class="top-product-image"><img src="{{ asset('images/products/sports-outdoors/sports-outdoors-11-01.jpg') }}" alt="Resistance Band Set"><span class="top-home-rank" aria-hidden="true">TOP 1</span><span class="top-home-metric" aria-hidden="true">MONTHLY SALES 4.6K+</span><button type="button" class="top-like" data-top-like aria-label="Save Resistance Band Set"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Sports and Outdoors</small><h3>Resistance Band Set</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(4.6k sold)</span></div><div class="top-product-bottom"><strong>₱499</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="sales-electronics-and-gadgets-7" data-product-id="electronics-and-gadgets:7" data-name="TWS Wireless Earbuds" data-price="1099" data-image="/images/products/electronics/electronics-07-01.jpg" data-top-category="Electronics and Gadgets">
            <div class="top-product-image"><img src="{{ asset('images/products/electronics/electronics-07-01.jpg') }}" alt="TWS Wireless Earbuds"><span class="top-home-rank" aria-hidden="true">TOP 2</span><span class="top-home-metric" aria-hidden="true">MONTHLY SALES 4.2K+</span><button type="button" class="top-like" data-top-like aria-label="Save TWS Wireless Earbuds"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Electronics and Gadgets</small><h3>TWS Wireless Earbuds</h3><div class="top-product-meta"><span><b>★ 4.7</b></span><span>(4.2k sold)</span></div><div class="top-product-bottom"><strong>₱1,099</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="sales-kids-and-baby-2" data-product-id="kids-and-baby:2" data-name="Floral Baby Dress" data-price="899" data-image="/images/products/kids-baby/kids-baby-02-01.jpg" data-top-category="Kids and Baby">
            <div class="top-product-image"><img src="{{ asset('images/products/kids-baby/kids-baby-02-01.jpg') }}" alt="Floral Baby Dress"><span class="top-home-rank" aria-hidden="true">TOP 3</span><span class="top-home-metric" aria-hidden="true">MONTHLY SALES 3.9K+</span><button type="button" class="top-like" data-top-like aria-label="Save Floral Baby Dress"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Kids and Baby</small><h3>Floral Baby Dress</h3><div class="top-product-meta"><span><b>★ 4.8</b></span><span>(3.9k sold)</span></div><div class="top-product-bottom"><strong>₱899</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="sales-home-and-garden-1" data-product-id="home-and-garden:1" data-name="Decorative Indoor Plant" data-price="599" data-image="/images/products/home-garden/home-garden-01-01.jpg" data-top-category="Home and Garden">
            <div class="top-product-image"><img src="{{ asset('images/products/home-garden/home-garden-01-01.jpg') }}" alt="Decorative Indoor Plant"><span class="top-home-rank" aria-hidden="true">TOP 4</span><span class="top-home-metric" aria-hidden="true">MONTHLY SALES 3.5K+</span><button type="button" class="top-like" data-top-like aria-label="Save Decorative Indoor Plant"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Home and Garden</small><h3>Decorative Indoor Plant</h3><div class="top-product-meta"><span><b>★ 4.8</b></span><span>(3.5k sold)</span></div><div class="top-product-bottom"><strong>₱599</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="sales-foods-and-gourmet-5" data-product-id="foods-and-gourmet:5" data-name="Extra Virgin Olive Oil" data-price="349" data-image="/images/products/foods-gourmet/foods-gourmet-05-01.jpg" data-top-category="Food and Gourmet">
            <div class="top-product-image"><img src="{{ asset('images/products/foods-gourmet/foods-gourmet-05-01.jpg') }}" alt="Extra Virgin Olive Oil"><span class="top-home-rank" aria-hidden="true">TOP 5</span><span class="top-home-metric" aria-hidden="true">MONTHLY SALES 3.2K+</span><button type="button" class="top-like" data-top-like aria-label="Save Extra Virgin Olive Oil"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Food and Gourmet</small><h3>Extra Virgin Olive Oil</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(3.2k sold)</span></div><div class="top-product-bottom"><strong>₱349</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
        <article class="top-product-card" data-top-product data-key="sales-mens-apparel-4" data-product-id="mens-apparel:4" data-name="Classic Piqué Polo" data-price="549" data-image="/images/products/men/mens-04-01.jpg" data-top-category="Men's Apparel">
            <div class="top-product-image"><img src="{{ asset('images/products/men/mens-04-01.jpg') }}" alt="Classic Piqué Polo"><span class="top-home-rank" aria-hidden="true">TOP 6</span><span class="top-home-metric" aria-hidden="true">MONTHLY SALES 2.9K+</span><button type="button" class="top-like" data-top-like aria-label="Save Classic Piqué Polo"><span class="material-symbols-outlined">favorite</span></button></div>
            <div class="top-product-copy"><small>Men's Apparel</small><h3>Classic Piqué Polo</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>(2.9k sold)</span></div><div class="top-product-bottom"><strong>₱549</strong><button type="button" data-top-add><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button></div></div>
        </article>
    </div>
</section>

<section class="discover" id="results" aria-labelledby="results-title">

<div class="section-heading"><div><h2 id="results-title">Daily Discoveries</h2><p id="results-caption" class="results-caption" hidden></p></div><button class="text-button" id="view-all">View all <span aria-hidden="true">→</span></button></div>

<div class="results-tools" id="results-tools" hidden><div id="active-filters"></div><label>Sort by <select id="sort"><option value="featured">Featured</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option><option value="name">Name: A–Z</option></select></label></div>







<div class="product-grid" id="product-grid"></div>

<div class="empty" id="empty" hidden><span class="material-symbols-outlined" aria-hidden="true">search_off</span><h3>No matching finds yet</h3><p>Try another search or explore a different category.</p><button class="button" id="reset-search">Browse all products</button></div>



<noscript><p>Enable JavaScript to browse this homepage’s sample catalog.</p></noscript>



</section>






<div class="load-area" id="home-load-area"><button class="button outline" id="load-more">Load more products <span class="material-symbols-outlined" aria-hidden="true">expand_more</span></button><p id="result-count" role="status" aria-live="polite"></p></div>



</main>



</div>

<footer class="footer buyer-home-footer">
    <div class="footer-inner">
        <section class="footer-brand" aria-label="Bearly">
            <a href="{{ url('/home') }}" class="footer-logo" aria-label="Bearly home">
                <img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly" width="150" height="50">
            </a>
            <p>A find for everyone.</p>
            <small>Quality products. Trusted sellers. A better everyday.</small>
            <div class="footer-socials" aria-label="Bearly social channels">
                <span class="footer-social-mark" title="Facebook">f</span>
                <span class="footer-social-mark" title="Instagram">◎</span>
                <span class="footer-social-mark" title="TikTok">♪</span>
                <span class="footer-social-mark" title="YouTube">▶</span>
            </div>
        </section>

        <nav class="footer-column" aria-label="Shop">
            <h2>Shop</h2>
            <a href="{{ url('/products') }}">All Categories</a>
            <a href="{{ url('/products') }}">Deals</a>
            <a href="{{ url('/top-products') }}">Top Products</a>
            <a href="{{ url('/top-sales') }}">Top Sales</a>
        </nav>

        <nav class="footer-column" aria-label="Help">
            <h2>Help</h2>
            <a href="{{ url('/profile#purchases') }}">Track an Order</a>
            <button type="button" data-info="help">Returns &amp; Refunds</button>
            <button type="button" data-info="help">Shipping Information</button>
            <button type="button" data-info="help">FAQs</button>
        </nav>

        <nav class="footer-column" aria-label="About">
            <h2>About</h2>
            <button type="button" data-info="about">Our Story</button>
            <a href="{{ url('/seller/dashboard') }}">Careers</a>
            <a href="{{ url('/terms') }}">Terms of Service</a>
            <a href="{{ url('/privacy') }}">Privacy Policy</a>
        </nav>

        <section class="footer-deals" aria-label="Deals newsletter">
            <h2>Get the latest deals</h2>
            <div class="footer-newsletter-form">
                <label class="sr-only" for="footer-email">Email address</label>
                <input id="footer-email" type="email" placeholder="Enter your email" autocomplete="email">
                <button type="button" aria-label="Newsletter signup is not connected yet" title="Newsletter signup is not connected yet">→</button>
            </div>
            <small>© {{ date('Y') }} Bearly. All rights reserved.</small>
        </section>
    </div>
</footer>





@include('buyer.partials.chat-drawer')

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
