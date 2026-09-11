@extends('layouts.seller')
@section('title', 'Financial Report')
@section('page-title', 'Reports')
@section('content')
<section class="report-detail-page report-financial-page" data-report-page>
    <header class="report-statement-header">
        <div><span class="section-kicker">Detailed report</span><h2>Financial Report</h2><p>See how gross completed sales become seller net earnings after discounts, refunds, and platform commission.</p></div>
        <div class="report-header-actions">
            <form class="report-date-range report-date-form" method="GET" action="{{ route('seller.reports.financial') }}" aria-label="Financial report date range">
                <label><span>From</span><input type="date" name="from" value="{{ $dateRange['from'] }}" max="{{ $dateRange['to'] }}"></label>
                <label><span>To</span><input type="date" name="to" value="{{ $dateRange['to'] }}" min="{{ $dateRange['from'] }}"></label>
                <button class="seller-secondary-button" type="submit"><i data-lucide="filter"></i>Apply</button>
            </form>
            <a class="seller-secondary-button" href="{{ route('seller.finance.transactions') }}"><i data-lucide="receipt-text"></i>Transactions</a>
            <button class="seller-secondary-button" type="button" data-report-export><i data-lucide="download"></i>Export statement</button>
        </div>
    </header>

    <p class="report-range-preview-note"><i data-lucide="info"></i> The From/To controls are now wired as real report parameters. While the project is still using preview data, the sample totals remain fixed until the database/report query is connected.</p>

    <section class="report-commission-note"><i data-lucide="badge-percent"></i><div><strong>Platform commission is calculated on eligible completed sales.</strong><p>For the current Bearly project, the 10% commission is finalized at <strong>COMPLETED</strong>, cancelled orders have no commission, and finalized full refunds reverse the related commission.</p></div></section>

    <section class="report-detail-summary financial-summary" aria-label="Financial summary">
        @foreach ($summary as $item)<article><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong><small>{{ $item['note'] }}</small></article>@endforeach
    </section>

    <section class="financial-statement-lead">
        <article class="financial-current-statement">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Current statement</span><h3>Selected-period earnings</h3><p>{{ $dateRange['from'] }} to {{ $dateRange['to'] }} · Philippine Peso</p></div><span class="report-status">Processing</span></div>
            <dl>
                @foreach ($currentStatement as $index => $item)<div class="{{ $index > 0 ? 'is-deduction' : '' }}"><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach
                <div class="is-net"><dt><span>Net Earnings</span><small>Seller amount after deductions</small></dt><dd>{{ $statementNetEarnings }}</dd></div>
            </dl>
        </article>
        <article class="financial-deduction-panel">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Cost composition</span><h3>Where deductions went</h3><p>{{ $statementTotalDeductions }} total deductions.</p></div></div>
            <div class="financial-deduction-total"><strong>{{ $deductionRate }}</strong><span>of gross sales</span></div>
            <div class="financial-deduction-list">
                @foreach ($deductions as $item)
                    <div><header><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong></header><span class="deduction-track"><i class="tone-{{ $item['tone'] }}" style="width:{{ $item['percent'] }}%"></i></span><small>{{ $item['percent'] }}% of deductions</small></div>
                @endforeach
            </div>
        </article>
    </section>

    <section class="report-detail-sheet">
        <div class="report-detail-heading"><div><span class="section-kicker">Statement archive</span><h3>Monthly financial history</h3><p>Compare gross sales, seller-funded deductions, platform commission, and net earnings.</p></div><label><i data-lucide="search"></i><input type="search" placeholder="Search month or status" data-report-search></label></div>
        <div class="report-table-wrap">
            <table class="report-table report-detail-table"><thead><tr><th>Statement Period</th><th>Gross Sales</th><th>Discounts</th><th>Refunds</th><th>Platform Commission</th><th>Net Earnings</th><th>Status</th></tr></thead><tbody>
                @foreach ($rows as $row)
                    <tr data-report-row data-search="{{ strtolower($row['period'].' '.$row['status']) }}"><td><strong>{{ $row['period'] }}</strong></td><td>{{ $row['gross'] }}</td><td class="report-deduction-value">−{{ $row['discounts'] }}</td><td class="report-deduction-value">{{ $row['refunds'] === '₱0' ? '—' : '−'.$row['refunds'] }}</td><td class="report-deduction-value">−{{ $row['commission'] }}</td><td><strong class="report-net-value">{{ $row['net'] }}</strong></td><td><span class="report-status {{ strtolower($row['status']) === 'paid' ? 'is-paid' : '' }}">{{ $row['status'] }}</span></td></tr>
                @endforeach
            </tbody></table>
            <div class="report-no-results" data-report-empty hidden><i data-lucide="search-x"></i><strong>No matching statement</strong><span>Try a different search.</span></div>
        </div>
        <footer class="report-detail-footer"><span>Showing <strong data-report-count>{{ count($rows) }}</strong> statements</span><span>Net Earnings is not labeled “profit” because product cost is not yet tracked.</span></footer>
    </section>
</section>
@endsection
