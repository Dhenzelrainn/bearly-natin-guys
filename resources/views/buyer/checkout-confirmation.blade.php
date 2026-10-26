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
        <span class="material-symbols-outlined" aria-hidden="true">{{ $order->status === 'cancelled' ? 'cancel' : ($order->status === 'fulfilled' ? 'verified' : 'check_circle') }}</span>
        <h1>{{ $order->status === 'cancelled' ? 'Order cancelled' : ($order->status === 'fulfilled' ? 'Order completed' : 'Order placed') }}</h1>
        <p>Order <strong>{{ $order->order_no }}</strong> · Cash on Delivery</p>
        @if ($order->status === 'fulfilled')
            <p>Payment completed. Thank you for confirming receipt of every item in this order.</p>
        @else
            <p>Your order is unpaid until delivery. Each Seller will review their part of this checkout.</p>
        @endif
        <div class="summary confirmation-totals">
            <div><span>Merchandise subtotal</span><strong>₱{{ number_format($order->subtotal_minor / 100, 2) }}</strong></div>
            @php
                $orderBuyerPaidShipping = $order->buyer_paid_shipping_fee_minor
                    ?? max(0, (int) $order->shipping_fee_minor - (int) $order->platform_shipping_discount_minor);
            @endphp
            <div>
                <span>Shipping paid by you<small>Original ₱{{ number_format($order->shipping_fee_minor / 100, 2) }} · Bearly subsidy ₱{{ number_format($order->platform_shipping_discount_minor / 100, 2) }}</small></span>
                <strong>₱{{ number_format((int) $orderBuyerPaidShipping / 100, 2) }}</strong>
            </div>
            @if ((int) $order->discount_minor > 0)
                <div><span>Voucher discounts</span><strong>−₱{{ number_format($order->discount_minor / 100, 2) }}</strong></div>
            @endif
            @if ($order->promotionRedemptions->isNotEmpty())
                <div class="confirmation-promotion-list">
                    <span>Offers applied to this order</span>
                    <div>
                        @foreach ($order->promotionRedemptions as $redemption)
                            @php
                                $promotionStatus = match ($redemption->status) {
                                    'reserved' => 'Reserved while payment is pending',
                                    'redeemed' => 'Applied',
                                    'released' => 'Released after cancellation',
                                    'refunded' => 'Released after full refund',
                                    default => str($redemption->status)->replace('_', ' ')->title(),
                                };
                                $promotionBenefitMinor = (int) $redemption->discount_minor + (int) $redemption->shipping_discount_minor;
                            @endphp
                            <p>
                                <span><strong>{{ $redemption->name }}</strong><small>{{ $promotionStatus }}{{ $redemption->code ? ' · Code '.$redemption->code : '' }}</small></span>
                                <strong>{{ $promotionBenefitMinor > 0 ? '−₱'.number_format($promotionBenefitMinor / 100, 2) : '—' }}</strong>
                            </p>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ((int) $order->campaign_platform_discount_minor > 0)
                <div class="confirmation-campaign-savings"><span>Bearly campaign savings (included in item prices)</span><strong>₱{{ number_format($order->campaign_platform_discount_minor / 100, 2) }}</strong></div>
            @endif
            <div class="payment-total"><span>Total due on delivery</span><strong>₱{{ number_format($order->total_minor / 100, 2) }}</strong></div>
        </div>
    </section>

    @foreach ($order->sellerOrders as $sellerOrder)
        <section class="checkout-card confirmation-seller-order">
            <div class="section-title"><span class="material-symbols-outlined">storefront</span>{{ $sellerOrder->store?->name ?? 'Seller' }}</div>
            <p>Seller Order {{ $sellerOrder->seller_order_no }} · {{ $fulfillmentLabels[$sellerOrder->id] ?? str_replace('_', ' ', ucfirst($sellerOrder->status)) }}</p>
            @foreach ($sellerOrder->items as $item)
                @php
                    $buyerLineTotalMinor = max(0, (int) $item->subtotal_minor
                        - (int) ($item->discount_minor ?? 0)
                        - (int) ($item->platform_discount_minor ?? 0)
                        - (int) ($item->campaign_platform_discount_minor ?? 0));
                    $itemQuantity = max(1, (int) $item->quantity);
                    $buyerUnitPriceMinor = intdiv($buyerLineTotalMinor + intdiv($itemQuantity, 2), $itemQuantity);
                @endphp
                <article class="confirmation-product">
                    @if (!empty($itemImages[$item->id] ?? ''))
                        <img src="{{ $itemImages[$item->id] }}" alt="{{ $item->product_name }}" loading="lazy" onerror="this.onerror=null;this.src='/images/bearly-logo.png'">
                    @else
                        <span class="confirmation-thumb-fallback" aria-hidden="true"><span class="material-symbols-outlined">shopping_bag</span></span>
                    @endif
                    <div class="confirmation-product-copy">
                        <strong>{{ $item->product_name }}</strong>
                        <small>{{ $item->variant_name ?: 'Standard' }} · Qty {{ $item->quantity }}</small>
                        <small>SKU: {{ $item->sku }}</small>
                    </div>
                    <div class="confirmation-product-side">
                        <small>₱{{ number_format($buyerUnitPriceMinor / 100, 2) }} each</small>
                        <strong>₱{{ number_format($buyerLineTotalMinor / 100, 2) }}</strong>
                    </div>
                </article>
            @endforeach
            @php
                $sellerBuyerPaidShipping = $sellerOrder->buyer_paid_shipping_fee_minor
                    ?? max(0, (int) $sellerOrder->shipping_fee_minor - (int) $sellerOrder->platform_shipping_discount_minor);
                $sellerPlatformShippingSubsidy = $sellerOrder->platform_shipping_discount_minor ?? 0;
                $savedZoneLabel = isset($sellerOrder->shipping_quote['zone_level'])
                    ? 'Zone '.$sellerOrder->shipping_quote['zone_level'].' · '.($sellerOrder->shipping_quote['zone_label'] ?? 'verified route')
                    : 'Saved shipping quote';
            @endphp
            <div class="confirmation-item">
                <span>Bearly Logistics · {{ $savedZoneLabel }} · {{ $sellerOrder->shipping_quote['billable_weight_kg'] ?? '—' }} kg
                    <small>Buyer pays ₱{{ number_format((int) $sellerBuyerPaidShipping / 100, 2) }} · Bearly subsidy ₱{{ number_format((int) $sellerPlatformShippingSubsidy / 100, 2) }}</small>
                </span>
                <strong>Original ₱{{ number_format($sellerOrder->shipping_fee_minor / 100, 2) }}</strong>
            </div>
        </section>
    @endforeach

    <section class="checkout-card confirmation-meta">
        <div><span>Order date</span><strong>{{ $placedAtLabel }}</strong></div>
        <div><span>Payment</span><strong>Cash on Delivery · {{ ucfirst($order->payment_status) }}</strong></div>
        <div class="confirmation-meta-address"><span>Deliver to</span><strong>{{ $shippingAddressLabel }}</strong></div>
    </section>

    @if (($receiptConfirmed ?? false) && $order->status !== 'cancelled')
        <div class="checkout-complete-note" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">verified</span>
            <p><strong>Order completed.</strong> You confirmed receipt of every item in this order.</p>
        </div>
    @elseif (($canConfirmReceipt ?? false) && $order->status !== 'cancelled')
        <form method="post" action="{{ route('checkout.confirm-receipt', $order->id) }}" class="checkout-confirm-form">
            @csrf
            <p>Only confirm after you have received every item in this order. Confirmation marks each Seller Order in this checkout as complete.</p>
            <button class="confirm-btn" type="submit">Confirm receipt — complete order</button>
        </form>
    @endif

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
