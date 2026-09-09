@php
$homeCategories = json_decode(file_get_contents(resource_path('data/buyer-categories.json')), true, 512, JSON_THROW_ON_ERROR);

$homeProducts = json_decode(file_get_contents(resource_path('data/buyer-home-products.json')), true, 512, JSON_THROW_ON_ERROR);

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

</head>
<body class="bh" style="--buyer-product-atlas: url('{{ asset('images/product-atlas.png') }}'); --buyer-outdoor-banner: url('{{ asset('images/outdoor-banner.png') }}')">

<a class="skip-link" href="#main">Skip to products</a>

<header class="header">

<button class="icon-button mobile-menu" id="menu-toggle" aria-label="Open categories" aria-expanded="false" aria-controls="sidebar"><span class="material-symbols-outlined" aria-hidden="true">menu</span></button>
<a class="brand" href="{{ url('/home') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly" width="192" height="64"></a>

<form class="search" id="search-form" role="search">

<label class="sr-only" for="search-category">Search category</label>

<select id="search-category"><option value="">All categories</option></select>

<label class="sr-only" for="search-input">Search products</label>
<input id="search-input" type="search" placeholder="Search for anything on Bearly" maxlength="120" autocomplete="off">

<button type="submit" aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button>

</form>

<nav class="header-actions" aria-label="Account">

<button data-info="orders"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><span>Orders</span></button>
<button data-info="chat"><span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span><span>Chat</span></button>

<a href="{{ url('/cart') }}"><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span><span>Cart</span></a>

<a href="{{ url('/login') }}"><span class="material-symbols-outlined" aria-hidden="true">person</span><span>Sign in</span></a>

</nav>

</header>

<div class="shell">
<button id="sidebar-backdrop" class="sidebar-backdrop" aria-label="Close categories" hidden></button>

<aside class="sidebar" id="sidebar" aria-label="Product categories">

<div class="sidebar-title"><span class="material-symbols-outlined" aria-hidden="true">menu</span><strong>Shop by category</strong><button id="menu-close" class="icon-button" aria-label="Close categories"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
<nav id="category-nav" aria-label="Shop by category"></nav>

<a class="seller-link" href="{{ url('/seller/dashboard') }}"><span class="material-symbols-outlined" aria-hidden="true">storefront</span>Sell on Bearly<span aria-hidden="true">→</span></a>

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
<footer class="footer"><a href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly home" width="110" height="37"></a><p>Good finds. Happy spaces.</p><nav aria-label="Footer"><button data-info="about">About Bearly</button><button data-info="help">Help centre</button><a href="{{ url('/seller/dashboard') }}">Become a seller</a></nav><span>Homepage preview</span></footer>
<button class="chat-button" data-info="chat"><span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>Chat</button>

<dialog id="product-dialog" aria-labelledby="product-title"><button class="dialog-close icon-button" data-close aria-label="Close product details"><span class="material-symbols-outlined" aria-hidden="true">close</span></button><div id="product-detail"></div></dialog>
<dialog id="info-dialog" aria-labelledby="info-title"><button class="dialog-close icon-button" data-close aria-label="Close"><span class="material-symbols-outlined" aria-hidden="true">close</span></button><h2 id="info-title"></h2><p id="info-copy"></p><a class="button gold" href="{{ url('/login') }}">Go to sign in</a></dialog>
<script id="home-data" type="application/json">{!! json_encode(['categories' => $homeCategories, 'products' => $homeProducts], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>

</body>

</html>
