@extends('layouts.seller')

@section('title', 'Seller Dashboard')
@section('page-title', 'Dashboard')

@section('topbar-subtitle')
    Welcome back! Here’s what’s happening with your store today.
@endsection

@section('topbar-controls')
    <label class="seller-date-control" aria-label="Dashboard date range">
        <i data-lucide="calendar-days" aria-hidden="true"></i>
        <select aria-label="Dashboard date range">
            <option>Aug 1, 2026 – Aug 31, 2026</option>
            <option>Last 7 days</option>
            <option>Last 30 days</option>
            <option>This month</option>
        </select>
    </label>
@endsection

@section('content')
@php
    $dashboardActions = [
        [
            'count' => 8,
            'label' => 'Orders to Confirm',
            'detail' => 'Confirm within 2 hours',
            'icon' => 'shopping-cart',
            'route' => route('seller.orders.new'),
        ],
        [
            'count' => 5,
            'label' => 'Low-Stock Products',
            'detail' => 'Restock to avoid stockouts',
            'icon' => 'package',
            'route' => route('seller.inventory'),
        ],
        [
            'count' => 3,
            'label' => 'Return Requests',
            'detail' => 'Awaiting your response',
            'icon' => 'rotate-ccw',
            'route' => route('seller.orders.returns'),
        ],
        [
            'count' => 5,
            'label' => 'Orders Ready for Pickup',
            'detail' => 'Ready for courier collection',
            'icon' => 'truck',
            'route' => route('seller.fulfillment.pickups'),
        ],
    ];

    $performanceCards = [
        ['label' => 'Total Sales', 'value' => '₱128,450', 'change' => '↑ 12.5%'],
        ['label' => 'Total Orders', 'value' => '91', 'change' => '↑ 8.3%'],
        ['label' => 'Net Earnings', 'value' => '₱109,876', 'change' => '↑ 10.8%'],
        ['label' => 'Platform Commission', 'value' => '₱12,845', 'change' => '↑ 8.1%'],
    ];

    $dashboardProducts = [
        ['name' => 'Classic Linen Shirt', 'variant' => 'Natural / Medium', 'sold' => 38, 'revenue' => '₱52,440', 'icon' => 'shirt'],
        ['name' => 'Canvas Tote Bag', 'variant' => 'Natural Canvas', 'sold' => 29, 'revenue' => '₱34,018', 'icon' => 'shopping-bag'],
        ['name' => 'Everyday Sneakers', 'variant' => 'White / Size 39', 'sold' => 24, 'revenue' => '₱25,320', 'icon' => 'footprints'],
        ['name' => 'Classic Brown Tee', 'variant' => 'Brown / Large', 'sold' => 18, 'revenue' => '₱17,820', 'icon' => 'shirt'],
        ['name' => 'Everyday Cap', 'variant' => 'Embroidered', 'sold' => 12, 'revenue' => '₱7,800', 'icon' => 'circle-dot'],
    ];

    $healthRows = [
        ['label' => 'Account Status', 'value' => 'Healthy', 'tone' => 'success'],
        ['label' => 'Order Fulfillment Rate', 'value' => '98%', 'tone' => 'success'],
        ['label' => 'Response Rate', 'value' => '100%', 'tone' => 'success'],
        ['label' => 'Cancellation Rate', 'value' => '< 1%', 'tone' => 'success'],
        ['label' => 'Product Stock Level', 'value' => '5 low-stock items', 'tone' => 'warning'],
    ];
@endphp

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
                        <strong>₱128,450</strong>
                        <span>↑ 12.5%</span>
                        <small>from previous period</small>
                    </div>
                </div>

                <div class="dashboard-ref-chart-controls">
                    <select aria-label="Sales metric">
                        <option>Total Sales</option>
                        <option>Net Earnings</option>
                    </select>
                    <select aria-label="Sales interval">
                        <option>Daily</option>
                        <option>Weekly</option>
                    </select>
                </div>
            </header>

            <div class="dashboard-ref-chart" aria-label="Sales overview for August 2026">
                <div class="dashboard-ref-chart-y" aria-hidden="true">
                    <span>20,000</span>
                    <span>15,000</span>
                    <span>10,000</span>
                    <span>5,000</span>
                    <span>0</span>
                </div>

                <div class="dashboard-ref-chart-plot">
                    <span class="dashboard-ref-grid-line line-1"></span>
                    <span class="dashboard-ref-grid-line line-2"></span>
                    <span class="dashboard-ref-grid-line line-3"></span>
                    <span class="dashboard-ref-grid-line line-4"></span>
                    <span class="dashboard-ref-grid-line line-5"></span>

                    <svg viewBox="0 0 760 230" role="img" aria-label="Daily sales trend" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="dashboardSalesFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#E9A72F" stop-opacity=".20"/>
                                <stop offset="1" stop-color="#E9A72F" stop-opacity="0"/>
                            </linearGradient>
                        </defs>

                        <path
                            class="dashboard-ref-chart-area"
                            d="M10 185 L60 162 L105 184 L150 148 L195 171 L240 143 L285 176 L330 134 L375 164 L420 124 L465 151 L510 135 L555 160 L600 118 L645 86 L690 122 L730 95 L752 60 L752 214 L10 214 Z"
                        />
                        <polyline
                            class="dashboard-ref-chart-line"
                            points="10,185 60,162 105,184 150,148 195,171 240,143 285,176 330,134 375,164 420,124 465,151 510,135 555,160 600,118 645,86 690,122 730,95 752,60"
                        />

                        @foreach ([
                            [10,185],[60,162],[105,184],[150,148],[195,171],[240,143],
                            [285,176],[330,134],[375,164],[420,124],[465,151],[510,135],
                            [555,160],[600,118],[645,86],[690,122],[730,95],[752,60]
                        ] as $point)
                            <circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="4.5" />
                        @endforeach
                    </svg>

                    <div class="dashboard-ref-chart-days" aria-hidden="true">
                        <span>Aug 1</span>
                        <span>Aug 5</span>
                        <span>Aug 10</span>
                        <span>Aug 15</span>
                        <span>Aug 20</span>
                        <span>Aug 25</span>
                        <span>Aug 31</span>
                    </div>
                </div>
            </div>
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
                @foreach ($dashboardProducts as $index => $product)
                    <article class="dashboard-ref-product-row">
                        <span class="dashboard-ref-product-rank">{{ $index + 1 }}</span>

                        <span class="dashboard-ref-product-thumb" aria-hidden="true">
                            <i data-lucide="{{ $product['icon'] }}"></i>
                        </span>

                        <div class="dashboard-ref-product-name">
                            <strong>{{ $product['name'] }}</strong>
                            <small>{{ $product['variant'] }}</small>
                        </div>

                        <strong class="dashboard-ref-product-sold">{{ $product['sold'] }}</strong>
                        <strong class="dashboard-ref-product-revenue">{{ $product['revenue'] }}</strong>
                    </article>
                @endforeach
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
                    <strong>₱109,876</strong>
                    <span>Net Earnings</span>
                    <small>↑ 10.8%</small>
                </div>

                <dl class="dashboard-ref-earnings-breakdown">
                    <div>
                        <dt>Total Sales</dt>
                        <dd>₱128,450</dd>
                    </div>
                    <div>
                        <dt>Platform Commission</dt>
                        <dd>− ₱12,845</dd>
                    </div>
                    <div>
                        <dt>Other Fees</dt>
                        <dd>− ₱5,729</dd>
                    </div>
                    <div class="is-total">
                        <dt>Net Earnings</dt>
                        <dd>₱109,876</dd>
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
                    <strong>5</strong>
                    <span>Orders for Pickup</span>
                    <small>Ready for courier collection</small>
                </div>
            </div>

            <div class="dashboard-ref-pickup-schedule">
                <i data-lucide="calendar-days" aria-hidden="true"></i>
                <div>
                    <span>Next Pickup Schedule</span>
                    <strong>Today</strong>
                    <small>{{ $pickupSummary['time'] ?? '3:00 PM' }} · {{ $pickupSummary['date'] ?? 'Laguna route' }}</small>
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
                            <i data-lucide="{{ $item['tone'] === 'warning' ? 'circle-alert' : 'circle-check-big' }}"></i>
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
