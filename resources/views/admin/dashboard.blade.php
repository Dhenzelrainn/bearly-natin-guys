@extends('layouts.admin')

@section('title', 'Dashboard Overview')


@section('content')
<section class="page-hero compact-hero">
    <div>
        <span class="eyebrow">Platform overview</span>
        <h1>Good evening, {{ explode(' ', $admin['name'])[0] }}.</h1>
        <p>Here is a clean snapshot of Bearly's marketplace activity and the items that need admin attention.</p>
    </div>
    <div class="hero-actions">
        <button class="button button-secondary" type="button" data-dashboard-refresh>
            <i data-lucide="refresh-cw"></i> Refresh
        </button>
        <a class="button button-primary" href="{{ route('admin.reports') }}">
            <i data-lucide="file-chart-column"></i> Open reports
        </a>
    </div>
</section>

@php
    $kpisByLabel = collect($kpis)->keyBy('label');
    $primaryKpiOrder = [
        'Total Users',
        'Buyers',
        'Sellers',
        'Logistics & Riders',
        'Pending Applications',
        'Restricted Accounts',
        'Active Orders',
        'Paid Collections',
    ];
    $adminRecordsKpi = $kpisByLabel->get('Admin Records');
@endphp

<section class="dashboard-kpi-grid" aria-label="Key platform metrics">
    @foreach ($primaryKpiOrder as $label)
        @php
            $kpi = $kpisByLabel->get($label);
        @endphp
        @if ($kpi)
            @php
                $metricCount = (int) preg_replace('/\D+/', '', $kpi['value']);
                $activeCount = (int) preg_replace('/\D+/', '', $kpi['change']);
                $description = match ($label) {
                    'Total Users' => 'All registered accounts across Bearly.',
                    'Buyers' => 'Buyer accounts on the platform.',
                    'Sellers' => 'Seller accounts on the platform.',
                    'Logistics & Riders' => 'Logistics centers and rider accounts.',
                    'Pending Applications' => 'Applications awaiting review.',
                    'Restricted Accounts' => 'Suspended or deactivated accounts.',
                    'Active Orders' => 'Orders still in progress.',
                    'Paid Collections' => 'Payments marked paid this year, by payment date.',
                    default => '',
                };
                $showStatus = ! in_array($label, ['Buyers', 'Sellers'], true);
                $statusText = match ($label) {
                    'Pending Applications' => $metricCount > 0 ? $kpi['change'] : 'No pending reviews',
                    'Restricted Accounts' => $metricCount > 0 ? $kpi['change'] : 'None',
                    default => $kpi['change'],
                };
                $statusClass = match ($label) {
                    'Total Users' => $activeCount > 0 ? 'is-success' : 'is-neutral',
                    'Pending Applications' => $metricCount > 0 ? 'is-warning' : 'is-neutral',
                    'Restricted Accounts' => $metricCount > 0 ? 'is-danger' : 'is-neutral',
                    default => 'is-neutral',
                };
            @endphp

            <article class="dashboard-kpi-card">
                <div class="dashboard-kpi-card-top">
                    <span class="metric-icon" aria-hidden="true"><i data-lucide="{{ $kpi['icon'] }}"></i></span>
                    @if ($showStatus)
                        <span class="dashboard-kpi-status {{ $statusClass }}">{{ $statusText }}</span>
                    @endif
                </div>
                <p>{{ $kpi['label'] }}</p>
                <strong>{{ $kpi['value'] }}</strong>
                <small>{{ $description }}</small>
            </article>
        @endif
    @endforeach
</section>

@if ($adminRecordsKpi)
    <section class="platform-overview-panel panel" aria-labelledby="platform-overview-title">
        <div class="panel-heading">
            <h2 id="platform-overview-title">Platform overview</h2>
        </div>
        <div class="platform-overview-metric">
            <span class="metric-icon" aria-hidden="true"><i data-lucide="{{ $adminRecordsKpi['icon'] }}"></i></span>
            <div>
                <strong>{{ $adminRecordsKpi['value'] }}</strong>
                <span>Admin records</span>
                <small>Announcements, policies, and system settings</small>
            </div>
        </div>
    </section>
@endif

<section class="dashboard-grid dashboard-grid-main">
    <article class="panel panel-large">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Marketplace performance</span>
                <h2>Sales trend</h2>
                <p>Net product sales from completed seller orders, grouped by completion date. Shipping is excluded; item discounts and completed refunds are deducted.</p>
            </div>
            <div class="segmented-control">
                <button type="button" class="is-active" data-dashboard-period="12M" aria-pressed="true">12M</button>
                <button type="button" data-dashboard-period="3M" aria-pressed="false">3M</button>
                <button type="button" data-dashboard-period="30D" aria-pressed="false">30D</button>
            </div>
        </div>

        @if ($hasSalesData)
            @foreach (['12M', '3M', '30D'] as $periodKey)
                @php
                    $trend = $salesTrend[$periodKey];
                    $trendCaption = match ($periodKey) {
                        '3M' => 'Net product sales for the last 3 calendar months, grouped by completion date',
                        '30D' => 'Net product sales for the last 30 days, grouped by completion date',
                        default => 'Net product sales for the last 12 calendar months, grouped by completion date',
                    };
                @endphp
                <div class="chart-shell" data-dashboard-period-view="{{ $periodKey }}" @if ($periodKey !== '12M') hidden @endif>
                    @if ($trend['has_data'])
                        <div class="chart-y-labels" aria-hidden="true">
                            @foreach ($trend['axis'] as $axisLabel)<span>{{ $axisLabel }}</span>@endforeach
                        </div>
                        <div
                            class="bar-chart @if (count($trend['buckets']) > 14) is-dense @endif @if ($trend['has_negative']) has-negative @endif"
                            data-chart
                            role="group"
                            aria-label="{{ $trendCaption }}"
                            style="grid-template-columns:repeat({{ count($trend['buckets']) }}, minmax(0, 1fr))@if ($trend['has_negative']);--zero-position: {{ $trend['zero_percent'] }}%@endif"
                        >
                            @foreach ($trend['buckets'] as $bucket)
                                <div
                                    class="bar-column"
                                    data-chart-point
                                    tabindex="{{ $loop->first ? 0 : -1 }}"
                                    role="img"
                                    aria-label="{{ $bucket['label'] }}: {{ $bucket['value'] }}"
                                    data-label="{{ $bucket['label'] }}"
                                    data-value="{{ $bucket['value'] }}"
                                >
                                    <div class="bar-track">
                                        @if ($bucket['value_minor'] !== 0)
                                            <span
                                                data-chart-bar
                                                @if ($bucket['negative']) class="is-negative" @endif
                                                style="height: {{ $bucket['height'] }}%; @if ($bucket['negative']) top: {{ 100 - $trend['zero_percent'] }}%; @else bottom: {{ $trend['zero_percent'] }}%; @endif"
                                            ></span>
                                        @endif
                                    </div>
                                    <small>{{ $bucket['short'] }}</small>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="dashboard-chart-empty chart-shell-empty" role="status">
                            <i data-lucide="chart-column" aria-hidden="true"></i>
                            <strong>{{ $trend['empty_note'] }}</strong>
                        </div>
                    @endif
                </div>
            @endforeach
        @else
            <div class="dashboard-chart-empty" role="status">
                <i data-lucide="chart-column" aria-hidden="true"></i>
                <strong>No net product sales recorded yet</strong>
                <span>Net product sales appear here after seller orders complete.</span>
            </div>
        @endif
    </article>

    <aside class="panel attention-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Needs attention</span>
                <h2>Admin queue</h2>
            </div>
            <span class="status-badge badge-warning">{{ count($systemNotices) }} items</span>
        </div>
        <div class="notice-stack">
            @foreach ($systemNotices as $notice)
                <div class="notice-card">
                    <span class="notice-marker"><i data-lucide="circle-alert"></i></span>
                    <div>
                        <strong>{{ $notice['title'] }}</strong>
                        <p>{{ $notice['text'] }}</p>
                        <a href="{{ route($notice['route']) }}">{{ $notice['action'] }} <i data-lucide="arrow-right"></i></a>
                    </div>
                </div>
            @endforeach
        </div>
    </aside>
</section>

<section class="dashboard-grid dashboard-grid-secondary">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Recent activity</span>
                <h2>Platform timeline</h2>
            </div>
            <a class="text-button" href="{{ route('admin.audit-logs') }}">View all</a>
        </div>
        <div class="activity-list">
            @forelse ($activity as $item)
                <div class="activity-row">
                    <span class="activity-dot dot-{{ $item['type'] }}"></span>
                    <div>
                        <strong>{{ $item['title'] }}</strong>
                        <small>{{ $item['meta'] }}</small>
                    </div>
                    <a class="icon-button subtle-icon" href="{{ route('admin.audit-logs') }}" aria-label="Open audit logs"><i data-lucide="chevron-right"></i></a>
                </div>
            @empty
                <div class="table-empty">
                    <i data-lucide="activity"></i>
                    <strong>No administrative activity yet</strong>
                    <span>New actions will appear here as they are recorded.</span>
                </div>
            @endforelse
        </div>
    </article>

    <article class="panel quick-actions-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Shortcuts</span>
                <h2>Quick actions</h2>
            </div>
        </div>
        <div class="quick-action-grid">
            <a href="{{ route('admin.registrations') }}"><span><i data-lucide="user-check"></i></span><strong>Review registrations</strong><small>Approve or disapprove applicants</small></a>
            <a href="{{ route('admin.compliance') }}"><span><i data-lucide="shield-alert"></i></span><strong>Review flagged items</strong><small>Check seller compliance</small></a>
            <a href="{{ route('admin.disputes') }}"><span><i data-lucide="messages-square"></i></span><strong>Resolve complaints</strong><small>Coordinate multiple parties</small></a>
            <a href="{{ route('admin.settings') }}"><span><i data-lucide="megaphone"></i></span><strong>Post announcement</strong><small>Update marketplace notices</small></a>
        </div>
    </article>
</section>
@endsection
