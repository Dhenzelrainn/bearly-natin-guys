@php
    $homeCategories = json_decode(file_get_contents(resource_path('data/categories.json')), true, 512, JSON_THROW_ON_ERROR);
    // Reference imagery is displayed through CSS windows; sample listings are not live seller inventory.
    $featured = [
        ['name'=>'Keychron K2 Mechanical Keyboard','price'=>3290,'shop'=>'TechifyPH','rating'=>'4.8','reviews'=>'570','sold'=>'340','category'=>'electronics-and-gadgets'],
        ['name'=>'Logitech M660 Wireless Mouse','price'=>1290,'shop'=>'Gadget Go','rating'=>'4.7','reviews'=>'320','sold'=>'620','category'=>'electronics-and-gadgets'],
        ['name'=>'Stanley Classic Insulated Tumbler 20oz','price'=>2490,'shop'=>'Daily Essentials','rating'=>'4.9','reviews'=>'410','sold'=>'680','category'=>'home-and-garden'],
        ['name'=>'Premium Polo Shirt (Black)','price'=>890,'shop'=>'UrbanBasics','rating'=>'4.8','reviews'=>'265','sold'=>'430','category'=>'men-s-apparel'],
        ['name'=>'SoundPEATS Air4 True Wireless Earbuds','price'=>1990,'shop'=>'AudioNest','rating'=>'4.8','reviews'=>'620','sold'=>'910','category'=>'electronics-and-gadgets'],
        ['name'=>'Atomic Habits by James Clear','price'=>645,'shop'=>'BookHive','rating'=>'4.9','reviews'=>'1.2k','sold'=>'2.1k','category'=>'books-and-media'],
    ];
    $faqs = [
        ['How do I place an order?', 'Browse a category and open a product to see its details. Ordering and checkout will be available when the store launches.'],
        ['Can I change or cancel my order?', 'Order changes and cancellations will depend on the seller and shipping status. Full instructions will be available at launch.'],
        ['How do I track my order?', 'Tracking will be available once delivery services are connected.'],
        ['What payment methods are available?', 'Supported payment methods will be announced before checkout becomes available.'],
        ['What is your return and refund policy?', 'The return and refund policy will be published before the marketplace starts accepting orders.'],
    ];
    $sprite = fn ($x, $y, $w, $h) => '--sprite-size:'.(683/$w*100).'% '.(2048/$h*100).'%;--sprite-position:'.($x/(683-$w)*100).'% '.($y/(2048-$h)*100).'%';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Discover unique finds from independent stores. Shopping made Bearly a hassle.">
    <title>BEARLY — Find more. Live better.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body class="bl" style="--bl-catalog:url('{{ asset('images/cloud.png') }}')">
<svg class="bl-symbols" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs>
<symbol id="bl-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/></symbol>
<symbol id="bl-user" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2Z"/></symbol>
<symbol id="bl-heart" viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></symbol>
<symbol id="bl-cart" viewBox="0 0 24 24"><path d="M1 2h3l3 14h13l2-10H5"/><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/></symbol>
<symbol id="bl-arrow" viewBox="0 0 24 24"><path d="M3 12h18m-7-7 7 7-7 7"/></symbol>
<symbol id="bl-store" viewBox="0 0 24 24"><path d="M3 10v12h18V10M2 10l3-8h14l3 8M2 10q3 5 5 0 3 5 5 0 3 5 5 0 3 5 5 0M8 22v-8h8v8"/></symbol>
<symbol id="bl-bag" viewBox="0 0 24 24"><path d="M4 7h16l2 16H2ZM8 9V5a4 4 0 0 1 8 0v4"/></symbol>
<symbol id="bl-shield" viewBox="0 0 24 24"><path d="m12 1 9 4v6c0 6-9 12-9 12S3 17 3 11V5ZM7 11l4 4 6-8"/></symbol>
<symbol id="bl-truck" viewBox="0 0 24 24"><path d="M1 3h14v15H1ZM15 8h4l4 6v4h-8"/><circle cx="5" cy="20" r="2"/><circle cx="19" cy="20" r="2"/></symbol>
<symbol id="bl-people" viewBox="0 0 24 24"><circle cx="12" cy="5" r="3"/><path d="M6 22v-7a6 6 0 0 1 12 0v7M3 8a3 3 0 1 0 0 6M21 8a3 3 0 1 1 0 6M1 22v-4h3m19 4v-4h-3"/></symbol>
<symbol id="bl-mail" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 5 10 8L22 5"/></symbol>
<symbol id="bl-phone" viewBox="0 0 24 24"><path d="m5 2 4 5-3 3q2 6 8 8l3-3 5 4-2 3C10 24 0 14 2 4Z"/></symbol>
<symbol id="bl-pin" viewBox="0 0 24 24"><path d="M20 9c0 6-8 14-8 14S4 15 4 9a8 8 0 1 1 16 0Z"/><circle cx="12" cy="9" r="3"/></symbol>
<symbol id="bl-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 5v7l5 3"/></symbol>
</defs></svg>
<a class="skip-link" href="#bl-main">Skip to content</a>
<header class="bl-header">
    <a class="bl-brand" href="{{ url('/') }}">BEARLY<small>Find more. Live better.</small></a>
    <nav class="bl-nav" aria-label="Main navigation"><a href="{{ url('/') }}" aria-current="page">Home</a><a href="{{ url('/home') }}">Shop</a><a href="#bl-about">About</a><a href="#bl-delivery">Delivery</a><a href="#bl-contact">Contact</a></nav>
    <div class="bl-actions"><a href="{{ url('/login') }}" aria-label="Sign in"><svg><use href="#bl-user"/></svg></a><button type="button" data-bl-wishlist aria-label="Saved products"><svg><use href="#bl-heart"/></svg></button><button type="button" data-bl-info="cart" aria-label="Shopping cart"><svg><use href="#bl-cart"/></svg><span class="bl-count">0</span></button></div>
    <form class="bl-search" action="{{ url('/home') }}" role="search"><button aria-label="Search"><svg><use href="#bl-search"/></svg></button><label class="sr-only" for="bl-query">Search products</label><input id="bl-query" name="search" type="search" maxlength="120" placeholder="Search for products, brands, or independent stores..."></form>
</header>
<main id="bl-main">
    <section class="bl-hero" aria-labelledby="bl-title">
        <img class="bl-banner-image" src="{{ asset('images/hero.png') }}" alt="Three men in casual everyday outfits in a sunlit studio" width="1536" height="1024" fetchpriority="high">
        <div class="bl-hero-copy"><p class="bl-wordmark">BEARLY</p><h1 id="bl-title">Shopping<br>made <em>Bearly<br>a hassle.</em></h1><p class="bl-lead">Shopping should be easy. Bearly stressful.</p><p class="bl-description">Discover unique finds from independent stores all in one place. Curated pieces, great deals, and effortless discovery.</p><div class="bl-buttons"><a class="bl-button" href="{{ url('/home') }}">Shop now <svg><use href="#bl-arrow"/></svg></a><a class="bl-button bl-outline" href="#bl-categories">Explore categories</a></div>
        <div class="bl-mini-benefits"><span><svg><use href="#bl-store"/></svg>Support<br>independent stores</span><span><svg><use href="#bl-bag"/></svg>Curated finds<br>for every lifestyle</span><span><svg><use href="#bl-heart"/></svg>A more meaningful<br>way to shop</span></div></div>
        <p class="bl-hero-note">Good<br>Things<br>Find You<br>Here.</p>
    </section>
    <div class="bl-container">
        <section id="bl-categories" class="bl-section" aria-labelledby="bl-category-title"><div class="bl-section-heading"><h2 id="bl-category-title">Shop by Categories</h2><a href="{{ url('/home') }}">View all categories <svg><use href="#bl-arrow"/></svg></a></div><div class="bl-categories">
        @foreach ($homeCategories as $index => $category)
            <a class="bl-category" href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}"><span class="bl-catalog-image" style="{{ $sprite(22 + ($index % 6) * 108, $index < 6 ? 554 : 676, 96, 84) }}" role="img" aria-label="{{ $category['name'] }}"></span><span>{{ str_replace(' and ', ' & ', $category['name']) }}</span></a>
        @endforeach
        </div></section>
        <section class="bl-section" id="bl-featured" aria-labelledby="bl-featured-title"><div class="bl-section-heading"><h2 id="bl-featured-title">Featured Products</h2><a href="{{ url('/home') }}">View all products <svg><use href="#bl-arrow"/></svg></a></div><div class="bl-products">
        @foreach ($featured as $index => $product)
            <article class="bl-product"><button type="button" class="bl-save" data-bl-save="{{ $index }}" aria-label="Save {{ $product['name'] }}" aria-pressed="false"><svg><use href="#bl-heart"/></svg></button><button class="bl-product-open" type="button" data-bl-product="{{ $index }}"><span class="bl-catalog-image bl-product-photo" style="{{ $sprite(23 + $index * 108, 859, 96, 92) }}" role="img" aria-label="{{ $product['name'] }}"></span><h3>{{ $product['name'] }}</h3><strong class="bl-price">₱{{ number_format($product['price']) }}</strong></button><p class="bl-seller">{{ $product['shop'] }}</p><p class="bl-rating"><span aria-label="Rating">★</span> {{ $product['rating'] }} ({{ $product['reviews'] }}) · {{ $product['sold'] }} sold</p><p class="bl-shipping">Delivery: 2–4 days</p></article>
        @endforeach
        </div></section>
        <section id="bl-delivery" class="bl-benefits" aria-label="Why shop with Bearly">
            @foreach ([['bag','Deals from independent stores','Compare prices and save more.'],['shield','Shop with confidence','Secure checkout, buyer protection, and easy returns.'],['truck','Reliable delivery','Fast shipping from local sellers to your doorstep.'],['people','Support small businesses','A marketplace that empowers independent sellers.']] as $benefit)
            <div><svg><use href="#bl-{{ $benefit[0] }}"/></svg><div><h3>{{ $benefit[1] }}</h3><p>{{ $benefit[2] }}</p></div></div>
            @endforeach
        </section>
        <section id="bl-about" class="bl-lifestyle bl-banner"><img class="bl-banner-image" src="{{ asset('images/lifestyle.png') }}" alt="Books, a brown mug, and a leafy plant on a warm wood table" width="1536" height="640" loading="lazy"><div class="bl-banner-copy"><h2>More than just shopping.<br>A brighter everyday.</h2><p>Discover curated finds from independent stores and small businesses. Unique items, better value, a more meaningful way to shop.</p><a class="bl-button" href="{{ url('/home') }}">Shop now <svg><use href="#bl-arrow"/></svg></a></div></section>
        <section id="bl-faq" class="bl-section bl-faq" aria-labelledby="bl-faq-title"><div class="bl-section-heading"><h2 id="bl-faq-title">Frequently asked questions</h2><a href="#bl-contact">More questions? Contact us.</a></div>@foreach ($faqs as [$question, $answer])<details><summary>{{ $question }}<span aria-hidden="true">+</span></summary><p>{{ $answer }}</p></details>@endforeach</section>
        <section class="bl-contact" id="bl-contact" aria-labelledby="bl-contact-title"><div><h2 id="bl-contact-title">We’re here to help</h2><p>Our contact details will be shared soon.</p></div>@foreach ([['mail','Email'],['phone','Phone'],['pin','Location'],['clock','Support hours']] as [$icon, $label])<div class="bl-contact-item"><svg><use href="#bl-{{ $icon }}"/></svg><div><h3>{{ $label }}</h3><p>To be announced</p></div></div>@endforeach</section>
        <section class="bl-signup bl-banner"><img class="bl-banner-image" src="{{ asset('images/signup.png') }}" alt="Camera, notebook, sunglasses, plant, phone, and a brown cap" width="1536" height="512" loading="lazy"><div class="bl-banner-copy"><h2>Make shopping<br>Bearly a hassle.</h2><p>Create an account and start discovering great finds from independent stores.</p><a class="bl-button" href="{{ url('/register') }}">Create an account</a></div></section>
    </div>
</main>
<footer class="bl-footer"><div class="bl-footer-grid"><div><a class="bl-brand" href="{{ url('/') }}">BEARLY<small>Find more. Live better.</small></a><p class="bl-copyright">© {{ date('Y') }} Bearly. All rights reserved.</p></div><nav aria-label="Shop links"><h3>Shop</h3><a href="{{ url('/home') }}">All Products</a><a href="#bl-featured">Featured Products</a><a href="#bl-categories">Categories</a></nav><nav aria-label="About links"><h3>About</h3><a href="#bl-about">Our Story</a><a href="{{ url('/seller/dashboard') }}">Sell on Bearly</a></nav><nav aria-label="Support links"><h3>Support</h3><a href="#bl-delivery">Delivery</a><a href="#bl-faq">FAQ</a><a href="#bl-contact">Contact Us</a></nav><div class="bl-newsletter"><h3>Be the first to know</h3><p>Get updates on new launches and special offers.</p><form id="bl-newsletter"><label class="sr-only" for="bl-email">Your email</label><input type="email" id="bl-email" required maxlength="254" placeholder="Enter your email"><button class="bl-button">Subscribe</button></form><p id="bl-newsletter-status" role="status"></p></div></div><div class="bl-footer-bottom"><span>Preview marketplace · Sample products and prices</span><div><button data-bl-info="privacy">Privacy Policy</button><span>|</span><button data-bl-info="terms">Terms of Service</button></div></div></footer>
<dialog class="bl-dialog" id="bl-dialog" aria-labelledby="bl-dialog-title"><button class="bl-dialog-close" aria-label="Close dialog">×</button><h2 id="bl-dialog-title"></h2><div id="bl-dialog-content"></div></dialog>
<p class="bl-toast" id="bl-toast" role="status" hidden></p>
<script id="bl-featured-data" type="application/json">{!! json_encode($featured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</body>
</html>
