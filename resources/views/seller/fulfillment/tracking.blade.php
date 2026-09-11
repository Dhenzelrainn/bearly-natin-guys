@extends('layouts.seller')
@section('title', 'Shipment Tracking')
@section('page-title', 'Shipment Tracking')
@section('content')
<div class="page-heading fulfillment-heading">
    <div><span class="section-kicker">Delivery monitoring</span><h2>Shipment Tracking</h2><p>Monitor every parcel after seller handover. Shipment events are controlled by logistics and courier scans.</p></div>
    <button class="seller-secondary-button" type="button" data-tracking-export><i data-lucide="download"></i>Export Shipments</button>
</div>

<section class="delivery-completion-notice">
    <i data-lucide="badge-check"></i>
    <div><strong>Delivered is not the same as Completed.</strong><p><strong>DELIVERED</strong> means the courier delivered the parcel. Seller earnings remain pending until the buyer confirms receipt and the order becomes <strong>COMPLETED</strong>.</p></div>
    <a href="{{ route('seller.finance.earnings') }}">View earnings logic <i data-lucide="arrow-right"></i></a>
</section>

@include('seller.fulfillment.partials.summary', ['items' => $summary])

<section class="fulfillment-workspace" data-tracking-workspace>
    <div class="fulfillment-tabs">
        <button class="is-active" type="button" data-tracking-tab="all">All Shipments <span>{{ count($shipments) }}</span></button>
        <button type="button" data-tracking-tab="sorting">Sorting Center</button>
        <button type="button" data-tracking-tab="transit">Sorted / In Transit</button>
        <button type="button" data-tracking-tab="out-delivery">Out for Delivery</button>
        <button type="button" data-tracking-tab="delivered">Delivered</button>
        <button type="button" data-tracking-tab="failed">Needs Attention <span>1</span></button>
    </div>
    <div class="fulfillment-toolbar tracking-toolbar">
        <label><i data-lucide="search"></i><input type="search" placeholder="Search tracking, order, customer, or destination" data-tracking-search></label>
        <select data-tracking-status><option value="">All shipment statuses</option><option value="sorting">At Sorting Center</option><option value="transit">Sorted / In Transit</option><option value="out-delivery">Out for Delivery</option><option value="delivered">Delivered</option><option value="failed">Delivery Failed</option></select>
        <button type="button" data-tracking-reset><i data-lucide="rotate-ccw"></i>Reset</button>
    </div>
    <div class="fulfillment-table-wrap">
        <table class="fulfillment-table tracking-table">
            <thead><tr><th>Tracking / Order</th><th>Customer</th><th>Destination</th><th>Assigned Rider</th><th>Latest Update</th><th>ETA</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @foreach($shipments as $item)
                <tr data-tracking-row data-status="{{ $item['status_key'] }}" data-search="{{ strtolower(implode(' ', $item)) }}">
                    <td><strong>{{ $item['tracking'] }}</strong><small>{{ $item['order'] }}</small></td>
                    <td>{{ $item['customer'] }}</td><td>{{ $item['destination'] }}</td><td>{{ $item['rider'] }}</td>
                    <td><strong>{{ $item['latest'] }}</strong><small>{{ $item['updated'] }}</small></td><td>{{ $item['eta'] }}</td>
                    <td><span class="fulfillment-status is-{{ $item['status_key'] }}">{{ $item['status'] }}</span><small class="canonical-status">{{ $item['canonical_status'] }}</small></td>
                    <td><button class="fulfillment-row-action" type="button" data-tracking-view data-shipment='@json($item)'>Track</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="fulfillment-empty" data-tracking-empty hidden><i data-lucide="search-x"></i><strong>No matching shipments</strong><span>Try changing your filters.</span></div>
    </div>
    <footer class="fulfillment-footer"><span>Showing <strong data-tracking-count>{{ count($shipments) }}</strong> shipments</span><span>Seller monitors these states; logistics/courier owns the shipment updates.</span></footer>
</section>

<div class="seller-modal fulfillment-modal tracking-modal" data-modal="tracking-details" hidden>
    <button class="modal-backdrop" type="button" data-modal-close></button>
    <section class="modal-card">
        <div class="modal-heading"><div><span class="section-kicker">Shipment activity</span><h3 data-tracking-number>Tracking details</h3></div><button type="button" data-modal-close><i data-lucide="x"></i></button></div>
        <div class="tracking-overview"><div><span>Order</span><strong data-tracking-order>—</strong></div><div><span>Customer</span><strong data-tracking-customer>—</strong></div><div><span>Destination</span><strong data-tracking-destination>—</strong></div><div><span>Estimated delivery</span><strong data-tracking-eta>—</strong></div></div>
        <ol class="shipment-timeline">
            <li class="is-done"><span><i data-lucide="check"></i></span><div><strong>Parcel picked up</strong><small>Rider confirmed seller handover</small></div></li>
            <li class="is-done"><span><i data-lucide="check"></i></span><div><strong>Sorting center processing</strong><small>Parcel scanned, sorted, and routed by destination</small></div></li>
            <li class="is-current"><span><i data-lucide="truck"></i></span><div><strong data-tracking-latest>Current shipment update</strong><small data-tracking-updated>Latest scan time</small></div></li>
            <li><span><i data-lucide="package-check"></i></span><div><strong>Delivered</strong><small>Courier records successful delivery</small></div></li>
            <li><span><i data-lucide="circle-check-big"></i></span><div><strong>Completed</strong><small>Buyer confirms receipt; financial settlement can finalize</small></div></li>
        </ol>
        <section class="delivery-proof" data-delivery-proof hidden><i data-lucide="badge-check"></i><div><strong>Proof of delivery recorded</strong><p>Delivered to the customer. Buyer confirmation is still required before COMPLETED.</p></div><button type="button" data-fulfillment-demo="Delivery proof opened.">View Proof</button></section>
        <div class="modal-actions"><button class="draft-button" type="button" data-modal-close>Close</button><button class="seller-primary-button" type="button" data-report-shipment><i data-lucide="triangle-alert"></i>Report Issue</button></div>
    </section>
</div>

<div class="seller-modal fulfillment-modal" data-modal="tracking-report" hidden>
    <button class="modal-backdrop" type="button" data-modal-close></button>
    <section class="modal-card">
        <div class="modal-heading"><div><span class="section-kicker">Shipment exception</span><h3>Report Shipment Issue</h3></div><button type="button" data-modal-close><i data-lucide="x"></i></button></div>
        <form class="shipment-report-form" data-shipment-report-form>
            <label><span>Issue type</span><select required><option value="">Choose issue</option><option>No tracking update</option><option>Pickup or delivery delay</option><option>Parcel damaged in transit</option><option>Incorrect delivery status</option></select></label>
            <label><span>Description</span><textarea rows="4" required placeholder="Describe what happened and the help you need."></textarea></label>
            <label><span>Supporting evidence</span><input type="file" accept="image/*,.pdf"></label>
            <div class="modal-actions"><button class="draft-button" type="button" data-modal-close>Cancel</button><button class="seller-primary-button" type="submit">Submit Report</button></div>
        </form>
    </section>
</div>
@endsection
