@extends('rider.layouts.app')

@section('title', 'Pickup Details')
@section('page-title', 'Pickup Details')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">
            Seller pickup assignment
        </p>

        <h2>
            {{ $job['id'] }}
        </h2>

        <p>
            Review the seller location, pickup window,
            assignment notes, and parcel manifest.
        </p>
    </div>

    <div class="page-actions">
        <a
            class="button"
            href="{{ route('rider.dashboard.pickups') }}"
        >
            <i data-lucide="arrow-left"></i>
            Back to pickup queue
        </a>
    </div>
</div>

<div class="workflow-steps">
    <div
        class="workflow-step
            {{ in_array(
                $job['status_raw'],
                ['assigned', 'accepted', 'arrived', 'picked_up', 'completed'],
                true
            ) ? 'is-complete' : '' }}"
    >
        <span>
            <i data-lucide="check"></i>
        </span>

        <strong>
            Assigned
        </strong>
    </div>

    <div
        class="workflow-step
            {{ in_array(
                $job['status_raw'],
                ['accepted', 'arrived', 'picked_up', 'completed'],
                true
            ) ? 'is-complete' : '' }}"
    >
        <span>2</span>

        <strong>
            Accepted
        </strong>
    </div>

    <div
        class="workflow-step
            {{ in_array(
                $job['status_raw'],
                ['picked_up', 'completed'],
                true
            )
                ? 'is-complete'
                : '' }}"
    >
        <span>3</span>

        <strong>
            Picked up
        </strong>
    </div>
</div>

<div class="route-layout">
    <section class="section-stack">
        <article class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        {{ $job['location_label'] ?? 'Seller location' }}
                    </h3>

                    <p>
                        Pickup window:
                        {{ $job['window'] }}
                    </p>
                </div>

                <span
                    class="status-badge"
                    data-status="{{ $job['status_raw'] }}"
                >
                    {{ $job['status'] }}
                </span>
            </div>

            <div class="panel-body">
                @php
                    $mapAddress = $job['map_address'] ?? [];
                    $hasMapCoordinates = is_numeric($job['latitude'] ?? null)
                        && is_numeric($job['longitude'] ?? null);
                    $osmLink = $hasMapCoordinates
                        ? 'https://www.openstreetmap.org/?mlat='.rawurlencode($job['latitude'])
                            .'&mlon='.rawurlencode($job['longitude'])
                            .'#map=17/'.rawurlencode($job['latitude'])
                            .'/'.rawurlencode($job['longitude'])
                        : 'https://www.openstreetmap.org/search?query='.rawurlencode($job['address']);
                @endphp

                <div class="pickup-map-shell">
                    <div
                        class="destination-map"
                        data-destination-map
                        data-latitude="{{ $job['latitude'] ?? '' }}"
                        data-longitude="{{ $job['longitude'] ?? '' }}"
                        data-map-tile-url="{{ config('maps.tile_url') }}"
                        data-map-attribution="{{ config('maps.tile_attribution') }}"
                        data-map-forward-geocode-url="{{ route('maps.forward-geocode') }}"
                        data-map-street="{{ $mapAddress['street'] ?? '' }}"
                        data-map-barangay="{{ $mapAddress['barangay'] ?? '' }}"
                        data-map-city="{{ $mapAddress['city'] ?? '' }}"
                        data-map-province="{{ $mapAddress['province'] ?? '' }}"
                        data-map-postal="{{ $mapAddress['postal'] ?? '' }}"
                        data-map-status-target="#pickup-map-status"
                        data-map-osm-link-target="#pickup-osm-link"
                        aria-label="Pickup location on OpenStreetMap"
                    ></div>

                    <div class="map-meta">
                        <span>
                            <strong>
                                {{ $job['contact_name'] ?? $job['seller'] }}
                            </strong>

                            <br>

                            {{ $job['address'] }}
                        </span>

                        <span>
                            <strong>
                                {{ $job['contact'] }}
                            </strong>

                            <br>

                            {{ str_contains(strtolower($job['seller']), 'return pickup') ? 'Buyer contact' : 'Seller contact' }}
                        </span>
                    </div>
                </div>

                <p id="pickup-map-status" class="destination-map-status" role="status">
                    {{ $hasMapCoordinates
                        ? 'Pickup location pinned on OpenStreetMap.'
                        : 'Locating the pickup address on OpenStreetMap.' }}
                </p>

                <a
                    id="pickup-osm-link"
                    class="osm-destination-link"
                    href="{{ $osmLink }}"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Open pickup location in OpenStreetMap
                    <i data-lucide="external-link"></i>
                </a>
            </div>
        </article>

        <article class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Package manifest
                    </h3>

                    <p>
                        Parcels attached to this pickup request.
                    </p>
                </div>
            </div>

            <div class="panel-body manifest-list">
                @forelse($job['manifest'] as $group)
                    <section class="manifest-shipment-group">
                        <div class="manifest-shipment-heading">
                            <strong>Waybill {{ $group['waybill'] }}</strong>
                            <small>Products in shipment</small>
                        </div>

                        @include('partials.order-item-preview-list', ['items' => $group['products']])

                        <div class="manifest-shipment-parcels">
                            @foreach($group['parcels'] as $item)
                                <div class="manifest-item">
                                    <span>
                                        <strong>{{ $item['parcel_no'] }}</strong>
                                        <small>{{ $item['size'] }}</small>
                                    </span>
                                    <span class="status-badge" data-status="{{ Str::slug($item['status'], '_') }}">
                                        {{ $item['status'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <p class="empty-copy">
                        No parcels are attached to this
                        pickup request.
                    </p>
                @endforelse
            </div>
        </article>
    </section>

    <aside class="section-stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Assignment summary
                    </h3>

                    <p>
                        Current Rider pickup assignment.
                    </p>
                </div>
            </div>

            <div class="panel-body detail-list">
                <div class="detail-item">
                    <small>
                        Seller
                    </small>

                    <strong>
                        {{ $job['seller'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Contact
                    </small>

                    <strong>
                        {{ $job['contact'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Parcels
                    </small>

                    <strong>
                        {{ $job['parcels'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Status
                    </small>

                    <strong>
                        {{ $job['status'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Assigned at
                    </small>

                    <strong>
                        {{ $job['assigned_at'] }}
                    </strong>
                </div>
            </div>

            @if($job['status_raw'] === 'assigned')
                <div class="panel-footer">
                    <form
                        method="POST"
                        action="{{ route(
                            'rider.orders.pickup.accept',
                            $job['id']
                        ) }}"
                        style="width:100%;"
                        data-single-submit
                    >
                        @csrf

                        <button
                            type="submit"
                            class="button button-primary"
                            style="width:100%;"
                        >
                            <i data-lucide="check-circle"></i>
                            Accept pickup assignment
                        </button>
                    </form>
                </div>
            @elseif($job['status_raw'] === 'accepted')
                <div class="panel-footer">
                    <div
                        style="
                            width:100%;
                            display:grid;
                            gap:12px;
                        "
                    >
                        <div
                            class="flash-banner"
                            style="width:100%;"
                        >
                            <i data-lucide="circle-check"></i>

                            <span>
                                Pickup accepted. Verify the physical
                                parcels with the seller before confirming
                                collection.
                            </span>
                        </div>

                        <form
                            method="POST"
                            action="{{ route(
                                'rider.orders.pickup.confirm',
                                $job['id']
                            ) }}"
                            data-single-submit
                        >
                            @csrf
                            <label class="rider-waybill-scan-field">
                                <span>Verify a waybill before pickup</span>
                                <div class="waybill-scan-tools">
                                    <input name="waybill_identifier" data-waybill-identifier required maxlength="120" placeholder="Scan or enter a waybill number" autocomplete="off">
                                    <button type="button" class="waybill-camera-button" data-waybill-camera-open><i data-lucide="camera"></i>Scan</button>
                                </div>
                                <input type="hidden" name="scan_method" value="manual" data-waybill-scan-method>
                                <small>Use the assignment's Code 128 label. Manual entry and USB/Bluetooth scanners are supported.</small>
                            </label>
                            <div class="waybill-camera-panel" data-waybill-camera-panel hidden><video data-waybill-camera-video playsinline muted></video><p class="waybill-camera-status" data-waybill-camera-status>Point the camera at the Code 128 waybill barcode.</p><button type="button" class="button button-small" data-waybill-camera-close>Cancel camera</button></div>

                            <button
                                type="submit"
                                class="button button-primary"
                                style="width:100%;"
                            >
                                <i data-lucide="package-check"></i>
                                Confirm parcels picked up
                            </button>
                        </form>
                    </div>
                </div>
            @elseif($job['status_raw'] === 'picked_up')
                <div class="panel-footer">
                    <div
                        class="flash-banner"
                        role="status"
                        style="width:100%;"
                    >
                        <i data-lucide="circle-check"></i>

                        <span>
                            Parcels collected. Next, bring them to your
                            assigned Sorting Center. Logistics will scan and
                            record each parcel in Incoming Parcels after
                            receiving the handoff.
                        </span>
                    </div>
                </div>
            @elseif($job['status_raw'] === 'completed')
                <div class="panel-footer">
                    <div
                        class="flash-banner is-success"
                        role="status"
                        style="width:100%;"
                    >
                        <i data-lucide="circle-check"></i>

                        <span>
                            Pickup complete. Logistics received all parcels
                            at the Sorting Center; this assignment is now
                            read-only.
                        </span>
                    </div>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Seller instructions
                    </h3>
                </div>
            </div>

            <div class="panel-body">
                <p
                    style="
                        margin:0;
                        color:var(--muted);
                        font-size:11px;
                        line-height:1.7;
                    "
                >
                    {{ $job['instructions'] }}
                </p>
            </div>
        </section>

        @if($job['assignment_notes'])
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>
                            Logistics notes
                        </h3>
                    </div>
                </div>

                <div class="panel-body">
                    <p
                        style="
                            margin:0;
                            color:var(--muted);
                            font-size:11px;
                            line-height:1.7;
                        "
                    >
                        {{ $job['assignment_notes'] }}
                    </p>
                </div>
            </section>
        @endif
    </aside>
</div>
@endsection
