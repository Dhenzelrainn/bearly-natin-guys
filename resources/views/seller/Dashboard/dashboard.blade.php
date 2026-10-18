@extends('layouts.seller')

@section('title', 'Seller Dashboard')
@section('page-title', 'Dashboard')

@section('topbar-subtitle')
    Welcome back! Here’s what’s happening with your store today.
@endsection

@section('topbar-controls')
    <form class="seller-date-control" method="GET" action="{{ route('seller.dashboard') }}" aria-label="Dashboard date range">
        <i data-lucide="calendar-days" aria-hidden="true"></i>
        <select name="period" aria-label="Dashboard date range" data-period-nav>
            <option value="this_month" @selected($periodKey === 'this_month')>This month</option>
            <option value="last_month" @selected($periodKey === 'last_month')>Last month</option>
            <option value="last_7" @selected($periodKey === 'last_7')>Last 7 days</option>
            <option value="last_30" @selected($periodKey === 'last_30')>Last 30 days</option>
        </select>
        <input type="hidden" name="interval" value="{{ $chartInterval }}">
        <noscript><button type="submit">Apply</button></noscript>
    </form>
@endsection

@section('content')
<div class="dashboard-ref-page">

    <section class="dashboard-ref-reminder" aria-label="Order workflow reminder">
        <span class="dashboard-ref-reminder-icon" aria-hidden="true">
            <i data-lucide="calendar-check-2"></i>
        </span>

        <div class="dashboard-ref-reminder-copy">
            <strong>Keep your store running smoothly</strong>
            <p>Confirm pending orders, prepare for pickup, and stay on top of customer requests.</p>
        </div>

        <a href="{{ route('seller.orders') }}" class="dashboard-ref-reminder-action">
            View All Orders
            <i data-lucide="arrow-right" aria-hidden="true"></i>
        </a>

        <button
            type="button"
            class="dashboard-ref-dismiss"
            aria-label="Dismiss reminder"
            onclick="this.closest('.dashboard-ref-reminder').style.display='none'"
        >
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </section>

    <section class="dashboard-ref-panel dashboard-ref-actions" aria-labelledby="dashboard-actions-title">
        <header class="dashboard-ref-section-header">
            <div>
                <h2 id="dashboard-actions-title">
                    <span class="dashboard-ref-alert-dot" aria-hidden="true">
                        <i data-lucide="circle-alert"></i>
                    </span>
                    Action Required
                </h2>
            </div>

            <a href="{{ route('seller.orders') }}" class="dashboard-ref-view-link">
                View All
            </a>
        </header>

        <div class="dashboard-ref-action-grid">
            @foreach ($dashboardActions as $action)
                <a href="{{ $action['route'] }}" class="dashboard-ref-action-card">
                    <span class="dashboard-ref-action-icon" aria-hidden="true">
                        <i data-lucide="{{ $action['icon'] }}"></i>
                    </span>

                    <span class="dashboard-ref-action-copy">
                        <strong>{{ $action['count'] }}</strong>
                        <span>{{ $action['label'] }}</span>
                        <small>{{ $action['detail'] }}</small>
                    </span>

                    <i class="dashboard-ref-chevron" data-lucide="chevron-right" aria-hidden="true"></i>
                </a>
            @endforeach
        </div>
    </section>

    <section class="dashboard-ref-panel dashboard-ref-performance" aria-labelledby="dashboard-performance-title">
        <header class="dashboard-ref-section-header">
            <h2 id="dashboard-performance-title">Business Performance</h2>
            <span class="dashboard-ref-period-note">vs. previous period</span>
        </header>

        <div class="dashboard-ref-performance-grid">
            @foreach ($performanceCards as $card)
                <article class="dashboard-ref-performance-card">
                    <strong>{{ $card['value'] }}</strong>
                    <div>
                        <span>{{ $card['label'] }}</span>
                        <small>{{ $card['change'] }}</small>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="dashboard-ref-main-grid">
        <article class="dashboard-ref-panel dashboard-ref-sales-panel">
            <header class="dashboard-ref-panel-heading">
                <div>
                    <h2>Sales Overview</h2>
                    <div class="dashboard-ref-sales-total">
                        <strong>{{ $salesTotal }}</strong>
                        <span>{{ $salesChange }}</span>
                        <small>{{ $salesChangeNote }}</small>
                    </div>
                </div>

                <form class="dashboard-ref-chart-controls" method="GET" action="{{ route('seller.dashboard') }}" aria-label="Sales chart filters">
                    <input type="hidden" name="period" value="{{ $periodKey }}">
                    <select name="interval" aria-label="Sales interval" data-period-nav>
                        <option value="daily" @selected($chartInterval === 'daily')>Daily</option>
                        <option value="weekly" @selected($chartInterval === 'weekly')>Weekly</option>
                    </select>
                    <noscript><button type="submit">Apply</button></noscript>
                </form>
            </header>

            @if ($salesChart['has_data'])
                <div class="dashboard-ref-chart" aria-label="Sales overview for {{ $periodLabel }}">
                    <div class="dashboard-ref-chart-y" aria-hidden="true">
                        @foreach ($salesChart['y_axis_points'] as $axis)
                            <span style="top:{{ round($axis['y'] / 260 * 100, 3) }}%">₱{{ $axis['label'] }}</span>
                        @endforeach
                    </div>

                    <div class="dashboard-ref-chart-plot" data-chart data-chart-hover="nearest">
                        <svg viewBox="0 0 720 260" role="group" aria-label="Gross completed sales trend" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="dashboardSalesFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#6f8251" stop-opacity=".18"/>
                                    <stop offset=".6" stop-color="#6f8251" stop-opacity=".06"/>
                                    <stop offset="1" stop-color="#6f8251" stop-opacity="0"/>
                                </linearGradient>
                            </defs>

                            <g class="dashboard-ref-chart-grid" aria-hidden="true">
                                @foreach ($salesChart['y_axis_points'] as $axis)
                                    @unless ($loop->first)
                                        <line x1="0" x2="720" y1="{{ $axis['y'] }}" y2="{{ $axis['y'] }}" vector-effect="non-scaling-stroke" {{ $loop->last ? 'class="is-baseline"' : '' }}/>
                                    @endunless
                                @endforeach
                            </g>

                            <path
                                class="dashboard-ref-chart-area"
                                d="{{ $salesChart['area'] }}"
                                aria-hidden="true"
                            />
                            <line class="dashboard-ref-chart-crosshair" data-chart-crosshair aria-hidden="true" x1="0" x2="0" y1="{{ collect($salesChart['y_axis_points'])->first()['y'] }}" y2="{{ collect($salesChart['y_axis_points'])->last()['y'] }}" vector-effect="non-scaling-stroke"/>
                            <polyline
                                class="dashboard-ref-chart-line"
                                points="{{ $salesChart['polyline'] }}"
                                vector-effect="non-scaling-stroke"
                                aria-hidden="true"
                            />

                            @foreach ($salesChart['details'] as $detail)
                                <circle
                                    cx="{{ $detail['x'] }}"
                                    cy="{{ $detail['y'] }}"
                                    r="4.5"
                                    data-chart-point
                                    tabindex="{{ $loop->first ? 0 : -1 }}"
                                    role="img"
                                    aria-label="{{ $detail['label'] }}: {{ $detail['value'] }}"
                                    data-label="{{ $detail['label'] }}"
                                    data-value="{{ $detail['value'] }}"
                                />
                            @endforeach
                        </svg>

                        <div class="dashboard-ref-chart-days" aria-hidden="true">
                            @foreach ($salesChart['label_points'] as $label)
                                <span style="left:{{ round($label['x'] / 720 * 100, 3) }}%">{{ $label['label'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="dashboard-ref-chart-empty report-no-results"><i data-lucide="bar-chart-3"></i><strong>No completed sales in this period</strong><span>Gross sales will chart here once orders complete.</span></div>
            @endif

            <p class="dashboard-ref-chart-note">Gross completed sales by completion date for {{ $periodLabel }}. Cancelled orders are excluded.</p>
        </article>

        <article class="dashboard-ref-panel dashboard-ref-products-panel">
            <header class="dashboard-ref-panel-heading">
                <h2>Top-Selling Products</h2>
                <a href="{{ route('seller.reports.sales') }}" class="dashboard-ref-view-link">View All</a>
            </header>

            <div class="dashboard-ref-product-head" aria-hidden="true">
                <span>#</span>
                <span>Product</span>
                <span>Sold</span>
                <span>Revenue</span>
            </div>

            <div class="dashboard-ref-product-list">
                @forelse ($dashboardProducts as $index => $product)
                    <article class="dashboard-ref-product-row">
                        <span class="dashboard-ref-product-rank">{{ $index + 1 }}</span>

                        <span class="dashboard-ref-product-thumb" aria-hidden="true">
                            @if ($product['image_url'] !== '')
                                <img src="{{ $product['image_url'] }}" alt="" loading="lazy">
                            @else
                                <i data-lucide="{{ $product['icon'] }}"></i>
                            @endif
                        </span>

                        <div class="dashboard-ref-product-name">
                            <strong>{{ $product['name'] }}</strong>
                            <small>{{ $product['variant'] }}</small>
                        </div>

                        <strong class="dashboard-ref-product-sold">{{ $product['sold'] }}</strong>
                        <strong class="dashboard-ref-product-revenue">{{ $product['revenue'] }}</strong>
                    </article>
                @empty
                    <p class="dashboard-ref-product-empty">No completed sales yet. Top products will appear here once orders complete.</p>
                @endforelse
            </div>
        </article>
    </section>

    <section class="dashboard-ref-bottom-grid">
        <article class="dashboard-ref-panel dashboard-ref-earnings-panel">
            <header class="dashboard-ref-panel-heading">
                <h2>Earnings Summary</h2>
                <a href="{{ route('seller.finance.earnings') }}" class="dashboard-ref-view-link">View Details</a>
            </header>

            <div class="dashboard-ref-earnings-layout">
                <div class="dashboard-ref-earnings-total">
                    <strong>{{ $earningsTotal }}</strong>
                    <span>Net Earnings</span>
                    <small>{{ $earningsChange }}</small>
                </div>

                <dl class="dashboard-ref-earnings-breakdown">
                    @foreach ($earningsBreakdown as $row)
                        <div>
                            <dt>{{ $row['label'] }}</dt>
                            <dd>{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                    <div class="is-total">
                        <dt>Net Earnings</dt>
                        <dd>{{ $earningsNet }}</dd>
                    </div>
                </dl>
            </div>
        </article>

        <article class="dashboard-ref-panel dashboard-ref-pickup-panel">
            <header class="dashboard-ref-panel-heading">
                <h2>Upcoming Pickup</h2>
                <a href="{{ route('seller.fulfillment.pickups') }}" class="dashboard-ref-view-link">View All</a>
            </header>

            <div class="dashboard-ref-pickup-summary">
                <span class="dashboard-ref-pickup-icon" aria-hidden="true">
                    <i data-lucide="truck"></i>
                </span>

                <div>
                    <strong>{{ $pickupSummary['count'] }}</strong>
                    <span>Orders for Pickup</span>
                    <small>{{ $pickupSummary['count'] > 0 ? 'Ready for courier collection' : 'No parcels ready yet' }}</small>
                </div>
            </div>

            <div class="dashboard-ref-pickup-schedule">
                <i data-lucide="calendar-days" aria-hidden="true"></i>
                <div>
                    <span>Next Pickup Schedule</span>
                    @if ($pickupSummary['has_schedule'])
                        <strong>{{ $pickupSummary['date_label'] }}</strong>
                        <small>{{ $pickupSummary['time_label'] }}</small>
                    @else
                        <strong>No pickup scheduled</strong>
                        <small>Submit a pickup request once parcels are ready.</small>
                    @endif
                </div>
            </div>
        </article>

        <article class="dashboard-ref-panel dashboard-ref-health-panel">
            <header class="dashboard-ref-panel-heading">
                <h2>Seller Health</h2>
                <a href="{{ route('seller.settings.account') }}" class="dashboard-ref-view-link">View Details</a>
            </header>

            <div class="dashboard-ref-health-list">
                @foreach ($healthRows as $item)
                    <div class="dashboard-ref-health-row tone-{{ $item['tone'] }}">
                        <span class="dashboard-ref-health-icon" aria-hidden="true">
                            <i data-lucide="{{ $item['tone'] === 'warning' ? 'circle-alert' : ($item['tone'] === 'neutral' ? 'circle-dot' : 'circle-check-big') }}"></i>
                        </span>
                        <span>{{ $item['label'] }}</span>
                        <strong>{{ $item['value'] }}</strong>
                    </div>
                @endforeach
            </div>
        </article>
    </section>

</div>
@endsection
