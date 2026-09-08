@extends('layouts.seller')

@section('title', 'Seller Dashboard')
@section('page-title', 'Seller Dashboard')

@section('content')
<div class="soft-dashboard">
    <section class="soft-dashboard-heading" aria-labelledby="dashboard-title">
        <div>
            <h2 id="dashboard-title">Dashboard</h2>
            <p>{{ $seller['store'] }} <span aria-hidden="true">·</span> Updated {{ $dashboard['updated_at'] }}</p>
        </div>
        <label class="soft-period-select">
            <i data-lucide="calendar-days" aria-hidden="true"></i>
            <span class="sr-only">Dashboard period</span>
            <select aria-label="Dashboard period">
                <option>Last 7 days</option>
                <option>Last 30 days</option>
                <option>This month</option>
            </select>
            <i data-lucide="chevron-down" aria-hidden="true"></i>
        </label>
    </section>

    <section class="soft-metric-strip" aria-label="Store performance summary">
        @foreach ($stats as $index => $stat)
            <article class="soft-metric">
                <span class="soft-metric-icon tone-{{ $stat['tone'] }}"><i data-lucide="{{ $stat['icon'] }}" aria-hidden="true"></i></span>
                <div>
                    <p>{{ $stat['label'] }}</p>
                    <strong>{{ $stat['value'] }}</strong>
                    @if ($index === 1)
                        <small>Next payout September 5</small>
                    @else
                        <small>{{ $stat['change'] }}</small>
                    @endif
                </div>
            </article>
        @endforeach
    </section>

    <section class="soft-dashboard-grid soft-dashboard-focus">
        <article class="soft-panel soft-actions-panel" aria-labelledby="actions-title">
            <header class="soft-panel-heading">
                <div><span>Today’s priorities</span><h3 id="actions-title">Action Required</h3></div>
                <a href="{{ route('seller.orders') }}">View all <i data-lucide="chevron-right" aria-hidden="true"></i></a>
            </header>
            <div class="soft-action-grid">
                @foreach ($dashboard['actions'] as $action)
                    <a class="soft-action-item" href="{{ $action['target'] }}">
                        <span class="soft-action-icon tone-{{ $action['tone'] }}"><i data-lucide="{{ $action['icon'] }}" aria-hidden="true"></i></span>
                        <span><strong>{{ $action['count'] }}</strong><small>{{ ucfirst($action['label']) }}</small></span>
                        <i data-lucide="chevron-right" aria-hidden="true"></i>
                    </a>
                @endforeach
            </div>
        </article>

        <article class="soft-panel soft-pickup-panel" aria-labelledby="pickup-title">
            <header class="soft-panel-heading">
                <div><span>Fulfillment</span><h3 id="pickup-title">Upcoming Pickup</h3></div>
                <a href="{{ route('seller.fulfillment.pickups') }}">View schedule <i data-lucide="chevron-right" aria-hidden="true"></i></a>
            </header>
            <div class="soft-pickup-main">
                <span class="soft-pickup-icon"><i data-lucide="truck" aria-hidden="true"></i></span>
                <div><strong>{{ $pickupSummary['time'] }}</strong><small>{{ $pickupSummary['date'] }}</small></div>
            </div>
            @php($pickupTotal = $pickupSummary['ready'] + $pickupSummary['not_ready'])
            @php($pickupProgress = $pickupTotal > 0 ? round(($pickupSummary['ready'] / $pickupTotal) * 100) : 0)
            <div class="soft-pickup-progress" aria-label="{{ $pickupProgress }} percent ready for pickup"><i style="width: {{ $pickupProgress }}%"></i></div>
            <dl class="soft-pickup-counts">
                <div><dd>{{ $pickupSummary['ready'] }}</dd><dt>Ready for pickup</dt></div>
                <div><dd>{{ $pickupSummary['not_ready'] }}</dd><dt>Not ready</dt></div>
            </dl>
        </article>
    </section>

    <section class="soft-dashboard-grid soft-dashboard-insights">
        <article class="soft-panel soft-sales-panel" aria-labelledby="sales-title">
            <header class="soft-panel-heading">
                <div><span>Performance</span><h3 id="sales-title">Sales Overview</h3></div>
                <label class="soft-chart-select"><span class="sr-only">Chart metric</span><select aria-label="Chart metric"><option>Gross Sales</option><option>Net Revenue</option><option>Orders</option></select><i data-lucide="chevron-down" aria-hidden="true"></i></label>
            </header>
            <div class="soft-sales-chart">
                <div class="soft-chart-axis"><span>₱30K</span><span>₱20K</span><span>₱10K</span><span>₱0</span></div>
                <div class="soft-chart-canvas">
                    <div class="soft-chart-grid" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                    <svg viewBox="0 0 700 230" role="img" aria-label="Gross sales from Monday to Sunday">
                        <defs><linearGradient id="softSalesFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#78865f" stop-opacity=".19"/><stop offset="1" stop-color="#78865f" stop-opacity="0"/></linearGradient></defs>
                        <path class="soft-chart-area" d="M18 180 L125 145 L232 128 L339 148 L446 91 L553 109 L682 55 L682 215 L18 215 Z"/>
                        <polyline class="soft-chart-line" points="18,180 125,145 232,128 339,148 446,91 553,109 682,55"/>
                        @foreach ([['18','180'],['125','145'],['232','128'],['339','148'],['446','91'],['553','109'],['682','55']] as $point)<circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="4"/>@endforeach
                    </svg>
                    <div class="soft-chart-days">@foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day)<span>{{ $day }}</span>@endforeach</div>
                </div>
            </div>
        </article>

        <article class="soft-panel soft-products-panel" aria-labelledby="products-title">
            <header class="soft-panel-heading">
                <div><span>Product performance</span><h3 id="products-title">Top-Selling Products</h3></div>
                <a href="{{ route('seller.reports.sales') }}">View all <i data-lucide="chevron-right" aria-hidden="true"></i></a>
            </header>
            <div class="soft-product-list">
                @foreach ($topProducts as $product)
                    <article class="soft-product-row">
                        <span class="soft-product-icon"><i data-lucide="{{ $product['icon'] }}" aria-hidden="true"></i></span>
                        <div><strong>{{ $product['name'] }}</strong><small>{{ $product['sold'] }} sold <span aria-hidden="true">·</span> {{ $product['sku'] }}</small></div>
                        <span><strong>{{ $product['revenue'] }}</strong><small>Revenue</small></span>
                    </article>
                @endforeach
            </div>
        </article>
    </section>

    <section class="soft-dashboard-grid soft-dashboard-detail">
        <article class="soft-panel soft-payout-detail">
            <header class="soft-panel-heading"><div><span>Earnings</span><h3>Payout Breakdown</h3></div><a href="{{ route('seller.reports.financial') }}">Financial report <i data-lucide="chevron-right" aria-hidden="true"></i></a></header>
            <dl>@foreach ($payoutSummary as $item)<div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach</dl>
        </article>
        <article class="soft-panel soft-store-note">
            <span><i data-lucide="sparkles" aria-hidden="true"></i></span>
            <div><small>Store health</small><h3>Your store is performing well</h3><p>Fulfillment improved this month. Keep preparing orders before the pickup cut-off.</p></div>
            <a href="{{ route('seller.reports') }}">View reports <i data-lucide="arrow-up-right" aria-hidden="true"></i></a>
        </article>
    </section>
</div>
@endsection
