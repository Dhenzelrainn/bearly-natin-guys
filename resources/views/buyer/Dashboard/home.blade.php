@php

$homeCategories = json_decode(file_get_contents(resource_path('data/buyer-categories.json')), true, 512, JSON_THROW_ON_ERROR);



$homeProducts = array_slice($liveCatalog ?? [], 0, 60);
$usingLiveCatalog = true;

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



<form class="search" id="search-form" role="search" action="{{ route('products.index') }}" method="get">



<label class="sr-only" for="search-category">Search category</label>



<select id="search-category" name="category"><option value="">All categories</option></select>



<label class="sr-only" for="search-input">Search products</label>

<input id="search-input" name="search" type="search" placeholder="Search for products, brands or sellers..." maxlength="120" autocomplete="off">



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



@include('buyer.partials.live-offers', ['promotions' => $activePromotions ?? collect()])

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







@include('buyer.partials.home-campaigns', ['campaigns' => $liveCampaigns ?? collect()])

@if (($liveFlashDealGroups ?? []) !== [])
    <section class="flash-deals-preview home-flash-deals home-featured-section" aria-labelledby="home-flash-title">
        <div class="flash-deals-heading">
            <div class="flash-deals-title"><h2 id="home-flash-title">Flash Deals</h2></div>
            <a class="text-button top-view-all" href="{{ url('/flash-deals') }}">View all <span aria-hidden="true">→</span></a>
        </div>

        <div class="home-flash-campaigns">
            @foreach ($liveFlashDealGroups as $group)
                @php($campaign = $group['campaign'])
                <section class="home-flash-campaign" aria-label="{{ $campaign['name'] }} Flash Deal">
                    <header class="home-flash-campaign-heading">
                        <div>
                            <span class="home-flash-campaign-type">{{ $campaign['type_label'] }}</span>
                            <h3>{{ $campaign['name'] }}</h3>
                            <p>{{ $campaign['period'] }}</p>
                        </div>
                        <a href="{{ route('campaigns.show', $campaign['id']) }}">Campaign details <span aria-hidden="true">→</span></a>
                    </header>
                    <div class="home-flash-product-grid">
                        @foreach ($group['products'] as $product)
                            <article class="home-flash-product-card discovery-card" data-discovery-product="live:{{ (int) $product['id'] }}" data-live-product-id="{{ (int) $product['id'] }}">
                                <a class="home-flash-product-image" href="{{ route('products.show', ['product' => $product['id']]) }}" aria-label="View {{ $product['name'] }}">
                                    @if (filled($product['image'] ?? null))
                                        <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy">
                                    @else
                                        <span class="material-symbols-outlined" aria-hidden="true">image</span>
                                    @endif
                                </a>
                                <button
                                    type="button"
                                    class="discovery-like{{ ($product['is_wishlisted'] ?? false) ? ' is-liked' : '' }}"
                                    data-discovery-like
                                    aria-pressed="{{ ($product['is_wishlisted'] ?? false) ? 'true' : 'false' }}"
                                    aria-label="{{ ($product['is_wishlisted'] ?? false) ? 'Remove' : 'Save' }} {{ $product['name'] }} {{ ($product['is_wishlisted'] ?? false) ? 'from' : 'to' }} My Likes"
                                ><span class="material-symbols-outlined" aria-hidden="true">favorite</span></button>
                                <div class="home-flash-product-copy">
                                    <span>{{ $product['category'] }}</span>
                                    <h4><a href="{{ route('products.show', ['product' => $product['id']]) }}">{{ $product['name'] }}</a></h4>
                                    <strong>₱{{ number_format(((int) $product['price_minor']) / 100, 2) }}</strong>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </section>
@endif

@if (($popularRankingProducts ?? []) !== [] || ($topSalesRankingProducts ?? []) !== [])
    <section class="top-products-preview home-trending-shell" aria-labelledby="top-products-title" data-home-ranking-section>
        <div class="top-products-heading">
            <div class="top-products-title-wrap">
                <div>
                    <p class="home-trending-eyebrow">BEARLY COMMUNITY RANKINGS</p>
                    <h2 id="top-products-title">Trending on Bearly</h2>
                </div>
                <div class="top-ranking-tabs" role="tablist" aria-label="Trending product rankings">
                    @if (($popularRankingProducts ?? []) !== [])
                        <button
                            id="home-ranking-tab-popular"
                            type="button"
                            role="tab"
                            aria-controls="home-ranking-panel-popular"
                            aria-selected="{{ ($defaultRankingKind ?? 'popular') === 'popular' ? 'true' : 'false' }}"
                            tabindex="{{ ($defaultRankingKind ?? 'popular') === 'popular' ? '0' : '-1' }}"
                            class="{{ ($defaultRankingKind ?? 'popular') === 'popular' ? 'is-active' : '' }}"
                            data-home-ranking-tab="popular"
                            data-ranking-url="{{ route('top-products') }}"
                        >Popular Picks</button>
                    @endif
                    @if (($topSalesRankingProducts ?? []) !== [])
                        <button
                            id="home-ranking-tab-top-sales"
                            type="button"
                            role="tab"
                            aria-controls="home-ranking-panel-top-sales"
                            aria-selected="{{ ($defaultRankingKind ?? 'popular') === 'top_sales' ? 'true' : 'false' }}"
                            tabindex="{{ ($defaultRankingKind ?? 'popular') === 'top_sales' ? '0' : '-1' }}"
                            class="{{ ($defaultRankingKind ?? 'popular') === 'top_sales' ? 'is-active' : '' }}"
                            data-home-ranking-tab="top_sales"
                            data-ranking-url="{{ route('top-sales') }}"
                        >Best Sellers</button>
                    @endif
                </div>
            </div>
            <a
                class="text-button top-view-all"
                href="{{ ($defaultRankingKind ?? 'popular') === 'popular' ? route('top-products') : route('top-sales') }}"
                data-home-ranking-view-all
            >View all <span aria-hidden="true">→</span></a>
        </div>

        <div class="home-ranking-groups">
            @if (($popularRankingProducts ?? []) !== [])
                <section
                    id="home-ranking-panel-popular"
                    class="home-ranking-group home-ranking-panel"
                    data-ranking-kind="popular"
                    data-home-ranking-panel="popular"
                    role="tabpanel"
                    aria-labelledby="home-ranking-tab-popular"
                    @if (($defaultRankingKind ?? 'popular') !== 'popular') hidden @endif
                >
                    <p class="home-ranking-explainer">Popular Picks, ranked by saves from active Buyers.</p>
                    <div class="home-ranking-carousel">
                        <button type="button" class="home-ranking-scroll is-previous" data-ranking-scroll="previous" aria-label="Scroll Popular Picks left" aria-controls="home-ranking-track-popular" hidden>
                            <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                        </button>
                        <div id="home-ranking-track-popular" class="top-products-grid home-ranking-grid" data-ranking-track>
                        @foreach ($popularRankingProducts as $product)
                            @include('buyer.partials.ranked-product-card', [
                                'product' => $product,
                                'rank' => $loop->iteration,
                                'rankingType' => 'popular',
                                'homeRankingCard' => true,
                            ])
                        @endforeach
                        </div>
                        <button type="button" class="home-ranking-scroll is-next" data-ranking-scroll="next" aria-label="Scroll Popular Picks right" aria-controls="home-ranking-track-popular" hidden>
                            <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                        </button>
                    </div>
                </section>
            @endif

            @if (($topSalesRankingProducts ?? []) !== [])
                <section
                    id="home-ranking-panel-top-sales"
                    class="home-ranking-group home-ranking-panel"
                    data-ranking-kind="top-sales"
                    data-home-ranking-panel="top_sales"
                    role="tabpanel"
                    aria-labelledby="home-ranking-tab-top-sales"
                    @if (($defaultRankingKind ?? 'popular') !== 'top_sales') hidden @endif
                >
                    <p class="home-ranking-explainer">Best Sellers, ranked by units in completed Seller Orders.</p>
                    <div class="home-ranking-carousel">
                        <button type="button" class="home-ranking-scroll is-previous" data-ranking-scroll="previous" aria-label="Scroll Best Sellers left" aria-controls="home-ranking-track-top-sales" hidden>
                            <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                        </button>
                        <div id="home-ranking-track-top-sales" class="top-products-grid home-ranking-grid" data-ranking-track>
                        @foreach ($topSalesRankingProducts as $product)
                            @include('buyer.partials.ranked-product-card', [
                                'product' => $product,
                                'rank' => $loop->iteration,
                                'rankingType' => 'top_sales',
                                'homeRankingCard' => true,
                            ])
                        @endforeach
                        </div>
                        <button type="button" class="home-ranking-scroll is-next" data-ranking-scroll="next" aria-label="Scroll Best Sellers right" aria-controls="home-ranking-track-top-sales" hidden>
                            <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                        </button>
                    </div>
                </section>
            @endif
        </div>
    </section>
@endif
<section class="discover" id="results" aria-labelledby="results-title">

<div class="section-heading"><div><h2 id="results-title">Daily Discoveries</h2><p id="results-caption" class="results-caption" hidden></p></div><button class="text-button" id="view-all">View all <span aria-hidden="true">→</span></button></div>

<div class="results-tools" id="results-tools" hidden><div id="active-filters"></div><label>Sort by <select id="sort"><option value="featured">Featured</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option><option value="name">Name: A–Z</option></select></label></div>







<div class="product-grid" id="product-grid"></div>

<div class="empty" id="empty" hidden><span class="material-symbols-outlined" aria-hidden="true">search_off</span><h3>No matching finds yet</h3><p>Try another search or explore a different category.</p><button class="button" id="reset-search">Browse all products</button></div>



<noscript><p>Enable JavaScript to browse this homepage’s {{ $usingLiveCatalog ? 'live product catalog' : 'sample catalog' }}.</p></noscript>



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

<script id="home-data" type="application/json">{!! json_encode(['categories' => $homeCategories, 'products' => $homeProducts, 'live' => $usingLiveCatalog], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>




</body>



</html>
