@extends('layouts.admin')

@section('title', 'Seller Payments')
@section('page-title', 'Seller Payments')

@section('content')
@php
    $pendingTotal = collect($payments)->whereIn('status', ['Pending', 'Processing'])->sum('net');
    $paidTotal = collect($payments)->where('status', 'Paid')->sum('net');
    $heldTotal = collect($payments)->where('status', 'On Hold')->sum('net');
@endphp

<section class="page-hero">
    <div><span class="eyebrow">Seller settlements</span><h1>Track who has been paid</h1><p>Monitor each seller payout after commission and refund adjustments. Payout status is controlled by the server-side settlement record.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-finance-export="payments"><i data-lucide="download"></i> Export payments</button></div>
</section>

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="clock-3"></i></span><div><strong>₱{{ number_format($pendingTotal, 2) }}</strong><small>Pending / processing</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-check-big"></i></span><div><strong>₱{{ number_format($paidTotal, 2) }}</strong><small>Paid settlements</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-pause"></i></span><div><strong>₱{{ number_format($heldTotal, 2) }}</strong><small>On hold</small></div></article>
    <article class="mini-stat"><span><i data-lucide="calendar-check"></i></span><div><strong>{{ collect($payments)->where('status', 'Paid')->count() }}/{{ count($payments) }}</strong><small>Settlements released</small></div></article>
</section>

<section class="panel" data-finance-module="payments">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Payment register</span><h2>Seller payout batches</h2><p><span data-finance-visible>{{ count($payments) }}</span> matching payments</p></div>
        <div class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Seller or payout ID..." data-finance-search></label>
            <select class="select-field" data-finance-status><option value="">All statuses</option><option>Pending</option><option>Processing</option><option>Paid</option><option>On Hold</option></select>
            <button class="button button-secondary button-small" type="button" data-finance-reset>Reset</button>
        </div>
    </div>
    <div class="table-wrap"><table class="admin-table" data-finance-table="payments">
        <thead><tr><th>Payout / Seller</th><th>Coverage</th><th class="align-right">Gross</th><th class="align-right">Commission</th><th class="align-right">Adjustments</th><th class="align-right">Net payout</th><th>Due / Reference</th><th>Status</th><th class="align-right">Action</th></tr></thead>
        <tbody>
        @foreach ($payments as $payment)
            @php $badge = match($payment['status']) { 'Paid' => 'badge-success', 'On Hold' => 'badge-danger', 'Processing' => 'badge-info', default => 'badge-warning' }; @endphp
            <tr data-finance-row data-payment-id="{{ $payment['id'] }}" data-status="{{ $payment['status'] }}" data-search="{{ strtolower($payment['id'].' '.$payment['seller'].' '.$payment['period'].' '.$payment['reference']) }}">
                <td><strong>{{ $payment['seller'] }}</strong><small class="table-subline">{{ $payment['id'] }}</small></td><td>{{ $payment['period'] }}</td>
                <td class="align-right">₱{{ number_format($payment['gross'], 2) }}</td><td class="align-right commission-value">−₱{{ number_format($payment['commission'], 2) }}</td><td class="align-right">{{ $payment['adjustments'] < 0 ? '−' : '' }}₱{{ number_format(abs($payment['adjustments']), 2) }}</td><td class="align-right"><strong>₱{{ number_format($payment['net'], 2) }}</strong></td>
                <td><strong>{{ $payment['due'] ? date('M d, Y', strtotime($payment['due'])) : '—' }}</strong><small class="table-subline" data-payment-reference>{{ $payment['reference'] }}</small></td><td><span class="status-badge js-payment-status {{ $badge }}">{{ $payment['status'] }}</span></td>
                <td class="align-right"><select class="select-field" data-payment-status aria-label="{{ $payment['id'] }} status" disabled title="Payout status changes require a verified settlement workflow"><option selected>{{ $payment['status'] }}</option></select></td>
            </tr>
        @endforeach
        </tbody>
    </table><div class="table-empty" data-finance-empty hidden><i data-lucide="search-x"></i><strong>No payments found</strong><span>Adjust the search or status filter.</span></div></div>
</section>
@endsection
