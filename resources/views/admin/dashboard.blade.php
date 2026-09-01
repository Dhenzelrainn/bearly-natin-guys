@extends('layouts.admin')

@section('title', 'Dashboard Overview')
@section('page-title', 'Dashboard Overview')

@section('content')
<section class="page-hero compact-hero">
    <div>
        <span class="eyebrow">Platform overview</span>
        <h1>Good evening, {{ explode(' ', $admin['name'])[0] }}.</h1>
        <p>Here is a clean snapshot of Bearly's marketplace activity and the items that need admin attention.</p>
    </div>
    <div class="hero-actions">
        <button class="button button-secondary" type="button" data-mock-action="Dashboard data refreshed.">
            <i data-lucide="refresh-cw"></i> Refresh
        </button>
        <a class="button button-primary" href="{{ route('admin.reports') }}">
            <i data-lucide="file-chart-column"></i> Open reports
        </a>
    </div>
</section>

<section class="kpi-grid">
    @foreach ($kpis as $kpi)
        <article class="kpi-card">
            <div class="kpi-card-top">
                <span class="metric-icon"><i data-lucide="{{ $kpi['icon'] }}"></i></span>
                <span class="metric-trend trend-{{ $kpi['trend'] }}">{{ $kpi['change'] }}</span>
            </div>
            <p>{{ $kpi['label'] }}</p>
            <strong>{{ $kpi['value'] }}</strong>
            <small>Live preview from static mock records</small>
        </article>
    @endforeach
</section>

<section class="dashboard-grid dashboard-grid-main">
    <article class="panel panel-large">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Marketplace performance</span>
                <h2>Sales trend</h2>
                <p>Monthly gross sales preview for the current year.</p>
            </div>
            <div class="segmented-control">
                <button type="button" class="is-active">12M</button>
                <button type="button" data-mock-action="Quarter view selected.">3M</button>
                <button type="button" data-mock-action="Month view selected.">30D</button>
            </div>
        </div>

        <div class="chart-shell">
            <div class="chart-y-labels"><span>₱1.5M</span><span>₱1.0M</span><span>₱500K</span><span>₱0</span></div>
            <div class="bar-chart" aria-label="Sales bar chart">
                @php $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; @endphp
                @foreach ($salesByMonth as $index => $height)
                    <div class="bar-column">
                        <div class="bar-track"><span style="height: {{ min(100, $height / 1.45) }}%"></span></div>
                        <small>{{ $months[$index] }}</small>
                    </div>
                @endforeach
            </div>
        </div>
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
            <button class="text-button" type="button" data-mock-action="All activity opened.">View all</button>
        </div>
        <div class="activity-list">
            @foreach ($activity as $item)
                <div class="activity-row">
                    <span class="activity-dot dot-{{ $item['type'] }}"></span>
                    <div>
                        <strong>{{ $item['title'] }}</strong>
                        <small>{{ $item['meta'] }}</small>
                    </div>
                    <button class="icon-button subtle-icon" type="button" data-mock-action="Activity details opened."><i data-lucide="chevron-right"></i></button>
                </div>
            @endforeach
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
