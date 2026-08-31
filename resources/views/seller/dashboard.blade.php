@extends('layouts.seller')

@section('title', 'Seller Dashboard')
@section('page-title', 'Seller Dashboard')

@section('content')
<section class="seller-operations-card" aria-labelledby="operations-title">
    <div class="operations-header">
        <div>
            <h2 id="operations-title">Today’s Operations</h2>
            <div class="operations-store-meta">
                <span class="operations-store-name"><img class="seller-ui-icon" src="{{ asset('images/store.svg') }}" alt="" aria-hidden="true">{{ $seller['store'] }}</span>
                <span class="seller-account-status"><img class="seller-ui-icon" src="{{ asset('images/circle-check-big.svg') }}" alt="" aria-hidden="true">{{ $operations['account_status'] }}</span>
                <span class="operations-updated">Updated {{ $operations['updated_at'] }}</span>
            </div>
        </div>
        <div class="operations-header-actions">
            <a class="seller-primary-button" href="{{ route('seller.orders') }}"><img class="seller-ui-icon" src="{{ asset('images/clipboard-list.svg') }}" alt="" aria-hidden="true">Manage Orders</a>
            <a class="seller-secondary-button" href="{{ route('seller.store') }}"><img class="seller-ui-icon" src="{{ asset('images/external-link.svg') }}" alt="" aria-hidden="true">View Store</a>
        </div>
    </div>

    <p class="operations-summary">
        <strong>{{ $operations['action_count'] }}</strong> orders require action before today’s
        <strong>{{ $operations['pickup_time'] }}</strong> courier pickup.
    </p>

    <div class="operations-task-grid" aria-label="Orders requiring action today">
        @foreach ($operations['tasks'] as $task)
            <article class="operations-task">
                <span class="operations-task-icon task-{{ $task['tone'] }}"><img class="seller-ui-icon" src="{{ asset('images/'.$task['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
                <div class="operations-task-copy">
                    <strong>{{ $task['count'] }}</strong>
                    <span>{{ $task['label'] }}</span>
                    <a href="{{ $task['target'] }}">{{ $task['action'] }}<img class="seller-ui-icon" src="{{ asset('images/arrow-right.svg') }}" alt="" aria-hidden="true"></a>
                </div>
            </article>
        @endforeach
    </div>
</section>

<section class="seller-stat-grid" aria-label="Store performance summary">
    @foreach ($stats as $stat)
        <article class="seller-stat-card">
            <span class="stat-icon stat-{{ $stat['tone'] }}"><img class="seller-ui-icon" src="{{ asset('images/'.$stat['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
            <div><p>{{ $stat['label'] }}</p><strong>{{ $stat['value'] }}</strong><small>{{ $stat['change'] }}</small></div>
        </article>
    @endforeach
</section>

<section class="seller-dashboard-grid seller-dashboard-primary" id="store-performance">
    <article class="seller-panel sales-panel">
        <div class="seller-panel-heading">
            <div><span class="section-kicker">Performance</span><h3>Sales Overview</h3></div>
            <button class="period-button" type="button"><img class="seller-ui-icon" src="{{ asset('images/calendar-days.svg') }}" alt="" aria-hidden="true"> Last 7 days <img class="seller-ui-icon" src="{{ asset('images/chevron-down.svg') }}" alt="" aria-hidden="true"></button>
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

    <article class="seller-panel order-status-panel" id="order-status">
        <div class="seller-panel-heading"><div><span class="section-kicker">Fulfillment</span><h3>Order Status</h3></div><a href="{{ route('seller.orders') }}">View all</a></div>
        <div class="status-list">
            @foreach ($orderStatuses as $status)
                <div class="status-row">
                    <span class="status-icon status-{{ $status['tone'] }}"><img class="seller-ui-icon" src="{{ asset('images/'.$status['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
                    <span class="status-label">{{ $status['label'] }}</span>
                    <strong>{{ $status['count'] }}</strong>
                    <span class="status-progress"><i class="progress-{{ $status['tone'] }}" style="width: {{ $status['percent'] }}%"></i></span>
                </div>
            @endforeach
        </div>
    </article>
</section>

<section class="seller-dashboard-grid seller-dashboard-secondary">
    <article class="seller-panel recent-orders-panel" id="recent-orders">
        <div class="seller-panel-heading"><div><span class="section-kicker">Latest activity</span><h3>Recent Orders</h3></div><a href="{{ route('seller.orders') }}">View all</a></div>
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
        <div class="seller-panel-heading"><div><span class="section-kicker">Stock monitoring</span><h3>Inventory Alerts</h3></div><a href="{{ route('seller.inventory') }}">View all</a></div>
        <div class="inventory-list">
            @foreach ($inventoryAlerts as $alert)
                <div class="inventory-row">
                    <span class="product-thumb"><img class="seller-ui-icon" src="{{ asset('images/'.$alert['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
                    <strong>{{ $alert['name'] }}</strong>
                    <span class="stock-count stock-{{ $alert['tone'] }}">{{ $alert['stock'] }}</span>
                    <span class="inventory-badge badge-{{ $alert['tone'] }}"><img class="seller-ui-icon" src="{{ asset('images/'.($alert['tone'] === 'danger' ? 'circle-alert' : 'triangle-alert').'.svg') }}" alt="" aria-hidden="true">{{ $alert['status'] }}</span>
                </div>
            @endforeach
        </div>
    </article>
</section>

<section class="seller-dashboard-grid seller-dashboard-support">
    <article class="seller-panel delivery-monitor-panel" id="delivery-monitoring">
        <div class="seller-panel-heading">
            <div><span class="section-kicker">Courier handover</span><h3>Delivery Monitoring</h3></div>
            <a href="#" data-preview-link="Deliveries">View deliveries</a>
        </div>
        <div class="delivery-overview">
            <div class="delivery-pickup-time">
                <span>Next courier pickup</span>
                <strong>{{ $deliverySummary['pickup_time'] }}</strong>
                <small>{{ $deliverySummary['pickup_date'] }}</small>
            </div>
            <div class="delivery-readiness">
                <span><strong>{{ $deliverySummary['ready'] }}</strong> orders ready</span>
                <span class="is-warning"><strong>{{ $deliverySummary['not_ready'] }}</strong> still need packing</span>
            </div>
        </div>
        <ol class="delivery-steps" aria-label="Courier handover checklist">
            @foreach ($deliverySummary['steps'] as $step)
                <li class="{{ $step['complete'] ? 'is-complete' : '' }}">
                    <span><img class="seller-ui-icon" src="{{ asset('images/'.$step['icon'].'.svg') }}" alt="" aria-hidden="true"></span>
                    <div><strong>{{ $step['label'] }}</strong><small>{{ $step['detail'] }}</small></div>
                </li>
            @endforeach
        </ol>
    </article>

    <article class="seller-panel feedback-panel">
        <div class="seller-panel-heading">
            <div><span class="section-kicker">Customer experience</span><h3>Customer Feedback</h3></div>
            <a href="#" data-preview-link="Customer Feedback">View all</a>
        </div>
        <div class="feedback-summary">
            <div><img class="seller-ui-icon" src="{{ asset('images/star.svg') }}" alt="" aria-hidden="true"><strong>{{ $feedbackSummary['rating'] }}</strong><span>out of 5</span></div>
            <p><strong>{{ $feedbackSummary['new_count'] }}</strong> new ratings need your attention.</p>
        </div>
        <div class="feedback-list">
            @foreach ($feedbackSummary['items'] as $feedback)
                <article class="feedback-row">
                    <span class="feedback-avatar">{{ $feedback['initials'] }}</span>
                    <div>
                        <div><strong>{{ $feedback['customer'] }}</strong><span>{{ $feedback['rating'] }} ★</span></div>
                        <p>{{ $feedback['comment'] }}</p>
                    </div>
                    <button type="button" data-preview-link="Reply to {{ $feedback['customer'] }}">Reply</button>
                </article>
            @endforeach
        </div>
    </article>
</section>
@endsection
