<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Learn more about Bearly and our marketplace for independent stores.">
    <title>About Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@300&display=block" rel="stylesheet">
    @vite(['resources/css/landing.css', 'resources/css/about.css'])
</head>
<body class="bl ba">
<header class="bl-header">
    <a class="bl-logo" href="{{ url('/') }}" aria-label="Bearly home">
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly - Find more. Live better.">
    </a>
    <nav class="bl-nav" aria-label="Main navigation">
        <a href="{{ url('/') }}">Home</a>
        <a href="{{ url('/home') }}">Discover</a>
        <a href="{{ route('about') }}" aria-current="page">About</a>
        <a href="{{ route('contact') }}">Contact</a>
    </nav>
    <div class="bl-actions">
        <a href="{{ url('/login') }}" aria-label="Sign in"><span class="material-symbols-outlined" aria-hidden="true">person</span></a>
        <a href="{{ url('/cart') }}" aria-label="Shopping cart"><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span></a>
    </div>
</header>

<main>
    <section class="ba-hero">
        <div class="ba-shell">
            <p class="ba-eyebrow">ABOUT BEARLY</p>
            <h1>Shopping should feel <span>more human.</span></h1>
            <p class="ba-hero-copy">Bearly brings independent stores and thoughtful shoppers together in one easy-to-use marketplace. We make discovery simpler, more personal, and a little less stressful.</p>
        </div>
    </section>

    <section class="ba-section">
        <div class="ba-shell ba-two-column">
            <article class="ba-copy-block">
                <p class="ba-label">OUR STORY</p>
                <h2>A marketplace built around better everyday choices.</h2>
                <p>We created Bearly for people who want to find useful, distinctive products without getting lost in the noise. Every store adds something different, and every discovery has a story behind it.</p>
            </article>
            <article class="ba-copy-block ba-copy-divider">
                <p class="ba-label">OUR PROMISE</p>
                <h2>Make finding good things feel easy.</h2>
                <p>From independent sellers to curious buyers, Bearly is designed to keep the experience clear, welcoming, and worth coming back to.</p>
            </article>
        </div>
    </section>

    <section class="ba-section ba-name-section">
        <div class="ba-shell ba-name-grid">
            <article>
                <p class="ba-label">WHY “BEARLY”</p>
                <h2>Because shopping should be barely a hassle.</h2>
                <p>It is a small idea with a practical goal: remove the unnecessary friction between people and the things they are looking for.</p>
            </article>
            <blockquote>Find more.<br><strong>Live better.</strong></blockquote>
        </div>
    </section>

    <section class="ba-section ba-offers">
        <div class="ba-shell">
            <p class="ba-label">WHAT YOU CAN EXPECT</p>
            <h2>A better way to browse.</h2>
            <div class="ba-offer-grid">
                <article><span class="material-symbols-outlined" aria-hidden="true">storefront</span><h3>Independent stores</h3><p>Discover products from sellers with their own point of view.</p></article>
                <article><span class="material-symbols-outlined" aria-hidden="true">search</span><h3>Thoughtful discovery</h3><p>Browse curated categories and find something that fits your life.</p></article>
                <article><span class="material-symbols-outlined" aria-hidden="true">verified_user</span><h3>Shopping confidence</h3><p>Clear product information and buyer-focused experiences.</p></article>
                <article><span class="material-symbols-outlined" aria-hidden="true">favorite</span><h3>More meaningful finds</h3><p>Choose useful, personal pieces instead of endless scrolling.</p></article>
            </div>
        </div>
    </section>

    <section class="ba-cta">
        <div class="ba-shell ba-cta-inner">
            <div>
                <p class="ba-label">START EXPLORING</p>
                <h2>Good things are waiting.</h2>
                <p>Browse Bearly and discover independent stores made for everyday life.</p>
            </div>
            <div class="ba-cta-actions">
                <a class="ba-primary" href="{{ url('/home') }}">Shop now <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a>
                <a class="ba-secondary" href="{{ url('/register') }}">Create an account</a>
            </div>
        </div>
    </section>
</main>

<footer class="bl-footer">
    <div class="bl-footer-grid">
        <div><a class="bl-brand" href="{{ url('/') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly - Find more. Live better."></a><p class="bl-copyright">© {{ date('Y') }} Bearly.<br>All rights reserved.</p></div>
        <nav aria-label="Shop links"><h3>Shop</h3><a href="{{ url('/home') }}">All Products</a><a href="{{ url('/').'#bl-categories' }}">Categories</a></nav>
        <nav aria-label="About links"><h3>About</h3><a href="{{ route('about') }}">Our Story</a><a href="{{ url('/seller/dashboard') }}">Sell on Bearly</a></nav>
        <nav aria-label="Support links"><h3>Support</h3><a href="{{ url('/').'#bl-delivery' }}">Delivery</a><a href="{{ route('contact') }}">Contact Us</a></nav>
    </div>
</footer>
</body>
</html>
