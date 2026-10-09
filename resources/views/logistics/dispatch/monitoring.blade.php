@extends('logistics.layouts.app')

@section('title', 'Delivery Monitoring')
@section('page-title', 'Delivery Monitoring')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">
            Live operations
        </p>

        <h2>
            Track active delivery runs
        </h2>

        <p>
            Follow provider-owned dispatch batches
            from rider assignment through delivery
            completion or exception handling.
        </p>
    </div>

    <div class="page-actions">
        <button
            class="button"
            type="button"
            onclick="location.reload()"
        >
            <i data-lucide="refresh-cw"></i>
            Refresh
        </button>
    </div>
</div>

<section class="metric-strip">
    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="user-round-check"></i>
        </span>

        <span class="metric-copy">
            <small>Assigned</small>

            <strong>
                {{ $metrics['assigned'] }}
            </strong>

            <span>
                Waiting for delivery progress
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="truck"></i>
        </span>

        <span class="metric-copy">
            <small>Out for delivery</small>

            <strong>
                {{ $metrics['out_for_delivery_parcels'] }}
            </strong>

            <span>
                Across
                {{ $metrics['out_for_delivery_routes'] }}
                {{ Str::plural(
                    'active route',
                    $metrics['out_for_delivery_routes']
                ) }}
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="circle-check-big"></i>
        </span>

        <span class="metric-copy">
            <small>Delivered today</small>

            <strong>
                {{ $metrics['delivered_today'] }}
            </strong>

            <span>
                Completed parcels today
            </span>
        </span>
    </article>

    <article class="metric-item">
        <span class="metric-icon">
            <i data-lucide="triangle-alert"></i>
        </span>

        <span class="metric-copy">
            <small>Exceptions</small>

            <strong>
                {{ $metrics['exceptions'] }}
            </strong>

            <span>
                Failed or exceptional parcels
            </span>
        </span>
    </article>
</section>

<section
    class="panel"
    data-table-scope
>
    <div class="table-toolbar">
        <div class="search-field">
            <i data-lucide="search"></i>

            <input
                type="search"
                data-table-search
                placeholder="Search dispatch, rider, zone, or update"
            >
        </div>

        <select
            class="filter-select"
            data-filter-status
        >
            <option value="">
                All stages
            </option>

            <option value="ASSIGNED_TO_RIDER">
                Assigned
            </option>

            <option value="OUT_FOR_DELIVERY">
                Out for Delivery
            </option>

            <option value="DELIVERED">
                Delivered
            </option>

            <option value="DELIVERY_FAILED">
                Exception / Failed
            </option>
        </select>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th data-sort>
                        Dispatch
                    </th>

                    <th>Rider</th>
                    <th>Zone</th>
                    <th>Parcels</th>
                    <th>Completion</th>
                    <th>Current stage</th>
                    <th>Last update</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                @forelse($deliveries as $delivery)
                    <tr
                        data-row
                        data-status="{{ $delivery['status'] }}"
                    >
                        <td>
                            <strong>
                                {{ $delivery['id'] }}
                            </strong>
                        </td>

                        <td>
                            {{ $delivery['rider'] }}
                        </td>

                        <td>
                            <span class="cell-title">
                                <strong>
                                    {{ $delivery['zone'] }}
                                </strong>

                                @if($delivery['area'])
                                    <small>
                                        {{ $delivery['area'] }}
                                    </small>
                                @endif
                            </span>
                        </td>

                        <td>
                            {{ $delivery['parcels'] }}
                        </td>

                        <td>
                            <div class="row-actions">
                                <div class="progress-track">
                                    <span
                                        style="width:{{ $delivery['progress'] }}%"
                                    ></span>
                                </div>

                                <small>
                                    {{ $delivery['progress'] }}%
                                </small>
                            </div>
                        </td>

                        <td>
                            <span
                                class="status-badge"
                                data-status-badge
                                data-status="{{ $delivery['status'] }}"
                            >
                                {{
                                    str($delivery['status'])
                                        ->replace('_', ' ')
                                        ->title()
                                }}
                            </span>
                        </td>

                        <td>
                            {{ $delivery['last'] }}
                        </td>

                        <td>
                            <button
                                class="button button-small"
                                type="button"
                                data-modal-open="delivery-{{ $loop->index }}"
                            >
                                View timeline
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td
                            class="empty-row"
                            colspan="8"
                        >
                            No dispatch batches are
                            available for monitoring.
                        </td>
                    </tr>
                @endforelse

                @if($deliveries->isNotEmpty())
                    <tr>
                        <td
                            class="empty-row"
                            colspan="8"
                            data-empty-row
                        >
                            No delivery runs match
                            these filters.
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</section>

@foreach($deliveries as $delivery)
    <section
        class="modal"
        data-modal="delivery-{{ $loop->index }}"
        hidden
    >
        <div class="modal-header">
            <div>
                <h3>
                    {{ $delivery['id'] }}
                    delivery timeline
                </h3>

                <p>
                    {{ $delivery['rider'] }}
                    ·
                    {{ $delivery['zone'] }}
                </p>
            </div>

            <button
                class="icon-button"
                type="button"
                data-modal-close
            >
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="modal-body">
            <div class="activity-list">
                @forelse(
                    $delivery['timeline']
                    as $event
                )
                    <div class="activity-item">
                        <span class="activity-time">
                            {{ $event['time'] }}
                        </span>

                        <span class="activity-dot"></span>

                        <span class="activity-copy">
                            <strong>
                                {{ $event['label'] }}
                            </strong>

                            <small>
                                {{ $event['detail'] }}
                            </small>
                        </span>
                    </div>
                @empty
                    <div class="empty-state">
                        No delivery activity has
                        been recorded yet.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="modal-footer">
            <button
                class="button"
                type="button"
                data-modal-close
            >
                Close
            </button>
        </div>
    </section>
@endforeach
@endsection