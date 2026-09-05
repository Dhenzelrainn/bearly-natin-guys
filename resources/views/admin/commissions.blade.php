@extends('layouts.admin')

@section('title', 'Commission Management')
@section('page-title', 'Manage Commission (10%)')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 06</span><h1>Manage platform commission</h1><p>Preview the configured 10% platform fee and review a static commission ledger by seller and transaction date.</p></div>
    <div class="commission-rate-card"><span>Platform rate</span><strong>{{ $rate }}%</strong><small>Applied to gross order value</small></div>
</section>

<section class="dashboard-grid dashboard-grid-secondary">
    <article class="panel commission-calculator">
        <div class="panel-heading"><div><span class="eyebrow">Fee calculator</span><h2>10% commission preview</h2></div></div>
        <div class="calculator-row">
            <label class="form-field"><span>Gross order amount</span><div class="money-input"><span>₱</span><input type="number" value="2500" min="0" step="0.01" data-commission-input></div></label>
            <div class="formula-card"><span>Platform commission</span><strong data-commission-output>₱250.00</strong><small>Gross × 10%</small></div>
            <div class="formula-card formula-net"><span>Seller net</span><strong data-seller-net>₱2,250.00</strong><small>Gross − commission</small></div>
        </div>
    </article>
    <article class="panel commission-summary">
        <div class="panel-heading"><div><span class="eyebrow">Current period</span><h2>Commission summary</h2></div></div>
        <div class="summary-metric"><span>Gross merchandise value</span><strong>₱1,283,130</strong></div><div class="summary-metric"><span>Platform commission</span><strong>₱128,313</strong></div><div class="summary-metric"><span>Seller net payout</span><strong>₱1,154,817</strong></div>
    </article>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Commission ledger</span><h2>Seller fee breakdown</h2></div>
        <div class="table-toolbar">
            <input class="date-field" type="date" value="2026-08-01" data-commission-date-start>
            <span>to</span>
            <input class="date-field" type="date" value="2026-08-24" data-commission-date-end>
            <button class="button button-secondary button-small" type="button" data-commission-date-apply>
                <i data-lucide="filter"></i> Apply
            </button>
        </div>
    </div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Order</th><th>Seller</th><th class="align-right">Gross</th><th class="align-right">10% Commission</th><th class="align-right">Seller Net</th></tr></thead><tbody>
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
