<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Shopping Cart | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@vite(['resources/css/buyer.css','resources/css/cart.css','resources/js/cart.js'])
</head>
<body class="bh cart-page">
<header class="header">
<a class="brand" href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly"></a>
<div class="cart-brand-title"><span></span><strong>Shopping Cart</strong></div>
<div class="cart-header-spacer"></div>
<nav class="header-actions">
<a href="{{ url('/profile#notifications') }}" class="notification-header-link"><span class="material-symbols-outlined">notifications</span><span>Notifications</span><span class="notification-badge" data-notification-badge>3</span></a>
<button type="button" onclick="window.location.href='{{ url('/profile#tracking') }}'"><span class="material-symbols-outlined">receipt_long</span><span>Orders</span></button>
<a href="{{ url('/chat') }}"><span class="material-symbols-outlined">chat_bubble</span><span>Chat</span></a>
<a class="cart-active" href="{{ url('/cart') }}"><span class="material-symbols-outlined">shopping_cart</span><span>Cart</span><b id="cart-header-count" class="cart-badge">0</b></a>
<a class="account-action" href="{{ url('/profile') }}"><span class="material-symbols-outlined">person</span><span>Mia Santos</span></a>
</nav>
</header>

<main class="cart-shell">
<div class="cart-page-top">
<a class="cart-back" href="javascript:history.back()" aria-label="Back"><span class="material-symbols-outlined">arrow_back</span></a>
<div><h1>Shopping Cart</h1><p><span id="cart-count">0</span> item(s)</p></div>
</div>

<section id="cart-content" hidden>
<div class="cart-columns">
<label class="check-wrap"><input id="select-all-top" type="checkbox"><span></span></label>
<div>Product</div><div>Unit Price</div><div>Quantity</div><div>Total Price</div><div>Actions</div>
</div>
<div id="cart-list" class="cart-list"></div>
</section>

<section id="cart-empty" class="cart-empty">
<span class="material-symbols-outlined">shopping_cart</span><h2>Your cart is empty</h2>
<p>Add products to your cart and they will appear here.</p><a href="{{ url('/home') }}">Continue Shopping</a>
</section>
</main>

<div id="cart-bar" class="cart-bottom" hidden>
<div class="cart-bottom-inner">
<label class="check-wrap"><input id="select-all-bottom" type="checkbox"><span></span></label>
<button id="select-all-label" class="text-btn" type="button">Select All (0)</button>
<button id="delete-selected" class="text-btn" type="button">Delete</button>
<a href="{{ url('/wishlist') }}" class="wishlist-link">Move to Wishlist</a>
<div class="bottom-spacer"></div>
<div class="selected-total">Total (<span id="selected-count">0</span> item): <strong id="cart-total">₱0.00</strong></div>
<button id="cart-checkout" class="checkout-btn" type="button">Check Out</button>
</div>
</div>
<div id="cart-toast" class="cart-toast" role="status"></div>
</body></html>