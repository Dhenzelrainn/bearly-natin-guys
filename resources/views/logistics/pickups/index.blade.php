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

<section class="metric-strip pickup-metric-strip">
    <article class="metric-item"><span class="metric-icon"><i data-lucide="clock-3"></i></span><span class="metric-copy"><small>Awaiting review</small><strong>{{ $pickupMetrics['awaiting_review'] }}</strong><span>Requires verification</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="user-plus"></i></span><span class="metric-copy"><small>Needs Rider assignment</small><strong>{{ $pickupMetrics['awaiting_assignment'] }}</strong><span>Verified requests to schedule</span></span></article>
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
            <thead><tr><th data-sort>Request</th><th data-sort>Seller</th><th>Pickup location</th><th>Products</th><th>Parcels</th><th>Requested window</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                @foreach($pickups as $pickup)
                    <tr data-row data-record-row data-record-type="pickups" data-record-id="{{ $pickup['id'] }}" data-status="{{ $pickup['status'] }}">
                        <td><strong>{{ $pickup['id'] }}</strong></td>
                        <td><span class="cell-title"><strong>{{ $pickup['seller'] }}</strong><small>{{ $pickup['pickup_type'] === 'return' ? 'Buyer return pickup' : 'Verified marketplace seller' }} · {{ $pickup['provider'] }}</small></span></td>
                        <td>{{ $pickup['location'] }}</td>
                        <td class="pickup-products-cell">@include('partials.order-item-preview-list', ['items' => $pickup['products']])</td>
                        <td>{{ $pickup['parcels'] }}</td>
                        <td>{{ $pickup['window'] }}</td>
                        <td><span class="status-badge" data-status-badge data-status="{{ $pickup['status'] }}">{{ $pickup['status'] }}</span></td>
                        <td>
                            <div class="row-actions">
                                <button class="button button-small {{ $pickup['status_key'] === 'verified' ? 'button-primary' : '' }}" type="button" data-modal-open="pickup-{{ $pickup['id'] }}">{{ $pickup['status_key'] === 'verified' ? 'Assign Rider' : 'Details' }}</button>
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
                <tr><td class="empty-row" colspan="8" data-empty-row>No pickup requests match these filters.</td></tr>
            </tbody>
        </table>
    </div>
</section>

@foreach($pickups as $pickup)
    <section class="modal pickup-request-modal" data-modal="pickup-{{ $pickup['id'] }}" role="dialog" aria-modal="true" aria-labelledby="pickup-title-{{ $pickup['database_id'] }}" hidden>
        <div class="modal-header pickup-request-modal-header">
            <span class="pickup-request-heading-icon" aria-hidden="true"><i data-lucide="package-check"></i></span>
            <div class="pickup-request-heading-copy">
                <div class="pickup-request-title-row">
                    <h3 id="pickup-title-{{ $pickup['database_id'] }}" tabindex="-1" data-modal-initial-focus>{{ $pickup['id'] }} <span aria-hidden="true">·</span> {{ $pickup['seller'] }}</h3>
                    <span class="status-badge" data-status-badge data-status="{{ $pickup['status_key'] }}">{{ $pickup['status'] }}</span>
                </div>
                <p class="pickup-request-meta">
                    <span>Submitted {{ $pickup['submitted_at'] }}</span>
                    <span><i data-lucide="calendar-clock" aria-hidden="true"></i>Pickup window: {{ $pickup['window'] }}</span>
                </p>
                @if($pickup['status_key'] === 'verified')
                    <p class="pickup-next-action">Verified request · assign an available Rider to schedule pickup.</p>
                @endif
            </div>
            <button class="icon-button" type="button" data-modal-close aria-label="Close pickup request details"><i data-lucide="x" aria-hidden="true"></i></button>
        </div>

        <div class="modal-body pickup-request-modal-body">
            <div class="pickup-context-grid">
                <section class="pickup-info-card pickup-location-card" aria-labelledby="pickup-location-{{ $pickup['database_id'] }}">
                    <div class="pickup-card-heading">
                        <span class="pickup-card-icon"><i data-lucide="map-pin" aria-hidden="true"></i></span>
                        <h4 id="pickup-location-{{ $pickup['database_id'] }}">{{ $pickup['pickup_type'] === 'return' ? 'Buyer return pickup location' : 'Seller and pickup location' }}</h4>
                    </div>
                    <dl class="pickup-location-list">
                        <div class="pickup-location-row">
                            <dt><i data-lucide="user-round" aria-hidden="true"></i>Pickup contact</dt>
                            <dd>{{ $pickup['contact_name'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="mail" aria-hidden="true"></i>Store email</dt>
                            <dd>{{ $pickup['seller_email'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="phone" aria-hidden="true"></i>Mobile number</dt>
                            <dd>@if($pickup['contact_phone'] !== 'Not provided')<a href="tel:{{ $pickup['contact_phone'] }}">{{ $pickup['contact_phone'] }}</a>@else{{ $pickup['contact_phone'] }}@endif</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="map-pin" aria-hidden="true"></i>Pickup address</dt>
                            <dd class="pickup-address-value">
                                <span>{{ $pickup['location'] ?: 'Not provided' }}</span>
                                @if(filled($pickup['location']) && $pickup['location'] !== 'Not provided')
                                    <a class="pickup-map-link" href="{{ filled($pickup['latitude'] ?? null) && filled($pickup['longitude'] ?? null) ? 'https://www.openstreetmap.org/?mlat='.rawurlencode($pickup['latitude']).'&amp;mlon='.rawurlencode($pickup['longitude']).'#map=17/'.rawurlencode($pickup['latitude']).'/'.rawurlencode($pickup['longitude']) : 'https://www.openstreetmap.org/search?query='.rawurlencode($pickup['location']) }}" target="_blank" rel="noopener noreferrer">
                                        View on Map <i data-lucide="external-link" aria-hidden="true"></i>
                                    </a>
                                @endif
                            </dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="file-text" aria-hidden="true"></i>Seller instructions</dt>
                            <dd>{{ $pickup['instructions'] }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="pickup-info-card pickup-schedule-card" aria-labelledby="pickup-schedule-{{ $pickup['database_id'] }}">
                    <div class="pickup-card-heading">
                        <span class="pickup-card-icon"><i data-lucide="clock-3" aria-hidden="true"></i></span>
                        <h4 id="pickup-schedule-{{ $pickup['database_id'] }}">Pickup details</h4>
                    </div>
                    <dl class="pickup-location-list">
                        <div class="pickup-location-row">
                            <dt><i data-lucide="calendar-days" aria-hidden="true"></i>Requested pickup date</dt>
                            <dd>{{ $pickup['requested_date'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="clock-3" aria-hidden="true"></i>Preferred time window</dt>
                            <dd>{{ $pickup['window_start_time'] }}–{{ $pickup['window_end_time'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="clock-3" aria-hidden="true"></i>Seller operating hours</dt>
                            <dd>{{ $pickup['operating_hours'] }}</dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="building-2" aria-hidden="true"></i>Logistics provider</dt>
                            <dd>{{ $pickup['provider'] }}<small>{{ $pickup['provider_city'] }}</small></dd>
                        </div>
                        <div class="pickup-location-row">
                            <dt><i data-lucide="bike" aria-hidden="true"></i>Assigned Rider</dt>
                            <dd>
                                {{ $pickup['rider']['name'] }}
                                <small>
                                    {{ $pickup['rider']['location'] }}
                                    @if ($pickup['rider']['profile_id'])
                                        · Rider profile #{{ $pickup['rider']['profile_id'] }}
                                    @endif
                                </small>
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            @if($pickup['status_key'] === 'verified')
                <section
                    class="pickup-info-card pickup-live-map-card"
                    data-pickup-live-map-panel
                    data-pickup-map-endpoint="{{ route('logistics.pickups.locations', $pickup['database_id']) }}"
                    data-pickup-map-tile-url="{{ config('maps.tile_url') }}"
                    data-pickup-map-attribution="{{ config('maps.tile_attribution') }}"
                    data-pickup-map-select-target="#pickup-rider-{{ $pickup['database_id'] }}"
                    aria-labelledby="pickup-live-map-{{ $pickup['database_id'] }}"
                >
                    <div class="pickup-section-heading pickup-live-map-heading">
                        <span class="pickup-card-icon"><i data-lucide="map-pinned" aria-hidden="true"></i></span>
                        <div class="pickup-live-map-heading-copy">
                            <h4 id="pickup-live-map-{{ $pickup['database_id'] }}">Live pickup map</h4>
                            <p>All assignable local Riders appear in the list. Fresh, accurate GPS reports get map pins; select a marker or any listed Rider for manual assignment.</p>
                        </div>
                        <span class="pickup-map-sync" data-pickup-map-sync aria-live="polite">Waiting for map</span>
                    </div>

                    <div class="pickup-live-map-layout">
                        <div class="pickup-live-map-wrap">
                            <div class="pickup-live-map" data-pickup-live-map aria-label="Pickup and available Rider locations on OpenStreetMap"></div>
                            <p class="pickup-map-status" data-pickup-map-status role="status" aria-live="polite">Loading assignable Riders and fresh locations…</p>
                        </div>
                        <aside class="pickup-live-map-sidebar" aria-label="Live pickup map legend and Riders">
                            <div class="pickup-map-legend">
                                <span><i class="pickup-map-legend-dot is-pickup" aria-hidden="true"></i>Pickup</span>
                                <span><i class="pickup-map-legend-dot is-rider" aria-hidden="true"></i>Rider with accurate GPS</span>
                                <span><i class="pickup-map-legend-line" aria-hidden="true"></i>Straight-line distance (approx.)</span>
                            </div>
                            <div class="pickup-map-rider-list" data-pickup-map-riders>
                                <p class="pickup-map-empty">Loading available Rider candidates…</p>
                            </div>
                        </aside>
                    </div>

                    <p class="pickup-map-note">A Rider pin requires a location update within 3 minutes and reported accuracy of ±100 m or better. Other eligible Riders remain selectable in the list, but stale, imprecise, or missing GPS is not plotted or used for distance; Sorting Zone/workload fallback remains available.</p>
                </section>
            @endif

            <section class="pickup-info-card pickup-manifest-card" aria-labelledby="pickup-manifest-{{ $pickup['database_id'] }}">
                <div class="pickup-section-heading">
                    <span class="pickup-card-icon"><i data-lucide="package" aria-hidden="true"></i></span>
                    <div class="pickup-manifest-heading-copy">
                        <h4 id="pickup-manifest-{{ $pickup['database_id'] }}">Parcel manifest</h4>
                        <p>Review parcel identity and shipping references before assigning a Rider.</p>
                    </div>
                    <div class="pickup-manifest-summary" aria-label="Pickup totals">
                        <span><small>Total parcels</small><strong>{{ $pickup['parcels'] }}</strong></span>
                        <span><small>Total weight</small><strong>{{ $pickup['total_weight'] }}</strong></span>
                        <span><small>Total COD</small><strong>{{ $pickup['total_cod'] }}</strong></span>
                    </div>
                </div>
                @if(count($pickup['fulfillment_groups']) > 0)
                    <p class="pickup-reference-help">Seller Order identifies the store purchase. Shipment and Parcel IDs are Logistics records. The Waybill / Tracking No. is the shipping-label number used for scanning.</p>
                    <div class="pickup-manifest-wrap" tabindex="0" role="region" aria-label="Parcel manifest; scroll horizontally on smaller screens">
                        <table class="pickup-manifest-table">
                            <thead><tr><th scope="col">#</th><th scope="col">Parcel and references</th><th scope="col">Weight</th><th scope="col">Dimensions (L × W × H)</th><th scope="col">Status and COD</th></tr></thead>
                            @php($manifestRowNumber = 0)
                            @foreach($pickup['fulfillment_groups'] as $group)
                                <tbody>
                                    <tr class="pickup-manifest-products-row">
                                        <td colspan="5">
                                            <strong class="pickup-manifest-products-label">Products in this Seller Order</strong>
                                            @include('partials.order-item-preview-list', ['items' => $group['products']])
                                        </td>
                                    </tr>
                                    @foreach($group['parcels'] as $parcel)
                                        <tr>
                                            <td class="pickup-manifest-sequence">{{ ++$manifestRowNumber }}</td>
                                            <td class="pickup-manifest-reference-cell">
                                                <div class="pickup-reference-line"><span>Seller Order No.</span><code>{{ $group['seller_order_no'] }}</code></div>
                                                <div class="pickup-reference-line"><span>Shipment ID</span><code>{{ $group['shipment_no'] }}</code></div>
                                                <div class="pickup-reference-line"><span>Waybill / Tracking No.</span><code>{{ $group['waybill_no'] }}</code></div>
                                                <div class="pickup-reference-line"><span>Parcel ID</span><code>{{ $parcel['parcel_no'] }}</code></div>
                                                <div class="pickup-reference-line"><span>Piece No.</span><strong>{{ $parcel['piece'] }}</strong></div>
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
                        @php($pickupRiders = $pickup['rider_recommendations'] ?? [])
                        @php($recommendedRider = collect($pickupRiders)->firstWhere('is_recommended', true))
                        @if($recommendedRider)
                            <div class="pickup-rider-recommendation" role="status">
                                <span class="pickup-rider-recommendation-icon"><i data-lucide="sparkles" aria-hidden="true"></i></span>
                                <span>
                                    <strong>Suggested Rider: {{ $recommendedRider['name'] }}</strong>
                                    <small>
                                        {{ $recommendedRider['recommendation_reason'] }}.
                                        @if($recommendedRider['distance_label'])
                                            Based on a fresh Rider location update.
                                        @elseif($recommendedRider['location_state'] === 'stale')
                                            The Rider's last location is older than 3 minutes, so it is not used for distance; the Sorting Zone/workload fallback is used.
                                        @elseif($recommendedRider['gps_quality'] === 'low_accuracy')
                                            The latest GPS report is too imprecise (±{{ number_format((float) $recommendedRider['accuracy_meters'], 0) }} m), so it is not used for distance; the Sorting Zone/workload fallback is used.
                                        @elseif($recommendedRider['location_state'] === 'fresh' && $recommendedRider['gps_quality'] === 'unknown')
                                            The fresh GPS update has no accuracy reading, so Sorting Zone/workload fallback is used.
                                        @elseif($recommendedRider['area_match'])
                                            Using the Rider's assigned Sorting Zone as the fallback.
                                        @else
                                            GPS is missing or stale, so active workload is used as the fallback.
                                        @endif
                                        Logistics still confirms the assignment and may choose another Rider.
                                    </small>
                                </span>
                            </div>
                        @endif
                        <label class="pickup-rider-select" for="pickup-rider-{{ $pickup['database_id'] }}">Pickup Rider
                            <select id="pickup-rider-{{ $pickup['database_id'] }}" name="rider_profile_id" required>
                                <option value="">Select available Rider</option>
                                @foreach($pickupRiders as $rider)
                                    <option value="{{ $rider['id'] }}" @selected($loop->first)>
                                        {{ $rider['name'] }} · {{ $rider['vehicle'] }}@if($rider['plate']) · {{ $rider['plate'] }}@endif
                                        @if($rider['distance_label']) · {{ $rider['distance_label'] }}
                                        @elseif($rider['location_state'] === 'stale') · GPS stale@if($rider['area_match']) · Same service area@endif
                                        @elseif($rider['location_state'] === 'fresh' && $rider['gps_quality'] === 'low_accuracy') · GPS too imprecise (±{{ number_format((float) $rider['accuracy_meters'], 0) }} m)
                                        @elseif($rider['location_state'] === 'fresh' && $rider['gps_quality'] === 'unknown') · GPS accuracy unavailable
                                        @elseif($rider['area_match']) · Same service area
                                        @else · GPS unavailable
                                        @endif
                                        · {{ $rider['active_jobs'] }} active {{ $rider['active_jobs'] === 1 ? 'job' : 'jobs' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label class="pickup-assignment-note" for="pickup-note-{{ $pickup['database_id'] }}">Assignment note <small>(optional)</small>
                            <textarea id="pickup-note-{{ $pickup['database_id'] }}" name="notes" maxlength="1000" placeholder="Access or collection instructions"></textarea>
                        </label>
                        <button class="button button-primary" type="submit" @disabled(count($pickupRiders) === 0) @if(count($pickupRiders) === 0) title="No available approved Rider can be assigned." @endif>Assign Rider &amp; schedule pickup</button>
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
