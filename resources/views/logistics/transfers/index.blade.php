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
            <h3>Accept an incoming handoff</h3>
            <p>Scan or enter the waybill. Incoming parcels are assigned automatically to your account’s single active Sorting Center.</p>
        </div>
    </div>
    <form class="panel-body" method="POST" action="{{ route('logistics.transfers.accept') }}" data-waybill-scan-form>
        @csrf
        <input type="hidden" name="scan_method" value="manual" data-waybill-scan-method>
        <div class="field-grid">
            <div class="field span-2">
                <label for="transfer-identifier">Waybill or barcode</label>
                    <input id="transfer-identifier" name="identifier" value="{{ old('identifier') }}" maxlength="120" required autocomplete="off" placeholder="Scan or enter the waybill number" data-waybill-identifier>
                    <div class="waybill-scan-tools"><button type="button" class="waybill-camera-button" data-waybill-camera-open><i data-lucide="camera"></i>Use camera</button><small>Manual entry and USB/Bluetooth barcode scanners are also supported.</small></div>
                @error('identifier')<small class="form-error">{{ $message }}</small>@enderror
            </div>
            <div class="field span-2">
                <label for="transfer-center">Receiving Sorting Center</label>
                <input id="transfer-center" type="text" value="{{ $sortingCenter?->name ?? 'No active Sorting Center available' }}" readonly>
            </div>
        </div>
        <div class="waybill-camera-panel" data-waybill-camera-panel hidden><video data-waybill-camera-video playsinline muted></video><p class="waybill-camera-status" data-waybill-camera-status>Point the camera at the Code 128 waybill barcode.</p><button type="button" class="button button-small" data-waybill-camera-close>Cancel camera</button></div>
        <div class="form-actions">
            <button class="button button-primary" type="submit" @disabled(! $sortingCenter)><i data-lucide="scan-line"></i> Scan and accept</button>
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
                        <thead><tr><th>Select</th><th>Waybill / Seller Order</th><th>Seller</th><th>Destination</th><th>Parcels</th></tr></thead>
                        <tbody>
                            @foreach($destinationShipments as $shipment)
                                <tr>
                                    <td><input type="checkbox" name="shipment_ids[]" value="{{ $shipment['id'] }}" aria-label="Add {{ $shipment['waybill'] }} to manifest"></td>
                                    <td><span class="cell-title"><strong>{{ $shipment['waybill'] }}</strong><small>{{ $shipment['seller_order'] }} · {{ $shipment['purpose'] }}</small></span></td>
                                    <td>{{ $shipment['seller'] }}</td>
                                    <td>{{ $shipment['destination'] }}{{ $shipment['province'] ? ', '.$shipment['province'] : '' }}</td>
                                    <td>{{ $shipment['pieces'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit" @disabled($availableCouriers->isEmpty())><i data-lucide="truck"></i> Assign manifest</button>
                    @if($availableCouriers->isEmpty())<small>No available, verified Courier with capacity is assigned to this Logistics provider.</small>@endif
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
    <div class="panel-header"><div><h3>Active line-haul manifests</h3><p>Origin and destination custody remain distinct. Arrival does not replace the destination acceptance scan.</p></div></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Manifest</th><th>Route / Courier</th><th>Status</th><th>Shipments</th><th>Action</th></tr></thead>
            <tbody>
                @forelse($activeLinehaulRuns as $run)
                    <tr>
                        <td><strong>{{ $run->run_no }}</strong><small>Assigned {{ $run->assigned_at?->format('M j, Y · g:i A') }}</small></td>
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
                @empty
                    <tr><td class="empty-row" colspan="5">No active manifests involve this Logistics provider.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="section-grid">
    <section class="panel">
        <div class="panel-header"><div><h3>Awaiting destination acceptance</h3><p>Custody stays with your account until the receiving Logistics scans the waybill.</p></div></div>
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
                        <tr><td class="empty-row" colspan="4">No handoffs are waiting for acceptance.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header"><div><h3>Incoming handoffs</h3><p>Accept only parcels physically received by your Sorting Center.</p></div></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Waybill</th><th>Seller</th><th>Sent by</th><th>Sent</th></tr></thead>
                <tbody>
                    @forelse($incomingTransfers as $transfer)
                        <tr>
                            <td>{{ $transfer->shipment?->waybill?->waybill_no ?? $transfer->shipment?->shipment_no }}</td>
                            <td>{{ $transfer->shipment?->sellerOrder?->store?->name ?? 'Unknown Seller' }}</td>
                            <td>{{ $transfer->fromLogisticsProfile?->display_name ?? 'Logistics account' }}</td>
                            <td>{{ $transfer->sent_at?->format('M j, Y · g:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td class="empty-row" colspan="4">No incoming handoffs are awaiting your scan.</td></tr>
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
            <thead><tr><th>Waybill</th><th>From</th><th>To</th><th>Accepted</th></tr></thead>
            <tbody>
                @forelse($recentTransfers as $transfer)
                    <tr>
                        <td>{{ $transfer->shipment?->waybill?->waybill_no ?? $transfer->shipment?->shipment_no }}</td>
                        <td>{{ $transfer->fromLogisticsProfile?->display_name ?? 'Logistics account' }}</td>
                        <td>{{ $transfer->toLogisticsProfile?->display_name ?? 'Logistics account' }}</td>
                        <td>{{ $transfer->accepted_at?->format('M j, Y · g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td class="empty-row" colspan="4">No accepted handoffs yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
