@extends('logistics.layouts.app')
@section('title','Incoming Parcels')
@section('page-title','Incoming Parcels')
@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Sorting center intake</p>
        <h2>Receive and log incoming parcels</h2>
        <p>Record every package arriving from pickup riders before it enters the sorting queue.</p>
    </div>
    <div class="page-actions">
        <button class="button button-primary" type="button" data-modal-open="log-parcel" @disabled($sortingCenters->isEmpty())><i data-lucide="scan-line"></i>Log incoming parcel</button>
    </div>
</div>
<section class="metric-strip">
    <article class="metric-item"><span class="metric-icon"><i data-lucide="package-open"></i></span><span class="metric-copy"><small>Received today</small><strong>{{ $intakeMetrics['received_today'] }}</strong><span>From {{ $intakeMetrics['seller_pickups'] }} pickup locations</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="truck"></i></span><span class="metric-copy"><small>Awaiting center intake</small><strong>{{ $intakeMetrics['awaiting_intake'] }}</strong><span>Picked-up parcels still with Logistics</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="list-checks"></i></span><span class="metric-copy"><small>Awaiting sorting</small><strong>{{ $intakeMetrics['awaiting_sorting'] }}</strong><span>Ready for zone assignment</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="scale"></i></span><span class="metric-copy"><small>Total weight</small><strong>{{ $intakeMetrics['total_weight'] }} kg</strong><span>Today’s intake</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="triangle-alert"></i></span><span class="metric-copy"><small>Intake exceptions</small><strong>{{ $intakeMetrics['exceptions'] }}</strong><span>Requires inspection</span></span></article>
</section>
<section class="panel" data-table-scope>
    <div class="table-toolbar">
        <div class="search-field"><i data-lucide="search"></i><input type="search" data-table-search placeholder="Scan or search waybill, order, seller, or rider"></div>
        <select class="filter-select" data-filter-status><option value="">All intake statuses</option><option>Awaiting Intake</option><option>Received</option><option>Sorted</option><option>Exception</option></select>
        <select class="filter-select" data-filter-zone>
            <option value="">All destinations</option>
            @foreach($incomingParcels->pluck('destination')->unique()->sort() as $destination)<option>{{ $destination }}</option>@endforeach
        </select>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th data-sort>Waybill / Order</th><th>Products in shipment</th><th>Seller</th><th>Received from</th><th data-sort>Received at (PHT)</th><th>Pieces</th><th>Weight</th><th>Destination</th><th>Status</th><th>Action</th></tr></thead>
            <tbody data-incoming-body>
                @foreach($incomingParcels as $parcel)
                    <tr data-row data-record-row data-record-type="incoming" data-record-id="{{ $parcel['waybill'] }}" data-status="{{ $parcel['status'] }}" data-zone="{{ $parcel['destination'] }}">
                        <td><span class="cell-title"><strong>{{ $parcel['waybill'] }}</strong><small>{{ $parcel['order'] }}</small></span></td>
                        <td class="incoming-products-cell">@include('partials.order-item-preview-list', ['items' => $parcel['products']])</td>
                        <td><span class="cell-title"><strong>{{ $parcel['seller'] }}</strong><small>{{ $parcel['purpose_label'] }}</small></span></td><td>{{ $parcel['rider'] }}</td><td>{{ $parcel['received'] }}</td><td>{{ $parcel['pieces'] }}</td><td>{{ $parcel['weight'] }}</td><td>{{ $parcel['destination'] }}</td>
                        <td><span class="status-badge" data-status-badge data-status="{{ $parcel['status'] }}">{{ $parcel['status'] }}</span></td>
                        <td>
                            @if($parcel['status'] === 'Awaiting Intake')
                                <button class="button button-small button-primary" type="button" data-modal-open="log-parcel" data-waybill-prefill="{{ $parcel['waybill'] }}">
                                    <i data-lucide="scan-line"></i>Log intake
                                </button>
                            @else
                                <a class="button button-small" href="{{ route('logistics.sorting.center', ['search' => $parcel['waybill']]) }}">Open sorting</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr><td class="empty-row" colspan="10" data-empty-row>No incoming parcels match these filters.</td></tr>
            </tbody>
        </table>
    </div>
</section>
<section class="modal" data-modal="log-parcel" @if(! $errors->any()) hidden @endif>
    <div class="modal-header"><div><h3>Log incoming parcel</h3><p>Scan a Parcel barcode or load a Waybill manifest to select one piece.</p></div><button class="icon-button" type="button" data-modal-close><i data-lucide="x"></i></button></div>
    <form class="modal-body" method="POST" action="{{ route('logistics.sorting.incoming.receive') }}" data-waybill-scan-form data-waybill-lookup-url="{{ route('logistics.waybills.lookup', ['identifier' => '__WAYBILL__']) }}">
        @csrf
        @error('intake')<div class="flash-banner is-danger" role="alert"><i data-lucide="circle-alert"></i><span>{{ $message }}</span></div>@enderror
        @if($errors->any() && ! $errors->has('intake'))<div class="flash-banner is-danger" role="alert"><i data-lucide="circle-alert"></i><span>{{ $errors->first() }}</span></div>@endif
        <input type="hidden" name="scan_method" value="manual" data-waybill-scan-method>
        <div class="field-grid">
            <div class="field span-2"><label>Parcel barcode or Waybill reference <span>*</span></label><input name="identifier" value="{{ old('identifier') }}" required maxlength="120" placeholder="Scan a Parcel barcode or look up a Waybill" data-waybill-identifier><div class="waybill-scan-tools"><button type="button" class="button button-small" data-waybill-load>Load manifest</button><button type="button" class="waybill-camera-button" data-waybill-camera-open><i data-lucide="camera"></i>Scan parcel</button><small>Scan each physical Parcel barcode. A Waybill reference only opens its manifest.</small></div>@error('identifier')<small>{{ $message }}</small>@enderror</div>
            <div class="field span-2 waybill-parcel-size-field">
                <label>Package size for the selected parcel <span>*</span></label>
                <small class="field-hint" id="waybill-parcel-size-help">Inspect the selected physical parcel and choose its size. Size is not inferred from contents, product imagery, dimensions, weight, or piece count.</small>
                <p class="waybill-parcel-size-status" data-waybill-parcel-size-status role="status" aria-live="polite">Load a Waybill manifest or scan a unique Parcel barcode.</p>
                <div class="waybill-parcel-size-list" data-waybill-parcel-size-list aria-describedby="waybill-parcel-size-help"></div>
                <script type="application/json" data-parcel-size-options>@json($parcelSizeClasses)</script>
                <script type="application/json" data-old-parcel-size-classes>@json(old('parcel_size_classes', []))</script>
            </div>
            <div class="field span-2"><label>Receiving sorting center <span>*</span></label><select name="sorting_center_id" required><option value="">Select sorting center</option>@foreach($sortingCenters as $center)<option value="{{ $center->id }}" @selected((string) old('sorting_center_id') === (string) $center->id)>{{ $center->name }} ({{ $center->code }})</option>@endforeach</select>@error('sorting_center_id')<small>{{ $message }}</small>@enderror</div>
        </div>
        <div class="waybill-camera-panel" data-waybill-camera-panel hidden><video data-waybill-camera-video playsinline muted></video><p class="waybill-camera-status" data-waybill-camera-status>Point the camera at the unique Code 128 Parcel barcode.</p><button type="button" class="button button-small" data-waybill-camera-close>Cancel camera</button></div>
        <div class="form-actions"><button class="button" type="button" data-modal-close>Cancel</button><button class="button button-primary" type="submit" @disabled($sortingCenters->isEmpty())>Log parcel</button></div>
    </form>
</section>
@endsection
