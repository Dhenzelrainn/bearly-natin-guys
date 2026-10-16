@extends('logistics.layouts.app')

@section('title', 'Sorting Center')
@section('page-title', 'Sorting Center')

@section('content')
@php($zoneRules = $editingZone['destination_rules'] ?? [])
<div class="page-header">
    <div>
        <p class="page-kicker">Parcel routing</p>
        <h2>Sort parcels by delivery zone</h2>
                <p>Set barangay coverage for your city’s Sorting Center, then route parcels only to zones that cover their destination.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="{{ route('logistics.sorting.incoming') }}">
            <i data-lucide="package-open"></i>View intake ledger
        </a>
        <a class="button button-primary" href="#delivery-zones">
            <i data-lucide="map-pin-plus"></i>Manage delivery zones
        </a>
    </div>
</div>

<section class="metric-strip sorting-metric-strip" aria-label="Sorting metrics">
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="inbox"></i></span>
        <span class="metric-copy"><small>Unsorted queue</small><strong>{{ $sortingMetrics['unsorted'] }}</strong><span>Waiting for zone assignment</span></span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="circle-check-big"></i></span>
        <span class="metric-copy"><small>Sorted today</small><strong>{{ $sortingMetrics['sorted_today'] }}</strong><span>Recorded sorting events</span></span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="map"></i></span>
        <span class="metric-copy"><small>Active zones</small><strong>{{ $sortingMetrics['active_zones'] }}</strong><span>Across active centers</span></span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="map-pin-off"></i></span>
        <span class="metric-copy"><small>No matching zone</small><strong>{{ $sortingMetrics['unmatched'] }}</strong><span>Coverage must be configured</span></span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="triangle-alert"></i></span>
        <span class="metric-copy"><small>Exceptions</small><strong>{{ $sortingMetrics['exceptions'] }}</strong><span>Inspection required</span></span>
    </article>
</section>

<section class="panel zone-management" id="delivery-zones" aria-labelledby="zone-management-title">
    <div class="panel-header">
        <div>
            <h3 id="zone-management-title">Delivery coverage zones</h3>
            <p>Each selected barangay gets its own zone. Archived zones are inactive; restoring one activates it again after its coverage is checked.</p>
        </div>
    </div>

    @if($errors->has('status'))
        <div class="zone-management-error" role="alert">{{ $errors->first('status') }}</div>
    @endif

    @if($zoneView === 'current')
        <details class="zone-create-details" @if($editingZone || $errors->has('barangays') || $errors->has('city_code')) open @endif>
            <summary>
                <span>
                    <strong>{{ $editingZone ? 'Edit zone coverage' : 'Add delivery zones' }}</strong>
                    <small>{{ $editingZone ? 'Update barangay coverage for this zone.' : 'Create one zone per selected barangay.' }}</small>
                </span>
                <span class="zone-create-summary-action">{{ $editingZone ? 'Editing' : 'Add zones' }}</span>
            </summary>
        <form
            method="POST"
            action="{{ $editingZone ? route('logistics.sorting.zones.update', $editingZone['id']) : route('logistics.sorting.zones.store') }}"
            class="zone-create-form"
            data-sorting-zone-form
            data-barangays-url="{{ url('/api/psgc/cities') }}"
            data-city-code="{{ $profile->psgc_city_code ?? '' }}"
        >
            @csrf
            @if($editingZone)@method('PATCH')@endif
            <script type="application/json" data-zone-old-barangays>@json(old('barangays', $zoneRules['barangays'] ?? []))</script>
            <div>
                <h4>{{ $editingZone ? 'Edit zone coverage' : 'Create delivery zones' }}</h4>
                <p class="zone-form-intro">
                    @if($editingZone)
                        Updating {{ $editingZone['code'] }} changes its generated name. Active dispatches and non-final parcels must be cleared first; Rider assignments refresh automatically.
                    @else
                        Each selected barangay creates a separate zone, with its own generated code and name. Select several to create them together.
                    @endif
                </p>
            </div>

            <div class="field-grid">
                <div class="field span-2">
                    <label for="zone-sorting-center">Your Sorting Center</label>
                    <input id="zone-sorting-center" type="text" value="{{ $zoneManagementCenters->first()['name'] ?? 'Not available' }}" readonly>
                    <small class="field-hint">Zones are automatically saved to your account’s single Sorting Center.</small>
                </div>

            <div class="field">
                <label>Province</label>
                <input type="text" value="{{ $serviceAddress?->province ?? 'Not configured' }}" readonly>
            </div>

            <div class="field">
                <label>Municipality / city</label>
                <input type="text" value="{{ $serviceAddress?->city_municipality ?? 'Not configured' }}" readonly>
                <input type="hidden" name="city_code" value="{{ $profile->psgc_city_code ?? '' }}">
                @error('city_code')<small class="form-error">{{ $message }}</small>@enderror
            </div>

                <div class="field span-2">
                    <label for="zone-barangay-search">{{ $editingZone ? 'Barangays covered by this zone' : 'Barangays to create zones for' }}</label>
                    <p class="field-hint" id="zone-barangay-help">
                        @if($editingZone)
                            Update the barangay coverage for this zone.
                        @else
                            Select one or more barangays. A separate zone will be created for each selection.
                        @endif
                    </p>
                    <input
                        id="zone-barangay-search"
                        type="search"
                        placeholder="Search barangays"
                        autocomplete="off"
                        data-zone-barangay-search
                        disabled
                        aria-describedby="zone-barangay-help zone-barangay-count"
                    >
                    <div class="zone-barangay-list" data-zone-barangays role="group" aria-label="Covered barangays" aria-live="polite">
                        <span class="zone-location-empty">Loading your municipality’s barangays…</span>
                    </div>
                    <small id="zone-barangay-count" class="field-hint" data-zone-barangay-count aria-live="polite">No barangays selected</small>
                    @foreach($errors->getMessages() as $key => $messages)
                        @if($key === 'barangays' || str_starts_with($key, 'barangays.'))
                            <small class="form-error">{{ $messages[0] }}</small>
                        @endif
                    @endforeach
                </div>
            </div>

            <p class="zone-location-status" data-zone-location-status role="status" aria-live="polite">Loading official location list…</p>
            <div class="zone-create-actions">
                <button class="button button-primary" type="submit" data-zone-submit disabled>
                    <i data-lucide="{{ $editingZone ? 'save' : 'plus' }}"></i>{{ $editingZone ? 'Save coverage' : 'Create zones' }}
                </button>
                @if($editingZone)
                    <a class="button" href="{{ route('logistics.sorting.center') }}">Cancel</a>
                @endif
            </div>
        </form>
        </details>
    @endif

    <nav class="zone-view-tabs" aria-label="Zone lists">
        <a href="{{ route('logistics.sorting.center', ['zone_view' => 'current']) }}" @class(['is-active' => $zoneView === 'current']) @if($zoneView === 'current') aria-current="page" @endif>
            Current <span>{{ $zoneCounts['current'] }}</span>
        </a>
        <a href="{{ route('logistics.sorting.center', ['zone_view' => 'archived']) }}" @class(['is-active' => $zoneView === 'archived']) @if($zoneView === 'archived') aria-current="page" @endif>
            Archived <span>{{ $zoneCounts['archived'] }}</span>
        </a>
    </nav>

    <form class="zone-filter-form" method="GET" action="{{ route('logistics.sorting.center') }}">
        <input type="hidden" name="zone_view" value="{{ $zoneView }}">
        <label class="zone-search-field">
            <span class="sr-only">Search zones</span>
            <i data-lucide="search" aria-hidden="true"></i>
            <input type="search" name="zone_search" value="{{ $zoneSearch }}" placeholder="Search zone, code, or barangay">
        </label>
        @if($zoneView === 'current')
            <label class="zone-status-filter">
                <span class="sr-only">Filter current zones by status</span>
                <select name="zone_status" class="filter-select">
                    <option value="">All statuses</option>
                    <option value="active" @selected($zoneStatus === 'active')>Active</option>
                    <option value="inactive" @selected($zoneStatus === 'inactive')>Inactive</option>
                </select>
            </label>
        @endif
        <button class="button button-small" type="submit">Filter</button>
        <a class="button button-small" href="{{ route('logistics.sorting.center', ['zone_view' => $zoneView]) }}">Reset</a>
    </form>

    @php($visibleZones = collect($zoneManagementCenters)->flatMap(fn ($center) => $center['zones']))
    <div class="zone-list" aria-label="{{ $zoneView === 'archived' ? 'Archived delivery zones' : 'Current delivery zones' }}">
        @forelse($visibleZones as $zone)
            <article class="zone-list-item">
                    <div class="zone-list-details">
                        <strong>{{ $zone['name'] }}</strong>
                        <span class="zone-code">{{ $zone['code'] }}</span>
                        @if($zoneView === 'archived')
                            <span class="status-badge is-muted">Archived</span>
                        @else
                            <span class="status-badge {{ $zone['status'] === 'active' ? 'is-success' : 'is-muted' }}">{{ ucfirst($zone['status']) }}</span>
                        @endif
                        <small>{{ $zone['coverage'] }}</small>
                    </div>
                    <div class="zone-list-actions">
                        @if($zoneView === 'archived')
                            <form method="POST" action="{{ route('logistics.sorting.zones.restore', $zone['id']) }}" data-zone-restore-form>
                                @csrf
                                @method('PATCH')
                                <button class="button button-small button-primary" type="submit">Unarchive</button>
                            </form>
                            <form method="POST" action="{{ route('logistics.sorting.zones.destroy', $zone['id']) }}" data-zone-delete-form>
                                @csrf
                                @method('DELETE')
                                <button class="button button-small button-danger" type="submit" @disabled(! $zone['can_permanently_delete']) @if(! $zone['can_permanently_delete']) title="Clear non-final parcels and active dispatch batches before deleting this zone." @endif>Delete permanently</button>
                            </form>
                        @else
                            <a class="button button-small" href="{{ route('logistics.sorting.center', ['edit_zone' => $zone['id']]) }}">Edit coverage</a>
                            <form method="POST" action="{{ route('logistics.sorting.zones.archive', $zone['id']) }}" data-zone-archive-form>
                                @csrf
                                @method('PATCH')
                                <button class="button button-small" type="submit">Archive</button>
                            </form>
                        @endif
                    </div>
                    @if($zoneView === 'archived' && ! $zone['can_permanently_delete'])
                        <p class="zone-delete-hint">Clear non-final parcels and active dispatch batches before permanent deletion. Historical records will remain, but their zone reference will be removed.</p>
                    @endif
            </article>
        @empty
            <p class="zone-list-empty">
                @if($zoneView === 'archived')
                    No archived zones match this search.
                @else
                    No current zones match these filters. Add a zone to begin routing parcels.
                @endif
            </p>
        @endforelse
    </div>

    @if($zonePaginator->hasPages())
        <nav class="zone-pagination" aria-label="Zone pagination">
            <span>Showing {{ $zonePaginator->firstItem() }}–{{ $zonePaginator->lastItem() }} of {{ $zonePaginator->total() }}</span>
            <div>
                @if($zonePaginator->onFirstPage())
                    <span class="is-disabled" aria-disabled="true">Previous</span>
                @else
                    <a href="{{ $zonePaginator->previousPageUrl() }}">Previous</a>
                @endif
                <span class="zone-pagination-current">Page {{ $zonePaginator->currentPage() }} of {{ $zonePaginator->lastPage() }}</span>
                @if($zonePaginator->hasMorePages())
                    <a href="{{ $zonePaginator->nextPageUrl() }}">Next</a>
                @else
                    <span class="is-disabled" aria-disabled="true">Next</span>
                @endif
            </div>
        </nav>
    @endif
</section>

<section class="panel" data-table-scope>
    <div class="table-toolbar">
        <div class="search-field"><i data-lucide="search"></i><input type="search" data-table-search value="{{ request('search') }}" placeholder="Search waybill, seller, or destination" aria-label="Search parcels"></div>
        <select class="filter-select" data-filter-status aria-label="Filter by parcel status">
            <option value="">All statuses</option>
            <option>Received</option>
            <option>Sorted</option>
            <option>Exception</option>
        </select>
        <select class="filter-select" data-filter-zone aria-label="Filter by assigned zone">
            <option value="">All zones</option>
            @foreach($sortingZones as $zone)
                <option value="{{ $zone['code'] }}">{{ $zone['code'] }}</option>
            @endforeach
        </select>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th data-sort>Waybill / Parcel</th><th>Seller</th><th>Destination</th><th>Package</th><th>Matching zones</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
                @foreach($parcels as $parcel)
                    <tr data-row data-record-row data-record-type="sorting" data-record-id="{{ $parcel['parcel_no'] }}" data-status="{{ $parcel['status'] }}" data-zone="{{ $parcel['zone'] }}">
                        <td><span class="cell-title"><strong>{{ $parcel['waybill'] }}</strong><small>{{ $parcel['parcel_no'] }}</small></span></td>
                        <td><span class="cell-title"><strong>{{ $parcel['seller'] }}</strong><small>{{ $parcel['purpose_label'] }}</small></span></td>
                        <td>
                            @if($parcel['destination'])<span class="cell-title"><strong>{{ $parcel['destination'] }}</strong><small>{{ $parcel['destination_area'] }}</small></span>
                            @else{{ $parcel['destination_area'] }}@endif
                        </td>
                        <td>{{ $parcel['size'] }}</td>
                        <td>
                            @if($parcel['can_sort'])
                                <form id="sort-parcel-{{ $parcel['id'] }}" method="POST" action="{{ route('logistics.sorting.parcels.update', $parcel['id']) }}">
                                    @csrf
                                    @method('PATCH')
                                    <select class="filter-select sorting-zone-select" name="sorting_zone_id" required aria-label="Matching zone for {{ $parcel['parcel_no'] }}">
                                        <option value="">Select matching zone</option>
                                        @foreach($parcel['zones'] as $zone)
                                            <option value="{{ $zone['id'] }}" @selected((int) $parcel['zone_id'] === (int) $zone['id'])>{{ $zone['code'] }} — {{ $zone['name'] }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            @else
                                <span class="zone-routing-note {{ $parcel['has_matching_zone'] ? '' : 'is-unmatched' }}">
                                    {{ $parcel['status'] === 'Exception' ? 'Inspection required' : 'No matching zone' }}
                                </span>
                            @endif
                        </td>
                        <td><span class="status-badge {{ $parcel['has_matching_zone'] ? '' : 'is-warning' }}" data-status-badge data-status="{{ $parcel['status'] }}">{{ $parcel['status_label'] }}</span></td>
                        <td>
                            @if($parcel['can_sort'])
                                <button class="button button-small button-primary" type="submit" form="sort-parcel-{{ $parcel['id'] }}">{{ $parcel['status'] === 'Sorted' ? 'Update zone' : 'Mark sorted' }}</button>
                            @elseif(! $parcel['has_matching_zone'] && $parcel['status'] !== 'Exception')
                                <a class="button button-small" href="#delivery-zones">Review coverage</a>
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
