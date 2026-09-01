@extends('layouts.courier')

@section('title', 'Pick Up Order')
@section('page-title', 'Pick Up Order')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 04</span><h1>Pick up active order</h1><p>Proceed to the seller's location, verify order information, and confirm item pickup before starting delivery.</p></div>
    <div class="hero-summary-card"><span class="metric-icon"><i data-lucide="package-check"></i></span><div><strong>{{ $task['order'] }}</strong><small>{{ $task['pickup_window'] }}</small></div></div>
</section>

<section class="task-layout">
    <article class="panel">
        <div class="task-summary"><div class="task-id"><span class="eyebrow">Active pickup</span><strong>{{ $task['order'] }}</strong><small>{{ $task['package'] }} • Payout {{ $task['payout'] }}</small></div><span class="status-badge badge-warning">Awaiting pickup</span></div>

        <div class="section-subtitle">Seller location</div>
        <div class="contact-card"><span class="avatar avatar-soft">NW</span><div><strong>{{ $task['seller'] }}</strong><small>{{ $task['address'] }}</small></div><button class="icon-button" type="button" data-mock-action="Seller contact action opened."><i data-lucide="phone"></i></button></div>
        <div class="detail-grid" style="margin-top:10px"><div><span>Pickup window</span><strong>{{ $task['pickup_window'] }}</strong></div><div><span>Seller contact</span><strong>{{ $task['seller_contact'] }}</strong></div><div><span>Customer</span><strong>{{ $task['buyer'] }}</strong></div><div><span>Package</span><strong>{{ $task['package'] }}</strong></div></div>

        <div class="section-subtitle">Order item verification</div>
        <div class="verify-list">
            @foreach($task['items'] as $index => $item)
                <label class="verify-item"><input type="checkbox" data-pickup-check><span><strong>{{ $item['name'] }}</strong><small>{{ $item['variant'] }}</small></span><span class="status-badge badge-neutral">Qty {{ $item['qty'] }}</span></label>
            @endforeach
            <label class="verify-item"><input type="checkbox" data-pickup-check><span><strong>Package sealed and labeled</strong><small>Confirm parcel condition and order label before leaving.</small></span><i data-lucide="package"></i></label>
            <label class="verify-item"><input type="checkbox" data-pickup-check><span><strong>Order number matches</strong><small>Seller parcel matches {{ $task['order'] }}.</small></span><i data-lucide="scan-line"></i></label>
        </div>
    </article>

    <aside class="panel task-side-card">
        <div class="panel-heading"><div><span class="eyebrow">Pickup checklist</span><h2>Ready to leave?</h2><p>Complete every verification item before confirming pickup.</p></div></div>
        <div class="notice-card"><span class="notice-marker"><i data-lucide="map-pin"></i></span><div><strong>Seller destination</strong><p>{{ $task['address'] }}</p><button class="text-button" type="button" data-mock-action="Navigation to seller opened.">Open navigation</button></div></div>
        <div class="policy-note" style="margin:12px 0"><i data-lucide="shield-check"></i><span>Do not confirm pickup until the physical parcel and order details have been verified.</span></div>
        <button class="button button-primary" type="button" data-confirm-pickup><i data-lucide="package-check"></i> Confirm item pickup</button>
        <a class="button button-secondary" style="margin-top:8px;width:100%;justify-content:center" href="{{ route('courier.transit') }}"><i data-lucide="navigation"></i> Preview delivery stage</a>
    </aside>
</section>
@endsection
