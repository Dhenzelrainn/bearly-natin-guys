@extends('layouts.courier')

@section('title', 'Complete Delivery')
@section('page-title', 'Complete Delivery')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 06</span><h1>Verify and complete delivery</h1><p>Confirm the receiver, add proof-of-delivery placeholder data, and simulate updating the order status to Completed.</p></div>
    <div class="hero-summary-card"><span class="metric-icon"><i data-lucide="badge-check"></i></span><div><strong>{{ $delivery['order'] }}</strong><small>Ready for completion</small></div></div>
</section>

<section class="completion-grid">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Delivery verification</span><h2>Customer handoff</h2><p>Verify the receiver before completing the order.</p></div><span class="status-badge badge-warning">Arrived</span></div>
        <div class="detail-grid"><div><span>Customer</span><strong>{{ $delivery['customer'] }}</strong></div><div><span>Seller</span><strong>{{ $delivery['seller'] }}</strong></div><div><span>Order value</span><strong>{{ $delivery['amount'] }}</strong></div><div><span>Payment</span><strong>{{ $delivery['payment'] }}</strong></div></div>
        <div class="section-subtitle">Receiver confirmation</div>
        <label class="form-field"><span>Receiver name *</span><input type="text" placeholder="Full name of receiver" data-receiver-name></label>
        <label class="form-field"><span>6-digit delivery OTP *</span><div class="otp-row">@for($i=0;$i<6;$i++)<input type="text" inputmode="numeric" maxlength="1" data-otp aria-label="OTP digit {{ $i + 1 }}">@endfor</div></label>
        <label class="form-field"><span>Delivery note</span><textarea placeholder="Optional note about the handoff...">Parcel handed directly to customer at lobby.</textarea></label>
    </article>

    <aside class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Proof of delivery</span><h2>Upload placeholder</h2></div></div>
        <label class="proof-upload"><input type="file" accept="image/*"><i data-lucide="camera"></i><strong>Add proof of delivery</strong><small>Photo upload preview • no file is stored</small></label>
        <div class="section-subtitle">Completion checks</div>
        <div class="delivery-checks">
            <div class="delivery-check"><i data-lucide="circle-check"></i><span><strong>Customer location reached</strong><small>{{ $delivery['address'] }}</small></span></div>
            <div class="delivery-check"><i data-lucide="shield-check"></i><span><strong>Payment status verified</strong><small>{{ $delivery['payment'] }}</small></span></div>
            <div class="delivery-check"><i data-lucide="wallet-cards"></i><span><strong>Courier payout</strong><small>{{ $delivery['payout'] }} estimated earnings</small></span></div>
        </div>
        <button class="button button-primary full-button" style="margin-top:14px" type="button" data-complete-delivery><i data-lucide="badge-check"></i> Confirm delivery in system</button>
    </aside>
</section>
@endsection
