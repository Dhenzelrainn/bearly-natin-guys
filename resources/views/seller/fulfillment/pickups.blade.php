@extends('layouts.seller')
@section('title', 'Pickup Requests')
@section('page-title', 'Pickup Requests')
@section('content')
@php
    $pickupRequests = collect($requests);
    $readyCount = count($eligibleOrders);
    $pickupTabs = ['all' => 'All Requests', 'pending' => 'Pending Logistics', 'assigned' => 'Rider Assigned', 'picked-up' => 'Collected / Received', 'cancelled' => 'Cancelled'];
    $providers = collect($providers);
    $providerNames = $pickupRequests->pluck('provider')->filter()->unique()->implode(', ');
    $hasPickupAddress = $pickupAddress !== 'Pickup address not set';
@endphp

<div class="pickups-page">
    <div class="page-heading fulfillment-heading">
        <div>
            <h2>Pickup Requests</h2>
            <p>Send ready Seller Orders to Logistics for review and Rider assignment.</p>
        </div>
        <button class="seller-primary-button" type="button" data-modal-open="pickup-create" @disabled($readyCount === 0 || $providers->isEmpty() || ! $hasPickupAddress)>
            <i data-lucide="plus" aria-hidden="true"></i>Create Pickup Request
        </button>
    </div>

    @if (session('success'))
        <p class="orders-success-note" role="status">{{ session('success') }}</p>
    @endif

    @if ($errors->any())
        <div class="pickup-validation-note" role="alert">
            <strong>Pickup request was not submitted.</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="pickup-ready-banner" aria-label="Pickup readiness">
        <span><i data-lucide="package-check" aria-hidden="true"></i></span>
        <div>
            <strong>{{ $readyCount }} {{ $readyCount === 1 ? 'order is' : 'orders are' }} ready for a pickup request</strong>
            <p>
                @if (! $hasPickupAddress)
                    Add a valid pickup address to your Seller profile before scheduling collection.
                @elseif ($providers->isEmpty())
                    No active Logistics provider is available. Contact an administrator before scheduling collection.
                @elseif ($readyCount)
                    Select orders and a pickup window. Bearly will create the shipment label and send the request to your selected Logistics provider.
                @else
                    Only ready orders without an existing shipment can be submitted for pickup.
                @endif
            </p>
        </div>
    </section>

    <section class="fulfillment-workspace" data-pickup-workspace aria-label="Pickup requests">
        <div class="fulfillment-tabs" aria-label="Filter by pickup status">
            @foreach ($pickupTabs as $key => $label)
                <button class="{{ $key === 'all' ? 'is-active' : '' }}" type="button" data-pickup-tab="{{ $key }}" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}">
                    {{ $label }} <span>{{ $key === 'all' ? $pickupRequests->count() : $pickupRequests->where('status_key', $key)->count() }}</span>
                </button>
            @endforeach
        </div>

        <div class="fulfillment-toolbar">
            <label><i data-lucide="search" aria-hidden="true"></i><input type="search" aria-label="Search pickup requests" placeholder="Search request, order, rider or address" data-pickup-search></label>
            <label class="pickup-date-filter"><i data-lucide="calendar-days" aria-hidden="true"></i><select aria-label="Filter pickup date" data-pickup-date><option value="">All dates</option><option value="today">Today</option><option value="tomorrow">Tomorrow</option></select></label>
        </div>

        <div class="fulfillment-table-wrap" tabindex="0" role="region" aria-label="Pickup requests table; scroll horizontally on smaller screens">
            <table class="fulfillment-table">
                <thead><tr><th scope="col">Request</th><th scope="col">Orders / Parcels</th><th scope="col">Pickup schedule</th><th scope="col">Pickup address</th><th scope="col">Rider</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
                <tbody>
                @foreach ($requests as $item)
                    @php
                        $schedule = explode(' · ', $item['schedule'], 2);
                        $address = explode(',', $item['address'], 2);
                    @endphp
                    <tr data-pickup-row data-status="{{ $item['status_key'] }}" data-date="{{ $item['date_key'] }}" data-search="{{ strtolower(implode(' ', $item)) }}">
                        <td><strong>{{ $item['id'] }}</strong></td>
                        <td><strong>{{ $item['orders'] }} · {{ $item['packages'] }} {{ $item['packages'] === 1 ? 'parcel' : 'parcels' }}</strong><small>{{ $item['order_numbers'] }}</small></td>
                        <td><span>{{ $schedule[0] }}</span>@if (isset($schedule[1]))<small>{{ $schedule[1] }}</small>@endif</td>
                        <td><span>{{ $address[0] }}</span>@if (isset($address[1]))<small>{{ trim($address[1]) }}</small>@endif</td>
                        <td><span>{{ $item['rider'] }}</span></td>
                        <td><span class="fulfillment-status is-{{ $item['status_key'] }}">{{ $item['status'] }}</span></td>
                        <td><button class="fulfillment-row-action" type="button" data-pickup-view data-pickup='@json($item)' aria-label="View {{ $item['id'] }} details">View Details</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="fulfillment-empty" data-pickup-empty @if ($pickupRequests->isNotEmpty()) hidden @endif>
                <i data-lucide="package-search" aria-hidden="true"></i>
                <strong>No pickup requests yet</strong>
                <span>Mark an order ready, then submit it here so Logistics can review and assign a Rider.</span>
            </div>
        </div>
        <footer class="fulfillment-footer">
            <span role="status" aria-live="polite">Showing <strong data-pickup-count>{{ $pickupRequests->count() }}</strong> requests</span>
            <span>@if ($providerNames)Pickup providers: {{ $providerNames }}<span class="pickup-footer-divider" aria-hidden="true"> · </span>@endif Logistics verifies requests and assigns Riders. Sorting Center scans record parcel receipt.</span>
        </footer>
    </section>

    <div class="seller-modal fulfillment-modal" data-modal="pickup-create" @if (! $errors->any()) hidden @else data-pickup-reopen @endif>
        <button class="modal-backdrop" type="button" data-modal-close aria-label="Close pickup request dialog"></button>
        <section class="modal-card pickup-create-card" role="dialog" aria-modal="true" aria-label="Create pickup request">
            <div class="modal-heading">
                <div><span class="section-kicker">Arrange shipment</span><h3>Create Pickup Request</h3></div>
                <button type="button" data-modal-close aria-label="Close dialog"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>
            <form class="pickup-form" data-pickup-form method="POST" action="{{ route('seller.fulfillment.pickups.store') }}">
                @csrf
                <fieldset>
                    <legend>Select ready orders</legend>
                    @forelse ($eligibleOrders as $order)
                        <label class="pickup-order-option">
                            <input type="checkbox" name="order_ids[]" value="{{ $order['id'] }}" data-pickup-order @checked(in_array($order['id'], (array) old('order_ids', [])))>
                            <span><strong>{{ $order['order'] }}</strong><small>1 parcel · {{ $order['weight'] }} · {{ $order['destination'] }} · waybill generated on submission</small></span>
                        </label>
                    @empty
                        <p>No orders are currently eligible for a new pickup request.</p>
                    @endforelse
                </fieldset>

                <div class="pickup-form-grid">
                    <label>
                        <span>Logistics provider</span>
                        <select name="logistics_profile_id" required>
                            <option value="">Choose a provider</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}" @selected((string) old('logistics_profile_id') === (string) $provider->id)>{{ $provider->display_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label><span>Pickup date</span><input type="date" name="requested_date" min="{{ now()->toDateString() }}" value="{{ old('requested_date') }}" required></label>
                    <label>
                        <span>Preferred time</span>
                        <select name="pickup_window" required>
                            <option value="">Choose time window</option>
                            @foreach (\App\Services\SellerPickupRequestService::PICKUP_WINDOWS as $key => $window)
                                <option value="{{ $key }}" @selected(old('pickup_window') === $key)>{{ $window['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="is-wide">
                        <span>Pickup address</span>
                        <input type="text" value="{{ $pickupAddress }}" readonly aria-describedby="pickup-address-note">
                        <small id="pickup-address-note">Contact: {{ $pickupPhone ?: 'No phone number saved' }} · From your Seller profile</small>
                    </label>
                    <label class="is-wide"><span>Pickup instructions <small>(optional)</small></span><textarea name="instructions" rows="2" maxlength="1000" placeholder="Gate, landmark, or handling notes for the Rider">{{ old('instructions') }}</textarea></label>
                </div>

                <div class="modal-actions">
                    <button class="draft-button" type="button" data-modal-close>Cancel</button>
                    <button class="seller-primary-button" type="submit" @disabled($readyCount === 0 || $providers->isEmpty() || ! $hasPickupAddress)>Submit to Logistics</button>
                </div>
            </form>
        </section>
    </div>

    <div class="seller-modal fulfillment-modal" data-modal="pickup-details" hidden>
        <button class="modal-backdrop" type="button" data-modal-close aria-label="Close pickup details"></button>
        <section class="modal-card" role="dialog" aria-modal="true" aria-label="Pickup request details">
            <div class="modal-heading"><div><span class="section-kicker">Pickup request</span><h3 data-pickup-id>Request details</h3></div><button type="button" data-modal-close aria-label="Close dialog"><i data-lucide="x" aria-hidden="true"></i></button></div>
            <dl class="pickup-detail-list">
                <div><dt>Status</dt><dd data-pickup-status>—</dd></div>
                <div><dt>Orders and parcels</dt><dd data-pickup-orders>—</dd></div>
                <div><dt>Logistics provider</dt><dd data-pickup-provider>—</dd></div>
                <div><dt>Schedule</dt><dd data-pickup-schedule>—</dd></div>
                <div><dt>Rider</dt><dd data-pickup-rider>—</dd></div>
                <div><dt>Pickup address</dt><dd data-pickup-address>—</dd></div>
                <div><dt>Instructions</dt><dd data-pickup-instructions>—</dd></div>
            </dl>
            <section class="handover-check"><i data-lucide="scan-line" aria-hidden="true"></i><div><strong>Next steps</strong><p>Logistics verifies the request and assigns a Rider. A Sorting Center scan records the parcel as received.</p></div></section>
            <div class="modal-actions"><button class="draft-button" type="button" data-modal-close>Close</button></div>
        </section>
    </div>
</div>
@endsection
