@extends('layouts.admin')

@section('title', 'Reports')
@section('page-title', 'Generate Reports')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 07</span><h1>Generate reports</h1><p>Preview sales summary and commission reporting screens with static visualizations and export controls.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-mock-action="PDF report preview generated."><i data-lucide="file-down"></i> Export PDF</button><button class="button button-primary" type="button" data-mock-action="CSV export preview generated."><i data-lucide="sheet"></i> Export CSV</button></div>
</section>

<section class="report-filter-bar panel">
    <div><span class="section-label">Report period</span><div class="date-range-inline"><input class="date-field" type="date" value="2026-01-01"><span>to</span><input class="date-field" type="date" value="2026-08-24"></div></div>
    <label class="form-field inline-filter"><span>Report type</span><select class="select-field"><option>Sales Summary</option><option>Commission Report</option></select></label>
    <button class="button button-primary" type="button" data-mock-action="Report filters applied."><i data-lucide="sliders-horizontal"></i> Apply filters</button>
</section>

<section class="kpi-grid">
    @foreach ($reportKpis as $metric)
        <article class="kpi-card report-kpi"><p>{{ $metric['label'] }}</p><strong>{{ $metric['value'] }}</strong><small>{{ $metric['note'] }}</small></article>
    @endforeach
</section>

<section class="dashboard-grid dashboard-grid-main">
    <article class="panel panel-large">
        <div class="panel-heading"><div><span class="eyebrow">Sales summary report</span><h2>Monthly sales performance</h2><p>Static visual preview for admin reporting.</p></div><span class="status-badge badge-success">+11.2%</span></div>
        <div class="line-chart-shell">
            <div class="line-chart-grid"></div>
            <svg class="line-chart-svg" viewBox="0 0 1100 320" preserveAspectRatio="none" aria-label="Sales trend chart">
                @php
                    $points = collect($salesSeries)->map(function($value, $i) use ($salesSeries) {
                        $x = ($i / (count($salesSeries)-1)) * 1080 + 10;
                        $max = max($salesSeries); $min = min($salesSeries);
                        $y = 285 - (($value-$min) / max(1, $max-$min)) * 235;
                        return $x.','.$y;
                    })->implode(' ');
                @endphp
                <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <div class="chart-months">@foreach (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $month)<span>{{ $month }}</span>@endforeach</div>
        </div>
    </article>
    <aside class="panel report-breakdown">
        <div class="panel-heading"><div><span class="eyebrow">Order channels</span><h2>Marketplace mix</h2></div></div>
        <div class="donut-wrap"><div class="css-donut"><span>8,421<small>orders</small></span></div></div>
        <div class="legend-list"><div><span class="legend-dot legend-a"></span><strong>Buyer marketplace</strong><em>68%</em></div><div><span class="legend-dot legend-b"></span><strong>Repeat purchases</strong><em>21%</em></div><div><span class="legend-dot legend-c"></span><strong>Promo-driven</strong><em>11%</em></div></div>
    </aside>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">Commission report</span><h2>Top seller contribution</h2></div><button class="text-button" type="button" data-mock-action="Full commission report opened.">View full report</button></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Seller</th><th class="align-right">Gross sales</th><th class="align-right">Platform commission</th><th>Performance</th></tr></thead><tbody>
        @foreach ($topSellers as $index => $seller)
            <tr><td><div class="identity-cell"><span class="avatar avatar-soft">{{ $index + 1 }}</span><div><strong>{{ $seller['seller'] }}</strong><small>Top performing seller</small></div></div></td><td class="align-right">{{ $seller['sales'] }}</td><td class="align-right commission-value">{{ $seller['commission'] }}</td><td><div class="progress-track"><span style="width: {{ 92 - ($index * 12) }}%"></span></div></td></tr>
        @endforeach
    </tbody></table></div>
</section>
@endsection
