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

<section class="panel" data-table-scope>
    <div class="panel-header">
        <div>
            <h3>Ready to send</h3>
            <p>Only received, unsorted shipments with an active destination-city Logistics account can be sent.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Waybill / Seller Order</th><th>Seller</th><th>Destination</th><th>Receiving Logistics</th><th>Pieces</th><th>Action</th></tr></thead>
            <tbody>
                @forelse($eligibleShipments as $shipment)
                    <tr>
                        <td><span class="cell-title"><strong>{{ $shipment['waybill'] }}</strong><small>{{ $shipment['seller_order'] }} · {{ $shipment['purpose'] }}</small></span></td>
                        <td>{{ $shipment['seller'] }}</td>
                        <td>{{ $shipment['destination'] }}{{ $shipment['province'] ? ', '.$shipment['province'] : '' }}</td>
                        <td>{{ $shipment['target'] }}</td>
                        <td>{{ $shipment['pieces'] }}</td>
                        <td>
                            <form method="POST" action="{{ route('logistics.transfers.send', $shipment['id']) }}" data-transfer-send-form>
                                @csrf
                                <button class="button button-small button-primary" type="submit"><i data-lucide="send"></i> Send handoff</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty-row" colspan="6">No received cross-city shipments are ready to hand off.</td></tr>
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
