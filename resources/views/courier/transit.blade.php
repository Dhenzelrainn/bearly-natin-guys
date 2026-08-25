@extends('layouts.courier')

@section('title', 'Deliver Order')
@section('page-title', 'Deliver Order')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 05</span><h1>Active delivery in transit</h1><p>Navigate to the customer, review special handling instructions, and update the delivery progress.</p></div>
    <div class="hero-summary-card"><span class="metric-icon"><i data-lucide="navigation"></i></span><div><strong>{{ $delivery['eta'] }}</strong><small>{{ $delivery['distance_remaining'] }} remaining</small></div></div>
</section>

<section class="task-layout">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Simulated navigation</span><h2>{{ $delivery['order'] }} route</h2><p>{{ $delivery['seller'] }} → {{ $delivery['customer'] }}</p></div><span class="status-badge badge-info">In Transit</span></div>
        <div class="delivery-map" aria-label="Static delivery map placeholder">
            <div class="map-route-line"></div><span class="map-pin map-pin-start"><i data-lucide="store"></i></span><span class="map-pin map-pin-end"><i data-lucide="house"></i></span><span class="map-rider"><i data-lucide="bike"></i></span><span class="map-label map-label-start">Seller</span><span class="map-label map-label-end">Customer</span>
        </div>
        <div class="transit-status-grid">
            <div class="transit-step is-done" data-transit-step data-order="1">Accepted</div>
            <div class="transit-step is-done" data-transit-step data-order="2">Picked Up</div>
            <div class="transit-step is-current" data-transit-step data-order="3">In Transit</div>
            <div class="transit-step" data-transit-step data-order="4">Arrived</div>
        </div>
    </article>

    <aside class="panel task-side-card">
        <div class="panel-heading"><div><span class="eyebrow">Customer details</span><h2>{{ $delivery['customer'] }}</h2></div><button class="icon-button" type="button" data-mock-action="Customer call action opened."><i data-lucide="phone"></i></button></div>
        <div class="detail-grid"><div><span>Contact</span><strong>{{ $delivery['phone'] }}</strong></div><div><span>ETA</span><strong>{{ $delivery['eta'] }}</strong></div></div>
        <div class="section-subtitle">Drop-off address</div>
        <div class="contact-card"><span class="avatar avatar-soft">MR</span><div><strong>{{ $delivery['address'] }}</strong><small>{{ $delivery['distance_remaining'] }} remaining</small></div><button class="icon-button" type="button" data-mock-action="Map navigation opened."><i data-lucide="map"></i></button></div>
        <div class="section-subtitle">Special instructions</div>
        <div class="instruction-card"><i data-lucide="message-square-text"></i><div><strong>Customer note</strong><p>{{ $delivery['instructions'] }}</p></div></div>
        <div class="panel-footer-actions" style="flex-direction:column;align-items:stretch"><button class="button button-secondary" type="button" data-transit-action="transit"><i data-lucide="truck"></i> Mark In Transit</button><button class="button button-primary" type="button" data-transit-action="arrived"><i data-lucide="map-pin-check"></i> Arrived at location</button><a class="button button-ghost" style="justify-content:center" href="{{ route('courier.complete') }}">Proceed to completion</a></div>
    </aside>
</section>
@endsection
