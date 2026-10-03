@extends('logistics.layouts.app')

@section('title', 'Pickup Requests')
@section('page-title', 'Pickup Requests')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Seller coordination</p>
        <h2>Parcel pickup requests</h2>
        <p>Verify seller requests, confirm parcel counts, and schedule approved pickup windows.</p>
    </div>
    <div class="page-actions">
        <button class="button" type="button" disabled title="Pickup requests are submitted by Sellers.">
            <i data-lucide="calendar-plus"></i>Create pickup
        </button>
    </div>
</div>

<section class="metric-strip">
    <article class="metric-item"><span class="metric-icon"><i data-lucide="clock-3"></i></span><span class="metric-copy"><small>Awaiting review</small><strong>{{ $pickupMetrics['awaiting_review'] }}</strong><span>Requires verification</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="calendar-check"></i></span><span class="metric-copy"><small>Scheduled today</small><strong>{{ $pickupMetrics['scheduled_today'] }}</strong><span>{{ $pickupMetrics['scheduled_parcels'] }} total parcels</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="truck"></i></span><span class="metric-copy"><small>In pickup</small><strong>{{ $pickupMetrics['in_pickup'] }}</strong><span>Riders en route</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="package-check"></i></span><span class="metric-copy"><small>Collected today</small><strong>{{ $pickupMetrics['collected_today'] }}</strong><span>Across {{ $pickupMetrics['completed_sellers'] }} {{ $pickupMetrics['completed_sellers'] === 1 ? 'seller' : 'sellers' }}</span></span></article>
</section>

<section class="panel" data-table-scope>
    <div class="table-toolbar">
        <div class="search-field"><i data-lucide="search"></i><input type="search" data-table-search placeholder="Search request, seller, or location"></div>
        <select class="filter-select" data-filter-status><option value="">All statuses</option><option>Pending</option><option>Verified</option><option>Scheduled</option><option>Collected</option><option>Rejected</option></select>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th data-sort>Request</th><th data-sort>Seller</th><th>Pickup location</th><th>Parcels</th><th>Requested window</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                @foreach($pickups as $pickup)
                    <tr data-row data-record-row data-record-type="pickups" data-record-id="{{ $pickup['id'] }}" data-status="{{ $pickup['status'] }}">
                        <td><strong>{{ $pickup['id'] }}</strong></td>
                        <td><span class="cell-title"><strong>{{ $pickup['seller'] }}</strong><small>Verified marketplace seller</small></span></td>
                        <td>{{ $pickup['location'] }}</td>
                        <td>{{ $pickup['parcels'] }}</td>
                        <td>{{ $pickup['window'] }}</td>
                        <td><span class="status-badge" data-status-badge data-status="{{ $pickup['status'] }}">{{ $pickup['status'] }}</span></td>
                        <td>
                            <div class="row-actions">
                                <button class="button button-small" type="button" data-modal-open="pickup-{{ $pickup['id'] }}">Details</button>
                                @if($pickup['status_key'] === 'requested')
                                    <form method="POST" action="{{ route('logistics.pickups.verify', $pickup['database_id']) }}">
                                        @csrf
                                        <button class="button button-small button-primary" type="submit">Verify</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                <tr><td class="empty-row" colspan="7" data-empty-row>No pickup requests match these filters.</td></tr>
            </tbody>
        </table>
    </div>
</section>

@foreach($pickups as $pickup)
    <section class="modal" data-modal="pickup-{{ $pickup['id'] }}" hidden>
        <div class="modal-header">
            <div><h3>{{ $pickup['id'] }} · {{ $pickup['seller'] }}</h3><p>Seller pickup request details.</p></div>
            <button class="icon-button" type="button" data-modal-close><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-list">
                <div class="detail-item"><small>Pickup address</small><strong>{{ $pickup['location'] }}</strong></div>
                <div class="detail-item"><small>Parcel quantity</small><strong>{{ $pickup['parcels'] }} parcels</strong></div>
                <div class="detail-item"><small>Requested window</small><strong>{{ $pickup['window'] }}</strong></div>
                <div class="detail-item"><small>Seller contact</small><strong>{{ $pickup['contact'] }}</strong></div>
                <div class="detail-item"><small>Assigned Rider</small><strong>{{ $pickup['rider'] }}</strong></div>
                <div class="detail-item"><small>Seller instructions</small><strong>{{ $pickup['instructions'] }}</strong></div>
            </div>

            @if(count($pickup['parcel_details']) > 0)
                <div class="attention-list" style="margin-top:15px">
                    @foreach($pickup['parcel_details'] as $parcel)
                        <div class="attention-item">
                            <i data-lucide="package"></i>
                            <span><strong>{{ $parcel['parcel_no'] }}</strong><small>{{ $parcel['waybill_no'] }} · {{ $parcel['shipment_no'] }} · {{ $parcel['seller_order_no'] }}</small></span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if(in_array($pickup['status_key'], ['requested', 'verified'], true))
                <div class="field" style="margin-top:15px">
                    <label for="pickup-cancellation-{{ $pickup['database_id'] }}">Rejection reason</label>
                    <textarea id="pickup-cancellation-{{ $pickup['database_id'] }}" name="cancellation_reason" form="cancel-pickup-{{ $pickup['database_id'] }}" required maxlength="1000" placeholder="Explain why this request cannot be accepted"></textarea>
                </div>
            @endif

            @if($pickup['status_key'] === 'verified')
                <div class="field-grid" style="margin-top:15px">
                    <div class="field">
                        <label for="pickup-rider-{{ $pickup['database_id'] }}">Pickup Rider</label>
                        <select id="pickup-rider-{{ $pickup['database_id'] }}" name="rider_profile_id" form="assign-pickup-{{ $pickup['database_id'] }}" required>
                            <option value="">Select available Rider</option>
                            @foreach($availableRiders as $rider)
                                <option value="{{ $rider['id'] }}">{{ $rider['name'] }} · {{ $rider['vehicle'] }}@if($rider['plate']) · {{ $rider['plate'] }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="pickup-note-{{ $pickup['database_id'] }}">Assignment note</label>
                        <textarea id="pickup-note-{{ $pickup['database_id'] }}" name="notes" form="assign-pickup-{{ $pickup['database_id'] }}" maxlength="1000" placeholder="Add handling or access instructions"></textarea>
                    </div>
                </div>
            @endif
        </div>
        <div class="modal-footer">
            @if(in_array($pickup['status_key'], ['requested', 'verified'], true))
                <form id="cancel-pickup-{{ $pickup['database_id'] }}" method="POST" action="{{ route('logistics.pickups.cancel', $pickup['database_id']) }}">
                    @csrf
                    @method('PATCH')
                    <button class="button button-danger" type="submit">Reject</button>
                </form>
            @endif
            @if($pickup['status_key'] === 'verified')
                <form id="assign-pickup-{{ $pickup['database_id'] }}" method="POST" action="{{ route('logistics.pickups.assign', $pickup['database_id']) }}">
                    @csrf
                    <button class="button button-primary" type="submit" @disabled(count($availableRiders) === 0) @if(count($availableRiders) === 0) title="No available approved Rider can be assigned." @endif>Approve &amp; schedule</button>
                </form>
            @endif
            @if(! in_array($pickup['status_key'], ['requested', 'verified'], true))
                <button class="button" type="button" data-modal-close>Close</button>
            @endif
        </div>
    </section>
@endforeach
@endsection
