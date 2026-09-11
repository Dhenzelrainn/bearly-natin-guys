@php
    $homeCategories = json_decode(file_get_contents(resource_path('data/categories.json')), true, 512, JSON_THROW_ON_ERROR);
    $landingProducts = json_decode(file_get_contents(resource_path('data/landing-products.json')), true, 512, JSON_THROW_ON_ERROR);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Shopping made Bearly a hassle. Discover products from big stores and independent sellers, all in one place.">
    <meta name="theme-color" content="#442214">
    <title>Bearly — Shopping made Bearly a hassle.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@400&display=block" rel="stylesheet">
    <link rel="preload" as="image" href="{{ asset('images/landing/hero.webp') }}">
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body class="bl bearly-landing" data-shop-url="{{ url('/products') }}" style="--landing-logo:url('{{ asset('images/bearly-logo.png') }}');--mixed-atlas:url('{{ asset('images/landing/mixed-products.webp') }}');--existing-atlas:url('{{ asset('images/catalog-placeholders.png') }}')">
<a class="lp-skip" href="#lp-main">Skip to content</a>
<header class="lp-header">
    <a class="lp-logo" href="{{ url('/') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly" width="205" height="64"></a>
    <nav class="lp-nav" aria-label="Main navigation">
        <a href="{{ url('/') }}" aria-current="page">Home</a><a href="{{ url('/products') }}">Shop</a><a href="{{ route('about') }}">About</a><a href="{{ route('contact') }}">Contact</a>
    </nav>
    <form class="lp-search" action="{{ url('/products') }}" role="search">
        <label class="lp-sr" for="lp-search">Search products and stores</label><input id="lp-search" type="search" name="search" placeholder="Search products and stores" maxlength="120" required>
        <button class="lp-icon" aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button>
    </form>
    <div class="lp-actions">
        <a class="lp-icon" href="{{ url('/login') }}" aria-label="Log in"><span class="material-symbols-outlined" aria-hidden="true">person</span></a>
        <button class="lp-icon" type="button" data-saved-open aria-label="Saved sample products"><span class="material-symbols-outlined" aria-hidden="true">favorite</span><span class="lp-badge" data-saved-count hidden>0</span></button>
        <a class="lp-icon" href="{{ url('/cart') }}" aria-label="Shopping cart"><span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span></a>
    </div>
</header>
<main id="lp-main">
    <section class="lp-hero" aria-roledescription="carousel" aria-label="Discover Bearly" tabindex="0">
        <div class="lp-slides">
            <article class="lp-slide is-active" role="group" aria-roledescription="slide" aria-label="1 of 3">
                <img class="lp-hero-photo" src="{{ asset('images/landing/hero.webp') }}" alt="Headphones, a rust canvas tote, sneakers, a camera and a book in the afternoon sun" width="1536" height="1024" fetchpriority="high">
                <div class="lp-hero-copy"><p class="lp-eyebrow">BEARLY</p><h1>Shopping made<br>Bearly a hassle.</h1><p>Discover unique finds from big stores and independent sellers, all in one place.</p><div class="lp-hero-links"><a class="lp-button" href="{{ url('/products') }}">Shop now <span aria-hidden="true">↗</span></a><a class="lp-text-link" href="#bl-categories">Explore categories</a></div>
                <div class="lp-bear-note"><span class="lp-bear" role="img" aria-label="Bearly bear with an orange shopping bag"></span><p>Good Things<br>Find You Here.</p></div></div>
                <span class="lp-hero-wordmark" aria-hidden="true">bearly</span>
            </article>
            <article class="lp-slide" role="group" aria-roledescription="slide" aria-label="2 of 3" hidden>
                <img class="lp-hero-photo lp-photo-tech" src="{{ asset('images/landing/tech.webp') }}" alt="Camera, laptop and mouse on a slate blue desk" width="1024" height="1024">
                <div class="lp-hero-copy"><p class="lp-eyebrow">YOUR DAILY SETUP</p><h2>Find more.<br>Live better.</h2><p>A little upgrade for work, play, and everything in between.</p><a class="lp-button" href="#bl-featured" data-hero-filter="Tech">Explore tech <span aria-hidden="true">↗</span></a><div class="lp-bear-note"><span class="lp-bear" aria-hidden="true"></span><p>Your next find<br>starts here.</p></div></div>
            </article>
            <article class="lp-slide" role="group" aria-roledescription="slide" aria-label="3 of 3" hidden>
                <img class="lp-hero-photo lp-photo-fashion" src="{{ asset('images/landing/fashion.webp') }}" alt="Everyday knitwear, denim and a cap against a warm terracotta background" width="1024" height="1024">
                <div class="lp-hero-copy"><p class="lp-eyebrow">EVERYDAY STYLE</p><h2>Shopping should<br>be easy.</h2><p>Bearly stressful. Find something that feels like you.</p><a class="lp-button" href="#bl-featured" data-hero-filter="Fashion">Explore fashion <span aria-hidden="true">↗</span></a><div class="lp-bear-note"><span class="lp-bear" aria-hidden="true"></span><p>A fresh little<br>upgrade.</p></div></div>
            </article>
        </div>
        <div class="lp-hero-controls"><div class="lp-dots" aria-label="Choose hero slide"><button aria-label="Go to slide 1" aria-current="true" data-slide-to="0"></button><button aria-label="Go to slide 2" data-slide-to="1"></button><button aria-label="Go to slide 3" data-slide-to="2"></button></div><button class="lp-icon" data-hero-pause aria-label="Pause slideshow"><span class="material-symbols-outlined" aria-hidden="true">pause</span></button></div>
        <div class="lp-hero-arrows"><button class="lp-round" data-hero-prev aria-label="Previous slide">←</button><button class="lp-round" data-hero-next aria-label="Next slide">→</button></div>
    </section>
    <div class="lp-brand-strip">Find more. Live better.</div>
    <section class="lp-section lp-categories" id="bl-categories" aria-labelledby="lp-category-title">
        <div class="lp-heading"><h2 id="lp-category-title">Shop by Categories</h2><button class="lp-text-link" type="button" data-categories-open>View all categories <span aria-hidden="true">↗</span></button></div>
        <div class="lp-category-row">
            <button data-category-filter="Fashion"><span class="lp-product-photo" data-atlas="mixed" style="--x:0%;--y:0%"></span><span>Fashion</span></button>
            <button data-category-filter="Tech"><span class="lp-product-photo" data-atlas="mixed" style="--x:33.333333%;--y:0%"></span><span>Tech</span></button>
            <button data-category-filter="Home"><span class="lp-product-photo" data-atlas="mixed" style="--x:100%;--y:100%"></span><span>Home</span></button>
            <button data-category-filter="Beauty"><span class="lp-product-photo" data-atlas="mixed" style="--x:66.666667%;--y:0%"></span><span>Beauty</span></button>
            <button data-category-filter="Books"><span class="lp-product-photo" data-atlas="mixed" style="--x:100%;--y:50%"></span><span>Books</span></button>
            <button data-category-filter="Accessories"><span class="lp-product-photo" data-atlas="mixed" style="--x:100%;--y:0%"></span><span>Accessories</span></button>
        </div>
    </section>
    <section class="lp-campaigns" aria-label="Explore collections">
        <a class="lp-campaign lp-reveal" href="#bl-featured" data-hero-filter="Tech"><img src="{{ asset('images/landing/tech.webp') }}" alt="Laptop, camera and mouse" loading="lazy" width="1024" height="1024"><div><p class="lp-eyebrow">TECH ESSENTIALS</p><h2>For your<br>everyday</h2><p>Your next daily upgrade.</p><span class="lp-text-link">Shop collection ↗</span></div></a>
        <a class="lp-campaign lp-reveal" href="#bl-featured" data-hero-filter="Fashion"><img src="{{ asset('images/landing/fashion.webp') }}" alt="Knitwear, denim and a cap" loading="lazy" width="1024" height="1024"><div><p class="lp-eyebrow">FRESH LOOKS</p><h2>A fresh little<br>upgrade</h2><p>Everyday style, your way.</p><span class="lp-text-link">Shop collection ↗</span></div></a>
    </section>
    <section class="lp-section lp-featured" id="bl-featured" aria-labelledby="lp-featured-title">
        <div class="lp-heading lp-featured-heading"><div><h2 id="lp-featured-title">Featured Products</h2><p>A little of everything, all in one place.</p></div><div class="lp-filters" role="group" aria-label="Filter featured products"></div></div>
        <p class="lp-catalogue-note">Sample catalogue · Illustrative photos · Prices to be confirmed</p>
        <div class="lp-product-grid" id="lp-products"></div>
        <noscript><p>Enable JavaScript to browse the sample catalogue, or <a href="{{ url('/products') }}">visit the shop</a>.</p></noscript>
        <div class="lp-product-nav"><p class="lp-catalogue-note" data-product-status role="status" aria-live="polite"></p><div class="lp-product-pages" aria-label="Product pages"></div><div class="lp-product-nav-actions"><button class="lp-round" data-products-prev aria-label="Previous product page">←</button><button class="lp-round" data-products-next aria-label="Next product page">→</button><a class="lp-text-link" href="{{ url('/products') }}">View all products ↗</a></div></div>
    </section>
    <section class="lp-section lp-story lp-reveal" aria-labelledby="lp-story-title"><div><p class="lp-eyebrow">ABOUT BEARLY</p><h2 id="lp-story-title">Shopping should<br>feel more human.</h2></div><div><p>Bearly brings independent stores and thoughtful shoppers together in one easy-to-use marketplace.</p><a class="lp-text-link" href="{{ route('about') }}">Read our story ↗</a></div></section>
    <section class="lp-section lp-support" id="bl-delivery" aria-labelledby="lp-support-title"><div><p class="lp-eyebrow">CONTACT BEARLY</p><h2 id="lp-support-title">We’re here to help.</h2></div><a href="{{ route('contact') }}#help-topics-title"><span class="material-symbols-outlined" aria-hidden="true">package_2</span>Shopping & Orders</a><a href="{{ route('contact') }}#help-topics-title"><span class="material-symbols-outlined" aria-hidden="true">storefront</span>Seller Support</a><a href="{{ route('contact') }}#help-topics-title"><span class="material-symbols-outlined" aria-hidden="true">person</span>Account & Registration</a><a class="lp-text-link" href="{{ route('contact') }}">Contact us ↗</a></section>
    <section class="lp-signup"><span class="lp-bear" aria-hidden="true"></span><div><h2>Make shopping Bearly a hassle.</h2><p>Create an account and start discovering.</p></div><a class="lp-button" href="{{ url('/register') }}">Create an account ↗</a></section>
</main>
<footer class="lp-footer">
    <div class="lp-footer-grid"><div><a class="lp-footer-logo" href="{{ url('/') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly" width="205" height="64" loading="lazy"></a><p>Shopping should be easy.<br>Bearly stressful.</p></div>
        <nav aria-label="Shop links"><h2>Shop</h2><a href="{{ url('/products') }}">All Products</a><a href="#bl-featured">Featured Products</a><a href="#bl-categories">Categories</a></nav>
        <nav aria-label="About and support links"><h2>About & Support</h2><a href="{{ route('about') }}">Our Story</a><a href="{{ url('/register') }}">Sell on Bearly</a><a href="{{ route('contact') }}">Contact Us</a></nav>
        <div class="lp-newsletter"><h2>Be the first to know</h2><p>Get updates on new launches and special offers.</p><form data-newsletter><label class="lp-sr" for="lp-email">Your email</label><input id="lp-email" name="email" type="email" required maxlength="254" placeholder="Enter your email"><button type="submit">Subscribe</button></form><p class="lp-newsletter-status" data-newsletter-status role="status"></p></div>
    </div>
    <div class="lp-footer-bottom"><span>© {{ date('Y') }} Bearly. All rights reserved.</span><div><button data-policy="privacy">Privacy Policy</button><button data-policy="terms">Terms of Service</button></div></div>
</footer>
<dialog class="lp-dialog lp-category-dialog" id="lp-categories" aria-labelledby="lp-explorer-title"><div class="lp-dialog-heading"><div><p class="lp-eyebrow">BEARLY MARKETPLACE</p><h2 id="lp-explorer-title">Shop by category</h2></div><button class="lp-round" data-close-dialog aria-label="Close categories">×</button></div><label class="lp-sr" for="lp-category-search">Search categories</label><input class="lp-category-search" id="lp-category-search" type="search" placeholder="Search categories and subcategories" autocomplete="off"><div data-category-groups></div><p data-category-empty hidden>No matching categories. Try another search.</p></dialog>
<dialog class="lp-dialog" id="lp-detail" aria-labelledby="lp-detail-title"><div class="lp-dialog-heading"><h2 id="lp-detail-title"></h2><button class="lp-round" data-close-dialog aria-label="Close preview">×</button></div><div id="lp-detail-content"></div></dialog>
<p class="lp-toast" role="status" data-toast hidden></p>
<script id="lp-products-data" type="application/json">{!! json_encode($landingProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
<script id="lp-categories-data" type="application/json">{!! json_encode($homeCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</body>
</html>
