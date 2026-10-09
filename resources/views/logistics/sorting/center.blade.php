@extends('logistics.layouts.app')
@section('title','Sorting Center')
@section('page-title','Sorting Center')
@section('content')
<div class="page-header">
    <div><p class="page-kicker">Parcel routing</p><h2>Sort parcels by delivery zone</h2><p>Validate destinations, group packages, and flag exceptions before dispatch assignment.</p></div>
    <div class="page-actions">
        <a class="button" href="{{ route('logistics.sorting.incoming') }}"><i data-lucide="package-open"></i>View intake ledger</a>
        <button class="button button-primary" type="button" disabled title="Batch sorting will be enabled after dispatch batching is connected."><i data-lucide="layers-3"></i>Batch sort</button>
    </div>
</div>
<section class="metric-strip">
    <article class="metric-item"><span class="metric-icon"><i data-lucide="inbox"></i></span><span class="metric-copy"><small>Unsorted queue</small><strong>{{ $sortingMetrics['unsorted'] }}</strong><span>Waiting for zone assignment</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="circle-check-big"></i></span><span class="metric-copy"><small>Sorted today</small><strong>{{ $sortingMetrics['sorted_today'] }}</strong><span>Recorded sorting events</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="map"></i></span><span class="metric-copy"><small>Active zones</small><strong>{{ $sortingMetrics['active_zones'] }}</strong><span>Across active centers</span></span></article>
    <article class="metric-item"><span class="metric-icon"><i data-lucide="triangle-alert"></i></span><span class="metric-copy"><small>Exceptions</small><strong>{{ $sortingMetrics['exceptions'] }}</strong><span>Destination review needed</span></span></article>
</section>
<section class="panel" data-table-scope>
    <div class="table-toolbar">
        <div class="search-field"><i data-lucide="search"></i><input type="search" data-table-search value="{{ request('search') }}" placeholder="Search waybill, seller, or destination"></div>
        <select class="filter-select" data-filter-status><option value="">All statuses</option><option>Received</option><option>Sorted</option><option>Exception</option></select>
        <select class="filter-select" data-filter-zone><option value="">All zones</option>@foreach($sortingZones as $zone)<option value="{{ $zone->code }}">{{ $zone->code }}</option>@endforeach</select>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th data-sort>Waybill / Parcel</th><th>Seller</th><th>Destination</th><th>Package</th><th>Assign zone</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                @foreach($parcels as $parcel)
                    <tr data-row data-record-row data-record-type="sorting" data-record-id="{{ $parcel['parcel_no'] }}" data-status="{{ $parcel['status'] }}" data-zone="{{ $parcel['zone'] }}">
                        <td><span class="cell-title"><strong>{{ $parcel['waybill'] }}</strong><small>{{ $parcel['parcel_no'] }}</small></span></td>
                        <td>{{ $parcel['seller'] }}</td><td>{{ $parcel['destination'] }}</td><td>{{ $parcel['size'] }}</td>
                        <td>
                            @if($parcel['can_sort'])
                                <form id="sort-parcel-{{ $parcel['id'] }}" method="POST" action="{{ route('logistics.sorting.parcels.update', $parcel['id']) }}">
                                    @csrf
                                    @method('PATCH')
                                    <select class="filter-select" name="sorting_zone_id" required aria-label="Zone for {{ $parcel['parcel_no'] }}">
                                        <option value="">Select zone</option>
                                        @foreach($parcel['zones'] as $zone)<option value="{{ $zone['id'] }}" @selected((int) $parcel['zone_id'] === (int) $zone['id'])>{{ $zone['code'] }} — {{ $zone['name'] }}</option>@endforeach
                                    </select>
                                </form>
                            @else
                                <span>{{ $parcel['zone'] ?: 'Review required' }}</span>
                            @endif
                        </td>
                        <td><span class="status-badge" data-status-badge data-status="{{ $parcel['status'] }}">{{ $parcel['status'] }}</span></td>
                        <td>
                            @if($parcel['can_sort'])
                                <button class="button button-small button-primary" type="submit" form="sort-parcel-{{ $parcel['id'] }}">{{ $parcel['status'] === 'Sorted' ? 'Update zone' : 'Mark sorted' }}</button>
                            @else
                                <span>Inspection required</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr><td class="empty-row" colspan="7" data-empty-row>No parcels match these filters.</td></tr>
            </tbody>
        </table>
    </div>
</section>
@if(request('search'))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelector('[data-table-search]')?.dispatchEvent(new Event('input'));
        });
    </script>
@endif
@endsection
