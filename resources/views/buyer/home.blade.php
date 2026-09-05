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
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
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
            <section class="hero" aria-labelledby="hero-title">
                <img class="hero-image" src="{{ asset('images/marketplace-hero.png') }}" alt="Headphones, sneakers, a tote bag and desk essentials on warm stone displays" fetchpriority="high" width="1536" height="1024">
                <div class="hero-copy"><p class="eyebrow">Welcome to your everyday marketplace</p><h1 id="hero-title">A little of everything.<br>A find for everyone.</h1><p>From daily essentials to your next favorite thing.</p><a href="#results" class="button gold">Start exploring <span aria-hidden="true">→</span></a></div>
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
