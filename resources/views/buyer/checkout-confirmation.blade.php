<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Order Confirmation | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@include('buyer.partials.account-context-script')
@vite(['resources/css/buyer.css','resources/css/checkout.css'])
</head>
<body class="bh checkout-page">
@include('buyer.partials.buyer-header', [
    'headerClass' => 'checkout-header',
    'headerSearchInputId' => 'confirmation-search-input',
    'headerSearchAction' => route('products.index'),
])
<main class="checkout-shell">
    <a class="checkout-back" href="{{ route('products.index') }}"><span class="material-symbols-outlined">arrow_back</span> Continue Shopping</a>
    @if (session('success'))<p class="checkout-success" role="status">{{ session('success') }}</p>@endif
    <section class="checkout-card confirmation-card">
        <span class="material-symbols-outlined" aria-hidden="true">{{ $order->status === 'cancelled' ? 'cancel' : 'check_circle' }}</span>
        <h1>{{ $order->status === 'cancelled' ? 'Order cancelled' : 'Order placed' }}</h1>
        <p>Order <strong>{{ $order->order_no }}</strong> · Cash on Delivery</p>
        <p>Your order is unpaid until delivery. Each Seller will review their part of this checkout.</p>
        <div class="summary confirmation-totals">
            <div><span>Merchandise subtotal</span><strong>₱{{ number_format($order->subtotal_minor / 100, 2) }}</strong></div>
            <div><span>Estimated shipping</span><strong>₱{{ number_format($order->shipping_fee_minor / 100, 2) }}</strong></div>
            <div class="payment-total"><span>Total due on delivery</span><strong>₱{{ number_format($order->total_minor / 100, 2) }}</strong></div>
        </div>
    </section>

    @foreach ($order->sellerOrders as $sellerOrder)
        <section class="checkout-card confirmation-seller-order">
            <div class="section-title"><span class="material-symbols-outlined">storefront</span>{{ $sellerOrder->store?->name ?? 'Seller' }}</div>
            <p>Seller Order {{ $sellerOrder->seller_order_no }} · {{ str_replace('_', ' ', ucfirst($sellerOrder->status)) }}</p>
            @foreach ($sellerOrder->items as $item)
                <div class="confirmation-item"><span>{{ $item->product_name }} · {{ $item->variant_name }} × {{ $item->quantity }}</span><strong>₱{{ number_format($item->subtotal_minor / 100, 2) }}</strong></div>
            @endforeach
            <div class="confirmation-item"><span>Estimated Bearly Logistics shipping ({{ $sellerOrder->shipping_quote['route_label'] ?? 'route quote' }}, {{ $sellerOrder->shipping_quote['billable_weight_kg'] ?? '—' }} kg)</span><strong>₱{{ number_format($sellerOrder->shipping_fee_minor / 100, 2) }}</strong></div>
        </section>
    @endforeach

    @if ($order->status === 'processing' && $order->sellerOrders->isNotEmpty() && $order->sellerOrders->every(fn ($sellerOrder) => $sellerOrder->status === 'pending'))
        <form method="post" action="{{ route('checkout.cancel', $order->id) }}" class="checkout-cancel-form">
            @csrf
            <input type="hidden" name="reason" value="Buyer requested cancellation">
            <button class="link-btn" type="submit">Cancel order before Seller processing</button>
        </form>
    @endif
</main>
</body>
</html>
