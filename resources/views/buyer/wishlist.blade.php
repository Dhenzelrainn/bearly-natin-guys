<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
@include('buyer.partials.account-context-script')
<title>My Wishlist | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@vite(['resources/css/buyer.css', 'resources/css/wishlist.css', 'resources/js/buyer.js', 'resources/js/wishlist.js'])
</head>
<body class="bh wl-page">
@include('buyer.partials.buyer-header')
<main class="wl-wrap">
    <div class="wl-top">
        <a class="wl-back" href="{{ url('/home') }}" aria-label="Back to shopping"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span></a>
        <div class="wl-title"><h1>My Wishlist</h1><div class="wl-sub"><span id="wl-count">{{ count($wishlistItems ?? []) }}</span> saved items</div></div>
    </div>

    <div id="wl-grid" class="wl-grid" @if(empty($wishlistItems)) hidden @endif>
        @foreach($wishlistItems ?? [] as $item)
            @php
                $hasStock = (int) ($item['stock'] ?? 0) > 0;
                $canPurchase = ($item['orderable'] ?? false) === true && $hasStock && ! empty($item['product_variant_id']);
                $unavailableMessage = ($item['orderable'] ?? false) !== true
                    ? 'Unavailable with Cash on Delivery checkout'
                    : 'Currently out of stock';
            @endphp
            <article class="wl-card" data-wishlist-card data-product-id="{{ (int) ($item['product_id'] ?? 0) }}" data-variant-id="{{ (int) ($item['product_variant_id'] ?? 0) }}" data-product-name="{{ $item['name'] ?? 'Product' }}">
                <div class="wl-img">
                    <img src="{{ $item['image'] ?: asset('images/bearly-logo-2.png') }}" alt="{{ $item['name'] ?? 'Product' }}" loading="lazy">
                    <button class="wl-remove" aria-label="Remove {{ $item['name'] ?? 'product' }} from My Wishlist" data-wishlist-remove type="button"><span class="material-symbols-outlined" aria-hidden="true">favorite</span></button>
                </div>
                <div class="wl-copy">
                    <div class="wl-cat">{{ $item['category'] ?? $item['seller_name'] ?? 'Bearly' }}</div>
                    <div class="wl-name">{{ $item['name'] ?? 'Product' }}</div>
                    <div class="wl-price">₱{{ number_format(((int) ($item['price_minor'] ?? 0)) / 100, 2) }}</div>
                    <div class="wl-meta">{{ $canPurchase ? 'Saved to your account' : 'Saved · '.$unavailableMessage }}</div>
                    <button class="wl-cart" type="button" data-wishlist-add @disabled(! $canPurchase)>{{ $canPurchase ? 'Add to Cart' : (($item['orderable'] ?? false) ? 'Out of stock' : 'Unavailable') }}</button>
                </div>
            </article>
        @endforeach
    </div>
    <div id="wl-empty" class="wl-empty" @if(!empty($wishlistItems)) hidden @endif>
        <span class="material-symbols-outlined" aria-hidden="true">favorite_border</span>
        <h2>Your wishlist is empty</h2>
        <p>Save a live Bearly product and it will appear here.</p>
        <a href="{{ url('/home') }}">Continue shopping</a>
    </div>
    <p id="wl-status" class="wl-status" role="status" aria-live="polite" hidden></p>
</main>
</body>
</html>
