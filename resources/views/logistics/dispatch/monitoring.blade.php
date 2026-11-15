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

                    <th>Products</th>
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

                        <td class="dispatch-products-cell">
                            @include('partials.order-item-preview-list', ['items' => $delivery['products']])
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
                            colspan="9"
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
                            colspan="9"
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
        class="modal delivery-timeline-modal"
        data-modal="delivery-{{ $loop->index }}"
        hidden
        role="dialog"
        aria-modal="true"
        aria-labelledby="delivery-timeline-title-{{ $loop->index }}"
        aria-describedby="delivery-timeline-subtitle-{{ $loop->index }}"
    >
        <div class="modal-header delivery-timeline-header">
            <div class="delivery-timeline-heading">
                <span class="delivery-timeline-icon" aria-hidden="true">
                    <i data-lucide="package"></i>
                </span>

                <div class="delivery-timeline-heading-copy">
                    <p class="delivery-timeline-eyebrow">Dispatch monitoring</p>
                    <h3 id="delivery-timeline-title-{{ $loop->index }}">
                        {{ $delivery['id'] }} delivery timeline
                    </h3>
                    <p id="delivery-timeline-subtitle-{{ $loop->index }}">
                        {{ $delivery['rider'] }}
                        <span aria-hidden="true">·</span>
                        {{ $delivery['zone'] }}
                    </p>
                </div>
            </div>

            <button
                class="icon-button delivery-timeline-dismiss"
                type="button"
                data-modal-close
                aria-label="Close delivery timeline"
            >
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>

        <div class="modal-body delivery-timeline-body">
            <div class="activity-list delivery-timeline-list {{ count($delivery['timeline']) ? 'has-events' : 'is-empty' }}">
                @forelse(
                    $delivery['timeline']
                    as $event
                )
                    <article class="activity-item delivery-timeline-event {{ $event['label'] === 'Parcel barcode verified' ? 'is-verification' : '' }}">
                        <span class="activity-time delivery-timeline-time">
                            {{ $event['time'] }}
                        </span>

                        <span class="activity-dot delivery-timeline-marker" aria-hidden="true"></span>

                        <span class="activity-copy delivery-timeline-copy">
                            <strong>
                                {{ $event['label'] }}
                            </strong>

                            <small>
                                {{ $event['detail'] }}
                            </small>

                            @if(filled($event['verification_detail'] ?? null))
                                <small class="activity-verification">
                                    {{ $event['verification_detail'] }}
                                </small>
                            @endif

                            @if(filled($event['proof_url'] ?? null))
                                <a
                                    class="button button-small"
                                    href="{{ $event['proof_url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    View delivery proof
                                </a>
                            @endif
                        </span>
                    </article>
                @empty
                    <div class="empty-state">
                        <i data-lucide="clock" aria-hidden="true"></i>
                        No delivery activity has
                        been recorded yet.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="modal-footer delivery-timeline-footer">
            <button
                class="button delivery-timeline-close"
                type="button"
                data-modal-close
            >
                Close
            </button>
        </div>
    </section>
@endforeach
@endsection
