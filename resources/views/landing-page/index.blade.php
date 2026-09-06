@php
    $homeCategories = json_decode(file_get_contents(resource_path('data/categories.json')), true, 512, JSON_THROW_ON_ERROR);
    // Local 4-by-4 placeholder photo catalog; replace with seller images when inventory is connected.
    $featured = [
        ['name'=>'Wireless Over-Ear Headphones','price'=>3290,'shop'=>'TechifyPH','rating'=>'4.8','reviews'=>'570','sold'=>'340','category'=>'electronics-and-gadgets'],
        ['name'=>'Everyday Electric Kettle','price'=>1290,'shop'=>'Home Essentials','rating'=>'4.7','reviews'=>'320','sold'=>'620','category'=>'home-and-garden'],
        ['name'=>'Classic Cotton Polo (Navy)','price'=>2490,'shop'=>'Daily Essentials','rating'=>'4.9','reviews'=>'410','sold'=>'680','category'=>'men-s-apparel'],
        ['name'=>'Classic Leather-Strap Watch','price'=>890,'shop'=>'UrbanBasics','rating'=>'4.8','reviews'=>'265','sold'=>'430','category'=>'jewelry-and-watches'],
        ['name'=>'Linen Hardcover Journal','price'=>1990,'shop'=>'Paper & Co.','rating'=>'4.8','reviews'=>'620','sold'=>'910','category'=>'books-and-media'],
        ['name'=>'Linen Blend Blouse','price'=>645,'shop'=>'Everyday Wear','rating'=>'4.9','reviews'=>'1.2k','sold'=>'2.1k','category'=>'women-s-apparel'],
    ];
    $faqs = [
        ['How do I place an order?', 'Browse a category and open a product to see its details. Ordering and checkout will be available when the store launches.'],
        ['Can I change or cancel my order?', 'Order changes and cancellations will depend on the seller and shipping status. Full instructions will be available at launch.'],
        ['How do I track my order?', 'Tracking will be available once delivery services are connected.'],
        ['What payment methods are available?', 'Supported payment methods will be announced before checkout becomes available.'],
        ['What is your return and refund policy?', 'The return and refund policy will be published before the marketplace starts accepting orders.'],
    ];
    $sprite = fn ($index) => '--sprite-position:'.(($index % 4) * 100 / 3).'% '.(intdiv($index, 4) * 100 / 3).'%';
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@300&display=block" rel="stylesheet">
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body class="bl" style="--bl-catalog:url('{{ asset('images/catalog-placeholders.png') }}')">
<a class="skip-link" href="#bl-main">Skip to content</a>
<header class="bl-header">
    <a class="bl-brand" href="{{ url('/') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly - Find more. Live better."></a>
    <nav class="bl-nav" aria-label="Main navigation"><a href="{{ url('/') }}" aria-current="page">Home</a><a href="{{ url('/home') }}">Shop</a><a href="#bl-about">About</a><a href="#bl-delivery">Delivery</a><a href="#bl-contact">Contact</a></nav>
    <div class="bl-actions"><a href="{{ url('/login') }}" aria-label="Sign in"><span class="material-symbols-outlined" aria-hidden="true">person</span></a><button type="button" data-bl-wishlist aria-label="Saved products"><span class="material-symbols-outlined" aria-hidden="true">favorite</span></button><button type="button" data-bl-info="cart" aria-label="Shopping cart"><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span><span class="bl-count">0</span></button></div>
    <form class="bl-search" action="{{ url('/home') }}" role="search"><button aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button><label class="sr-only" for="bl-query">Search products</label><input id="bl-query" name="search" type="search" maxlength="120" placeholder="Search for products, brands, or independent stores..."></form>
</header>
<main id="bl-main">
    <section class="bl-hero" aria-labelledby="bl-title">
        <img class="bl-banner-image" src="{{ asset('images/hero.png') }}" alt="Three men in casual everyday outfits in a sunlit studio" width="1536" height="1024" fetchpriority="high">
        <div class="bl-hero-copy"><p class="bl-wordmark">BEARLY</p><h1 id="bl-title">Shopping<br>made <em>Bearly<br>a hassle.</em></h1><p class="bl-lead">Shopping should be easy. Bearly stressful.</p><p class="bl-description">Discover unique finds from independent stores all in one place. Curated pieces, great deals, and effortless discovery.</p><div class="bl-buttons"><a class="bl-button" href="{{ url('/home') }}">Shop now <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a><a class="bl-button bl-outline" href="#bl-categories">Explore categories</a></div>
        <div class="bl-mini-benefits"><span><span class="material-symbols-outlined" aria-hidden="true">storefront</span>Support<br>independent stores</span><span><span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>Curated finds<br>for every lifestyle</span><span><span class="material-symbols-outlined" aria-hidden="true">favorite</span>A more meaningful<br>way to shop</span></div></div>
        <p class="bl-hero-note">Good<br>Things<br>Find You<br>Here.</p>
    </section>
    <div class="bl-container">
        <section id="bl-categories" class="bl-section" aria-labelledby="bl-category-title"><div class="bl-section-heading"><h2 id="bl-category-title">Shop by Categories</h2><a href="{{ url('/home') }}">View all categories <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div><div class="bl-categories">
        @foreach ($homeCategories as $index => $category)
            <a class="bl-category" href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}"><span class="bl-catalog-image" style="{{ $sprite($index) }}" role="img" aria-label="{{ $category['name'] }}"></span><span>{{ str_replace(' and ', ' & ', $category['name']) }}</span></a>
        @endforeach
        </div></section>
        <section class="bl-section" id="bl-featured" aria-labelledby="bl-featured-title"><div class="bl-section-heading"><h2 id="bl-featured-title">Featured Products</h2><a href="{{ url('/home') }}">View all products <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div><div class="bl-products">
        @foreach ($featured as $index => $product)
            <article class="bl-product"><button type="button" class="bl-save" data-bl-save="{{ $index }}" aria-label="Save {{ $product['name'] }}" aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">favorite</span></button><button class="bl-product-open" type="button" data-bl-product="{{ $index }}"><span class="bl-catalog-image bl-product-photo" style="{{ $sprite([1, 5, 3, 11, 8, 2][$index]) }}" role="img" aria-label="{{ $product['name'] }}"></span><h3>{{ $product['name'] }}</h3><strong class="bl-price">₱{{ number_format($product['price']) }}</strong></button><p class="bl-seller">{{ $product['shop'] }}</p><p class="bl-rating"><span class="material-symbols-outlined bl-star" aria-hidden="true">star</span> {{ $product['rating'] }} ({{ $product['reviews'] }}) · {{ $product['sold'] }} sold</p><p class="bl-shipping">Delivery: 2–4 days</p></article>
        @endforeach
        </div></section>
        <section id="bl-delivery" class="bl-benefits" aria-label="Why shop with Bearly">
            @foreach ([['shopping_bag','Deals from independent stores','Compare prices and save more.'],['verified_user','Shop with confidence','Secure checkout, buyer protection, and easy returns.'],['local_shipping','Reliable delivery','Fast shipping from local sellers to your doorstep.'],['groups','Support small businesses','A marketplace that empowers independent sellers.']] as $benefit)
            <div><span class="material-symbols-outlined" aria-hidden="true">{{ $benefit[0] }}</span><div><h3>{{ $benefit[1] }}</h3><p>{{ $benefit[2] }}</p></div></div>
            @endforeach
        </section>
        <section id="bl-about" class="bl-lifestyle bl-banner"><img class="bl-banner-image" src="{{ asset('images/lifestyle.png') }}" alt="Books, a brown mug, and a leafy plant on a warm wood table" width="1536" height="640" loading="lazy"><div class="bl-banner-copy"><h2>More than just shopping.<br>A brighter everyday.</h2><p>Discover curated finds from independent stores and small businesses. Unique items, better value, a more meaningful way to shop.</p><a class="bl-button" href="{{ url('/home') }}">Shop now <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></section>
        <section id="bl-faq" class="bl-section bl-faq" aria-labelledby="bl-faq-title"><div class="bl-section-heading"><h2 id="bl-faq-title">Frequently asked questions</h2><a href="#bl-contact">More questions? Contact us.</a></div>@foreach ($faqs as [$question, $answer])<details><summary>{{ $question }}<span class="material-symbols-outlined" aria-hidden="true">add</span></summary><p>{{ $answer }}</p></details>@endforeach</section>
        <section class="bl-contact" id="bl-contact" aria-labelledby="bl-contact-title"><div><h2 id="bl-contact-title">We’re here to help</h2><p>Our contact details will be shared soon.</p></div>@foreach ([['mail','Email'],['call','Phone'],['location_on','Location'],['schedule','Support hours']] as [$icon, $label])<div class="bl-contact-item"><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span><div><h3>{{ $label }}</h3><p>To be announced</p></div></div>@endforeach</section>
        <section class="bl-signup bl-banner"><img class="bl-banner-image" src="{{ asset('images/signup.png') }}" alt="Camera, notebook, sunglasses, plant, phone, and a brown cap" width="1536" height="512" loading="lazy"><div class="bl-banner-copy"><h2>Make shopping<br>Bearly a hassle.</h2><p>Create an account and start discovering great finds from independent stores.</p><a class="bl-button" href="{{ url('/register') }}">Create an account</a></div></section>
    </div>
</main>
<footer class="bl-footer"><div class="bl-footer-grid"><div><a class="bl-brand" href="{{ url('/') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly - Find more. Live better."></a><p class="bl-copyright">© {{ date('Y') }} Bearly. All rights reserved.</p></div><nav aria-label="Shop links"><h3>Shop</h3><a href="{{ url('/home') }}">All Products</a><a href="#bl-featured">Featured Products</a><a href="#bl-categories">Categories</a></nav><nav aria-label="About links"><h3>About</h3><a href="#bl-about">Our Story</a><a href="{{ url('/seller/dashboard') }}">Sell on Bearly</a></nav><nav aria-label="Support links"><h3>Support</h3><a href="#bl-delivery">Delivery</a><a href="#bl-faq">FAQ</a><a href="#bl-contact">Contact Us</a></nav><div class="bl-newsletter"><h3>Be the first to know</h3><p>Get updates on new launches and special offers.</p><form id="bl-newsletter"><label class="sr-only" for="bl-email">Your email</label><input type="email" id="bl-email" required maxlength="254" placeholder="Enter your email"><button class="bl-button">Subscribe</button></form><p id="bl-newsletter-status" role="status"></p></div></div><div class="bl-footer-bottom"><span>Preview marketplace · Sample products and prices</span><div><button data-bl-info="privacy">Privacy Policy</button><span>|</span><button data-bl-info="terms">Terms of Service</button></div></div></footer>
<dialog class="bl-dialog" id="bl-dialog" aria-labelledby="bl-dialog-title"><button class="bl-dialog-close" aria-label="Close dialog"><span class="material-symbols-outlined" aria-hidden="true">close</span></button><h2 id="bl-dialog-title"></h2><div id="bl-dialog-content"></div></dialog>
<p class="bl-toast" id="bl-toast" role="status" hidden></p>
<script id="bl-featured-data" type="application/json">{!! json_encode($featured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</body>
</html>
