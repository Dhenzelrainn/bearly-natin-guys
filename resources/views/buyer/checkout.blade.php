<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Checkout | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@include('buyer.partials.account-context-script')
@vite(['resources/css/buyer.css','resources/css/checkout.css','resources/js/checkout.js'])
</head>
<body class="bh checkout-page">
@include('buyer.partials.buyer-header', [
    'headerClass' => 'checkout-header',
    'headerSearchInputId' => 'checkout-search-input',
    'headerSearchAction' => route('products.index'),
])

<main class="checkout-shell">
<a class="checkout-back" href="{{ url('/cart') }}"><span class="material-symbols-outlined">arrow_back</span> Back to Cart</a>
<p class="preview-notice">Shipping is charged once per combined Seller Order shipment. The zone uses verified PSGC locations; package dimensions are for delivery planning and do not change the fee.</p>
<p id="local-preview-warning" class="checkout-warning" hidden>Browser-only preview items are not saved as Bearly cart products and will not be included in this order.</p>

<form id="checkout-form" method="post" action="{{ route('checkout.place') }}">
@csrf
<input type="hidden" name="checkout_request_key" value="{{ $checkoutRequestKey }}">
<input type="hidden" name="payment_method" value="cod">
<input type="hidden" name="platform_voucher_id" id="checkout-platform-voucher-id" value="">
<input type="hidden" name="platform_shipping_voucher_id" id="checkout-platform-shipping-voucher-id" value="">
<input type="hidden" name="store_voucher_id" id="checkout-store-voucher-id" value="">
<input type="hidden" name="voucher_code" id="checkout-voucher-code" value="">
<input type="hidden" name="address_id" id="checkout-address-id" value="">
<input type="hidden" name="quote_token" id="checkout-quote-token" value="">
<div id="checkout-cart-item-ids"></div>

<section class="checkout-card address-card">
<div class="section-title"><span class="material-symbols-outlined">location_on</span>Delivery Address</div>
<div class="address-row">
<div><strong id="address-name">{{ $buyerName }}</strong><span id="address-phone">{{ $buyerPhone ?: 'No phone saved' }}</span></div>
<p id="address-text">Choose one of your saved addresses.</p>
<span id="address-default-tag" class="default-tag" hidden>Default</span>
<button id="change-address" class="link-btn" type="button">Change</button>
</div>
</section>

<section class="checkout-card products-card">
<div class="product-head"><strong>Products Ordered</strong><span>Unit Price</span><span>Quantity</span><span>Item Subtotal</span></div>
<div id="checkout-products"></div>
<div class="seller-options">
<div class="message-box"><label for="seller-message">Message for Sellers (optional):</label><input id="seller-message" name="seller_note" maxlength="120" placeholder="Add a short note for the Sellers"></div>
<div class="shipping-box"><div><strong>Shipping Option</strong><span id="shipping-label">Bearly Logistics</span><small id="shipping-method-note">Zone-based fee per combined Seller Order shipment</small></div><strong id="shipping-price">—</strong></div>
</div>
<div class="order-total-line">Order Total (<span id="order-items">0</span> item): <strong id="order-total">₱0.00</strong></div>
</section>

<section class="checkout-card payment-card">
<div class="payment-head"><h2>Payment Method</h2><div class="cod-payment"><span class="material-symbols-outlined">payments</span><span><strong id="payment-label">Cash on Delivery</strong><small>Pay the confirmed order total in cash when it is delivered.</small></span></div></div>
<div class="checkout-voucher-box" aria-labelledby="checkout-voucher-title">
    <div class="checkout-voucher-heading">
        <div>
            <h3 id="checkout-voucher-title">Vouchers</h3>
            <p>Apply up to one merchandise voucher, one shipping voucher, and one Store voucher. Eligible automatic offers are checked too.</p>
        </div>
        <button id="checkout-voucher-clear" class="checkout-voucher-clear" type="button" hidden>Clear</button>
    </div>
    <div class="checkout-voucher-fields">
        <label>
            <span>Platform merchandise voucher</span>
            <select id="checkout-platform-voucher">
                <option value="">No merchandise voucher</option>
            </select>
        </label>
        <label>
            <span>Platform shipping voucher</span>
            <select id="checkout-platform-shipping-voucher">
                <option value="">No shipping voucher</option>
            </select>
        </label>
        <label>
            <span>Store voucher</span>
            <select id="checkout-store-voucher">
                <option value="">No Store voucher</option>
            </select>
        </label>
    </div>
    <div class="checkout-voucher-code">
        <label for="checkout-voucher-code-input" class="sr-only">Voucher code</label>
        <input id="checkout-voucher-code-input" type="text" maxlength="40" placeholder="Or enter a voucher code" autocomplete="off" spellcheck="false">
        <button id="checkout-voucher-code-apply" type="button">Apply code</button>
    </div>
    <p id="checkout-voucher-status" class="checkout-voucher-status" role="status" aria-live="polite" hidden></p>
    <div id="checkout-applied-vouchers" class="checkout-applied-vouchers"></div>
    <div id="checkout-automatic-offer-notes" class="checkout-automatic-offer-notes" aria-live="polite" hidden></div>
</div>
<div class="summary">
<div><span>Merchandise Subtotal</span><strong id="summary-subtotal">₱0.00</strong></div>
<div><span>Original shipping</span><strong id="summary-shipping">—</strong></div>
<div id="summary-seller-discount-row" hidden><span>Store voucher</span><strong id="summary-seller-discount">—</strong></div>
<div id="summary-platform-discount-row" hidden><span>Platform merchandise discount</span><strong id="summary-platform-discount">—</strong></div>
<div id="summary-shipping-discount-row" hidden><span>Bearly shipping voucher</span><strong id="summary-shipping-discount">—</strong></div>
<div class="payment-total"><span>Total Due on Delivery</span><strong id="summary-total">—</strong></div>
</div>
<div id="shipping-breakdown" class="shipping-breakdown" aria-live="polite"></div>
@if ($errors->any())
<div class="checkout-validation-errors" role="alert">{{ $errors->first() }}</div>
@endif
<p id="checkout-error" class="field-error" role="alert" hidden></p>
<p id="checkout-status" class="checkout-status" role="status" aria-live="polite">Loading your saved cart and delivery addresses…</p>
<div class="place-row"><button id="place-order" class="place-order" type="submit" disabled>Place Order</button></div>
</section>
</form>
</main>

<div id="checkout-empty" class="checkout-modal" hidden><div class="modal-box"><span class="material-symbols-outlined">shopping_cart</span><h2>No saved items selected</h2><p>Checkout can only use products saved in your Bearly cart. Choose products from your cart and try again.</p><a href="{{ url('/cart') }}">Back to Cart</a></div></div>
<div id="choice-modal" class="checkout-modal" hidden><div class="modal-box choice-box"><button class="modal-close" data-close type="button" aria-label="Close address choices">×</button><h2 id="choice-title">Choose delivery address</h2><div id="choice-options"></div></div></div>
<div id="checkout-toast" class="checkout-toast" role="status" aria-live="polite"></div>
</body></html>
