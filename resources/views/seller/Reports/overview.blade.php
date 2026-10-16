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
                <div class="report-chart">
                    <div class="report-y-axis">@foreach ($salesChart['y_axis'] as $axisLabel)<span>₱{{ $axisLabel }}</span>@endforeach</div>
                    <div class="report-chart-plot">
                        <svg viewBox="0 0 700 220" role="img" aria-label="Gross completed sales for the selected period">
                            <defs><linearGradient id="reportArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#77885b" stop-opacity=".22"/><stop offset="1" stop-color="#77885b" stop-opacity="0"/></linearGradient></defs>
                            <path class="report-chart-area" d="{{ $salesChart['area'] }}"/>
                            <polyline class="report-chart-line" points="{{ $salesChart['polyline'] }}"/>
                            @foreach ($salesChart['points'] as $point)<circle cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="4"/>@endforeach
                        </svg>
                        <div class="report-chart-labels" style="grid-template-columns:repeat({{ max(1, count($salesChart['labels'])) }}, 1fr)">@foreach ($salesChart['labels'] as $label)<span>{{ $label }}</span>@endforeach</div>
                    </div>
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
