@extends('logistics.layouts.app')
@section('title', 'City Handoffs')
@section('page-title', 'City Handoffs')
@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Inter-city parcel custody</p>
        <h2>City-to-city handoffs</h2>
        <p>Send received parcels to the Logistics account serving their destination. The receiving account scans and accepts before sorting or delivery can continue.</p>
    </div>
</div>

<section class="panel">
    <div class="panel-header">
        <div>
            <h3>Accept an incoming Waybill</h3>
            <p>Scan a Parcel barcode or select a Waybill from the queue—not the Courier Run ID. Review the matching Waybill and parcel count before accepting; one acceptance receives the whole shipment.</p>
        </div>
    </div>
    <form class="panel-body" method="POST" action="{{ route('logistics.transfers.accept') }}" data-waybill-scan-form data-handoff-accept-form data-handoff-lookup-url="{{ route('logistics.transfers.lookup') }}">
        @csrf
        <input type="hidden" name="scan_method" value="manual" data-waybill-scan-method>
        <div class="field-grid">
            <div class="field span-2">
                <label for="transfer-identifier">Waybill number or Parcel barcode</label>
                    <input id="transfer-identifier" name="identifier" value="{{ old('identifier') }}" maxlength="120" required autocomplete="off" placeholder="Scan a Parcel label or enter a Waybill" data-waybill-identifier>
                    <div class="waybill-scan-tools"><button type="button" class="waybill-camera-button" data-waybill-camera-open><i data-lucide="camera"></i>Use camera</button><small>Camera and USB/Bluetooth scanners read the Parcel barcode. Manual entry and Select Waybill also work.</small></div>
                @error('identifier')<small class="form-error">{{ $message }}</small>@enderror
            </div>
            <div class="field span-2">
                <label for="transfer-center">Receiving Sorting Center</label>
                <input id="transfer-center" type="text" value="{{ $sortingCenter?->name ?? 'No active Sorting Center available' }}" readonly>
            </div>
        </div>
        <div class="waybill-camera-panel" data-waybill-camera-panel hidden><video data-waybill-camera-video playsinline muted></video><p class="waybill-camera-status" data-waybill-camera-status>Point the camera at the Code 128 Parcel barcode.</p><button type="button" class="button button-small" data-waybill-camera-close>Cancel camera</button></div>
        <div class="zone-routing-note is-handoff" data-handoff-preview hidden aria-live="polite">
            <strong data-handoff-preview-waybill></strong>
            <small data-handoff-preview-count></small>
            <small>Accepting this Waybill receives every parcel in the shipment, not only the scanned Parcel.</small>
        </div>
        <p class="zone-routing-note is-handoff" data-handoff-lookup-status role="status" aria-live="polite">Scan a Parcel label or select a Waybill below to review the shipment before acceptance.</p>
        <div class="form-actions">
            <button class="button button-primary" type="submit" data-handoff-accept-button @disabled(! $sortingCenter)><i data-lucide="scan-line"></i> <span data-handoff-accept-label>Review Waybill</span></button>
        </div>
    </form>
</section>

<section class="panel" id="ready-to-send">
    <div class="panel-header">
        <div>
            <h3>Build a Courier manifest</h3>
            <p>Select shipments for one destination Logistics provider and assign an available Courier whose vehicle and parcel capacity have been verified by Logistics. Cargo moves only when the Courier departs.</p>
        </div>
    </div>
    <div class="panel-body">
        @forelse($eligibleShipments->groupBy('target_profile_id') as $destinationProfileId => $destinationShipments)
            <form method="POST" action="{{ route('logistics.transfers.manifests.store') }}" class="panel" style="margin-bottom:16px" data-manifest-form>
                @csrf
                <div class="panel-header">
                    <div><h4>{{ $destinationShipments->first()['target'] }}</h4><p>{{ $destinationShipments->count() }} eligible shipment(s) to this destination city.</p></div>
                    <label class="field">Available verified Courier
                        <select name="courier_profile_id" required @disabled($availableCouriers->isEmpty())>
                            <option value="">Select Courier</option>
                            @foreach($availableCouriers as $courier)
                                <option value="{{ $courier->id }}">{{ $courier->user?->name ?? 'Courier' }} · {{ $courier->vehicle_type }} · {{ $courier->linehaul_parcel_capacity }} parcels</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Select</th><th>Waybill / Seller Order</th><th>Products in shipment</th><th>Seller</th><th>Destination</th><th>Parcels</th></tr></thead>
                        <tbody>
                            @foreach($destinationShipments as $shipment)
                                <tr>
                                    <td><input type="checkbox" name="shipment_ids[]" value="{{ $shipment['id'] }}" aria-label="Add {{ $shipment['waybill'] }} to manifest"></td>
                                    <td><span class="cell-title"><strong>{{ $shipment['waybill'] }}</strong><small>{{ $shipment['seller_order'] }} · {{ $shipment['purpose'] }}</small></span></td>
                                    <td class="handoff-products-cell">@include('partials.order-item-preview-list', ['items' => $shipment['products']])</td>
                                    <td>{{ $shipment['seller'] }}</td>
                                    <td>{{ $shipment['destination'] }}{{ $shipment['province'] ? ', '.$shipment['province'] : '' }}</td>
                                    <td>{{ $shipment['pieces'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="form-actions manifest-actions">
                    @if($availableCouriers->isEmpty())<small class="manifest-availability-note">No available, verified Courier with capacity is assigned to this Logistics provider.</small>@endif
                    <button class="button button-primary" type="submit" @disabled($availableCouriers->isEmpty())><i data-lucide="truck"></i> Assign manifest</button>
                </div>
            </form>
        @empty
            <p class="empty-copy">No received cross-city shipments are ready for a Courier manifest.</p>
        @endforelse
    </div>
</section>

@if($unroutableShipments->isNotEmpty())
<section class="panel" id="needs-destination">
    <div class="panel-header">
        <div>
            <h3>Needs destination Logistics</h3>
            <p>These received cross-city parcels cannot be sent until the destination city has an active Logistics account with one active Sorting Center.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Waybill / Seller Order</th><th>Seller</th><th>Destination</th><th>Receiving Logistics</th><th>Issue</th></tr></thead>
            <tbody>
                @foreach($unroutableShipments as $shipment)
                    <tr>
                        <td><span class="cell-title"><strong>{{ $shipment['waybill'] }}</strong><small>{{ $shipment['seller_order'] }} · {{ $shipment['purpose'] }}</small></span></td>
                        <td>{{ $shipment['seller'] }}</td>
                        <td>{{ $shipment['destination'] }}{{ $shipment['province'] ? ', '.$shipment['province'] : '' }}</td>
                        <td>{{ $shipment['target'] }}</td>
                        <td><span class="zone-routing-note is-unmatched">{{ $shipment['handoff_issue'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif

<section class="panel">
    <div class="panel-header"><div><h3>Courier Run Status</h3><p>Track Courier progress here. To receive a shipment after arrival, accept its Waybill in the form above.</p></div></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Transfer ID</th><th>Route / Courier</th><th>Status</th><th>Shipments</th><th>Action</th></tr></thead>
            <tbody>
                @forelse($activeLinehaulRuns as $run)
                    <tr>
                        <td><strong>{{ $run->run_no }}</strong></td>
                        <td><span class="cell-title"><strong>{{ $run->originSortingCenter?->name ?? 'Origin' }} → {{ $run->destinationLogisticsProfile?->display_name ?? 'Destination' }}</strong><small>{{ $run->courierRiderProfile?->user?->name ?? 'Courier' }}</small></span></td>
                        <td><span class="status-badge" data-status="{{ $run->status }}">{{ str($run->status)->replace('_', ' ')->title() }}</span></td>
                        <td>{{ $run->shipments->count() }} shipment(s) · {{ $run->shipments->sum(fn ($shipment) => $shipment->parcels->count()) }} parcel(s)</td>
                        <td>
                            @if($run->status === 'exception' && (int) $run->origin_logistics_profile_id === (int) auth()->user()?->logisticsProfile?->id)
                                <form method="POST" action="{{ route('logistics.transfers.runs.exceptions.resolve', $run) }}">
                                    @csrf
                                    <label class="field">Resolution notes<textarea name="resolution" minlength="5" maxlength="1000" required></textarea></label>
                                    <button class="button button-small button-primary" type="submit">Resolve exception</button>
                                </form>
                            @else
                                @if($run->status === 'arrived')<small>Awaiting destination acceptance of all shipments.</small>@else<small>See run history in Courier workspace.</small>@endif
                            @endif
                        </td>
                    </tr>
                    <tr class="linehaul-assigned-row">
                        <td colspan="5"><small>Assigned <time datetime="{{ $run->assigned_at?->toIso8601String() }}">{{ $run->assigned_at?->format('M j, Y · g:i A') }}</time></small></td>
                    </tr>
                @empty
                    <tr><td class="empty-row" colspan="5">No active Courier runs involve this Logistics provider.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="section-grid">
    <section class="panel">
        <div class="panel-header"><div><h3>Outgoing handoffs</h3><p>Waybills sent from your facility stay in your custody until destination Logistics accepts them.</p></div></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Waybill</th><th>Seller</th><th>Sent to</th><th>Sent</th></tr></thead>
                <tbody>
                    @forelse($outgoingTransfers as $transfer)
                        <tr>
                            <td>{{ $transfer->shipment?->waybill?->waybill_no ?? $transfer->shipment?->shipment_no }}</td>
                            <td>{{ $transfer->shipment?->sellerOrder?->store?->name ?? 'Unknown Seller' }}</td>
                            <td>{{ $transfer->toLogisticsProfile?->display_name ?? 'Logistics account' }}</td>
                            <td>{{ $transfer->sent_at?->format('M j, Y · g:i A') }}</td>
                        </tr>
                    @empty
                    <tr><td class="empty-row" colspan="4">No outgoing handoffs are awaiting acceptance.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header"><div><h3>Waybills Awaiting Acceptance</h3><p>Select a Waybill to load it into the form above. Accept it after the Courier run shows Arrived. One Waybill accepts the whole shipment, including all its parcels.</p></div></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Waybill</th><th>Seller</th><th>Sent by</th><th>Sent</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse($incomingTransfers as $transfer)
                        @php
                            $incomingWaybill = $transfer->shipment?->waybill?->waybill_no;
                            $incomingRun = $transfer->shipment?->linehaulRunAssignment?->run;
                        @endphp
                        <tr>
                            <td>{{ $incomingWaybill ?? $transfer->shipment?->shipment_no }}</td>
                            <td>{{ $transfer->shipment?->sellerOrder?->store?->name ?? 'Unknown Seller' }}</td>
                            <td>{{ $transfer->fromLogisticsProfile?->display_name ?? 'Logistics account' }}</td>
                            <td>{{ $transfer->sent_at?->format('M j, Y · g:i A') }}</td>
                            <td>
                                @if($incomingWaybill && (! $incomingRun || $incomingRun->status === 'arrived'))
                                    <button
                                        class="button button-small button-primary"
                                        type="button"
                                        data-handoff-waybill-prefill="{{ $incomingWaybill }}"
                                        aria-label="Select Waybill {{ $incomingWaybill }} for acceptance"
                                    >Select Waybill</button>
                                @elseif($incomingRun)
                                    <small>Wait until Courier Run Status is Arrived</small>
                                @else
                                    <small>Waybill number unavailable</small>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="empty-row" colspan="5">No Waybills are awaiting acceptance.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-header"><div><h3>Recently accepted</h3><p>Latest completed custody changes involving your account.</p></div></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Waybill</th><th>Products in shipment</th><th>From</th><th>To</th><th>Accepted</th></tr></thead>
            <tbody>
                @forelse($recentTransfers as $transfer)
                    <tr>
                        <td>{{ $transfer->shipment?->waybill?->waybill_no ?? $transfer->shipment?->shipment_no }}</td>
                        <td class="handoff-products-cell">@include('partials.order-item-preview-list', ['items' => $recentTransferProductPreviews->get($transfer->id, [])])</td>
                        <td>{{ $transfer->fromLogisticsProfile?->display_name ?? 'Logistics account' }}</td>
                        <td>{{ $transfer->toLogisticsProfile?->display_name ?? 'Logistics account' }}</td>
                        <td>{{ $transfer->accepted_at?->format('M j, Y · g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td class="empty-row" colspan="5">No accepted handoffs yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
