@extends('layouts.courier')

@section('title', 'Delivery Requests')
@section('page-title', 'Accept Delivery Requests')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 03</span><h1>Available delivery requests</h1><p>Review pickup and delivery details, then simulate claiming a request under a first-come, first-served workflow.</p></div>
    <div class="hero-summary-card"><span class="metric-icon"><i data-lucide="radio-tower"></i></span><div><strong>{{ count($jobs) }}</strong><small>Open requests nearby</small></div></div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Live request feed</span><h2>Pickup opportunities</h2></div>
        <div class="table-toolbar"><label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Search route or seller..." data-request-search></label><select class="select-field" data-request-filter><option value="">All parcel sizes</option><option>Small</option><option>Medium</option><option>Large</option></select></div>
    </div>
    <div class="request-grid">
        @foreach($jobs as $job)
            <article class="request-card" data-request-card data-size="{{ $job['size'] }}" data-search="{{ strtolower($job['seller'].' '.$job['pickup'].' '.$job['dropoff'].' '.$job['id'].' '.$job['order']) }}">
                <div class="request-card-head"><div><span class="eyebrow">{{ $job['id'] }}</span><h3>{{ $job['order'] }} • {{ $job['seller'] }}</h3></div><span class="status-badge {{ $job['priority'] === 'Priority' ? 'badge-warning' : 'badge-neutral' }}">{{ $job['priority'] }}</span></div>
                <div class="route-points"><div class="route-point"><span>Pick up</span><strong>{{ $job['pickup'] }}</strong></div><div class="route-point"><span>Drop off</span><strong>{{ $job['dropoff'] }}</strong></div></div>
                <div class="job-meta-grid"><div class="job-meta"><span>Distance</span><strong>{{ $job['distance'] }}</strong></div><div class="job-meta"><span>Est. time</span><strong>{{ $job['eta'] }}</strong></div><div class="job-meta"><span>Parcel</span><strong>{{ $job['size'] }}</strong></div><div class="job-meta"><span>Queue</span><strong>Open</strong></div></div>
                <div class="request-card-actions"><div class="payout">{{ $job['payout'] }}<small>estimated courier payout</small></div><button class="button button-primary button-small" type="button" data-open-modal="accept-job" data-job-id="{{ $job['id'] }}" data-seller="{{ $job['seller'] }}" data-pickup="{{ $job['pickup'] }}" data-dropoff="{{ $job['dropoff'] }}" data-payout="{{ $job['payout'] }}" data-distance="{{ $job['distance'] }}"><i data-lucide="hand"></i> Accept request</button></div>
            </article>
        @endforeach
    </div>
</section>

<div class="modal-shell" data-modal="accept-job" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-label="Accept delivery request">
        <div class="modal-heading"><div><span class="eyebrow" data-modal-job-id>REQ-0000</span><h2>Accept this delivery?</h2></div><button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <div class="policy-note"><i data-lucide="timer"></i><span>This preview simulates first-come, first-served assignment. The first courier to confirm would receive the delivery in the backend system.</span></div>
        <div class="detail-grid" style="margin-top:14px"><div><span>Seller</span><strong data-modal-seller>—</strong></div><div><span>Distance</span><strong data-modal-distance>—</strong></div><div><span>Pickup</span><strong data-modal-pickup>—</strong></div><div><span>Drop-off</span><strong data-modal-dropoff>—</strong></div><div><span>Estimated payout</span><strong data-modal-payout>—</strong></div><div><span>Assignment</span><strong>First accepted courier</strong></div></div>
        <div class="modal-footer"><button class="button button-secondary" type="button" data-close-modal>Cancel</button><button class="button button-primary" type="button" data-confirm-accept><i data-lucide="circle-check"></i> Confirm acceptance</button></div>
    </section>
</div>
@endsection
