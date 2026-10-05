<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Shopping Cart | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@include('buyer.partials.account-context-script')
@vite(['resources/css/buyer.css','resources/css/cart.css','resources/js/cart.js'])
</head>
<body class="bh cart-page">
<header class="header">
<a class="brand" href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly"></a>
<form class="search cart-search" action="{{ route('products.index') }}" method="get" role="search">
<label class="sr-only" for="cart-search-input">Search products</label>
<input id="cart-search-input" name="search" type="search" placeholder="Search for products, brands and more..." maxlength="120" autocomplete="off">
<button type="submit" aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button>
</form>
<nav class="header-actions">
<a href="{{ url('/profile#notifications') }}" class="notification-header-link"><span class="material-symbols-outlined">notifications</span><span>Notifications</span><span class="notification-badge" data-notification-badge>0</span></a>
<button type="button" onclick="window.location.href='{{ url('/profile#tracking') }}'"><span class="material-symbols-outlined">receipt_long</span><span>Orders</span></button>
<a href="{{ url('/chat') }}"><span class="material-symbols-outlined">chat_bubble</span><span>Chat</span></a>
<a class="cart-active" href="{{ url('/cart') }}"><span class="material-symbols-outlined">shopping_cart</span><span>Cart</span><b id="cart-header-count" class="cart-badge" hidden aria-label="Cart item count">0</b></a>
<a class="account-action" href="{{ url('/profile') }}"><span class="material-symbols-outlined">person</span><span>{{ $buyerName }}</span></a>
</nav>
</header>

<main class="cart-shell">
<div class="cart-page-top">
<a class="cart-back" href="javascript:history.back()" aria-label="Back"><span class="material-symbols-outlined">arrow_back</span></a>
<div><h1>Shopping Cart</h1><p><span id="cart-selected-count">0 items</span> selected (<span id="cart-count">0 items</span> in your cart)</p></div>
<a class="cart-wishlist-button" href="{{ url('/wishlist') }}"><span class="material-symbols-outlined" aria-hidden="true">favorite</span> View Wishlist</a>
</div>

<section id="cart-content" hidden>
<div class="cart-layout">
<div class="cart-main">
<section class="cart-promo" aria-label="Shipping voucher information">
<span class="material-symbols-outlined cart-promo-icon" aria-hidden="true">confirmation_number</span>
<div><strong>Free shipping vouchers available</strong><p>Apply a voucher at checkout to enjoy lower shipping fees.</p></div>
<button id="cart-vouchers" type="button">View Vouchers <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
</section>

<div class="cart-selection-row">
<div class="selection-left">
<label class="check-wrap" aria-label="Select all items"><input id="select-all-top" type="checkbox"><span></span></label>
<button id="select-all-label" class="selection-label" type="button">Select All (0)</button>
</div>
<button id="delete-selected" class="delete-selected" type="button"><span class="material-symbols-outlined" aria-hidden="true">delete</span> Delete Selected</button>
</div>

<div id="cart-list" class="cart-list"></div>
</div>

<aside class="order-summary" aria-labelledby="order-summary-title">
<h2 id="order-summary-title">Order Summary</h2>
<div class="summary-lines">
<div><span>Subtotal (<span id="summary-item-count">0</span> items)</span><strong id="summary-subtotal">₱0.00</strong></div>
<div><span>Shipping Fee <span class="summary-info" title="Shipping is finalized at checkout">i</span></span><strong id="summary-shipping">₱0.00</strong></div>
<div class="summary-discount"><span>Shipping Discount</span><strong id="summary-shipping-discount">− ₱0.00</strong></div>
</div>
<button id="cart-voucher-summary" class="summary-voucher" type="button"><span><span class="material-symbols-outlined" aria-hidden="true">confirmation_number</span> Voucher Discount</span><span><strong id="summary-voucher-discount">− ₱0.00</strong><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></span></button>
<div class="summary-total"><span>Total <small>(VAT included)</small></span><strong id="summary-total">₱0.00</strong></div>
<button id="cart-checkout" class="checkout-btn" type="button"><span id="checkout-label">Checkout (0)</span><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
</aside>
</div>
</section>

<section id="cart-empty" class="cart-empty">
<span class="material-symbols-outlined">shopping_cart</span><h2>Your cart is empty</h2>
<p>Add products to your cart and they will appear here.</p><div class="cart-empty-actions"><a href="{{ url('/home') }}">Continue Shopping</a><a href="{{ url('/wishlist') }}" class="empty-wishlist-link">View Wishlist</a></div>
</section>
</main>
<div id="cart-toast" class="cart-toast" role="status"></div>
<script>window.bearlyCartItems = @json($cartItems ?? []);</script>
</body></html>
