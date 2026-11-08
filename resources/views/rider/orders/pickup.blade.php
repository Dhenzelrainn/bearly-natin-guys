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

@if(session('pickup_error'))
    <div class="flash-banner is-danger" role="alert" aria-live="assertive">
        <i data-lucide="triangle-alert" aria-hidden="true"></i>
        <span>{{ session('pickup_error') }}</span>
    </div>
@endif

@error('waybill_identifier')
    <div class="flash-banner is-danger" role="alert" aria-live="assertive">
        <i data-lucide="triangle-alert" aria-hidden="true"></i>
        <span>{{ $message }}</span>
    </div>
@enderror

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
                        && is_numeric($job['longitude'] ?? null)
                        && (float) $job['latitude'] >= -90
                        && (float) $job['latitude'] <= 90
                        && (float) $job['longitude'] >= -180
                        && (float) $job['longitude'] <= 180;
                    $canShowPickupRoute = in_array($job['status_raw'], ['accepted', 'arrived'], true);
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
                        data-pickup-marker-label="{{ $job['location_label'] ?? 'Seller pickup location' }}"
                        data-pickup-marker-kind="{{ ($job['location_label'] ?? '') === 'Buyer pickup location' ? 'buyer' : 'seller' }}"
                        data-pickup-status="{{ $job['status_raw'] }}"
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
                        data-pickup-route-endpoint="{{ $canShowPickupRoute && $hasMapCoordinates
                            ? route('rider.orders.pickup.route', $job['id'])
                            : '' }}"
                        data-pickup-route-enabled="{{ $canShowPickupRoute ? 'true' : 'false' }}"
                        data-pickup-route-status-target="#pickup-route-status"
                        aria-label="Pickup location and your shared Rider location on OpenStreetMap"
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

                <p id="pickup-route-status" class="destination-map-status" role="status" aria-live="polite">
                    @if($canShowPickupRoute)
                        {{ $hasMapCoordinates
                            ? 'Share your live location to display the road route to this pickup.'
                            : 'A saved pickup map pin is required to calculate a road route.' }}
                    @elseif($job['status_raw'] === 'assigned')
                        Share live location to show your Rider pin and direct guide. Accept this pickup and share accurate GPS to display a road route.
                    @else
                        Road routing is available after accepting the assignment and ends when pickup is confirmed.
                    @endif
                </p>

                <div class="pickup-route-legend" aria-label="Pickup map legend">
                    <span>
                        <i class="pickup-route-legend-pin is-seller" aria-hidden="true"><i data-lucide="store"></i></i>
                        {{ $job['location_label'] ?? 'Seller pickup location' }}
                    </span>
                    <span>
                        <i class="pickup-route-legend-pin is-rider" aria-hidden="true"><i data-lucide="bike"></i></i>
                        Your shared Rider location
                    </span>
                    @if($canShowPickupRoute)
                        <span><i class="pickup-route-legend-key is-road" aria-hidden="true"></i>Road route</span>
                    @endif
                    <span><i class="pickup-route-legend-key is-guide" aria-hidden="true"></i>Direct guide line · not a road route</span>
                </div>

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
                        <div class="manifest-shipment-heading rider-manifest-shipment-heading">
                            <div class="rider-waybill-code">
                                <span>Waybill</span>
                                <code>{{ $group['waybill'] }}</code>
                            </div>
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
                            data-waybill-scan-form
                            data-single-submit
                        >
                            @csrf
                            <div class="rider-waybill-verification">
                                <div class="rider-waybill-verification-heading">
                                    <i data-lucide="scan-line" aria-hidden="true"></i>
                                    <div>
                                        <strong>Match the parcel waybill</strong>
                                        <p>Scan a Code 128 Parcel barcode from one of the printed labels. It must belong to this pickup.</p>
                                    </div>
                                </div>

                                <div class="rider-waybill-expected" role="group" aria-label="Waybill codes assigned to this pickup">
                                    <span>Assigned waybill codes</span>
                                    @php
                                        $assignedWaybills = collect($job['manifest'])
                                            ->filter(fn ($group) => filled($group['waybill'] ?? null)
                                                && ($group['waybill'] ?? null) !== 'Not generated');
                                    @endphp
                                    @forelse($assignedWaybills as $group)
                                        @php
                                            $parcelBarcodes = collect($group['parcels'] ?? [])
                                                ->pluck('parcel_no')
                                                ->filter()
                                                ->values();
                                        @endphp
                                        <div class="rider-waybill-expected-item">
                                            <small>Waybill reference</small>
                                            <code>{{ $group['waybill'] }}</code>
                                            @if(filled($group['barcode_value'] ?? null)
                                                && $group['barcode_value'] !== $group['waybill']
                                                && !$parcelBarcodes->contains($group['barcode_value']))
                                                <small>Waybill barcode: <code>{{ $group['barcode_value'] }}</code></small>
                                            @endif
                                            @foreach($parcelBarcodes as $parcelBarcode)
                                                <small>Printed Parcel barcode (Code 128): <code>{{ $parcelBarcode }}</code></small>
                                            @endforeach
                                        </div>
                                    @empty
                                        <p class="rider-waybill-missing">No generated waybill is attached. Contact the seller before pickup.</p>
                                    @endforelse
                                </div>

                                <div class="rider-waybill-scan-field">
                                    <label for="rider-waybill-identifier">Scan or enter a printed Parcel barcode or Waybill reference</label>
                                    <div class="waybill-scan-tools">
                                        <input id="rider-waybill-identifier" type="text" name="waybill_identifier" value="{{ old('waybill_identifier') }}" data-waybill-identifier required maxlength="120" placeholder="Scan Code 128 or type the printed code" autocomplete="off" spellcheck="false" aria-describedby="rider-waybill-scan-help">
                                        <button type="button" class="waybill-camera-button" data-waybill-camera-open><i data-lucide="camera" aria-hidden="true"></i>Scan Parcel</button>
                                    </div>
                                    <input type="hidden" name="scan_method" value="manual" data-waybill-scan-method>
                                    <small id="rider-waybill-scan-help">Scan the Code 128 barcode printed on a Parcel label, use a USB/Bluetooth scanner, or enter the Waybill reference shown above.</small>
                                </div>
                                <div class="waybill-camera-panel" data-waybill-camera-panel hidden><video data-waybill-camera-video playsinline muted></video><p class="waybill-camera-status" data-waybill-camera-status>Point the camera at the Code 128 Parcel barcode.</p><button type="button" class="button button-small" data-waybill-camera-close>Cancel camera</button></div>
                            </div>

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
