@extends('layouts.seller')

@section('title', 'Seller Dashboard')
@section('page-title', 'Seller Dashboard')

@section('content')
<section class="dashboard-heading" aria-labelledby="dashboard-title">
    <div><span class="section-kicker">Business summary</span><h2 id="dashboard-title">Dashboard</h2><p>{{ $seller['store'] }} · Updated {{ $dashboard['updated_at'] }}</p></div>
    <label class="dashboard-period"><span class="sr-only">Dashboard period</span><img class="seller-ui-icon" src="{{ asset('images/calendar-days.svg') }}" alt="" aria-hidden="true"><select aria-label="Dashboard period"><option>Last 7 days</option><option>Last 30 days</option><option>This month</option></select></label>
</section>

<section class="dashboard-action-panel" aria-labelledby="actions-title">
    <div class="dashboard-action-heading">
        <div><span class="section-kicker">Needs attention</span><h3 id="actions-title">Action Required</h3></div>
        <p><strong>{{ $dashboard['action_count'] }}</strong> tasks before today’s {{ $dashboard['pickup_time'] }} pickup</p>
    </div>
    <div class="dashboard-action-list">
        @foreach ($dashboard['actions'] as $action)
            <a class="dashboard-action-item" href="{{ $action['target'] }}">
                <span class="dashboard-action-icon action-{{ $action['tone'] }}"><img class="seller-ui-icon" src="{{ asset('images/'.$action['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
                <span class="dashboard-action-copy"><strong>{{ $action['count'] }} {{ $action['label'] }}</strong><small>{{ $action['detail'] }}</small></span>
                <span class="dashboard-action-link">{{ $action['action'] }}<img class="seller-ui-icon" src="{{ asset('images/arrow-right.svg') }}" alt="" aria-hidden="true"></span>
            </a>
        @endforeach
    </div>
</section>

<section class="seller-financial-strip" aria-label="Financial performance summary">
    @foreach ($stats as $stat)
        <article class="seller-financial-item">
            <span class="stat-icon stat-{{ $stat['tone'] }}"><img class="seller-ui-icon" src="{{ asset('images/'.$stat['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
            <div><p>{{ $stat['label'] }}</p><strong>{{ $stat['value'] }}</strong><small>{{ $stat['change'] }}</small></div>
        </article>
    @endforeach
</section>

<section class="seller-dashboard-grid seller-dashboard-primary">
    <article class="seller-panel sales-panel">
        <div class="seller-panel-heading"><div><span class="section-kicker">Performance</span><h3>Sales Overview</h3></div><a href="#" data-preview-link="Sales Report">Open sales report</a></div>
        <div class="sales-chart" aria-label="Sales overview for the last seven days">
            <div class="chart-y-axis"><span>₱30,000</span><span>₱20,000</span><span>₱10,000</span><span>₱0</span></div>
            <div class="line-chart-wrap">
                <svg class="line-chart" viewBox="0 0 700 220" role="img" aria-label="Sales from Monday to Sunday">
                    <defs><linearGradient id="sellerChartFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#77885b" stop-opacity=".28"/><stop offset="1" stop-color="#77885b" stop-opacity="0"/></linearGradient></defs>
                    <path class="chart-area" d="M24 162 L128 127 L232 124 L336 91 L440 61 L544 112 L676 48 L676 190 L24 190 Z" />
                    <polyline class="chart-line" points="24,162 128,127 232,124 336,91 440,61 544,112 676,48" />
                    @foreach ([['24','162'],['128','127'],['232','124'],['336','91'],['440','61'],['544','112'],['676','48']] as $point)<circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="5" />@endforeach
                    <line class="chart-guide" x1="440" y1="61" x2="440" y2="190" />
                </svg>
                <span class="chart-tooltip">₱{{ number_format($sales[4]) }}</span>
                <div class="chart-days">@foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day)<span>{{ $day }}</span>@endforeach</div>
            </div>
        </div>
    </article>

    <article class="seller-panel top-products-panel">
        <div class="seller-panel-heading"><div><span class="section-kicker">Product performance</span><h3>Top-Selling Products</h3></div><a href="#" data-preview-link="Product Performance">View report</a></div>
        <div class="top-products-head" aria-hidden="true"><span>Product</span><span>Sold</span><span>Revenue</span></div>
        <div class="top-products-list">
            @foreach ($topProducts as $product)
                <article class="top-product-row">
                    <span class="top-product-thumb"><img class="seller-ui-icon" src="{{ asset('images/'.$product['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
                    <div class="top-product-name"><strong>{{ $product['name'] }}</strong><small>SKU: {{ $product['sku'] }}</small></div>
                    <strong class="top-product-sold">{{ $product['sold'] }}</strong><span class="top-product-bar" aria-hidden="true"><i style="width: {{ $product['percent'] }}%"></i></span><strong class="top-product-revenue">{{ $product['revenue'] }}</strong>
                </article>
            @endforeach
        </div>
    </article>
</section>

<section class="dashboard-bottom-grid">
    <article class="seller-panel dashboard-payout-panel">
        <div class="seller-panel-heading"><div><span class="section-kicker">Earnings</span><h3>Payout Summary</h3></div><a href="#" data-preview-link="Financial Report">View financial report</a></div>
        <dl class="dashboard-payout-list">@foreach ($payoutSummary as $item)<div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach</dl>
    </article>
    <article class="seller-panel dashboard-pickup-panel">
        <div class="seller-panel-heading"><div><span class="section-kicker">Fulfillment</span><h3>Upcoming Pickup</h3></div><a href="#" data-preview-link="Pickup Scheduling">Open schedule</a></div>
        <div class="dashboard-pickup-content"><span class="dashboard-pickup-icon"><img class="seller-ui-icon" src="{{ asset('images/truck.svg') }}" alt="" aria-hidden="true"></span><div><strong>{{ $pickupSummary['time'] }}</strong><span>{{ $pickupSummary['date'] }}</span></div><dl><div><dt>Ready</dt><dd>{{ $pickupSummary['ready'] }}</dd></div><div><dt>Not ready</dt><dd>{{ $pickupSummary['not_ready'] }}</dd></div></dl></div>
    </article>
</section>
@endsection
