@extends('layouts.courier')

@section('title', 'Profit Dashboard')
@section('page-title', 'Profit Dashboard')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 07</span><h1>Courier profit dashboard</h1><p>Review daily, weekly, and monthly earnings with a clear breakdown of delivery pay, incentives, tips, and deductions.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-mock-action="Earnings report preview exported."><i data-lucide="download"></i> Export preview</button></div>
</section>

<section class="earnings-summary-grid">
    @foreach($summary as $item)
        <article class="earnings-card"><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong><small>{{ $item['note'] }}</small></article>
    @endforeach
</section>

<section class="earnings-layout">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Performance trend</span><h2>Weekly earnings</h2><p>Static seven-day courier earnings preview.</p></div><div class="segmented-control"><button class="is-active" type="button" data-earnings-period>Week</button><button type="button" data-earnings-period>Month</button><button type="button" data-earnings-period>Year</button></div></div>
        <div class="chart-shell">
            <div class="chart-y-labels"><span>₱1,600</span><span>₱1,100</span><span>₱550</span><span>₱0</span></div>
            <div class="bar-chart" aria-label="Weekly courier earnings chart">
                @php $days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun']; @endphp
                @foreach($series as $index => $height)
                    <div class="bar-column"><div class="bar-track"><span style="height: {{ min(100,$height / 1.2) }}%"></span></div><small>{{ $days[$index] }}</small></div>
                @endforeach
            </div>
        </div>
    </article>

    <aside class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Current week</span><h2>Earnings breakdown</h2><p>Mock payout composition.</p></div></div>
        <div class="breakdown-list">
            @foreach($breakdown as $item)<div class="breakdown-row"><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong></div>@endforeach
            <div class="breakdown-row total"><span>Net earnings</span><strong>₱6,840</strong></div>
        </div>
        <div class="policy-note" style="margin-top:14px"><i data-lucide="info"></i><span>All values are static demo amounts. Real payout computation will be implemented during backend development.</span></div>
    </aside>
</section>

<section class="panel" style="margin-top:16px">
    <div class="panel-heading panel-heading-wrap"><div><span class="eyebrow">Summary reports</span><h2>Daily earnings ledger</h2></div><div class="date-range-inline"><input class="date-field" type="date" value="2026-08-19"><span>to</span><input class="date-field" type="date" value="2026-08-25"><button class="button button-secondary button-small" type="button" data-mock-action="Date range applied to static earnings table.">Apply</button></div></div>
    <div class="table-wrap"><table class="courier-table"><thead><tr><th>Date</th><th>Deliveries</th><th>Base Pay</th><th>Incentives</th><th>Tips</th><th>Deductions</th><th class="align-right">Net</th></tr></thead><tbody>
        @foreach($rows as $row)<tr><td>{{ $row['date'] }}</td><td>{{ $row['deliveries'] }}</td><td>{{ $row['base'] }}</td><td>{{ $row['incentives'] }}</td><td>{{ $row['tips'] }}</td><td>{{ $row['deductions'] }}</td><td class="align-right"><strong>{{ $row['net'] }}</strong></td></tr>@endforeach
    </tbody></table></div>
</section>
@endsection
