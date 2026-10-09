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
                Only approved and available riders
                assigned to the active facility and zone
                are eligible.
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

    <div class="table-wrap">
        <table class="data-table">
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
                            </span>
                        </td>

                        <td>
                            <strong>
                                {{ $zone['ready'] }}
                            </strong>
                            {{ Str::plural('parcel', $zone['ready']) }}
                        </td>

                        <td>
                            {{ count($zone['riders']) }}
                            eligible
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

                                <select
                                    class="filter-select"
                                    name="rider_profile_id"
                                    required
                                    @disabled(
                                        $zone['ready'] === 0
                                        || count($zone['riders']) === 0
                                    )
                                >
                                    <option value="">
                                        Choose eligible rider
                                    </option>

                                    @foreach($zone['riders'] as $rider)
                                        <option
                                            value="{{ $rider['id'] }}"
                                        >
                                            {{ $rider['name'] }}
                                            ·
                                            {{ $rider['remaining'] }}
                                            slots remaining
                                        </option>
                                    @endforeach
                                </select>
                            </form>
                        </td>

                        <td>
                            @if($zone['ready'] === 0)
                                <span>
                                    No ready parcels
                                </span>
                            @elseif(count($zone['riders']) === 0)
                                <span>
                                    Waiting for rider capacity
                                </span>
                            @else
                                <span>
                                    Ready for dispatch
                                </span>
                            @endif
                        </td>

                        <td>
                            <button
                                class="button button-small button-primary"
                                type="submit"
                                form="dispatch-zone-{{ $zone['id'] }}"
                                @disabled(
                                    $zone['ready'] === 0
                                    || count($zone['riders']) === 0
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