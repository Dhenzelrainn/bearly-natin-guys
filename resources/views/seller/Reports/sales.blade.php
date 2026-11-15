@extends('layouts.seller')
@section('title', 'Sales & Performance')
@section('page-title', 'Reports')
@section('content')
<section class="report-detail-page report-sales-page" data-report-page>
    <header class="report-statement-header">
        <div><span class="section-kicker">Detailed report</span><h2>Sales &amp; Performance</h2><p>Understand what sold, when sales moved, and which products drove {{ $periodLabel }}.</p></div>
        <div class="report-header-actions">
            <form class="report-date-range report-date-form" method="GET" action="{{ route('seller.reports.sales') }}" aria-label="Sales report date range">
                <label><span>From</span><input type="date" name="from" value="{{ $dateRange['from'] }}" max="{{ $dateRange['to'] }}"></label>
                <label><span>To</span><input type="date" name="to" value="{{ $dateRange['to'] }}" min="{{ $dateRange['from'] }}"></label>
                <input type="hidden" name="sort" value="{{ $sort }}">
                <button class="seller-secondary-button" type="submit"><i data-lucide="filter"></i>Apply</button>
            </form>
            <a class="seller-secondary-button" href="{{ $exportUrl }}"><i data-lucide="download"></i>Export sales</a>
        </div>
    </header>
    <section class="report-detail-summary" aria-label="Sales summary">
        @foreach ($summary as $item)
            <article><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong><small><i data-lucide="{{ $item['icon'] }}"></i>{{ $item['note'] }}</small></article>
        @endforeach
    </section>
    <section class="sales-performance-lead">
        <article class="sales-momentum-panel">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Sales movement</span><h3>{{ $momentumHeading }}</h3><p>{{ $momentumSummary }}</p></div><strong>{{ $momentumChange['label'] }}<small>{{ $momentumChange['context'] }}</small></strong></div>
            <div class="sales-momentum-bars" data-chart role="group" aria-label="Gross sales by period" style="grid-template-columns:repeat({{ max(1, count($momentum)) }}, 1fr)">
                @foreach ($momentum as $period)
                    <div
                        data-chart-point
                        tabindex="{{ $loop->first ? 0 : -1 }}"
                        role="img"
                        aria-label="{{ $period['label'] }}: {{ $period['value_label'] }}"
                        data-label="{{ $period['label'] }}"
                        data-value="{{ $period['value_label'] }}"
                    ><span><i data-chart-bar style="height:{{ $period['height'] }}%"></i></span><strong>₱{{ number_format($period['value']) }}</strong><small>{{ $period['label'] }}</small></div>
                @endforeach
            </div>
        </article>
        <article class="sales-leaderboard-panel">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Product ranking</span><h3>Top-selling products</h3><p>{{ $sort === 'units' ? 'Ranked by units sold.' : 'Ranked by net sales for the selected period.' }}</p></div></div>
            <ol class="sales-leaderboard">
                @forelse ($productPerformance as $product)
                    <li><b>{{ $product['rank'] }}</b><span><i data-lucide="{{ $product['icon'] }}"></i></span><div><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }} · {{ $product['subcategory'] }}</small></div><dl><dt>{{ $product['units'] }} sold</dt><dd>{{ $product['revenue'] }}</dd></dl></li>
                @empty
                    <li class="sales-leaderboard-empty">No completed sales yet. Rankings appear once orders complete.</li>
                @endforelse
            </ol>
        </article>
    </section>
    <section class="report-detail-sheet product-sales-report" aria-labelledby="product-sales-report-title">
        <div class="report-detail-heading">
            <div><span class="section-kicker">Product performance</span><h3 id="product-sales-report-title">Product Sales Report</h3><p>See which products generated the most units and revenue for the selected period.</p></div>
            <div class="product-sales-sort" aria-label="Rank products by">
                <span>Rank by</span>
                <a href="{{ $sortUrls['net'] }}" class="{{ $sort === 'net' ? 'is-active' : '' }}" @if ($sort === 'net') aria-current="true" @endif>Net sales</a>
                <a href="{{ $sortUrls['units'] }}" class="{{ $sort === 'units' ? 'is-active' : '' }}" @if ($sort === 'units') aria-current="true" @endif>Units sold</a>
            </div>
        </div>
        <div class="report-table-wrap">
            <table class="report-table report-detail-table product-sales-table">
                <caption class="sr-only">Product sales ranking for the selected period</caption>
                <thead><tr><th scope="col">Rank</th><th scope="col">Product</th><th scope="col">Subcategory</th><th scope="col">Units Sold</th><th scope="col">Gross Sales</th><th scope="col">Discounts</th><th scope="col">Refunds</th><th scope="col">Net Sales</th><th scope="col">Share of Gross Sales</th></tr></thead>
                <tbody>
                    @forelse ($productPerformance as $product)
                        <tr>
                            <td><strong class="product-sales-rank">{{ $product['rank'] }}</strong></td>
                            <td><span class="report-product"><i data-lucide="{{ $product['icon'] }}"></i><span><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }}</small></span></span></td>
                            <td><small class="report-product-subcategory">{{ $product['subcategory'] }}</small></td>
                            <td><strong>{{ $product['units'] }}</strong></td>
                            <td>{{ $product['gross'] }}</td>
                            <td class="report-deduction-value">{{ $product['discounts'] }}</td>
                            <td class="report-deduction-value">{{ $product['refunds'] }}</td>
                            <td><strong class="report-net-value">{{ $product['net'] }}</strong></td>
                            <td><span class="report-share"><b>{{ $product['share'] }}%</b><i><em style="width:{{ $product['share'] }}%"></em></i></span></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><div class="report-no-results"><i data-lucide="package-search"></i><strong>No product sales yet</strong><span>Product performance will appear when completed sales are available.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="report-detail-footer"><span>Showing <strong>{{ count($productPerformance) }}</strong> products</span><span>Product values use order-item snapshots and exclude shipping; share uses the product subtotal total.</span></footer>
    </section>
    <section class="report-detail-sheet">
        <div class="report-detail-heading"><div><span class="section-kicker">Completed sales</span><h3>Sales by period</h3><p>Refunds and seller-funded discounts are shown separately for accurate comparison.</p></div><label><i data-lucide="search"></i><input type="search" placeholder="Search period" data-report-search></label></div>
        <div class="report-table-wrap"><table class="report-table report-detail-table"><thead><tr><th>Period</th><th>Orders</th><th>Units Sold</th><th>Gross Sales</th><th>Discounts</th><th>Refunds</th><th>Net Sales</th></tr></thead><tbody>
            @forelse ($rows as $row)
                <tr data-report-row data-search="{{ strtolower($row['period']) }}"><td><strong>{{ $row['period'] }}</strong></td><td>{{ $row['orders'] }}</td><td>{{ $row['units'] }}</td><td>{{ $row['gross'] }}</td><td class="report-deduction-value">{{ $row['discounts'] }}</td><td class="report-deduction-value">{{ $row['refunds'] }}</td><td><strong>{{ $row['netSales'] }}</strong></td></tr>
            @empty
                <tr data-report-row data-search=""><td colspan="7"><div class="report-no-results"><i data-lucide="calendar-x"></i><strong>No completed sales in this period</strong><span>Try a wider date range.</span></div></td></tr>
            @endforelse
        </tbody></table><div class="report-no-results" data-report-empty hidden><i data-lucide="search-x"></i><strong>No matching period</strong><span>Try a different search.</span></div></div>
        <footer class="report-detail-footer"><span>Showing <strong data-report-count>{{ count($rows) }}</strong> periods</span><span>Completed orders by completion date · refunds by refund completion date</span></footer>
    </section>
</section>
@endsection
