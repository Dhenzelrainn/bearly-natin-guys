@php
    $landingCategories = json_decode(file_get_contents(resource_path('data/buyer-categories.json')), true, 512, JSON_THROW_ON_ERROR);
    $landingProducts = json_decode(file_get_contents(resource_path('data/buyer-home-products.json')), true, 512, JSON_THROW_ON_ERROR);

    $categorySamples = [];
    foreach ($landingProducts as $product) {
        $slug = $product['category_slug'] ?? '';
        if ($slug !== '' && !isset($categorySamples[$slug])) {
            $categorySamples[$slug] = $product;
        }
    }

    $featuredIds = [2, 4, 6, 8, 9, 12];
    $featuredProducts = array_values(array_filter(
        $landingProducts,
        fn ($product) => in_array($product['id'], $featuredIds, true)
    ));

    $featuredMeta = [
        2 => ['seller' => 'AudioNest', 'rating' => '4.8', 'reviews' => '620', 'sold' => '910 sold'],
        4 => ['seller' => 'UrbanBasics', 'rating' => '4.6', 'reviews' => '285', 'sold' => '430 sold'],
        6 => ['seller' => 'HomeEssentials', 'rating' => '4.7', 'reviews' => '405', 'sold' => '680 sold'],
        8 => ['seller' => 'Glow & Co.', 'rating' => '4.9', 'reviews' => '560', 'sold' => '1.2k sold'],
        9 => ['seller' => 'BookHive', 'rating' => '4.9', 'reviews' => '1.2k', 'sold' => '2.1k sold'],
        12 => ['seller' => 'TimeMinded PH', 'rating' => '4.7', 'reviews' => '390', 'sold' => '510 sold'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Bearly — an easy marketplace for curated finds from independent stores.">
    <title>Bearly — Shopping made Bearly a hassle</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Libre+Caslon+Display&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,300,0,0&display=swap" rel="stylesheet">

    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body
    class="landing-page"
    style="
        --product-atlas: url('{{ asset('images/product-atlas.png') }}');
        --hero-image: url('{{ asset('images/landing/bearly-hero-background.png') }}');
        --story-image: url('{{ asset('images/landing/bearly-story-lifestyle.png') }}');
        --cta-image: url('{{ asset('images/landing/bearly-cta-background.jpg') }}');
    "
>
<a class="skip-link" href="#main-content">Skip to content</a>

<div class="announcement">Shopping made bearly easy.</div>

<header class="site-header" id="top">
    <div class="header-main shell-width">
        <a class="brand" href="{{ route('shop.home') }}" aria-label="Bearly landing page">
            <strong>BEARLY</strong>
            <span>Find more. Live better.</span>
        </a>

        <button class="menu-toggle" id="menu-toggle" type="button" aria-expanded="false" aria-controls="main-nav" aria-label="Open navigation">
            <span class="material-symbols-outlined">menu</span>
        </button>

        <nav class="main-nav" id="main-nav" aria-label="Primary navigation">
            <a class="active" href="{{ route('shop.home') }}">Home</a>
            <a href="{{ route('home') }}">Shop</a>
            <a href="#about">About</a>
            <a href="#delivery">Delivery</a>
            <a href="#contact">Contact</a>
        </nav>

        <div class="account-links">
            <a class="login-link" href="{{ route('login') }}">Log in</a>
            <a class="create-link" href="{{ route('register') }}">Create Account</a>
        </div>
    </div>

    <form class="landing-search shell-width" action="{{ route('products.index') }}" method="GET" role="search">
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
        <label class="sr-only" for="landing-search-input">Search products</label>
        <input id="landing-search-input" name="q" type="search" placeholder="Search for products, brands, or independent stores..." autocomplete="off">
        <label class="sr-only" for="landing-search-category">Category</label>
        <select id="landing-search-category" name="category">
            <option value="">All categories</option>
            @foreach ($landingCategories as $category)
                <option value="{{ $category['slug'] }}">{{ $category['name'] }}</option>
            @endforeach
        </select>
        <button type="submit">Search</button>
    </form>
</header>

<main id="main-content">
    <section class="hero" aria-labelledby="hero-title">
        <div class="hero-bg" role="img" aria-label="Bearly lifestyle models"></div>
        <div class="hero-overlay shell-width">
            <div class="hero-copy">
                <p class="hero-kicker">BEARLY</p>
                <h1 id="hero-title">Shopping<br>made <span>Bearly</span><br>a hassle.</h1>
                <p class="hero-lead">Shopping should be easy. Bearly stressful.</p>
                <p class="hero-description">Discover unique finds from independent stores all in one place. Curated pieces, great deals, and effortless discovery.</p>

                <div class="hero-actions">
                    <a class="button button-primary" href="{{ route('home') }}">Shop now <span>→</span></a>
                    <a class="button button-outline" href="#categories">Explore categories</a>
                </div>

                <div class="hero-benefits" aria-label="Why shop on Bearly">
                    <div><span class="material-symbols-outlined">storefront</span><p><strong>Support</strong><br>independent stores</p></div>
                    <div><span class="material-symbols-outlined">diamond</span><p><strong>Curated finds</strong><br>for every lifestyle</p></div>
                    <div><span class="material-symbols-outlined">favorite</span><p><strong>A more meaningful</strong><br>way to shop</p></div>
                </div>
            </div>
        </div>
    </section>

    <section class="section shell-width" id="categories" aria-labelledby="categories-title">
        <div class="section-heading">
            <h2 id="categories-title">Shop by Categories</h2>
            <a href="{{ route('products.index') }}">View all categories <span>→</span></a>
        </div>

        <div class="category-grid">
            @foreach ($landingCategories as $category)
                @php
                    $sample = $categorySamples[$category['slug']] ?? null;
                    $photoIndex = $sample['photo'] ?? $loop->index;
                    $x = ($photoIndex % 4) * 100 / 3;
                    $y = floor($photoIndex / 4) * 100 / 3;
                @endphp
                <a class="category-card" href="{{ route('products.index', ['category' => $category['slug']]) }}">
                    <span class="atlas-photo category-photo" style="--x: {{ $x }}%; --y: {{ $y }}%" aria-hidden="true"></span>
                    <span>{{ $category['name'] }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="section shell-width" aria-labelledby="featured-title">
        <div class="section-heading">
            <h2 id="featured-title">Featured Products</h2>
            <a href="{{ route('products.index') }}">View all products <span>→</span></a>
        </div>

        <div class="featured-grid">
            @foreach ($featuredProducts as $product)
                @php
                    $photoIndex = $product['photo'];
                    $x = ($photoIndex % 4) * 100 / 3;
                    $y = floor($photoIndex / 4) * 100 / 3;
                    $meta = $featuredMeta[$product['id']] ?? ['seller' => 'Independent Seller', 'rating' => '4.8', 'reviews' => '120', 'sold' => '200 sold'];
                @endphp
                <a class="product-card" href="{{ route('products.index', ['product' => $product['id']]) }}">
                    <span class="heart material-symbols-outlined" aria-hidden="true">favorite</span>
                    <span class="atlas-photo product-photo" style="--x: {{ $x }}%; --y: {{ $y }}%" role="img" aria-label="{{ $product['name'] }}"></span>
                    <span class="product-copy">
                        <span class="product-name">{{ $product['name'] }}</span>
                        <strong>₱{{ number_format($product['price']) }}</strong>
                        <small>{{ $meta['seller'] }}</small>
                        <span class="rating"><b>★</b> {{ $meta['rating'] }} ({{ $meta['reviews'] }}) <i>·</i> {{ $meta['sold'] }}</span>
                        <span class="delivery-text">Delivery: 2–4 days</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="benefit-strip shell-width" id="delivery" aria-label="Shopping benefits">
        <article><span class="material-symbols-outlined">shopping_bag</span><div><strong>Deals from independent stores</strong><p>Compare prices and save more.</p></div></article>
        <article><span class="material-symbols-outlined">verified_user</span><div><strong>Shop with confidence</strong><p>Secure checkout, buyer protection, and easy returns.</p></div></article>
        <article><span class="material-symbols-outlined">local_shipping</span><div><strong>Reliable delivery</strong><p>Fast shipping from local sellers to your doorstep.</p></div></article>
        <article><span class="material-symbols-outlined">groups</span><div><strong>Support small businesses</strong><p>A marketplace that empowers independent sellers.</p></div></article>
    </section>

    <section class="story shell-width" id="about" aria-labelledby="story-title">
        <div class="story-copy">
            <h2 id="story-title">More than just shopping.<br>A brighter everyday.</h2>
            <p>Discover curated finds from independent stores and small businesses. Unique items, better value, and a more meaningful way to shop.</p>
            <a class="button button-primary" href="{{ route('home') }}">Shop now <span>→</span></a>
        </div>
        <div class="story-image" role="img" aria-label="Warm lifestyle objects and home decor"></div>
    </section>

    <section class="faq shell-width" aria-labelledby="faq-title">
        <div class="section-heading compact-heading">
            <h2 id="faq-title">Frequently asked questions</h2>
            <a href="#contact">More questions? Contact us.</a>
        </div>
        <div class="faq-list">
            <details><summary>How do I place an order?</summary><p>Browse the buyer shop, open a product, add it to your cart, then continue to checkout.</p></details>
            <details><summary>Can I change or cancel my order?</summary><p>Order-management actions will follow the final backend order rules once the checkout flow is connected.</p></details>
            <details><summary>How do I track my order?</summary><p>Order tracking will follow the buyer order status from preparation through delivery.</p></details>
            <details><summary>What payment methods are available?</summary><p>Available payment methods will appear during checkout once payment integration is enabled.</p></details>
            <details><summary>What is your return and refund policy?</summary><p>The final return and refund policy will be shown here when the marketplace policies are finalized.</p></details>
        </div>
    </section>

    <section class="support shell-width" id="contact" aria-labelledby="support-title">
        <div class="support-intro"><h2 id="support-title">We’re here to help</h2><p>Our contact details will be shared soon.</p></div>
        <article><span class="material-symbols-outlined">mail</span><p><strong>Email</strong><br>To be announced</p></article>
        <article><span class="material-symbols-outlined">call</span><p><strong>Phone</strong><br>To be announced</p></article>
        <article><span class="material-symbols-outlined">location_on</span><p><strong>Location</strong><br>To be announced</p></article>
        <article><span class="material-symbols-outlined">schedule</span><p><strong>Support hours</strong><br>To be announced</p></article>
    </section>

    <section class="cta shell-width" aria-labelledby="cta-title">
        <div class="cta-copy">
            <h2 id="cta-title">Make shopping<br>Bearly a hassle.</h2>
            <p>Create an account and start discovering great finds from independent stores.</p>
            <a class="button button-primary" href="{{ route('register') }}">Create an account</a>
        </div>
        <div class="cta-image" role="img" aria-label="Bearly lifestyle accessories"></div>
    </section>
</main>

<footer class="site-footer">
    <div class="footer-grid shell-width">
        <div class="footer-brand">
            <strong>BEARLY</strong>
            <p>Find more. Live better.</p>
            <div class="socials"><span>f</span><span>◎</span><span>♪</span><span>▶</span></div>
            <small>© 2026 Bearly. All rights reserved.</small>
        </div>
        <nav><strong>Shop</strong><a href="{{ route('products.index') }}">All Products</a><a href="{{ route('home') }}">New Arrivals</a><a href="{{ route('home') }}">Best Sellers</a><a href="#categories">Categories</a></nav>
        <nav><strong>About</strong><a href="#about">Our Story</a><a href="{{ route('register') }}">Careers</a><a href="#about">Press</a></nav>
        <nav><strong>Support</strong><a href="#delivery">Delivery</a><a href="#faq-title">FAQ</a><a href="#contact">Contact Us</a></nav>
        <div class="newsletter"><strong>Be the first to know</strong><p>Get updates on new launches and special offers.</p><form><input type="email" placeholder="Enter your email" aria-label="Email for newsletter"><button type="button">Subscribe</button></form><div><a href="#">Privacy Policy</a><span>|</span><a href="#">Terms of Service</a></div></div>
    </div>
</footer>
</body>
</html>
