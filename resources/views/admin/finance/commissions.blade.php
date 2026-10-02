@extends('layouts.admin')

@section('title', 'Commission Management')
@section('page-title', 'Manage Commission ('.$rate.'%)')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Finance & Reports</span><h1>Manage platform commission</h1><p>Review the configured {{ $rate }}% platform fee and calculate the seller net for each completed transaction.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-finance-export="commissions"><i data-lucide="download"></i> Export commissions</button><div class="commission-rate-card"><span>Platform rate</span><strong>{{ $rate }}%</strong><small>Applied to gross order value</small></div></div>
</section>

<section class="dashboard-grid dashboard-grid-secondary">
    <article class="panel commission-calculator" data-commission-rate="{{ $rate }}">
        <div class="panel-heading"><div><span class="eyebrow">Fee calculator</span><h2>{{ $rate }}% commission preview</h2></div></div>
        <div class="calculator-row">
            <label class="form-field"><span>Gross order amount</span><div class="money-input"><span>₱</span><input type="number" value="2500" min="0" step="0.01" data-commission-input></div></label>
            <div class="formula-card"><span>Platform commission</span><strong data-commission-output>₱{{ number_format(2500 * ($rate / 100), 2) }}</strong><small>Gross × {{ $rate }}%</small></div>
            <div class="formula-card formula-net"><span>Seller net</span><strong data-seller-net>₱{{ number_format(2500 * (1 - ($rate / 100)), 2) }}</strong><small>Gross − commission</small></div>
        </div>
    </article>
    <article class="panel commission-summary">
        <div class="panel-heading"><div><span class="eyebrow">Current period</span><h2>Commission summary</h2></div></div>
        <div class="summary-metric"><span>Gross merchandise value</span><strong>₱{{ number_format($commissionSummary['gross'], 2) }}</strong></div><div class="summary-metric"><span>Platform commission</span><strong>₱{{ number_format($commissionSummary['commission'], 2) }}</strong></div><div class="summary-metric"><span>Seller net payout</span><strong>₱{{ number_format($commissionSummary['sellerNet'], 2) }}</strong></div>
    </article>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Commission ledger</span><h2>Seller fee breakdown</h2></div>
        <div class="table-toolbar">
            <input class="date-field" type="date" value="{{ $dateRangeStart }}" data-commission-date-start>
            <span>to</span>
            <input class="date-field" type="date" value="{{ $dateRangeEnd }}" data-commission-date-end>
            <button class="button button-secondary button-small" type="button" data-commission-date-apply>
                <i data-lucide="filter"></i> Apply
            </button>
        </div>
    </div>
    <div class="table-wrap"><table class="admin-table" data-finance-table="commissions"><thead><tr><th>Date</th><th>Order</th><th>Seller</th><th class="align-right">Gross</th><th class="align-right">{{ $rate }}% Commission</th><th class="align-right">Seller Net</th></tr></thead><tbody>
        @foreach ($ledger as $row)
            <tr data-commission-ledger-row data-commission-date="{{ \Carbon\Carbon::parse($row['date'])->format('Y-m-d') }}"><td>{{ $row['date'] }}</td><td><strong>{{ $row['order'] }}</strong></td><td>{{ $row['seller'] }}</td><td class="align-right">₱{{ number_format($row['gross'], 2) }}</td><td class="align-right commission-value">₱{{ number_format($row['commission'], 2) }}</td><td class="align-right">₱{{ number_format($row['sellerNet'], 2) }}</td></tr>
        @endforeach
        <tr data-commission-empty hidden>
            <td colspan="6" style="text-align:center; padding: 28px 16px;">
                No commission transactions found for the selected date range.
            </td>
        </tr>
    </tbody></table></div>
</section>
@endsection
