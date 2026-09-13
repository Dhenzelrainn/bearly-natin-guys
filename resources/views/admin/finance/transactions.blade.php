@extends('layouts.admin')

@section('title', 'Commission Transactions')
@section('page-title', 'Commission Transactions')

@section('content')
@php
    $grossTotal = collect($transactions)->where('status', '!=', 'Failed')->sum('gross');
    $refundTotal = collect($transactions)->sum('refund');
    $commissionTotal = collect($transactions)->sum('commission');
@endphp

<section class="page-hero">
    <div><span class="eyebrow">Finance ledger</span><h1>Track commission transactions</h1><p>Review every order amount, refund adjustment, 10% platform commission, and resulting seller net.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-finance-export="transactions"><i data-lucide="download"></i> Export ledger</button></div>
</section>

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="receipt"></i></span><div><strong>{{ count($transactions) }}</strong><small>Ledger entries</small></div></article>
    <article class="mini-stat"><span><i data-lucide="banknote"></i></span><div><strong>₱{{ number_format($grossTotal, 2) }}</strong><small>Captured gross</small></div></article>
    <article class="mini-stat"><span><i data-lucide="rotate-ccw"></i></span><div><strong>₱{{ number_format($refundTotal, 2) }}</strong><small>Refund adjustments</small></div></article>
    <article class="mini-stat"><span><i data-lucide="percent"></i></span><div><strong>₱{{ number_format($commissionTotal, 2) }}</strong><small>Earned commission</small></div></article>
</section>

<section class="panel" data-finance-module="transactions">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Commission ledger</span><h2>Order transactions</h2><p><span data-finance-visible>{{ count($transactions) }}</span> matching entries</p></div>
        <div class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Order, seller, buyer..." data-finance-search></label>
            <select class="select-field" data-finance-status><option value="">All statuses</option><option>Completed</option><option>Pending</option><option>Refunded</option><option>Failed</option></select>
            <input class="date-field" type="date" value="2026-08-21" data-finance-start>
            <input class="date-field" type="date" value="2026-08-24" data-finance-end>
            <button class="button button-secondary button-small" type="button" data-finance-reset>Reset</button>
        </div>
    </div>
    <div class="table-wrap"><table class="admin-table" data-finance-table="transactions">
        <thead><tr><th>Date / Transaction</th><th>Order parties</th><th>Method</th><th>Status</th><th class="align-right">Gross</th><th class="align-right">Refund</th><th class="align-right">Commission</th><th class="align-right">Seller net</th></tr></thead>
        <tbody>
        @foreach ($transactions as $transaction)
            @php $badge = match($transaction['status']) { 'Completed' => 'badge-success', 'Refunded', 'Failed' => 'badge-danger', default => 'badge-warning' }; @endphp
            <tr data-finance-row data-date="{{ $transaction['date'] }}" data-status="{{ $transaction['status'] }}" data-search="{{ strtolower($transaction['id'].' '.$transaction['order'].' '.$transaction['seller'].' '.$transaction['buyer'].' '.$transaction['method']) }}">
                <td><strong>{{ date('M d, Y', strtotime($transaction['date'])) }}</strong><small class="table-subline">{{ $transaction['id'] }}</small></td>
                <td><strong>{{ $transaction['order'] }} · {{ $transaction['seller'] }}</strong><small class="table-subline">Buyer: {{ $transaction['buyer'] }}</small></td>
                <td>{{ $transaction['method'] }}</td><td><span class="status-badge {{ $badge }}">{{ $transaction['status'] }}</span></td>
                <td class="align-right">₱{{ number_format($transaction['gross'], 2) }}</td><td class="align-right">₱{{ number_format($transaction['refund'], 2) }}</td><td class="align-right commission-value">₱{{ number_format($transaction['commission'], 2) }}</td><td class="align-right">₱{{ number_format($transaction['sellerNet'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table><div class="table-empty" data-finance-empty hidden><i data-lucide="search-x"></i><strong>No transactions found</strong><span>Adjust the search, date range, or status.</span></div></div>
</section>
@endsection
