@extends('layouts.courier')

@section('title', 'Delivery Dashboard')
@section('page-title', 'Delivery Dashboard')

@section('content')
<section class="page-hero compact-hero">
    <div>
        <span class="eyebrow">Module 02 · Courier overview</span>
        <h1>Good evening, {{ explode(' ', $courier['name'])[0] }}.</h1>
        <p>You are currently online. Review nearby pickup requests, active delivery updates, and your latest earnings.</p>
    </div>
    <div class="hero-actions">
        <button class="button button-secondary" type="button" data-mock-action="Courier dashboard refreshed."><i data-lucide="refresh-cw"></i> Refresh</button>
        <a class="button button-primary" href="{{ route('courier.requests') }}"><i data-lucide="package-search"></i> View requests</a>
    </div>
</section>

<section class="kpi-grid">
    @foreach($kpis as $kpi)
        <article class="kpi-card">
            <div class="kpi-card-top"><span class="metric-icon"><i data-lucide="{{ $kpi['icon'] }}"></i></span><span class="metric-trend trend-{{ $kpi['trend'] }}">{{ $kpi['change'] }}</span></div>
            <p>{{ $kpi['label'] }}</p><strong>{{ $kpi['value'] }}</strong><small>Static courier activity preview</small>
        </article>
    @endforeach
</section>

<section class="dashboard-grid dashboard-grid-main">
    <article class="panel panel-large">
        <div class="panel-heading">
            <div><span class="eyebrow">Today's performance</span><h2>Earnings by delivery</h2><p>Visual preview of completed-delivery earnings for your current shift.</p></div>
            <span class="status-badge badge-success"><span class="online-dot"></span> On duty</span>
        </div>
        <div class="chart-shell">
            <div class="chart-y-labels"><span>₱220</span><span>₱160</span><span>₱80</span><span>₱0</span></div>
            <div class="bar-chart" aria-label="Courier earnings chart">
                @foreach($earningsSeries as $index => $height)
                    <div class="bar-column"><div class="bar-track"><span style="height: {{ min(100, $height) }}%"></span></div><small>D{{ $index + 1 }}</small></div>
                @endforeach
            </div>
        </div>
    </article>

    <aside class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Delivery notifications</span><h2>Needs attention</h2></div><span class="status-badge badge-warning">{{ count($deliveryNotices) }} updates</span></div>
        <div class="notice-stack">
            @foreach($deliveryNotices as $notice)
                <div class="notice-card"><span class="notice-marker"><i data-lucide="bell-ring"></i></span><div><strong>{{ $notice['title'] }}</strong><p>{{ $notice['text'] }}</p><a href="{{ route($notice['route']) }}">Open <i data-lucide="arrow-right"></i></a></div></div>
            @endforeach
        </div>
    </aside>
</section>

<section class="dashboard-grid dashboard-grid-secondary">
    <article class="panel">
        <div class="panel-heading panel-heading-wrap"><div><span class="eyebrow">Available now</span><h2>Pickup requests</h2><p>Nearby requests are simulated as first-come, first-served jobs.</p></div><a class="text-button" href="{{ route('courier.requests') }}">See all requests</a></div>
        <div class="activity-list">
            @foreach($pickupRequests as $request)
                <div class="activity-row">
                    <span class="activity-dot dot-success"></span>
                    <div><strong>{{ $request['seller'] }} • {{ $request['payout'] }}</strong><small>{{ $request['pickup'] }} → {{ $request['dropoff'] }} • {{ $request['distance'] }} • {{ $request['eta'] }}</small></div>
                    <a class="icon-button subtle-icon" href="{{ route('courier.requests') }}"><i data-lucide="chevron-right"></i></a>
                </div>
            @endforeach
        </div>
    </article>

    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Recent activity</span><h2>Shift timeline</h2></div><a class="text-button" href="{{ route('courier.history') }}">History</a></div>
        <div class="activity-list">
            @foreach($activity as $item)
                <div class="activity-row"><span class="activity-dot dot-{{ $item['type'] }}"></span><div><strong>{{ $item['title'] }}</strong><small>{{ $item['meta'] }}</small></div><button class="icon-button subtle-icon" type="button" data-mock-action="Activity detail opened."><i data-lucide="chevron-right"></i></button></div>
            @endforeach
        </div>
    </article>
</section>
@endsection
