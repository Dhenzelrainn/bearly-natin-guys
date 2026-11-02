@extends('logistics.layouts.app')
@section('title','Dashboard')
@section('page-title','Dashboard')
@section('content')
<div class="page-header">
    <div><p class="page-kicker">Operations overview</p><h2>{{ $greeting }}, {{ explode(' ', $operator['name'])[0] }}</h2><p>Monitor rider approvals, parcel movement, sorting progress, and dispatch readiness.</p></div>
    <div class="page-actions"><a class="button" href="{{ route('logistics.sorting.incoming') }}"><i data-lucide="scan-line"></i>Log incoming parcel</a><a class="button button-primary" href="{{ route('logistics.dispatch.index') }}"><i data-lucide="send"></i>Open dispatch board</a></div>
</div>
<section class="panel" style="margin-bottom:20px" aria-labelledby="dashboard-period-title">
    <div class="panel-header">
        <div>
            <h3 id="dashboard-period-title">Dashboard period</h3>
            <p id="dashboard-period-help">Incoming parcels and recent fulfillment activity use this period. Queue, readiness, available riders, and active dispatch values remain live current-state counts.</p>
        </div>
    </div>
    <form class="table-toolbar" method="GET" action="{{ route('logistics.dashboard') }}" aria-describedby="dashboard-period-help">
        <div class="field">
            <label for="dashboard-from">From</label>
            <input
                id="dashboard-from"
                name="from"
                type="date"
                value="{{ old('from', $dashboardRange['from']) }}"
                @if($errors->has('from')) aria-invalid="true" aria-describedby="dashboard-from-error" @endif
            >
            @error('from')
                <small class="field-error" id="dashboard-from-error">{{ $message }}</small>
            @enderror
        </div>
        <div class="field">
            <label for="dashboard-to">To</label>
            <input
                id="dashboard-to"
                name="to"
                type="date"
                value="{{ old('to', $dashboardRange['to']) }}"
                @if($errors->has('to')) aria-invalid="true" aria-describedby="dashboard-to-error" @endif
            >
            @error('to')
                <small class="field-error" id="dashboard-to-error">{{ $message }}</small>
            @enderror
        </div>
        <button class="button button-primary" type="submit">
            <i data-lucide="filter"></i>
            Apply range
        </button>
    </form>
</section>
<section class="metric-strip" aria-label="Dashboard metrics for the selected period and current state">
    @foreach($metrics as $metric)
    <article class="metric-item"><span class="metric-icon"><i data-lucide="{{ $metric['icon'] }}"></i></span><span class="metric-copy"><small>{{ $metric['label'] }}</small><strong>{{ number_format($metric['value']) }}</strong><span>{{ $metric['trend'] }}</span></span></article>
    @endforeach
</section>
<div class="content-grid">
    <section class="panel">
        <div class="panel-header"><div><h3>Zone readiness</h3><p>Live current-state view of parcels staged for delivery runs; not limited by the selected dates.</p></div><a class="text-button" href="{{ route('logistics.dispatch.index') }}">Manage assignments</a></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Delivery area</th><th>Parcels</th><th>Sorted & ready</th><th>Available riders</th><th>Readiness</th></tr></thead><tbody>
        @forelse($zones as $zone)
            @php(
                $percentage = $zone['parcels'] > 0
                    ? min(
                        100,
                        round(
                            ($zone['ready'] / $zone['parcels'])
                            * 100
                        )
                    )
                    : 0
            )

            <tr>
                <td>
                    <strong>
                        {{ $zone['zone'] }}
                    </strong>
                </td>

                <td>
                    {{ $zone['parcels'] }}
                </td>

                <td>
                    {{ $zone['ready'] }}
                </td>

                <td>
                    {{ $zone['riders'] }}
                </td>

                <td>
                    <div class="row-actions">
                        <div class="progress-track">
                            <span
                                style="width:{{ $percentage }}%"
                            ></span>
                        </div>

                        <small>
                            {{ $percentage }}%
                        </small>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    No active sorting zones are configured
                    for this facility.
                </td>
            </tr>
        @endforelse
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="panel-header"><div><h3>Needs attention</h3><p>Current queues and exceptions requiring an operator decision.</p></div></div>
        <div class="panel-body attention-list">
            <a class="attention-item" href="{{ route('logistics.riders.index') }}"><i data-lucide="user-round-check"></i><span><strong>{{ $pendingRiderCount }} rider {{ $pendingRiderCount === 1 ? 'application' : 'applications' }}</strong><small>Credentials are ready for review</small></span><i data-lucide="chevron-right"></i></a>
            <a class="attention-item" href="{{ route('logistics.pickups.index') }}"><i data-lucide="package-check"></i><span><strong>{{ $pendingPickupCount }} pickup {{ $pendingPickupCount === 1 ? 'request' : 'requests' }}</strong><small>Seller requests are awaiting verification</small></span><i data-lucide="chevron-right"></i></a>
            <a
                class="attention-item"
                href="{{ route('logistics.sorting.center') }}"
            >
                <i data-lucide="triangle-alert"></i>

                <span>
                    <strong>
                        {{ $sortingExceptionCount }}
                        sorting
                        {{ $sortingExceptionCount === 1
                            ? 'exception'
                            : 'exceptions' }}
                    </strong>

                    <small>
                        Parcels requiring sorting review
                    </small>
                </span>

                <i data-lucide="chevron-right"></i>
            </a>
        </div>
    </section>
</div>
<div class="content-grid equal" style="margin-top:20px">
    <section class="panel"><div class="panel-header"><div><h3>Recent activity</h3><p>Fulfillment and rider activity recorded within the selected period.</p></div></div><div class="panel-body activity-list">
        @forelse($activity as $item)
            <div class="activity-item">
                <span class="activity-time">
                    {{ $item['time'] }}
                </span>

                <span class="activity-dot"></span>

                <span class="activity-copy">
                    <strong>
                        {{ $item['title'] }}
                    </strong>

                    <small>
                        {{ $item['detail'] }}
                    </small>
                </span>
            </div>
        @empty
            <div class="empty-state">
                <strong>No recent activity yet</strong>
                <p>
                    Rider applications and fulfillment
                    activity will appear here.
                </p>
            </div>
        @endforelse
    </div></section>
    <section class="panel"><div class="panel-header"><div><h3>Quick actions</h3><p>Jump into frequent operations.</p></div></div><div class="panel-body quick-actions">
        <a class="quick-action" href="{{ route('logistics.sorting.incoming') }}"><i data-lucide="package-plus"></i><span><strong>Receive parcels</strong><small>Log arrivals at the center</small></span></a>
        <a class="quick-action" href="{{ route('logistics.sorting.center') }}"><i data-lucide="arrow-down-up"></i><span><strong>Sort queue</strong><small>Group parcels by area</small></span></a>
        <a class="quick-action" href="{{ route('logistics.riders.index') }}"><i data-lucide="badge-check"></i><span><strong>Review riders</strong><small>Approve new applications</small></span></a>
        <a class="quick-action" href="{{ route('logistics.reports.index') }}"><i data-lucide="file-chart-column"></i><span><strong>Generate report</strong><small>Preview performance data</small></span></a>
    </div></section>
</div>
@endsection
