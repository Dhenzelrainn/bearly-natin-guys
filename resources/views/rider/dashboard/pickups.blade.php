@extends('rider.layouts.app')

@section('title', 'Pickup Queue')
@section('page-title', 'Items for Pickup')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">
            Rider pickup queue
        </p>

        <h2>
            Assigned seller pickups
        </h2>

        <p>
            Review pickup jobs assigned to you by your
            Logistics provider.
        </p>
    </div>

    <div class="page-actions">
        <a
            class="button"
            href="{{ route('rider.dashboard.pickups') }}"
        >
            <i data-lucide="refresh-cw"></i>
            Refresh queue
        </a>
    </div>
</div>

<section class="metric-strip">
    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="package-check"></i>
        </span>

        <span class="metric-copy">
            <small>Assigned</small>

            <strong>
                {{ $pickupMetrics['assigned'] }}
            </strong>

            <span>
                Waiting for your response
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="bike"></i>
        </span>

        <span class="metric-copy">
            <small>In progress</small>

            <strong>
                {{ $pickupMetrics['in_progress'] }}
            </strong>

            <span>
                Accepted or already collected
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="boxes"></i>
        </span>

        <span class="metric-copy">
            <small>Expected parcels</small>

            <strong>
                {{ $pickupMetrics['parcels'] }}
            </strong>

            <span>
                Across active pickup assignments
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="calendar-clock"></i>
        </span>

        <span class="metric-copy">
            <small>Due today</small>

            <strong>
                {{ $pickupMetrics['due_today'] }}
            </strong>

            <span>
                Pickup requests scheduled today
            </span>
        </span>
    </article>
</section>

<section
    class="panel"
    data-table-scope
>
    <div class="panel-header">
        <div>
            <h3>
                Pickup assignments
            </h3>

            <p>
                Only jobs assigned to your Rider account
                are shown here.
            </p>
        </div>
    </div>

    <div class="table-toolbar">
        <div class="search-field">
            <i data-lucide="search"></i>

            <input
                type="search"
                data-table-search
                placeholder="Search seller, pickup number, or address"
            >
        </div>

        <select
            class="filter-select"
            data-filter-status
        >
            <option value="">
                All job states
            </option>

            <option value="assigned">
                Assigned
            </option>

            <option value="accepted">
                Accepted
            </option>

            <option value="arrived">
                Arrived
            </option>

            <option value="picked_up">
                Picked Up
            </option>
        </select>
    </div>

    <div class="panel-body">
        @if($pickups->isEmpty())
            <p class="empty-copy">
                No active pickup assignments.
            </p>
        @else
            <div class="job-grid">
                @foreach($pickups as $pickup)
                    <article
                        class="job-card"
                        data-row
                        data-status="{{ $pickup['status_raw'] }}"
                    >
                        <div class="job-card-head">
                            <div>
                                <h3>
                                    {{ $pickup['seller'] }}
                                </h3>

                                <small>
                                    {{ $pickup['id'] }}
                                </small>
                            </div>

                            <span
                                class="status-badge"
                                data-status="{{ $pickup['status_raw'] }}"
                            >
                                {{ $pickup['status'] }}
                            </span>
                        </div>

                        <div class="job-card-body">
                            <div class="job-detail">
                                <i data-lucide="map-pin"></i>

                                <span>
                                    {{ $pickup['address'] }}

                                    <small>
                                        Seller pickup address
                                    </small>
                                </span>
                            </div>

                            <div class="job-detail">
                                <i data-lucide="boxes"></i>

                                <span>
                                    {{ $pickup['parcels'] }}
                                    {{ Str::plural('parcel', $pickup['parcels']) }}

                                    <small>
                                        Physical packages in this request
                                    </small>
                                </span>
                            </div>

                            <div class="job-detail">
                                <i data-lucide="clock"></i>

                                <span>
                                    {{ $pickup['window'] }}

                                    <small>
                                        Requested pickup window
                                    </small>
                                </span>
                            </div>
                        </div>

                        <div class="job-card-footer">
                            <a
                                class="button button-primary"
                                href="{{ route(
                                    'rider.orders.pickup',
                                    $pickup['id']
                                ) }}"
                            >
                                Review pickup
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
@endsection