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

    <style>
        /* Bearly category explorer — self-contained so it cannot fail because
           of a missing public CSS asset. */
        body.bl.bl-category-explorer-open { overflow: hidden; }

        body.bl .bl-view-all-categories {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            padding: 6px 0;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-size: 14px;
            cursor: pointer;
            position: relative;
            z-index: 2;
        }

        body.bl .bl-view-all-categories:hover,
        body.bl .bl-view-all-categories:focus-visible {
            color: var(--bl-gold);
        }

        body.bl .bl-view-all-categories .material-symbols-outlined {
            width: 21px;
            height: 21px;
            color: var(--bl-gold);
        }

        body.bl #bl-category-explorer[hidden] {
            display: none !important;
        }

        body.bl #bl-category-explorer {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: grid;
            place-items: start center;
            padding: 22px 20px;
        }

        body.bl .bl-category-explorer-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(24, 19, 15, .56);
            cursor: default;
        }

        body.bl .bl-category-explorer-panel {
            position: relative;
            z-index: 1;
            width: min(1180px, calc(100vw - 40px));
            max-height: calc(100vh - 44px);
            overflow: hidden;
            border-radius: 4px;
            background: #fff;
            color: #302217;
            box-shadow: 0 24px 80px rgba(24, 16, 10, .26);
            outline: none;
            animation: bl-category-in .18s ease-out;
        }

        @keyframes bl-category-in {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        body.bl .bl-category-explorer-header {
            min-height: 78px;
            display: grid;
            grid-template-columns: 190px 1fr 44px;
            align-items: center;
            gap: 20px;
            padding: 14px 26px;
            border-bottom: 1px solid #ece8e2;
            background: #fff;
        }

        body.bl .bl-category-explorer-brand {
            display: block;
            width: 150px;
        }

        body.bl .bl-category-explorer-brand img {
            width: 100%;
            height: auto;
        }

        body.bl .bl-category-explorer-heading {
            text-align: center;
        }

        body.bl .bl-category-explorer-heading p {
            margin: 0 0 3px;
            color: #8b8178;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: .18em;
        }

        body.bl .bl-category-explorer-heading h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -.03em;
        }

        body.bl .bl-category-explorer-close {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            justify-self: end;
            border: 0;
            border-radius: 50%;
            background: transparent;
            color: #302217;
            cursor: pointer;
        }

        body.bl .bl-category-explorer-close:hover {
            background: #f4f0eb;
        }

        body.bl .bl-category-explorer-body {
            max-height: calc(100vh - 122px);
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 22px 32px 42px;
            scrollbar-width: thin;
            scrollbar-color: #b6aca2 #f3efeb;
        }

        body.bl .bl-category-explorer-search {
            position: relative;
            width: min(820px, 100%);
            margin: 0 auto 28px;
        }

        body.bl .bl-category-explorer-search input {
            width: 100%;
            height: 44px;
            padding: 0 50px 0 18px;
            border: 1px solid #d8d2cb;
            border-radius: 24px;
            background: #fff;
            color: #302217;
            outline: 0;
            font: inherit;
            font-size: 13px;
        }

        body.bl .bl-category-explorer-search input:focus {
            border-color: var(--bl-gold);
            box-shadow: 0 0 0 2px rgba(149, 96, 31, .12);
        }

        body.bl .bl-category-explorer-search .material-symbols-outlined {
            position: absolute;
            top: 50%;
            right: 16px;
            transform: translateY(-50%);
            color: #80776f;
            pointer-events: none;
        }

        body.bl .bl-category-explorer-top-grid {
            width: min(900px, 100%);
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 18px 22px;
        }

        body.bl .bl-category-explorer-tile {
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            text-align: center;
            color: #302217;
            font-size: 11px;
            line-height: 1.25;
        }

        body.bl .bl-category-explorer-tile:hover {
            color: var(--bl-gold);
        }

        body.bl .bl-category-explorer-image {
            width: 86px;
            height: 86px;
            aspect-ratio: auto;
            border-radius: 4px;
            background-color: #f8f5f1;
        }

        body.bl .bl-category-explorer-divider {
            width: min(900px, 100%);
            height: 1px;
            margin: 32px auto 26px;
            background: #ece7e1;
        }

        body.bl .bl-category-explorer-subhead {
            width: min(1040px, 100%);
            margin: 0 auto 18px;
        }

        body.bl .bl-category-explorer-subhead h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        body.bl .bl-category-explorer-subhead p {
            margin: 5px 0 0;
            color: #7d746c;
            font-size: 11px;
        }

        body.bl .bl-category-explorer-groups {
            width: min(1040px, 100%);
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        body.bl .bl-category-explorer-group {
            min-width: 0;
            padding: 18px;
            border: 1px solid #ebe5de;
            border-radius: 8px;
            background: #fcfaf7;
        }

        body.bl .bl-category-explorer-group-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e7e0d8;
            color: #302217;
            font-size: 13px;
            font-weight: 600;
        }

        body.bl .bl-category-explorer-group-title .material-symbols-outlined {
            color: var(--bl-gold);
            font-size: 18px;
        }

        body.bl .bl-category-explorer-group ul {
            margin: 11px 0 0;
            padding: 0;
            list-style: none;
        }

        body.bl .bl-category-explorer-group li + li {
            margin-top: 6px;
        }

        body.bl .bl-category-explorer-group li a {
            display: block;
            color: #655b53;
            font-size: 11px;
            line-height: 1.35;
        }

        body.bl .bl-category-explorer-group li a:hover {
            color: var(--bl-gold);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        body.bl .bl-category-explorer-empty {
            margin: 36px auto;
            text-align: center;
            color: #766c64;
            font-size: 13px;
        }

        body.bl .bl-category-filter-hidden {
            display: none !important;
        }

        @media (max-width: 900px) {
            body.bl #bl-category-explorer { padding: 12px; }

            body.bl .bl-category-explorer-panel {
                width: calc(100vw - 24px);
                max-height: calc(100vh - 24px);
            }

            body.bl .bl-category-explorer-header {
                grid-template-columns: 130px 1fr 40px;
                padding: 12px 16px;
            }

            body.bl .bl-category-explorer-brand { width: 120px; }

            body.bl .bl-category-explorer-body {
                max-height: calc(100vh - 100px);
                padding: 18px 20px 32px;
            }

            body.bl .bl-category-explorer-top-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            body.bl .bl-category-explorer-groups {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 620px) {
            body.bl .bl-view-all-categories { font-size: 12px; }
            body.bl #bl-category-explorer { padding: 0; }

            body.bl .bl-category-explorer-panel {
                width: 100vw;
                max-height: 100vh;
                min-height: 100vh;
                border-radius: 0;
            }

            body.bl .bl-category-explorer-header {
                position: sticky;
                top: 0;
                z-index: 2;
                grid-template-columns: 1fr 42px;
                min-height: 68px;
            }

            body.bl .bl-category-explorer-brand { width: 115px; }
            body.bl .bl-category-explorer-heading { display: none; }

            body.bl .bl-category-explorer-body {
                max-height: calc(100vh - 68px);
                padding: 18px 15px 28px;
            }

            body.bl .bl-category-explorer-top-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 18px 10px;
            }

            body.bl .bl-category-explorer-image {
                width: 72px;
                height: 72px;
            }

            body.bl .bl-category-explorer-groups {
                grid-template-columns: 1fr;
            }
        }
    </style>

</head>
<body class="bl" style="--bl-catalog:url('{{ asset('images/catalog-placeholders.png') }}')">
<a class="skip-link" href="#bl-main">Skip to content</a>
<header class="bl-header">
    <a class="bl-brand" href="{{ url('/') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly - Find more. Live better."></a>
    <nav class="bl-nav" aria-label="Main navigation"><a href="{{ url('/') }}" aria-current="page">Home</a><a href="{{ url('/home') }}">Shop</a><a href="{{ route('about') }}">About</a><a href="#bl-delivery">Delivery</a><a href="{{ route('contact') }}">Contact</a></nav>
    <div class="bl-actions"><a href="{{ url('/login') }}" aria-label="Sign in"><span class="material-symbols-outlined" aria-hidden="true">person</span></a><button type="button" data-bl-wishlist aria-label="Saved products"><span class="material-symbols-outlined" aria-hidden="true">favorite</span></button><button type="button" data-bl-info="cart" aria-label="Shopping cart"><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span><span class="bl-count">0</span></button></div>
    <form class="bl-search" action="{{ url('/home') }}" role="search"><button aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button><label class="sr-only" for="bl-query">Search products</label><input id="bl-query" name="search" type="search" maxlength="120" placeholder="Search for products, brands, or independent stores..."></form>
</header>
<main id="bl-main">
    <section class="bl-hero" aria-labelledby="bl-title">
        <img class="bl-banner-image" src="{{ asset('images/hero.png') }}" alt="Three men in casual everyday outfits in a sunlit studio" width="1536" height="1024" fetchpriority="high">
        <div class="bl-hero-copy"><p class="bl-wordmark">BEARLY</p><h1 id="bl-title">Shopping<br>made <em>Bearly<br>a hassle.</em></h1><p class="bl-lead">Shopping should be easy. Bearly stressful.</p><p class="bl-description">Discover unique finds from independent stores all in one place.
Curated pieces, great deals, and effortless discovery.</p><div class="bl-buttons"><a class="bl-button" href="{{ url('/home') }}">Shop now <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a><a class="bl-button bl-outline" href="#bl-categories">Explore categories</a></div>
        <div class="bl-mini-benefits"><span><span class="material-symbols-outlined" aria-hidden="true">storefront</span>Support<br>independent stores</span><span><span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>Curated finds<br>for every lifestyle</span><span><span class="material-symbols-outlined" aria-hidden="true">favorite</span>A more meaningful<br>way to shop</span></div></div>
        <p class="bl-hero-note">Good<br>Things<br>Find You<br>Here.</p>
    </section>
    <div class="bl-container">
        <section id="bl-categories" class="bl-section" aria-labelledby="bl-category-title">
            <div class="bl-section-heading">
                <h2 id="bl-category-title">Shop by Categories</h2>
                <button
                    type="button"
                    class="bl-view-all-categories"
                    data-bl-open-categories
                    aria-haspopup="dialog"
                    aria-controls="bl-category-explorer"
                    aria-expanded="false"
                >
                    View all categories
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                </button>
            </div>
            <div class="bl-categories">
            @foreach ($homeCategories as $index => $category)
                <a class="bl-category" href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}"><span class="bl-catalog-image" style="{{ $sprite($index) }}" role="img" aria-label="{{ $category['name'] }}"></span><span>{{ str_replace(' and ', ' & ', $category['name']) }}</span></a>
            @endforeach
            </div>
        </section>

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
        <section id="bl-about" class="bl-lifestyle bl-banner"><img class="bl-banner-image" src="{{ asset('images/lifestyle.png') }}" alt="Books, a brown mug, and a leafy plant on a warm wood table" width="1536" height="640" loading="lazy"><div class="bl-banner-copy"><h2>More than just shopping.<br>A brighter everyday.</h2><p>Discover curated finds from independent stores and small businesses.
Unique items, better value, a more meaningful way to shop.</p><a class="bl-button" href="{{ url('/home') }}">Shop now <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></section>
        <section id="bl-faq" class="bl-section bl-faq" aria-labelledby="bl-faq-title"><div class="bl-section-heading"><h2 id="bl-faq-title">Frequently asked questions</h2><a href="#bl-contact">More questions? Contact us.</a></div>@foreach ($faqs as [$question, $answer])<details><summary>{{ $question }}<span class="material-symbols-outlined" aria-hidden="true">add</span></summary><p>{{ $answer }}</p></details>@endforeach</section>
        <section class="bl-contact" id="bl-contact" aria-labelledby="bl-contact-title"><div><h2 id="bl-contact-title">We’re here to help</h2><p>Our contact details will be shared soon.</p></div>@foreach ([['mail','Email'],['call','Phone'],['location_on','Location'],['schedule','Support hours']] as [$icon, $label])<div class="bl-contact-item"><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span><div><h3>{{ $label }}</h3><p>To be announced</p></div></div>@endforeach</section>
        <section class="bl-signup bl-banner"><img class="bl-banner-image" src="{{ asset('images/signup.png') }}" alt="Camera, notebook, sunglasses, plant, phone, and a brown cap" width="1536" height="512" loading="lazy"><div class="bl-banner-copy"><h2>Make shopping<br>Bearly a hassle.</h2><p>Create an account and start discovering great finds from independent stores.</p><a class="bl-button" href="{{ url('/register') }}">Create an account</a></div></section>
    </div>
</main>
<footer class="bl-footer"><div class="bl-footer-grid"><div><a class="bl-brand" href="{{ url('/') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly - Find more. Live better."></a><p class="bl-copyright">© {{ date('Y') }} Bearly.
All rights reserved.</p></div><nav aria-label="Shop links"><h3>Shop</h3><a href="{{ url('/home') }}">All Products</a><a href="#bl-featured">Featured Products</a><a href="#bl-categories">Categories</a></nav><nav aria-label="About links"><h3>About</h3><a href="{{ route('about') }}">Our Story</a><a href="{{ url('/seller/dashboard') }}">Sell on Bearly</a></nav><nav aria-label="Support links"><h3>Support</h3><a href="#bl-delivery">Delivery</a><a href="#bl-faq">FAQ</a><a href="{{ route('contact') }}">Contact Us</a></nav><div class="bl-newsletter"><h3>Be the first to know</h3><p>Get updates on new launches and special offers.</p><form id="bl-newsletter"><label class="sr-only" for="bl-email">Your email</label><input type="email" id="bl-email" required maxlength="254" placeholder="Enter your email"><button class="bl-button">Subscribe</button></form><p id="bl-newsletter-status" role="status"></p></div></div><div class="bl-footer-bottom"><span>Preview marketplace · Sample products and prices</span><div><button data-bl-info="privacy">Privacy Policy</button><span>|</span><button data-bl-info="terms">Terms of Service</button></div></div></footer>

{{-- UNIQLO-style full category explorer, adapted for Bearly's marketplace categories. --}}
<div class="bl-category-explorer" id="bl-category-explorer" hidden aria-hidden="true">
    <div class="bl-category-explorer-backdrop" data-bl-close-categories></div>

    <section
        class="bl-category-explorer-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bl-category-explorer-title"
        tabindex="-1"
    >
        <header class="bl-category-explorer-header">
            <a class="bl-category-explorer-brand" href="{{ url('/') }}" aria-label="Bearly home">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
            </a>

            <div class="bl-category-explorer-heading">
                <p>BEARLY MARKETPLACE</p>
                <h2 id="bl-category-explorer-title">Shop by category</h2>
            </div>

            <button
                type="button"
                class="bl-category-explorer-close"
                data-bl-close-categories
                aria-label="Close categories"
            >
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </header>

        <div class="bl-category-explorer-body">
            <form class="bl-category-explorer-search" role="search" onsubmit="return false">
                <label class="sr-only" for="bl-category-search">Search categories</label>
                <input
                    id="bl-category-search"
                    type="search"
                    placeholder="What are you looking for?"
                    autocomplete="off"
                    data-bl-category-search
                >
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
            </form>

            <div class="bl-category-explorer-top-grid" aria-label="Main categories">
                @foreach ($homeCategories as $index => $category)
                    <a
                        class="bl-category-explorer-tile"
                        data-bl-category-tile
                        data-search="{{ strtolower($category['name'].' '.implode(' ', $category['subcategories'])) }}"
                        href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}"
                    >
                        <span
                            class="bl-catalog-image bl-category-explorer-image"
                            style="{{ $sprite($index) }}"
                            role="img"
                            aria-label="{{ $category['name'] }}"
                        ></span>
                        <span>{{ str_replace(' and ', ' & ', $category['name']) }}</span>
                    </a>
                @endforeach
            </div>

            <div class="bl-category-explorer-divider"></div>

            <div class="bl-category-explorer-subhead">
                <h3>Explore subcategories</h3>
                <p>Find exactly what you need across Bearly's independent stores.</p>
            </div>

            <div class="bl-category-explorer-groups">
                @foreach ($homeCategories as $category)
                    <article
                        class="bl-category-explorer-group"
                        data-bl-category-group
                        data-search="{{ strtolower($category['name'].' '.implode(' ', $category['subcategories'])) }}"
                    >
                        <a
                            class="bl-category-explorer-group-title"
                            href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}"
                        >
                            {{ $category['name'] }}
                            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                        </a>

                        <ul>
                            @foreach ($category['subcategories'] as $subcategory)
                                <li data-search="{{ strtolower($category['name'].' '.$subcategory) }}">
                                    <a href="{{ url('/home').'?category='.$category['slug'].'&subcategory='.urlencode($subcategory) }}">
                                        {{ $subcategory }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>

            <p class="bl-category-explorer-empty" data-bl-category-empty hidden>
                No categories match your search.
            </p>
        </div>
    </section>
</div>

<dialog class="bl-dialog" id="bl-dialog" aria-labelledby="bl-dialog-title"><button class="bl-dialog-close" aria-label="Close dialog"><span class="material-symbols-outlined" aria-hidden="true">close</span></button><h2 id="bl-dialog-title"></h2><div id="bl-dialog-content"></div></dialog>
<p class="bl-toast" id="bl-toast" role="status" hidden></p>
<script id="bl-featured-data" type="application/json">{!! json_encode($featured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>

<script>
(() => {
    "use strict";

    const openButton = document.querySelector("[data-bl-open-categories]");
    const explorer = document.getElementById("bl-category-explorer");

    if (!openButton || !explorer) {
        console.warn("Bearly category explorer: trigger or explorer element missing.");
        return;
    }

    const panel = explorer.querySelector(".bl-category-explorer-panel");
    const searchInput = explorer.querySelector("[data-bl-category-search]");
    const closeButtons = explorer.querySelectorAll("[data-bl-close-categories]");
    const tiles = [...explorer.querySelectorAll("[data-bl-category-tile]")];
    const groups = [...explorer.querySelectorAll("[data-bl-category-group]")];
    const emptyState = explorer.querySelector("[data-bl-category-empty]");

    let lastFocusedElement = null;

    const normalize = (value) =>
        String(value ?? "")
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .trim();

    const resetFilter = () => {
        if (searchInput) searchInput.value = "";

        tiles.forEach((tile) => {
            tile.classList.remove("bl-category-filter-hidden");
        });

        groups.forEach((group) => {
            group.classList.remove("bl-category-filter-hidden");
            group.querySelectorAll("li").forEach((item) => {
                item.classList.remove("bl-category-filter-hidden");
            });
        });

        if (emptyState) emptyState.hidden = true;
    };

    const applyFilter = () => {
        const query = normalize(searchInput?.value);

        if (!query) {
            resetFilter();
            return;
        }

        let visibleCount = 0;

        tiles.forEach((tile) => {
            const match = normalize(tile.dataset.search).includes(query);
            tile.classList.toggle("bl-category-filter-hidden", !match);
            if (match) visibleCount += 1;
        });

        groups.forEach((group) => {
            const title = normalize(
                group.querySelector(".bl-category-explorer-group-title")?.textContent
            );
            const titleMatches = title.includes(query);
            let childMatches = 0;

            group.querySelectorAll("li").forEach((item) => {
                const match =
                    titleMatches ||
                    normalize(item.dataset.search || item.textContent).includes(query);

                item.classList.toggle("bl-category-filter-hidden", !match);
                if (match) childMatches += 1;
            });

            const showGroup = titleMatches || childMatches > 0;
            group.classList.toggle("bl-category-filter-hidden", !showGroup);

            if (showGroup) visibleCount += 1;
        });

        if (emptyState) emptyState.hidden = visibleCount !== 0;
    };

    const openExplorer = () => {
        lastFocusedElement = document.activeElement;

        explorer.hidden = false;
        explorer.setAttribute("aria-hidden", "false");
        document.body.classList.add("bl-category-explorer-open");
        openButton.setAttribute("aria-expanded", "true");

        requestAnimationFrame(() => {
            if (panel) panel.focus();
            setTimeout(() => searchInput?.focus(), 50);
        });
    };

    const closeExplorer = () => {
        explorer.hidden = true;
        explorer.setAttribute("aria-hidden", "true");
        document.body.classList.remove("bl-category-explorer-open");
        openButton.setAttribute("aria-expanded", "false");
        resetFilter();

        if (lastFocusedElement instanceof HTMLElement) {
            lastFocusedElement.focus();
        }
    };

    // Direct listener on the actual button.
    openButton.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        openExplorer();
    });

    closeButtons.forEach((button) => {
        button.addEventListener("click", (event) => {
            event.preventDefault();
            closeExplorer();
        });
    });

    searchInput?.addEventListener("input", applyFilter);

    document.addEventListener("keydown", (event) => {
        if (explorer.hidden) return;

        if (event.key === "Escape") {
            event.preventDefault();
            closeExplorer();
        }
    });
})();
</script>

</body>
</html>
