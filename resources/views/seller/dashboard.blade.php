@extends('layouts.seller')

@section('title', 'Seller Dashboard')
@section('page-title', 'Seller Dashboard')

@section('content')
<section class="seller-welcome-card">
    <div class="welcome-copy">
        <span class="welcome-kicker">Store overview</span>
        <h2>Good morning, {{ $seller['first_name'] }}!</h2>
        <p>Here’s what’s happening in your store today.</p>
        <div class="store-name"><i data-lucide="store"></i><span>{{ $seller['store'] }}</span></div>
        <button class="seller-primary-button" type="button" data-preview-link="Add Product"><i data-lucide="plus"></i> Add Product</button>
    </div>
    <div class="welcome-art" aria-hidden="true">
        <img src="{{ asset('images/seller-dashboard-hero.png') }}" alt="">
    </div>
</section>

<section class="seller-stat-grid" aria-label="Store performance summary">
    @foreach ($stats as $stat)
        <article class="seller-stat-card">
            <span class="stat-icon stat-{{ $stat['tone'] }}"><i data-lucide="{{ $stat['icon'] }}"></i></span>
            <div><p>{{ $stat['label'] }}</p><strong>{{ $stat['value'] }}</strong><small>{{ $stat['change'] }}</small></div>
        </article>
    @endforeach
</section>

<section class="seller-dashboard-grid seller-dashboard-primary">
    <article class="seller-panel sales-panel">
        <div class="seller-panel-heading">
            <div><span class="section-kicker">Performance</span><h3>Sales Overview</h3></div>
            <button class="period-button" type="button"><i data-lucide="calendar-days"></i> Last 7 days <i data-lucide="chevron-down"></i></button>
        </div>
        <div class="sales-chart" aria-label="Sales overview for the last seven days">
            <div class="chart-y-axis"><span>₱30,000</span><span>₱20,000</span><span>₱10,000</span><span>₱0</span></div>
            <div class="line-chart-wrap">
                <svg class="line-chart" viewBox="0 0 700 220" role="img" aria-label="Sales from Monday to Sunday">
                    <defs>
                        <linearGradient id="sellerChartFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#77885b" stop-opacity=".28"/><stop offset="1" stop-color="#77885b" stop-opacity="0"/></linearGradient>
                    </defs>
                    <path class="chart-area" d="M24 162 L128 127 L232 124 L336 91 L440 61 L544 112 L676 48 L676 190 L24 190 Z" />
                    <polyline class="chart-line" points="24,162 128,127 232,124 336,91 440,61 544,112 676,48" />
                    @foreach ([['24','162'],['128','127'],['232','124'],['336','91'],['440','61'],['544','112'],['676','48']] as $point)
                        <circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="5" />
                    @endforeach
                    <line class="chart-guide" x1="440" y1="61" x2="440" y2="190" />
                </svg>
                <span class="chart-tooltip">₱{{ number_format($sales[4]) }}</span>
                <div class="chart-days">@foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day)<span>{{ $day }}</span>@endforeach</div>
            </div>
        </div>
    </article>

    <article class="seller-panel order-status-panel">
        <div class="seller-panel-heading"><div><span class="section-kicker">Fulfillment</span><h3>Order Status</h3></div><a href="#" data-preview-link="Orders">View all</a></div>
        <div class="status-list">
            @foreach ($orderStatuses as $status)
                <div class="status-row">
                    <span class="status-icon status-{{ $status['tone'] }}"><i data-lucide="{{ $status['icon'] }}"></i></span>
                    <span class="status-label">{{ $status['label'] }}</span>
                    <strong>{{ $status['count'] }}</strong>
                    <span class="status-progress"><i class="progress-{{ $status['tone'] }}" style="width: {{ $status['percent'] }}%"></i></span>
                </div>
            @endforeach
        </div>
    </article>
</section>

<section class="seller-dashboard-grid seller-dashboard-secondary">
    <article class="seller-panel recent-orders-panel">
        <div class="seller-panel-heading"><div><span class="section-kicker">Latest activity</span><h3>Recent Orders</h3></div><a href="#" data-preview-link="Orders">View all</a></div>
        <div class="seller-table-wrap">
            <table class="seller-table">
                <thead><tr><th>Order ID</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($recentOrders as $order)
                        <tr><td><strong>{{ $order['id'] }}</strong></td><td>{{ $order['customer'] }}</td><td>{{ $order['items'] }}</td><td>{{ $order['total'] }}</td><td><span class="order-badge badge-{{ $order['tone'] }}">{{ $order['status'] }}</span></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>

    <article class="seller-panel inventory-panel">
        <div class="seller-panel-heading"><div><span class="section-kicker">Stock monitoring</span><h3>Inventory Alerts</h3></div><a href="#" data-preview-link="Inventory">View all</a></div>
        <div class="inventory-list">
            @foreach ($inventoryAlerts as $alert)
                <div class="inventory-row">
                    <span class="product-thumb"><i data-lucide="{{ $alert['icon'] }}"></i></span>
                    <strong>{{ $alert['name'] }}</strong>
                    <span class="stock-count stock-{{ $alert['tone'] }}">{{ $alert['stock'] }}</span>
                    <span class="inventory-badge badge-{{ $alert['tone'] }}"><i data-lucide="{{ $alert['tone'] === 'danger' ? 'circle-alert' : 'triangle-alert' }}"></i>{{ $alert['status'] }}</span>
                </div>
            @endforeach
        </div>
    </article>
</section>
@endsection
