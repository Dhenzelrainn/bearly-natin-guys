@extends('logistics.layouts.app')

@section('title', 'Delivery Assignment')
@section('page-title', 'Delivery Assignment')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">
            Dispatch control
        </p>

        <h2>
            Assign sorted parcels to riders
        </h2>

        <p>
            Match delivery zones with available riders
            and release ready parcel batches.
        </p>
        <p>
            Each dispatch records a planned work window for conflict checks;
            the existing dispatch action still starts immediately.
        </p>
    </div>

    <div class="page-actions">
        <a
            class="button"
            href="{{ route('logistics.dispatch.monitoring') }}"
        >
            <i data-lucide="map-pinned"></i>
            Monitor deliveries
        </a>
    </div>
</div>

<section class="metric-strip">
    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="package-check"></i>
        </span>

        <span class="metric-copy">
            <small>Ready to dispatch</small>

            <strong>
                {{ $metrics['ready'] }}
            </strong>

            <span>
                Across
                {{ $metrics['ready_zones'] }}
                {{ Str::plural('zone', $metrics['ready_zones']) }}
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="bike"></i>
        </span>

        <span class="metric-copy">
            <small>Available riders</small>

            <strong>
                {{ $metrics['available_riders'] }}
            </strong>

            <span>
                Approved and on duty
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="route"></i>
        </span>

        <span class="metric-copy">
            <small>Active routes</small>

            <strong>
                {{ $metrics['active_routes'] }}
            </strong>

            <span>
                Open dispatch batches
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="truck"></i>
        </span>

        <span class="metric-copy">
            <small>Parcels in transit</small>

            <strong>
                {{ $metrics['in_transit'] }}
            </strong>

            <span>
                Dispatched or out for delivery
            </span>
        </span>
    </article>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <h3>Zone dispatch board</h3>

            <p>
                Destination-zone Riders come first. If none can carry the full batch, eligible local Riders from another active zone at this facility may be selected with a reason.
            </p>
        </div>

        @if($dispatchCenter)
            <span class="status-badge is-success">
                {{ $dispatchCenter->code }}
            </span>
        @else
            <span class="status-badge">
                No active facility
            </span>
        @endif
    </div>

    <div
        class="table-wrap dispatch-board-wrap"
        role="region"
        aria-label="Zone dispatch assignments"
        tabindex="0"
    >
        <table class="data-table {{ $zones->isNotEmpty() ? 'dispatch-board-table' : '' }}">
            <thead>
                <tr>
                    <th>Zone / Area</th>
                    <th>Ready parcels</th>
                    <th>Eligible riders</th>
                    <th>Assign rider</th>
                    <th>Dispatch state</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                @forelse($zones as $zone)
                    <tr>
                        <td>
                            <span class="cell-title">
                                <strong>
                                    {{ $zone['zone'] }}
                                </strong>

                                <small>
                                    {{ $zone['area'] }}
                                </small>
                                <small class="dispatch-zone-coverage">
                                    {{ $zone['coverage'] }}
                                </small>
                            </span>
                        </td>

                        <td>
                            <strong>
                                {{ $zone['ready'] }}
                            </strong>
                            {{ Str::plural('parcel', $zone['ready']) }}
                            @if($zone['routing_issues'] > 0)
                                <small class="dispatch-routing-warning">
                                    {{ $zone['routing_issues'] }} {{ $zone['routing_issues'] === 1 ? 'parcel does' : 'parcels do' }} not match this zone and will be held for re-sorting.
                                </small>
                            @endif
                        </td>

                        <td>
                            {{ count($zone['riders']) }} in zone
                            @if(count($zone['nearby_riders']) > 0)
                                <small class="dispatch-proximity-count">+ {{ count($zone['nearby_riders']) }} nearby</small>
                            @endif
                        </td>

                        <td>
                            <form
                                id="dispatch-zone-{{ $zone['id'] }}"
                                method="POST"
                                action="{{ route(
                                    'logistics.dispatch.store',
                                    $zone['id']
                                ) }}"
                            >
                                @csrf

                                <div class="dispatch-assignment-form">
                                    <div class="dispatch-assignment-rider">
                                        <label for="dispatch-rider-{{ $zone['id'] }}">Assign rider</label>
                                        <select
                                            id="dispatch-rider-{{ $zone['id'] }}"
                                            class="filter-select"
                                            name="rider_profile_id"
                                            required
                                            @disabled(
                                                $zone['ready'] === 0
                                                || (count($zone['riders']) === 0 && count($zone['nearby_riders']) === 0)
                                            )
                                        >
                                            <option value="">
                                                Choose eligible rider
                                            </option>

                                            @if(count($zone['riders']) > 0)
                                                <optgroup label="Assigned to this zone">
                                                    @foreach($zone['riders'] as $rider)
                                                        <option value="{{ $rider['id'] }}">
                                                            {{ $rider['name'] }} · {{ $rider['specialization'] }} · {{ $rider['remaining'] }} slots remaining
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endif

                                            @if(count($zone['nearby_riders']) > 0)
                                                <optgroup label="Nearby-zone fallback · Logistics approval required">
                                                    @foreach($zone['nearby_riders'] as $rider)
                                                        <option value="{{ $rider['id'] }}" data-assignment-mode="nearby_zone">
                                                            {{ $rider['name'] }} · {{ $rider['zone_code'] }} · {{ $rider['remaining'] }} slots
                                                            @if($rider['distance_label'])
                                                                · {{ $rider['distance_label'] }}
                                                            @endif
                                                            · {{ $rider['gps_status_label'] }}
                                                            @if($rider['is_recommended'])
                                                                · Closest estimate
                                                            @endif
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                        </select>
                                    </div>

                                    @if(count($zone['nearby_riders']) > 0)
                                        <div class="dispatch-overflow-details">
                                            <p class="dispatch-proximity-note" role="status">{{ $zone['proximity_message'] }}</p>
                                            <label for="dispatch-reason-{{ $zone['id'] }}">Reason for nearby-zone assignment <span>(required when selecting a Rider from another zone)</span></label>
                                            <textarea id="dispatch-reason-{{ $zone['id'] }}" name="assignment_reason" rows="2" minlength="5" maxlength="1000" required aria-describedby="dispatch-reason-help-{{ $zone['id'] }}">{{ old('assignment_reason') }}</textarea>
                                            <small id="dispatch-reason-help-{{ $zone['id'] }}">Nearby assignments are one-time overflow; the Rider’s home zone will not change.</small>
                                            @error('assignment_reason')<small role="alert">{{ $message }}</small>@enderror
                                        </div>
                                    @elseif($zone['proximity_message'])
                                        <p class="dispatch-proximity-note" role="status">{{ $zone['proximity_message'] }}</p>
                                    @endif

                                    <div class="dispatch-window-fields">
                                        <div class="dispatch-window-field">
                                            <label for="dispatch-window-start-{{ $zone['id'] }}">Planned start</label>
                                            <input
                                                id="dispatch-window-start-{{ $zone['id'] }}"
                                                type="datetime-local"
                                                name="planned_window_start"
                                                min="{{ now()->format('Y-m-d\TH:i') }}"
                                                value="{{ old('planned_window_start') }}"
                                                required
                                                @disabled($zone['ready'] === 0 || (count($zone['riders']) === 0 && count($zone['nearby_riders']) === 0))
                                            >
                                            @error('planned_window_start')<small role="alert">{{ $message }}</small>@enderror
                                        </div>

                                        <div class="dispatch-window-field">
                                            <label for="dispatch-window-end-{{ $zone['id'] }}">Planned end</label>
                                            <input
                                                id="dispatch-window-end-{{ $zone['id'] }}"
                                                type="datetime-local"
                                                name="planned_window_end"
                                                min="{{ now()->format('Y-m-d\TH:i') }}"
                                                value="{{ old('planned_window_end') }}"
                                                required
                                                @disabled($zone['ready'] === 0 || (count($zone['riders']) === 0 && count($zone['nearby_riders']) === 0))
                                            >
                                            @error('planned_window_end')<small role="alert">{{ $message }}</small>@enderror
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </td>

                        <td>
                            @if($zone['ready'] === 0 && $zone['routing_issues'] > 0)
                                <span class="dispatch-routing-warning">No matching parcels ready</span>
                            @elseif($zone['ready'] === 0)
                                <span>No ready parcels</span>
                            @elseif(count($zone['riders']) === 0 && count($zone['nearby_riders']) > 0)
                                <span>Nearby Rider available · reason required</span>
                            @elseif(count($zone['riders']) === 0)
                                <span>
                                    No eligible Rider with capacity at this Sorting Center
                                </span>
                            @else
                                <span>Ready for dispatch</span>
                            @endif
                        </td>

                        <td>
                            <button
                                class="button button-small button-primary"
                                type="submit"
                                form="dispatch-zone-{{ $zone['id'] }}"
                                @disabled(
                                    $zone['ready'] === 0
                                    || (count($zone['riders']) === 0 && count($zone['nearby_riders']) === 0)
                                )
                            >
                                Assign & dispatch
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td
                            class="empty-row"
                            colspan="6"
                        >
                            No active sorting zones are
                            available for dispatch.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div
    class="content-grid equal"
    style="margin-top:20px"
>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Rider capacity</h3>

                <p>
                    Current parcel load across active
                    dispatch batches.
                </p>
            </div>
        </div>

        <div class="panel-body attention-list">
            @forelse($riderCapacities as $rider)
                @php
                    $percentage =
                        $rider['capacity'] > 0
                            ? min(
                                100,
                                round(
                                    $rider['load']
                                    / $rider['capacity']
                                    * 100
                                )
                            )
                            : 0;
                @endphp

                <div class="attention-item">
                    <i data-lucide="bike"></i>

                    <span>
                        <strong>
                            {{ $rider['name'] }}
                        </strong>

                        <small>
                            {{ $rider['load'] }}
                            of
                            {{ $rider['capacity'] }}
                            parcel slots assigned
                        </small>
                    </span>

                    <div class="progress-track">
                        <span
                            style="width:{{ $percentage }}%"
                        ></span>
                    </div>
                </div>
            @empty
                <div class="empty-state">
                    No available riders are assigned
                    to this facility.
                </div>
            @endforelse
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Dispatch checklist</h3>

                <p>
                    Operational checks before releasing
                    a batch.
                </p>
            </div>
        </div>

        <div class="panel-body">
            @foreach([
                'Parcel count matches the dispatch manifest',
                'Rider identity and vehicle verified',
                'Delivery route and exceptions reviewed',
                'Rider has acknowledged the assignment',
            ] as $item)
                <label class="toggle-row">
                    <span class="toggle-copy">
                        <strong>
                            {{ $item }}
                        </strong>
                    </span>

                    <span class="switch">
                        <input type="checkbox">
                        <span></span>
                    </span>
                </label>
            @endforeach
        </div>
    </section>
</div>
@endsection
