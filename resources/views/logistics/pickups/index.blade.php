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
                        <td><span class="cell-title"><strong>{{ $pickup['seller'] }}</strong><small>{{ $pickup['pickup_type'] === 'return' ? 'Buyer return pickup' : 'Verified marketplace seller' }}</small></span></td>
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
    <section class="modal pickup-request-modal" data-modal="pickup-{{ $pickup['id'] }}" role="dialog" aria-modal="true" aria-labelledby="pickup-title-{{ $pickup['database_id'] }}" hidden>
        <div class="modal-header pickup-request-modal-header">
            <div>
                <div class="pickup-request-title-row">
                    <h3 id="pickup-title-{{ $pickup['database_id'] }}" tabindex="-1" data-modal-initial-focus>{{ $pickup['id'] }} <span aria-hidden="true">·</span> {{ $pickup['seller'] }}</h3>
                    <span class="status-badge" data-status-badge data-status="{{ $pickup['status_key'] }}">{{ $pickup['status'] }}</span>
                </div>
                <p class="pickup-request-meta">
                    <span>Submitted {{ $pickup['submitted_at'] }}</span>
                    <span><i data-lucide="calendar-clock" aria-hidden="true"></i>Pickup window: {{ $pickup['window'] }}</span>
                </p>
            </div>
            <button class="icon-button" type="button" data-modal-close aria-label="Close pickup request details"><i data-lucide="x" aria-hidden="true"></i></button>
        </div>

        <div class="modal-body pickup-request-modal-body">
            <section class="pickup-info-card pickup-location-card" aria-labelledby="pickup-location-{{ $pickup['database_id'] }}">
                <div class="pickup-card-heading">
                    <span class="pickup-card-icon"><i data-lucide="map-pin" aria-hidden="true"></i></span>
                    <h4 id="pickup-location-{{ $pickup['database_id'] }}">{{ $pickup['pickup_type'] === 'return' ? 'Buyer return pickup location' : 'Seller and pickup location' }}</h4>
                </div>
                <div class="pickup-location-columns">
                    <dl class="pickup-location-list pickup-location-primary">
                        <div class="pickup-location-row">
                            <dt><i data-lucide="user-round" aria-hidden="true"></i>Pickup contact</dt>
                            <dd>{{ $pickup['contact_name'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="mail" aria-hidden="true"></i>Store email</dt>
                            <dd>{{ $pickup['seller_email'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="map-pin" aria-hidden="true"></i>Pickup address</dt>
                            <dd>{{ $pickup['location'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="file-text" aria-hidden="true"></i>Seller instructions</dt>
                            <dd>{{ $pickup['instructions'] }}</dd>
                        </div>
                    </dl>
                    <dl class="pickup-location-list pickup-location-secondary">
                        <div class="pickup-location-row">
                            <dt><i data-lucide="phone" aria-hidden="true"></i>Mobile number</dt>
                            <dd>@if($pickup['contact_phone'] !== 'Not provided')<a href="tel:{{ $pickup['contact_phone'] }}">{{ $pickup['contact_phone'] }}</a>@else{{ $pickup['contact_phone'] }}@endif</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="clock-3" aria-hidden="true"></i>Seller operating hours</dt>
                            <dd>{{ $pickup['operating_hours'] }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section class="pickup-info-card pickup-manifest-card" aria-labelledby="pickup-manifest-{{ $pickup['database_id'] }}">
                <div class="pickup-section-heading">
                    <span class="pickup-card-icon"><i data-lucide="package" aria-hidden="true"></i></span>
                    <div>
                        <h4 id="pickup-manifest-{{ $pickup['database_id'] }}">Parcel manifest</h4>
                        <p>{{ $pickup['parcels'] }} {{ $pickup['parcels'] === 1 ? 'parcel' : 'parcels' }} · {{ $pickup['total_weight'] }} total · COD {{ $pickup['total_cod'] }}</p>
                    </div>
                </div>
                @if(count($pickup['fulfillment_groups']) > 0)
                    <div class="pickup-manifest-wrap" tabindex="0" role="region" aria-label="Parcel manifest; scroll horizontally on smaller screens">
                        <table class="pickup-manifest-table">
                            <thead><tr><th scope="col">Parcel and references</th><th scope="col">Weight</th><th scope="col">Dimensions</th><th scope="col">Status and COD</th></tr></thead>
                            @foreach($pickup['fulfillment_groups'] as $group)
                                <tbody>
                                    @foreach($group['parcels'] as $parcel)
                                        <tr>
                                            <td class="pickup-manifest-reference-cell">
                                                <strong>Seller Order {{ $group['seller_order_no'] }}</strong>
                                                <small>Shipment {{ $group['shipment_no'] }} · Waybill {{ $group['waybill_no'] }}</small>
                                                <small>Parcel {{ $parcel['parcel_no'] }} · Piece {{ $parcel['piece'] }}</small>
                                            </td>
                                            <td>{{ $parcel['weight'] }}</td>
                                            <td>{{ $parcel['dimensions'] }}</td>
                                            <td class="pickup-manifest-status-cell">
                                                <span class="pickup-parcel-status">{{ $parcel['status'] }}</span>
                                                @if($loop->first)<strong class="pickup-manifest-cod">COD {{ $group['cod_amount'] }}</strong>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            @endforeach
                        </table>
                    </div>
                @else
                    <p class="pickup-inline-empty">No provider-owned parcel records are linked to this request.</p>
                @endif
            </section>

            @if($pickup['cancellation_reason'])
                <section class="pickup-rejection-note" aria-label="Rejection reason">
                    <strong>Rejection reason</strong>
                    <p>{{ $pickup['cancellation_reason'] }}</p>
                </section>
            @endif

            <section class="pickup-info-card pickup-activity-card" aria-labelledby="pickup-activity-{{ $pickup['database_id'] }}">
                <div class="pickup-section-heading">
                    <span class="pickup-card-icon"><i data-lucide="clock-3" aria-hidden="true"></i></span>
                    <h4 id="pickup-activity-{{ $pickup['database_id'] }}">Request activity</h4>
                </div>
                @if(count($pickup['activity']) > 0)
                    <ol class="pickup-activity-list">
                        @foreach($pickup['activity'] as $event)
                            <li>
                                <span class="pickup-activity-marker" aria-hidden="true"></span>
                                <div class="pickup-activity-copy">
                                    <strong>{{ $event['title'] }}</strong>
                                    @if($event['detail'])<p>{{ $event['detail'] }}</p>@endif
                                </div>
                                <time>{{ $event['occurred_at'] }}</time>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="pickup-inline-empty">No activity has been recorded yet.</p>
                @endif
            </section>

            @if(in_array($pickup['status_key'], ['requested', 'verified'], true))
                <form id="cancel-pickup-{{ $pickup['database_id'] }}" class="pickup-rejection-form" data-pickup-rejection-form hidden method="POST" action="{{ route('logistics.pickups.cancel', $pickup['database_id']) }}">
                    @csrf
                    @method('PATCH')
                    <label for="pickup-cancellation-{{ $pickup['database_id'] }}">Why are you rejecting this request?</label>
                    <p>Give the Seller a clear reason so they know what needs to be corrected.</p>
                    <textarea id="pickup-cancellation-{{ $pickup['database_id'] }}" name="cancellation_reason" required maxlength="1000" placeholder="For example: pickup address is incomplete or the parcel count does not match."></textarea>
                </form>
            @endif
        </div>

        <div class="modal-footer pickup-request-modal-footer">
            <div class="pickup-modal-main-actions" data-pickup-main-actions>
                @if($pickup['status_key'] === 'requested')
                    <form method="POST" action="{{ route('logistics.pickups.verify', $pickup['database_id']) }}">
                        @csrf
                        <button class="button button-primary" type="submit">Verify request</button>
                    </form>
                @elseif($pickup['status_key'] === 'verified')
                    <form id="assign-pickup-{{ $pickup['database_id'] }}" method="POST" action="{{ route('logistics.pickups.assign', $pickup['database_id']) }}">
                        @csrf
                        <label class="pickup-rider-select" for="pickup-rider-{{ $pickup['database_id'] }}">Pickup Rider
                            <select id="pickup-rider-{{ $pickup['database_id'] }}" name="rider_profile_id" required>
                                <option value="">Select available Rider</option>
                                @foreach($availableRiders as $rider)
                                    <option value="{{ $rider['id'] }}">{{ $rider['name'] }} · {{ $rider['vehicle'] }}@if($rider['plate']) · {{ $rider['plate'] }}@endif</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="pickup-assignment-note" for="pickup-note-{{ $pickup['database_id'] }}">Assignment note <small>(optional)</small>
                            <textarea id="pickup-note-{{ $pickup['database_id'] }}" name="notes" maxlength="1000" placeholder="Access or collection instructions"></textarea>
                        </label>
                        <button class="button button-primary" type="submit" @disabled(count($availableRiders) === 0) @if(count($availableRiders) === 0) title="No available approved Rider can be assigned." @endif>Approve &amp; schedule</button>
                    </form>
                @endif
            </div>

            <div class="pickup-reject-actions">
                @if(in_array($pickup['status_key'], ['requested', 'verified'], true))
                    <button class="button button-danger" type="button" data-pickup-reject-toggle aria-expanded="false" aria-controls="cancel-pickup-{{ $pickup['database_id'] }}">Reject request</button>
                    <button class="button" type="button" data-pickup-reject-cancel hidden>Cancel rejection</button>
                    <button class="button button-danger" type="submit" form="cancel-pickup-{{ $pickup['database_id'] }}" data-pickup-reject-confirm hidden>Confirm rejection</button>
                @endif
                <button class="button" type="button" data-modal-close>Close</button>
            </div>
        </div>
    </section>
@endforeach
@endsection
