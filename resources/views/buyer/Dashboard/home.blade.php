@extends('layouts.buyer')

@section('title', 'Bearly | Everything You Need')

@section('content')
@php
    $productImages = [
        'https://images.unsplash.com/photo-1606220945770-b5b6c2c55bf1?auto=format&fit=crop&w=600&q=85',
        'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=600&q=85',
        'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=600&q=85',
        'https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&fit=crop&w=600&q=85',
        'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=600&q=85',
        'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=600&q=85',
    ];
    $categoryImages = [
        'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1594633312681-425c7b97ccd1?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1559454403-b8fb88521f11?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1618221195710-dd6b41faaea6?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1519861531473-9200262188bf?auto=format&fit=crop&w=220&q=85',
        'https://images.unsplash.com/photo-1543466835-00a7907e9de1?auto=format&fit=crop&w=220&q=85',
    ];
@endphp

<div class="marketplace-shell">
    <header class="market-header">
        <div class="header-top">
            <button class="mobile-menu" type="button" aria-label="Open categories">☰</button>
            <a class="brand" href="{{ route('home') }}"><span class="brand-mark">🐻</span><span>Bearly</span></a>
            <form class="market-search" action="{{ route('products.index') }}" method="GET">
                <input name="search" type="search" placeholder="Search products, shops, and more" aria-label="Search products">
                <button type="submit" aria-label="Search">⌕</button>
            </form>
            <nav class="header-actions" aria-label="Quick actions">
                <button type="button" data-open-notifications><span>♧</span><small>Alerts</small><b>3</b></button>
                <button type="button" data-open-chat><span>▢</span><small>Chat</small></button>
                <a href="{{ route('wishlist.index') }}"><span>♡</span><small>Wishlist</small><b class="gold">6</b></a>
                <button type="button"><span>▤</span><small>Orders</small></button>
                <a href="{{ route('cart.view') }}"><span>🛒</span><small>Cart</small><b class="cart-number" id="cartCount">0</b></a>
            </nav>
        </div>
        <nav class="main-nav" aria-label="Main navigation">
            <a class="active" href="{{ route('home') }}">Home</a>
            <a href="{{ route('products.index') }}">Categories</a>
            <a href="{{ route('products.index', ['sort' => 'popular']) }}">Flash Deals</a>
            <a href="{{ route('products.index', ['sort' => 'newest']) }}">New Arrivals</a>
            <a href="{{ route('products.index', ['sort' => 'popular']) }}">Best Sellers</a>
            <a href="{{ route('wishlist.index') }}">Wishlist</a>
        </nav>
    </header>

    <div class="market-body">
        <aside class="category-sidebar">
            <div class="category-title"><span>☰</span> Shop by category</div>
            @foreach($categories as $index => $category)
                <a href="{{ route('products.index', ['category' => $category['name']]) }}"><span class="sidebar-category-icon"><img src="{{ $categoryImages[$index] ?? $productImages[0] }}" alt=""></span>{{ $category['name'] }}</a>
            @endforeach
            <a class="all-categories" href="{{ route('products.index') }}">◌ &nbsp; View all categories</a>
        </aside>

        <main class="market-main">
            <section class="hero-slider" aria-label="Featured offers">
                <article class="hero-banner is-active" data-slide="0">
                    <div class="hero-copy"><p class="eyebrow">The Bearly edit</p><h1>Good finds.<br>Better days.</h1><p>Discover useful, beautiful picks from shops<br>you can count on.</p><a class="shop-button" href="{{ route('products.index') }}">Start shopping <span aria-hidden="true">→</span></a></div>
                    <div class="hero-visual"><div class="hero-sun"></div><img class="hero-product hero-product-main" src="https://images.unsplash.com/photo-1559454403-b8fb88521f11?auto=format&fit=crop&w=700&q=88" alt="Teddy bear product"><img class="hero-product hero-product-bag" src="https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=450&q=88" alt="Brown handbag"><img class="hero-product hero-product-shoe" src="https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=500&q=88" alt="White sneaker"></div>
                </article>
                <article class="hero-banner" data-slide="1">
                    <div class="hero-copy"><p class="eyebrow">Fresh arrivals</p><h1>New finds,<br>made for you.</h1><p>Explore new styles and everyday essentials<br>from trusted Bearly shops.</p><a class="shop-button" href="{{ route('products.index', ['sort' => 'newest']) }}">See new arrivals <span aria-hidden="true">→</span></a></div>
                    <div class="hero-visual hero-visual-alt"><div class="hero-sun"></div><img class="hero-product hero-product-main" src="https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=700&q=88" alt="Modern backpack"><img class="hero-product hero-product-bag" src="https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=450&q=88" alt="Running shoe"><img class="hero-product hero-product-shoe" src="https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=500&q=88" alt="Smartphone"></div>
                </article>
                <article class="hero-banner" data-slide="2">
                    <div class="hero-copy"><p class="eyebrow">Home &amp; living picks</p><h1>Make space<br>for better days.</h1><p>Warm details and useful upgrades for<br>every corner of home.</p><a class="shop-button" href="{{ route('products.index', ['category' => 'Home & Living']) }}">Shop home <span aria-hidden="true">→</span></a></div>
                    <div class="hero-visual hero-visual-alt"><div class="hero-sun"></div><img class="hero-product hero-product-main" src="https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=700&q=88" alt="Comfortable sofa"><img class="hero-product hero-product-bag" src="https://images.unsplash.com/photo-1485955900006-10f4d324d411?auto=format&fit=crop&w=450&q=88" alt="Indoor plant"><img class="hero-product hero-product-shoe" src="https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=500&q=88" alt="Pendant light"></div>
                </article>
                <div class="hero-dots" role="tablist" aria-label="Hero slides"><button class="selected" type="button" data-slide-to="0" aria-label="Show first slide"></button><button type="button" data-slide-to="1" aria-label="Show second slide"></button><button type="button" data-slide-to="2" aria-label="Show third slide"></button></div>
            </section>

            <section class="benefit-strip" aria-label="Bearly services">
                <div><strong>▱</strong><span>Free shipping<small>On orders over RM99</small></span></div><div><strong>♧</strong><span>Daily vouchers<small>Save more every day</small></span></div><div><strong>♢</strong><span>Secure payments<small>Protected checkout</small></span></div><div><strong>⟳</strong><span>Easy returns<small>Hassle-free support</small></span></div><div><strong>♧</strong><span>Shop with confidence<small>Trusted local sellers</small></span></div>
            </section>

            <section class="market-section flash-section">
                <div class="section-head"><div><p class="section-kicker">Limited-time offers</p><h2><em>ϟ</em> Flash deals</h2></div><div class="countdown-label">Ends in <strong id="hours">02</strong> : <strong id="minutes">45</strong> : <strong id="seconds">18</strong></div><a href="{{ route('products.index', ['sort' => 'popular']) }}">See all deals →</a></div>
                <div class="product-row">
                    @foreach(array_slice($recommendedProducts, 0, 5) as $index => $product)
                        <article class="deal-card"><div class="deal-image"><span class="discount">-{{ $product['discount'] }}%</span><img src="{{ $productImages[$index] }}" alt="{{ $product['name'] }}"><button class="heart wishlist-product" type="button" data-product-id="{{ $product['id'] }}" aria-label="Add {{ $product['name'] }} to wishlist">♡</button></div><h3>{{ $product['name'] }}</h3><strong class="deal-price">RM{{ number_format($product['price']) }}</strong><del>RM{{ number_format($product['old_price']) }}</del><small>{{ $product['sold'] }} sold</small><div class="deal-progress deal-progress--{{ $index + 1 }}"><span></span></div></article>
                    @endforeach
                </div>
            </section>

            <section class="market-section category-section"><div class="section-head"><div><p class="section-kicker">Browse by interest</p><h2>Shop by category</h2></div><a href="{{ route('products.index') }}">View all →</a></div><div class="category-row">@foreach($categories as $index => $category)<a href="{{ route('products.index', ['category' => $category['name']]) }}"><span><img src="{{ $categoryImages[$index] ?? $productImages[0] }}" alt="{{ $category['name'] }}"></span><strong>{{ $category['name'] }}</strong></a>@endforeach<a href="{{ route('products.index') }}"><span class="more-categories">•••</span><strong>View all</strong></a></div></section>

            <section class="market-section shops-section"><div class="section-head"><div><p class="section-kicker">Trusted sellers</p><h2>Featured shops</h2></div><a href="{{ route('products.index') }}">Explore shops →</a></div><div class="shop-row">@foreach($featuredShops as $shop)<a class="shop-card" href="{{ route('products.index') }}"><span class="shop-logo">{{ $shop['logo'] }}</span><span class="shop-card-copy"><strong>{{ $shop['name'] }}</strong><small>★ {{ $shop['rating'] }} · {{ number_format($shop['followers']) }} followers</small></span><span class="shop-arrow">→</span></a>@endforeach</div></section>

            <section class="market-section recommended-section"><div class="section-head"><div><p class="section-kicker">Picked for your next scroll</p><h2>Daily discovery</h2></div><a href="{{ route('products.index') }}">View all →</a></div><div class="product-row recommended-row">@foreach($bestSellers as $index => $product)<article class="deal-card recommendation-card"><div class="deal-image"><span class="discount">-{{ $product['discount'] }}%</span><img src="{{ $productImages[($index + 2) % count($productImages)] }}" alt="{{ $product['name'] }}"><button class="heart wishlist-product" type="button" data-product-id="{{ $product['id'] }}" aria-label="Add {{ $product['name'] }} to wishlist">♡</button></div><h3>{{ $product['name'] }}</h3><strong class="deal-price">RM{{ number_format($product['price']) }}</strong><del>RM{{ number_format($product['old_price']) }}</del><small>★ {{ $product['rating'] }} · {{ $product['sold'] }} sold</small></article>@endforeach</div></section>
        </main>
    </div>
</div>
<div class="toast" id="toast" role="status"></div>
@endsection
