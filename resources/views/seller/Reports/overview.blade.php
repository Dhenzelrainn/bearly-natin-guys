@extends('layouts.seller')

@section('title', 'Reports Overview')
@section('page-title', 'Reports')

@section('content')
<section class="report-statement" data-report-page>
    <header class="report-statement-header">
        <div><span class="section-kicker">Seller business statement</span><h2>{{ $periodTitle }} Business Statement</h2><p>{{ $period }} <i></i> Compared with {{ $previousLabel }}</p></div>
        <div class="report-header-actions">
            <form class="report-date-range report-date-form" method="GET" action="{{ route('seller.reports') }}" aria-label="Report date range">
                <label><span>From</span><input type="date" name="from" value="{{ $dateRange['from'] }}" max="{{ $dateRange['to'] }}"></label>
                <label><span>To</span><input type="date" name="to" value="{{ $dateRange['to'] }}" min="{{ $dateRange['from'] }}"></label>
                <button class="seller-secondary-button" type="submit"><i data-lucide="filter"></i>Apply</button>
            </form>
            <a class="seller-secondary-button" href="{{ $exportUrl }}"><i data-lucide="download"></i>Export report</a>
        </div>
    </header>

    <section class="report-ledger" aria-label="Statement summary">
        @foreach ($metrics as $metric)
            <article class="{{ ($metric['primary'] ?? false) ? 'is-primary' : '' }}">
                <span>{{ ($metric['primary'] ?? false) ? 'What you earned' : $metric['label'] }}</span>
                <strong>{{ $metric['value'] }}</strong>
                @if ($metric['primary'] ?? false)<small class="report-ledger-label">{{ $metric['label'] }}</small>@endif
                <small class="report-change"><i data-lucide="{{ $metric['icon'] }}"></i>{{ $metric['change'] }} <em>{{ $metric['context'] }}</em></small>
            </article>
        @endforeach
    </section>

    <section class="report-analysis-sheet">
        <article class="report-trend-panel">
            <div class="report-section-heading"><div><h3>How {{ $trendTitle }} moved</h3><p>{{ $trendSummary }}</p></div><span><i></i>Gross Sales</span></div>
            @if ($salesChart['has_data'])
                <div class="report-chart" aria-label="Gross completed sales for the selected period">
                    <div class="report-chart-y" aria-hidden="true">
                        @foreach ($salesChart['y_axis_points'] as $axis)
                            <span style="top:{{ round($axis['y'] / 260 * 100, 3) }}%">₱{{ $axis['label'] }}</span>
                        @endforeach
                    </div>

                    <div class="report-chart-plot" data-chart data-chart-hover="nearest">
                        <svg viewBox="0 0 720 260" role="group" aria-label="Gross completed sales trend" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="reportTrendFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#6f8251" stop-opacity=".18"/>
                                    <stop offset=".6" stop-color="#6f8251" stop-opacity=".06"/>
                                    <stop offset="1" stop-color="#6f8251" stop-opacity="0"/>
                                </linearGradient>
                            </defs>

                            <g class="report-chart-grid" aria-hidden="true">
                                @foreach ($salesChart['y_axis_points'] as $axis)
                                    @unless ($loop->first)
                                        <line x1="0" x2="720" y1="{{ $axis['y'] }}" y2="{{ $axis['y'] }}" vector-effect="non-scaling-stroke" {{ $loop->last ? 'class="is-baseline"' : '' }}/>
                                    @endunless
                                @endforeach
                            </g>

                            <path class="report-chart-area" d="{{ $salesChart['area'] }}" aria-hidden="true"/>
                            <line class="report-chart-crosshair" data-chart-crosshair aria-hidden="true" x1="0" x2="0" y1="{{ collect($salesChart['y_axis_points'])->first()['y'] }}" y2="{{ collect($salesChart['y_axis_points'])->last()['y'] }}" vector-effect="non-scaling-stroke"/>
                            <polyline class="report-chart-line" points="{{ $salesChart['polyline'] }}" vector-effect="non-scaling-stroke" aria-hidden="true"/>

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

                        <div class="report-chart-labels" aria-hidden="true">
                            @foreach ($salesChart['label_points'] as $label)
                                <span style="left:{{ round($label['x'] / 720 * 100, 3) }}%">{{ $label['label'] }}</span>
                            @endforeach
                        </div>
                    </div>

                    <table class="sr-only">
                        <caption>Gross completed sales by period</caption>
                        <thead><tr><th scope="col">Period</th><th scope="col">Gross sales</th></tr></thead>
                        <tbody>
                            @foreach ($salesChart['details'] as $detail)
                                @if ($loop->first || $salesChart['details'][$loop->index - 1]['label'] !== $detail['label'])
                                    <tr><th scope="row">{{ $detail['label'] }}</th><td>{{ $detail['value'] }}</td></tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="report-no-results report-chart-empty"><i data-lucide="bar-chart-3"></i><strong>No completed sales in this period</strong><span>Gross sales will chart here once orders complete.</span></div>
            @endif
        </article>

        <article class="report-bridge-panel">
            <div class="report-section-heading"><div><h3>From sales to earnings</h3><p>Accounting view for {{ $period }}.</p></div></div>
            <dl class="report-bridge">
                @foreach ($financialBridge as $item)<div class="{{ $item['negative'] ? 'is-deduction' : '' }}"><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach
                <div class="is-total"><dt>Net Revenue</dt><dd>{{ $netRevenue }}</dd></div>
            </dl>
            <a href="{{ route('seller.reports.financial', ['from' => $dateRange['from'], 'to' => $dateRange['to']]) }}">Open Financial Report<i data-lucide="arrow-right"></i></a>
        </article>

        <section class="report-drivers" aria-labelledby="report-drivers-title">
            <div class="report-divider-heading"><h3 id="report-drivers-title">What drove the result</h3><span></span></div>
            <div class="report-driver-grid">
                @foreach ($insights as $insight)
                    <article><span><i data-lucide="{{ $insight['icon'] }}"></i></span><div><strong>{{ $insight['title'] }}</strong><b>{{ $insight['value'] }}</b><small>{{ $insight['detail'] }}</small></div></article>
                @endforeach
            </div>
        </section>

        <section class="report-product-contribution" aria-labelledby="product-contribution-title">
            <div class="report-section-heading"><div><h3 id="product-contribution-title">Product contribution</h3><p>Ranked by net sales in the selected period.</p></div><a href="{{ route('seller.reports.sales', ['from' => $dateRange['from'], 'to' => $dateRange['to']]) }}">Open Sales &amp; Performance<i data-lucide="arrow-right"></i></a></div>
            <div class="report-table-wrap"><table class="report-table"><thead><tr><th>#</th><th>Product</th><th>Subcategory</th><th>Units Sold</th><th>Revenue</th><th>Share of Gross Sales</th></tr></thead><tbody>
                @forelse ($products as $index => $product)
                    <tr><td>{{ $index + 1 }}</td><td><span class="report-product"><i data-lucide="{{ $product['icon'] }}"></i><strong>{{ $product['name'] }}</strong></span></td><td><small class="report-product-subcategory">{{ $product['subcategory'] }}</small></td><td>{{ $product['units'] }}</td><td>{{ $product['revenue'] }}</td><td><span class="report-share"><b>{{ $product['share'] }}%</b><i><em style="width:{{ $product['share'] }}%"></em></i></span></td></tr>
                @empty
                    <tr><td colspan="6"><div class="report-no-results"><i data-lucide="package-search"></i><strong>No completed sales yet</strong><span>Product contribution will appear when completed sales are available.</span></div></td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    </section>

    <footer class="report-scope"><i data-lucide="file-text"></i><p>For transaction-level fees, adjustments, and complete breakdowns, open the <a href="{{ route('seller.reports.financial', ['from' => $dateRange['from'], 'to' => $dateRange['to']]) }}">Financial Report</a>.</p></footer>
</section>
@endsection
