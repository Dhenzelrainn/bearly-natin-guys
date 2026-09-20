@extends('layouts.seller')
@section('title', 'Pickup Requests')
@section('page-title', 'Pickup Requests')
@section('content')
@php
    $pickupRequests = collect($requests);
    $readyCount = count($eligibleOrders);
    $pickupTabs = ['all' => 'All Requests', 'pending' => 'Pending Approval', 'assigned' => 'Rider Assigned', 'picked-up' => 'Picked Up', 'cancelled' => 'Cancelled'];
    $providers = $pickupRequests->pluck('provider')->filter()->unique()->implode(', ');
@endphp
<div class="pickups-page">
    <div class="page-heading fulfillment-heading">
        <div><h2>Pickup Requests</h2><p>Schedule parcel collection and monitor pickup requests.</p></div>
        <button class="seller-primary-button" type="button" data-modal-open="pickup-create" @disabled($readyCount === 0)><i data-lucide="plus" aria-hidden="true"></i>Create Pickup Request</button>
    </div>
    <section class="pickup-ready-banner" aria-label="Pickup readiness">
        <span><i data-lucide="package-check" aria-hidden="true"></i></span>
        <div>
            <strong>{{ $readyCount }} {{ $readyCount === 1 ? 'order ready' : 'orders ready' }} for pickup request</strong>
            <p>{{ $readyCount ? 'Waybills printed and parcel details complete.' : 'Prepare and label parcels in Waybills before creating a request.' }}</p>
        </div>
    </section>
    <section class="fulfillment-workspace" data-pickup-workspace aria-label="Pickup requests">
        <div class="fulfillment-tabs" aria-label="Filter by pickup status">
            @foreach($pickupTabs as $key => $label)
                <button class="{{ $key === 'all' ? 'is-active' : '' }}" type="button" data-pickup-tab="{{ $key }}" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}">{{ $label }} <span>{{ $key === 'all' ? $pickupRequests->count() : $pickupRequests->where('status_key', $key)->count() }}</span></button>
            @endforeach
        </div>
        <div class="fulfillment-toolbar">
            <label><i data-lucide="search" aria-hidden="true"></i><input type="search" aria-label="Search pickup requests" placeholder="Search request, rider or address" data-pickup-search></label>
            <label class="pickup-date-filter"><i data-lucide="calendar-days" aria-hidden="true"></i><select aria-label="Filter pickup date" data-pickup-date><option value="">All dates</option><option value="today">Today</option><option value="tomorrow">Tomorrow</option></select></label>
        </div>
        <div class="fulfillment-table-wrap" tabindex="0" role="region" aria-label="Pickup requests table; scroll horizontally on smaller screens">
            <table class="fulfillment-table">
                <thead><tr><th scope="col">Request</th><th scope="col">Parcels</th><th scope="col">Pickup schedule</th><th scope="col">Pickup address</th><th scope="col">Rider</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                <tbody>
                @foreach($requests as $item)
                    @php
                        $schedule = explode(' · ', $item['schedule'], 2);
                        $address = explode(',', $item['address'], 2);
                        $rider = explode(' · ', $item['rider'], 2);
                    @endphp
                    <tr data-pickup-row data-status="{{ $item['status_key'] }}" data-date="{{ strtolower(trim($schedule[0])) }}" data-search="{{ strtolower(implode(' ', $item)) }}">
                        <td><strong>{{ $item['id'] }}</strong></td>
                        <td><strong>{{ $item['packages'] }} {{ (int) $item['packages'] === 1 ? 'parcel' : 'parcels' }}</strong><small>{{ $item['orders'] }}</small></td>
                        <td><span>{{ $schedule[0] }}</span>@if(isset($schedule[1]))<small>{{ $schedule[1] }}</small>@endif</td>
                        <td><span>{{ $address[0] }}</span>@if(isset($address[1]))<small>{{ trim($address[1]) }}</small>@endif</td>
                        <td><span>{{ $rider[0] }}</span>@if(isset($rider[1]))<small>{{ $rider[1] }}</small>@endif</td>
                        <td><span class="fulfillment-status is-{{ $item['status_key'] }}">{{ $item['status'] }}</span></td>
                        <td><button class="fulfillment-row-action" type="button" data-pickup-view data-pickup='@json($item)' aria-label="View {{ $item['id'] }} details">{{ in_array($item['status_key'], ['pending', 'assigned']) ? 'Manage' : 'View Details' }}</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="fulfillment-empty" data-pickup-empty hidden><i data-lucide="search-x" aria-hidden="true"></i><strong>No matching pickup requests</strong><span>Try another status, date or search term.</span></div>
        </div>
        <footer class="fulfillment-footer"><span role="status" aria-live="polite">Showing <strong data-pickup-count>{{ count($requests) }}</strong> requests</span><span>@if($providers)Pickup provider: {{ $providers }}<span class="pickup-footer-divider" aria-hidden="true"> | </span>@endif Approval and rider assignment are handled by logistics.</span></footer>
    </section>
<div class="seller-modal fulfillment-modal" data-modal="pickup-create" hidden><button class="modal-backdrop" type="button" data-modal-close></button><section class="modal-card pickup-create-card" role="dialog" aria-modal="true" aria-label="Create pickup request"><div class="modal-heading"><div><span class="section-kicker">Arrange shipment</span><h3>Create Pickup Request</h3></div><button type="button" data-modal-close aria-label="Close dialog"><i data-lucide="x"></i></button></div><form class="pickup-form" data-pickup-form><fieldset><legend>Select labeled parcels</legend>@foreach($eligibleOrders as $order)<label class="pickup-order-option"><input type="checkbox" name="orders[]" value="{{ $order['order'] }}" data-pickup-order><span><strong>{{ $order['order'] }}</strong><small>{{ $order['tracking'] }} · {{ $order['packages'] }} parcel · {{ $order['weight'] }}</small></span></label>@endforeach</fieldset><div class="pickup-form-grid"><label><span>Logistics provider</span><select required><option>Bearly Logistics</option></select></label><label><span>Pickup date</span><input type="date" min="{{ now()->toDateString() }}" required></label><label><span>Preferred time</span><select required><option value="">Choose time window</option><option>10:00 AM–12:00 PM</option><option>1:00–3:00 PM</option><option>3:00–5:00 PM</option></select></label><label><span>Contact person</span><input type="text" value="Bea Rivera" required></label><label class="is-wide"><span>Pickup address</span><textarea rows="2" required>Juan’s Clothing Shop, Santa Rosa City, Laguna</textarea></label><label class="is-wide"><span>Pickup instructions</span><textarea rows="2" placeholder="Optional instructions for the rider"></textarea></label></div><div class="modal-actions"><button class="draft-button" type="button" data-modal-close>Cancel</button><button class="seller-primary-button" type="submit">Submit Request</button></div></form></section></div>
<div class="seller-modal fulfillment-modal" data-modal="pickup-details" hidden><button class="modal-backdrop" type="button" data-modal-close></button><section class="modal-card" role="dialog" aria-modal="true" aria-label="Pickup request details"><div class="modal-heading"><div><span class="section-kicker">Pickup request</span><h3 data-pickup-id>Request details</h3></div><button type="button" data-modal-close aria-label="Close dialog"><i data-lucide="x"></i></button></div><dl class="pickup-detail-list"><div><dt>Status</dt><dd data-pickup-status>—</dd></div><div><dt>Orders and parcels</dt><dd data-pickup-orders>—</dd></div><div><dt>Schedule</dt><dd data-pickup-schedule>—</dd></div><div><dt>Rider</dt><dd data-pickup-rider>—</dd></div><div><dt>Pickup address</dt><dd data-pickup-address>—</dd></div></dl><section class="handover-check"><i data-lucide="scan-line"></i><div><strong>Parcel handover</strong><p>Confirm only after the rider scans and receives every parcel.</p></div></section><div class="modal-actions" data-pickup-actions><button class="draft-button" type="button" data-pickup-cancel>Cancel Request</button><button class="seller-primary-button" type="button" data-pickup-confirm>Confirm Handover</button></div></section></div>
</div>
@endsection
