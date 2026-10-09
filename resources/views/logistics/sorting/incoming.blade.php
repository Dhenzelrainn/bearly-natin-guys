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
    <article class="metric-item"><span class="metric-icon"><i data-lucide="package-open"></i></span><span class="metric-copy"><small>Received today</small><strong>{{ $intakeMetrics['received_today'] }}</strong><span>From {{ $intakeMetrics['seller_pickups'] }} seller pickups</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="list-checks"></i></span><span class="metric-copy"><small>Awaiting sorting</small><strong>{{ $intakeMetrics['awaiting_sorting'] }}</strong><span>Ready for zone assignment</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="scale"></i></span><span class="metric-copy"><small>Total weight</small><strong>{{ $intakeMetrics['total_weight'] }} kg</strong><span>Today’s intake</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="triangle-alert"></i></span><span class="metric-copy"><small>Intake exceptions</small><strong>{{ $intakeMetrics['exceptions'] }}</strong><span>Requires inspection</span></span></article>
</section>
<section class="panel" data-table-scope>
    <div class="table-toolbar">
        <div class="search-field"><i data-lucide="search"></i><input type="search" data-table-search placeholder="Scan or search waybill, order, seller, or rider"></div>
        <select class="filter-select" data-filter-status><option value="">All intake statuses</option><option>Received</option><option>Sorted</option><option>Exception</option></select>
        <select class="filter-select" data-filter-zone>
            <option value="">All destinations</option>
            @foreach($incomingParcels->pluck('destination')->unique()->sort() as $destination)<option>{{ $destination }}</option>@endforeach
        </select>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th data-sort>Waybill / Order</th><th>Seller</th><th>Received from</th><th data-sort>Received at</th><th>Pieces</th><th>Weight</th><th>Destination</th><th>Status</th><th>Action</th></tr></thead>
            <tbody data-incoming-body>
                @foreach($incomingParcels as $parcel)
                    <tr data-row data-record-row data-record-type="incoming" data-record-id="{{ $parcel['waybill'] }}" data-status="{{ $parcel['status'] }}" data-zone="{{ $parcel['destination'] }}">
                        <td><span class="cell-title"><strong>{{ $parcel['waybill'] }}</strong><small>{{ $parcel['order'] }}</small></span></td>
                        <td>{{ $parcel['seller'] }}</td><td>{{ $parcel['rider'] }}</td><td>{{ $parcel['received'] }}</td><td>{{ $parcel['pieces'] }}</td><td>{{ $parcel['weight'] }}</td><td>{{ $parcel['destination'] }}</td>
                        <td><span class="status-badge" data-status-badge data-status="{{ $parcel['status'] }}">{{ $parcel['status'] }}</span></td>
                        <td><a class="button button-small" href="{{ route('logistics.sorting.center', ['search' => $parcel['waybill']]) }}">Open sorting</a></td>
                    </tr>
                @endforeach
                <tr><td class="empty-row" colspan="9" data-empty-row>No incoming parcels match these filters.</td></tr>
            </tbody>
        </table>
    </div>
</section>
<section class="modal" data-modal="log-parcel" @if(! $errors->hasAny(['identifier', 'sorting_center_id'])) hidden @endif>
    <div class="modal-header"><div><h3>Log incoming parcel</h3><p>Enter or scan a waybill at the intake desk.</p></div><button class="icon-button" type="button" data-modal-close><i data-lucide="x"></i></button></div>
    <form class="modal-body" method="POST" action="{{ route('logistics.sorting.incoming.receive') }}">
        @csrf
        <div class="field-grid">
            <div class="field span-2"><label>Waybill or barcode <span>*</span></label><input name="identifier" value="{{ old('identifier') }}" required maxlength="120" placeholder="Scan or enter the waybill number">@error('identifier')<small>{{ $message }}</small>@enderror</div>
            <div class="field span-2"><label>Receiving sorting center <span>*</span></label><select name="sorting_center_id" required><option value="">Select sorting center</option>@foreach($sortingCenters as $center)<option value="{{ $center->id }}" @selected((string) old('sorting_center_id') === (string) $center->id)>{{ $center->name }} ({{ $center->code }})</option>@endforeach</select>@error('sorting_center_id')<small>{{ $message }}</small>@enderror</div>
        </div>
        <div class="form-actions"><button class="button" type="button" data-modal-close>Cancel</button><button class="button button-primary" type="submit" @disabled($sortingCenters->isEmpty())>Log parcel</button></div>
    </form>
</section>
@endsection
