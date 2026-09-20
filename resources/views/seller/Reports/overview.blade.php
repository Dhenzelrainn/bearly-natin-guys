@extends('layouts.seller')

@section('title', 'Reports Overview')
@section('page-title', 'Reports')

@section('content')
<section class="report-statement" data-report-page>
    <header class="report-statement-header">
        <div><span class="section-kicker">Seller business statement</span><h2>August Business Statement</h2><p>{{ $period }} <i></i> Compared with July</p></div>
        <div class="report-header-actions">
            <label class="report-date-control"><i data-lucide="calendar-days"></i><span class="sr-only">Report period</span><select data-report-period><option>{{ $period }}</option><option>Jul 1 – Jul 31, 2026</option><option>Jun 1 – Jun 30, 2026</option></select></label>
            <button class="seller-secondary-button" type="button" data-report-export><i data-lucide="download"></i>Export report</button>
        </div>
    </header>

    <section class="report-ledger" aria-label="Statement summary">
        @foreach ($metrics as $metric)
            <article class="{{ ($metric['primary'] ?? false) ? 'is-primary' : '' }}">
                <span>{{ ($metric['primary'] ?? false) ? 'What you earned' : $metric['label'] }}</span>
                <strong>{{ $metric['value'] }}</strong>
                @if ($metric['primary'] ?? false)<small class="report-ledger-label">{{ $metric['label'] }}</small>@endif
                <small class="report-change"><i data-lucide="arrow-up-right"></i>{{ $metric['change'] }} <em>{{ $metric['context'] }}</em></small>
            </article>
        @endforeach
    </section>

    <section class="report-analysis-sheet">
        <article class="report-trend-panel">
            <div class="report-section-heading"><div><h3>How August moved</h3><p>Sales closed 12.5% above July.</p></div><span><i></i>Gross Sales</span></div>
            <div class="report-chart">
                <div class="report-y-axis"><span>₱30K</span><span>₱20K</span><span>₱10K</span><span>₱0</span></div>
                <div class="report-chart-plot">
                    <svg viewBox="0 0 700 220" role="img" aria-label="Gross sales for seven periods in August">
                        <defs><linearGradient id="reportArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#77885b" stop-opacity=".22"/><stop offset="1" stop-color="#77885b" stop-opacity="0"/></linearGradient></defs>
                        <path class="report-chart-area" d="M24 137 L132 115 L240 103 L348 112 L456 120 L564 96 L676 92 L676 190 L24 190 Z"/>
                        <polyline class="report-chart-line" points="24,137 132,115 240,103 348,112 456,120 564,96 676,92"/>
                        @foreach ([[24,137],[132,115],[240,103],[348,112],[456,120],[564,96],[676,92]] as [$x,$y])<circle cx="{{ $x }}" cy="{{ $y }}" r="4"/>@endforeach
                    </svg>
                    <div class="report-chart-labels">@foreach ($salesLabels as $label)<span>{{ $label }}</span>@endforeach</div>
                </div>
            </div>
        </article>

        <article class="report-bridge-panel">
            <div class="report-section-heading"><div><h3>From sales to earnings</h3><p>Accounting view for {{ $period }}.</p></div></div>
            <dl class="report-bridge">
                @foreach ($financialBridge as $item)<div class="{{ $item['negative'] ? 'is-deduction' : '' }}"><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach
                <div class="is-total"><dt>Net Revenue</dt><dd>₱109,876.00</dd></div>
            </dl>
            <a href="{{ route('seller.reports.financial') }}">Open Financial Report<i data-lucide="arrow-right"></i></a>
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
            <div class="report-section-heading"><div><h3 id="product-contribution-title">Product contribution</h3><p>Ranked by gross sales in the selected period.</p></div><a href="{{ route('seller.reports.sales') }}">Open Sales & Performance<i data-lucide="arrow-right"></i></a></div>
            <div class="report-table-wrap"><table class="report-table"><thead><tr><th>#</th><th>Product</th><th>Units Sold</th><th>Revenue</th><th>Share of Gross Sales</th></tr></thead><tbody>
                @foreach ($products as $index => $product)<tr><td>{{ $index + 1 }}</td><td><span class="report-product"><i data-lucide="{{ $product['icon'] }}"></i><strong>{{ $product['name'] }}</strong></span></td><td>{{ $product['units'] }}</td><td>{{ $product['revenue'] }}</td><td><span class="report-share"><b>{{ $product['share'] }}%</b><i><em style="width:{{ $product['share'] }}%"></em></i></span></td></tr>@endforeach
            </tbody></table></div>
        </section>
    </section>

    <footer class="report-scope"><i data-lucide="file-text"></i><p>For transaction-level fees, adjustments, and complete breakdowns, open the <a href="{{ route('seller.reports.financial') }}">Financial Report</a>.</p></footer>
</section>
@endsection
