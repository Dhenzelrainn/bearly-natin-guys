@extends('layouts.seller')
@section('title', 'Financial Report')
@section('page-title', 'Reports')
@section('content')
<section class="report-detail-page report-financial-page" data-report-page>
    <header class="report-statement-header">
        <div><span class="section-kicker">Detailed report</span><h2>Financial Report</h2><p>See exactly how gross sales become the revenue earned by your store.</p></div>
        <div class="report-header-actions"><label class="report-date-control"><i data-lucide="calendar-days"></i><select data-report-period aria-label="Report period"><option>Aug 1 – Aug 31, 2026</option><option>Jul 1 – Jul 31, 2026</option></select></label><button class="seller-secondary-button" type="button" data-report-export><i data-lucide="download"></i>Export statement</button></div>
    </header>
    <section class="report-detail-summary financial-summary" aria-label="Financial summary">@foreach ($summary as $item)<article><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong><small>{{ $item['note'] }}</small></article>@endforeach</section>
    <section class="financial-statement-lead">
        <article class="financial-current-statement">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Current statement</span><h3>August earnings</h3><p>Aug 1–31, 2026 · Philippine Peso</p></div><span class="report-status">Processing</span></div>
            <dl>@foreach ($currentStatement as $index => $item)<div class="{{ $index > 0 ? 'is-deduction' : '' }}"><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach<div class="is-net"><dt><span>Net Revenue</span><small>Amount earned after deductions</small></dt><dd>₱109,876.00</dd></div></dl>
        </article>
        <article class="financial-deduction-panel">
            <div class="report-detail-panel-heading"><div><span class="section-kicker">Cost composition</span><h3>Where deductions went</h3><p>₱18,574 total deductions.</p></div></div>
            <div class="financial-deduction-total"><strong>14.5%</strong><span>of gross sales</span></div>
            <div class="financial-deduction-list">@foreach ($deductions as $item)<div><header><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong></header><span class="deduction-track"><i class="tone-{{ $item['tone'] }}" style="width:{{ $item['percent'] }}%"></i></span><small>{{ $item['percent'] }}% of deductions</small></div>@endforeach</div>
        </article>
    </section>
    <section class="report-detail-sheet">
        <div class="report-detail-heading"><div><span class="section-kicker">Statement archive</span><h3>Monthly financial history</h3><p>Use this history to compare revenue and deductions between periods.</p></div><label><i data-lucide="search"></i><input type="search" placeholder="Search month or status" data-report-search></label></div>
        <div class="report-table-wrap"><table class="report-table report-detail-table"><thead><tr><th>Statement Period</th><th>Gross Sales</th><th>Discounts</th><th>Refunds</th><th>Commission</th><th>Net Revenue</th><th>Status</th></tr></thead><tbody>
            @foreach ($rows as $row)<tr data-report-row data-search="{{ strtolower($row['period'].' '.$row['status']) }}"><td><strong>{{ $row['period'] }}</strong></td><td>{{ $row['gross'] }}</td><td class="report-deduction-value">−{{ $row['discounts'] }}</td><td class="report-deduction-value">{{ $row['refunds'] === '₱0' ? '—' : '−'.$row['refunds'] }}</td><td class="report-deduction-value">−{{ $row['commission'] }}</td><td><strong class="report-net-value">{{ $row['net'] }}</strong></td><td><span class="report-status {{ strtolower($row['status']) === 'paid' ? 'is-paid' : '' }}">{{ $row['status'] }}</span></td></tr>@endforeach
        </tbody></table><div class="report-no-results" data-report-empty hidden><i data-lucide="search-x"></i><strong>No matching statement</strong><span>Try a different search.</span></div></div>
        <footer class="report-detail-footer"><span>Showing <strong data-report-count>{{ count($rows) }}</strong> statements</span><span>Frontend preview data</span></footer>
    </section>
</section>
@endsection
