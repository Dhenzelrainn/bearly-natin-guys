@extends('layouts.seller')
@section('title', 'Sales & Performance')
@section('page-title', 'Reports')
@section('content')
<section class="report-detail-page report-sales-page" data-report-page>
    <header class="report-statement-header">
        <div><span class="section-kicker">Detailed report</span><h2>Sales & Performance</h2><p>Understand what sold, when sales moved, and which products drove the period.</p></div>
        <div class="report-header-actions"><label class="report-date-control"><i data-lucide="calendar-days"></i><select data-report-period aria-label="Report period"><option>Aug 1 – Aug 31, 2026</option><option>Jul 1 – Jul 31, 2026</option></select></label><button class="seller-secondary-button" type="button" data-report-export><i data-lucide="download"></i>Export sales</button></div>
    </header>
    <section class="report-detail-summary" aria-label="Sales summary">@foreach ($summary as $item)<article><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong><small><i data-lucide="trending-up"></i>{{ $item['note'] }}</small></article>@endforeach</section>
    <section class="sales-performance-lead">
        <article class="sales-momentum-panel">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Sales movement</span><h3>Momentum through August</h3><p>Gross sales increased across the month despite a slower opening week.</p></div><strong>+12.5%<small>vs July</small></strong></div>
            <div class="sales-momentum-bars" aria-label="Gross sales by period">@foreach ($momentum as $period)<div><span><i style="height:{{ $period['height'] }}%"></i></span><strong>₱{{ number_format($period['value']) }}</strong><small>{{ $period['label'] }}</small></div>@endforeach</div>
        </article>
        <article class="sales-leaderboard-panel">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Product ranking</span><h3>Top contributors</h3><p>Ranked by gross revenue.</p></div></div>
            <ol class="sales-leaderboard">@foreach ($productPerformance as $product)<li><b>{{ $product['rank'] }}</b><span><i data-lucide="{{ $product['icon'] }}"></i></span><div><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }}</small></div><dl><dt>{{ $product['units'] }} sold</dt><dd>{{ $product['revenue'] }}</dd></dl></li>@endforeach</ol>
        </article>
    </section>
    <section class="report-detail-sheet">
        <div class="report-detail-heading"><div><span class="section-kicker">Completed sales</span><h3>Sales by period</h3><p>Refunds and seller-funded discounts are shown separately for accurate comparison.</p></div><label><i data-lucide="search"></i><input type="search" placeholder="Search period" data-report-search></label></div>
        <div class="report-table-wrap"><table class="report-table report-detail-table"><thead><tr><th>Period</th><th>Orders</th><th>Units Sold</th><th>Gross Sales</th><th>Discounts</th><th>Refunds</th><th>Net Sales</th></tr></thead><tbody>
            @foreach ($rows as $row)<tr data-report-row data-search="{{ strtolower($row['period']) }}"><td><strong>{{ $row['period'] }}</strong></td><td>{{ $row['orders'] }}</td><td>{{ $row['units'] }}</td><td>{{ $row['gross'] }}</td><td class="report-deduction-value">−{{ $row['discounts'] }}</td><td class="report-deduction-value">{{ $row['refunds'] === '₱0' ? '—' : '−'.$row['refunds'] }}</td><td><strong>{{ $row['netSales'] }}</strong></td></tr>@endforeach
        </tbody></table><div class="report-no-results" data-report-empty hidden><i data-lucide="search-x"></i><strong>No matching period</strong><span>Try a different search.</span></div></div>
        <footer class="report-detail-footer"><span>Showing <strong data-report-count>{{ count($rows) }}</strong> periods</span><span>Completed orders only · Frontend preview</span></footer>
    </section>
</section>
@endsection
