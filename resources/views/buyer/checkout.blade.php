<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Checkout | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@include('buyer.partials.account-context-script')
@vite(['resources/css/buyer.css','resources/css/checkout.css','resources/js/checkout.js'])
</head>
<body class="bh checkout-page">
<header class="header checkout-header">
<a class="brand" href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly"></a>
<div class="checkout-brand-title"><span></span><strong>Checkout</strong><span class="preview-badge">Preview only</span></div>
<div class="checkout-header-spacer"></div>
<nav class="header-actions">
<a href="{{ url('/profile#notifications') }}" class="notification-header-link"><span class="material-symbols-outlined">notifications</span><span>Notifications</span><span class="notification-badge" data-notification-badge>0</span></a>
<a href="{{ url('/profile#tracking') }}"><span class="material-symbols-outlined">receipt_long</span><span>Orders</span></a>
<a href="{{ url('/chat') }}"><span class="material-symbols-outlined">chat_bubble</span><span>Chat</span></a>
<a href="{{ url('/cart') }}"><span class="material-symbols-outlined">shopping_cart</span><span>Cart</span></a>
<a class="account-action" href="{{ url('/profile') }}"><span class="material-symbols-outlined">person</span><span>{{ $buyerName }}</span></a>
</nav>
</header>

<main class="checkout-shell">
<p class="preview-notice">Checkout is a frontend preview. Placing an order will not create an order, payment, inventory, or delivery record.</p>
<a class="checkout-back" href="{{ url('/cart') }}"><span class="material-symbols-outlined">arrow_back</span> Back to Cart</a>

<section class="checkout-card address-card">
<div class="section-title"><span class="material-symbols-outlined">location_on</span>Delivery Address</div>
<div class="address-row">
<div><strong id="address-name">{{ $buyerName }}</strong><span id="address-phone">{{ $buyerPhone ?: 'No phone saved' }}</span></div>
<p id="address-text">Your delivery address will appear here.</p>
<span id="address-default-tag" class="default-tag" hidden>Default</span>
<button id="change-address" class="link-btn" type="button">Change</button>
</div>
</section>

<section class="checkout-card products-card">
<div class="product-head"><strong>Products Ordered</strong><span>Unit Price</span><span>Quantity</span><span>Item Subtotal</span></div>
<div id="checkout-products"></div>
<div class="seller-options">
<div class="message-box"><label for="seller-message">Message for Seller:</label><input id="seller-message" maxlength="120" placeholder="Please leave a message..."></div>
<div class="shipping-box"><div><strong>Shipping Option</strong><span id="shipping-label">Standard Delivery</span><small>Estimated 3–7 days</small></div><button id="change-shipping" class="link-btn" type="button">Change</button><strong id="shipping-price">₱50.00</strong></div>
</div>
<div class="order-total-line">Order Total (<span id="order-items">0</span> item): <strong id="order-total">₱0.00</strong></div>
</section>

<section class="checkout-card simple-row">
<div class="row-label"><span class="material-symbols-outlined">confirmation_number</span>Bearly Voucher</div>
<div><span id="voucher-status">No voucher selected</span><button id="voucher-btn" class="link-btn" type="button">Select Voucher</button></div>
</section>

<section class="checkout-card payment-card">
<div class="payment-head"><h2>Payment Method</h2><div class="cod-payment"><span class="material-symbols-outlined">payments</span><span><strong id="payment-label">Cash on Delivery</strong><small>Pay with cash when your order is delivered.</small></span></div></div>
<div class="summary">
<div><span>Merchandise Subtotal</span><strong id="summary-subtotal">₱0.00</strong></div>
<div><span>Shipping Subtotal</span><strong id="summary-shipping">₱50.00</strong></div>
<div><span>Voucher Discount</span><strong id="summary-discount">−₱0.00</strong></div>
<div class="payment-total"><span>Total Payment</span><strong id="summary-total">₱0.00</strong></div>
</div>
<div class="place-row"><button id="place-order" class="place-order" type="button">Place Order</button></div>
</section>
</main>

<div id="checkout-empty" class="checkout-modal" hidden><div class="modal-box"><span class="material-symbols-outlined">shopping_cart</span><h2>No items selected</h2><p>Go back to your cart and select products to check out.</p><a href="{{ url('/cart') }}">Back to Cart</a></div></div>
<div id="choice-modal" class="checkout-modal" hidden><div class="modal-box choice-box"><button class="modal-close" data-close type="button">×</button><h2 id="choice-title">Choose</h2><div id="choice-options"></div></div></div>
<div id="checkout-toast" class="checkout-toast" role="status"></div>
</body></html>
