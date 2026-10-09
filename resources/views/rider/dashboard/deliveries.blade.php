@extends('rider.layouts.app')

@section('title', 'Items for Delivery')
@section('page-title', 'Items for Delivery')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">
            Active delivery assignments
        </p>

        <h2>
            Customer delivery assignments
        </h2>

        <p>
            Review real dispatch assignments released
            by your Logistics sorting center.
        </p>
    </div>

    <div class="page-actions">
        <span class="rider-availability">
            {{ $metrics['active_stops'] }}
            {{ Str::plural(
                'active stop',
                $metrics['active_stops']
            ) }}
        </span>

        <a
            class="button"
            href="{{ route('rider.dashboard.pickups') }}"
        >
            <i data-lucide="package-plus"></i>
            Pickup dashboard
        </a>
    </div>
</div>

<section class="metric-strip">
    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="package"></i>
        </span>

        <span class="metric-copy">
            <small>Assigned parcels</small>

            <strong>
                {{ $metrics['assigned_parcels'] }}
            </strong>

            <span>
                Waiting for delivery start
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="bike"></i>
        </span>

        <span class="metric-copy">
            <small>Out for delivery</small>

            <strong>
                {{ $metrics['out_for_delivery'] }}
            </strong>

            <span>
                Active parcel movements
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="banknote"></i>
        </span>

        <span class="metric-copy">
            <small>COD to collect</small>

            <strong>
                ₱{{ number_format(
                    $metrics['cod_minor'] / 100,
                    2
                ) }}
            </strong>

            <span>
                Across active delivery stops
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="map-pinned"></i>
        </span>

        <span class="metric-copy">
            <small>Active stops</small>

            <strong>
                {{ $metrics['active_stops'] }}
            </strong>

            <span>
                Shipment-level customer handoffs
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
                Delivery route
            </h3>

            <p>
                Assignments currently linked to
                your Rider account.
            </p>
        </div>
    </div>

    <div class="table-toolbar">
        <div class="search-field">
            <i data-lucide="search"></i>

            <input
                type="search"
                data-table-search
                placeholder="Search recipient, waybill, address, or zone"
            >
        </div>

        <select
            class="filter-select"
            data-filter-status
        >
            <option value="">
                All delivery stages
            </option>

            <option value="Assigned">
                Assigned
            </option>

            <option value="Out for Delivery">
                Out for Delivery
            </option>

            <option value="Delivery Failed">
                Delivery Failed
            </option>
        </select>
    </div>

    <div class="panel-body">
        <div class="job-grid">
            @forelse($deliveries as $delivery)
                <article
                    class="job-card"
                    data-row
                    data-status="{{ $delivery['status'] }}"
                >
                    <div class="job-card-head">
                        <div>
                            <h3>
                                {{ $delivery['customer'] }}
                            </h3>

                            <small>
                                {{ $delivery['id'] }}
                                ·
                                {{ $delivery['waybill'] }}
                            </small>
                        </div>

                        <span
                            class="status-badge"
                            data-status-badge
                            data-status="{{ $delivery['status'] }}"
                        >
                            {{ $delivery['status'] }}
                        </span>
                    </div>

                    <div class="job-card-body">
                        <div class="job-detail">
                            <i data-lucide="map-pin"></i>

                            <span>
                                {{ $delivery['address'] }}

                                <small>
                                    Zone
                                    {{ $delivery['zone'] }}
                                </small>
                            </span>
                        </div>

                        <div class="job-detail">
                            <i data-lucide="package"></i>

                            <span>
                                {{ $delivery['parcel_count'] }}
                                {{ Str::plural(
                                    'parcel',
                                    $delivery['parcel_count']
                                ) }}

                                <small>
                                    Dispatch
                                    {{ $delivery['batch_no'] }}
                                </small>
                            </span>
                        </div>

                        <div class="job-detail">
                            <i data-lucide="receipt"></i>

                            <span>
                                @if($delivery['cod_minor'] > 0)
                                    ₱{{ number_format(
                                        $delivery['cod_minor'] / 100,
                                        2
                                    ) }}
                                    COD
                                @else
                                    No COD collection
                                @endif

                                <small>
                                    Payment:
                                    {{
                                        str(
                                            $delivery['payment_status']
                                        )
                                            ->replace('_', ' ')
                                            ->title()
                                    }}
                                </small>
                            </span>
                        </div>
                    </div>

                    <div class="job-card-footer">
                        <a
                            class="button button-primary"
                            href="{{
                                route(
                                    'rider.orders.delivery',
                                    $delivery['id']
                                )
                            }}"
                        >
                            Open delivery
                        </a>
                    </div>
                </article>
            @empty
                <p class="empty-copy">
                    No delivery assignments are
                    currently assigned to you.
                </p>
            @endforelse
        </div>

        @if($deliveries->isNotEmpty())
            <p
                class="empty-copy"
                data-empty-cards
                hidden
            >
                No deliveries match the selected filters.
            </p>
        @endif
    </div>
</section>

<div
    class="content-grid equal"
    style="margin-top:20px"
>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Assignment source</h3>

                <p>
                    Delivery work is released by
                    Logistics after parcel sorting.
                </p>
            </div>
        </div>

        <div class="panel-body attention-list">
            <div class="attention-item">
                <i data-lucide="warehouse"></i>

                <span>
                    <strong>
                        Logistics dispatch
                    </strong>

                    <small>
                        Only database-backed dispatch
                        batches assigned to your Rider
                        profile appear here.
                    </small>
                </span>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Route checklist</h3>

                <p>
                    Review before starting delivery.
                </p>
            </div>
        </div>

        <div class="panel-body">
            @foreach([
                'Parcel count matches the assignment',
                'Recipient and contact details reviewed',
                'Phone is charged and online',
                'Vehicle is ready for the route',
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