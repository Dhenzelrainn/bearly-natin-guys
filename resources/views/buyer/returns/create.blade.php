<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Return request · Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/css/checkout.css', 'resources/css/buyer-return.css'])
</head>
<body class="bh buyer-return-page">
@include('buyer.partials.buyer-header', [
    'headerClass' => 'checkout-header',
    'headerSearchInputId' => 'return-search-input',
    'headerSearchAction' => route('products.index'),
])
<main class="buyer-return-shell">
    <a class="checkout-back" href="{{ route('buyer.profile') }}#purchases"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>My Purchases</a>
    @if (session('success'))<p class="buyer-return-alert is-success" role="status">{{ session('success') }}</p>@endif
    @if ($errors->any())<div class="buyer-return-alert is-error" role="alert"><strong>Please review the form.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <header class="buyer-return-heading">
        <span class="section-kicker">Order {{ $seller_order->seller_order_no }}</span>
        <h1>Request a return or refund</h1>
        <p>{{ $seller_order->store?->name ?? 'Seller' }} · Submitted within 7 days of order completion.</p>
        @if ($deadline)<small>Request by {{ $deadline->format('M j, Y · g:i A') }}</small>@endif
    </header>

    @if ($can_request)
        <form class="buyer-return-form" method="post" action="{{ route('buyer.returns.store', $seller_order->id) }}" enctype="multipart/form-data">
            @csrf
            <section class="buyer-return-card">
                <div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><div><h2>Choose item quantities</h2><p>Refund values use the saved order-item prices. Quantities already in another case are unavailable.</p></div></div>
                <div class="buyer-return-items">
                    @foreach ($items as $item)
                        <article class="buyer-return-item {{ $item['returnable_quantity'] < 1 ? 'is-unavailable' : '' }}">
                            @if ($item['image_url'])<img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy" onerror="this.onerror=null;this.src='/images/bearly-logo.png'">@else<span class="buyer-return-image-fallback material-symbols-outlined" aria-hidden="true">shopping_bag</span>@endif
                            <div class="buyer-return-item-copy"><strong>{{ $item['name'] }}</strong><span>{{ $item['variant'] }} · SKU {{ $item['sku'] }}</span><small>Purchased {{ $item['quantity'] }} · ₱{{ number_format($item['unit_price_minor'] / 100, 2) }} each</small></div>
                            <label class="buyer-return-quantity" for="return-quantity-{{ $item['id'] }}"><span>Return qty</span><input id="return-quantity-{{ $item['id'] }}" type="number" name="item_quantities[{{ $item['id'] }}]" min="0" max="{{ $item['returnable_quantity'] }}" value="{{ old('item_quantities.'.$item['id'], 0) }}" @disabled($item['returnable_quantity'] < 1)><small>{{ $item['returnable_quantity'] }} available</small></label>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="buyer-return-card buyer-return-fields">
                <label for="return-type">What are you requesting?<select id="return-type" name="request_type" required><option value="return_refund" @selected(old('request_type') === 'return_refund')>Return item and request a refund</option><option value="refund_only" @selected(old('request_type') === 'refund_only')>Refund only — no physical return</option></select></label>
                <p class="buyer-return-policy" data-return-policy>For a physical return, Admin approval requests pickup from the Logistics provider assigned to the original shipment. No return-shipping fee is collected in this phase. The Seller inspects the parcel before Admin can record a refund payout.</p>
                <label for="reason-code">Reason<select id="reason-code" name="reason_code" required><option value="">Choose a reason</option>@foreach ($reason_codes as $key => $label)<option value="{{ $key }}" @selected(old('reason_code') === $key)>{{ $label }}</option>@endforeach</select></label>
                <label for="buyer-note">Tell the Seller what happened<textarea id="buyer-note" name="buyer_note" rows="5" maxlength="2000" placeholder="Add details that can help the Seller review your request.">{{ old('buyer_note') }}</textarea><small>Do not include passwords, payment PINs, or other private credentials.</small></label>
                <label class="buyer-return-upload" for="return-evidence"><span class="material-symbols-outlined" aria-hidden="true">add_a_photo</span><span><strong>Add photo evidence (optional)</strong><small>JPG, PNG, or WebP · up to 5 photos · 5 MB each</small></span><input id="return-evidence" type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple></label>
            </section>

            <aside class="buyer-return-info"><span class="material-symbols-outlined" aria-hidden="true">info</span><p>Your request goes to the Seller first, then Bearly Admin reviews the case. Approved payouts are made externally and recorded by Admin; Bearly does not automatically transfer money.</p></aside>
            <div class="buyer-return-actions"><a class="buyer-return-secondary" href="{{ route('buyer.profile') }}#purchases">Cancel</a><button type="submit" class="buyer-return-primary">Submit request</button></div>
        </form>
    @else
        <section class="buyer-return-card buyer-return-unavailable"><span class="material-symbols-outlined" aria-hidden="true">event_busy</span><h2>No eligible quantities</h2><p>This Seller Order is outside the 7-day return window, is not completed, has no recorded payment, or all item quantities already belong to another case.</p></section>
    @endif

    @if ($cases->isNotEmpty())
        <section class="buyer-return-card buyer-return-history">
            <div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">history</span><div><h2>Existing cases</h2><p>Follow the status and any Seller/Admin updates here.</p></div></div>
            @foreach ($cases as $existing)
                <a class="buyer-return-case-row" href="{{ route('buyer.returns.show', $existing->id) }}"><span><strong>{{ $existing->return_no }}</strong><small>{{ Str::headline($existing->request_type) }} · {{ $existing->submitted_at?->format('M j, Y') ?? 'Date unavailable' }}</small></span><span class="buyer-return-status">{{ Str::headline($existing->status) }}</span></a>
            @endforeach
        </section>
    @endif
</main>
</body>
</html>
