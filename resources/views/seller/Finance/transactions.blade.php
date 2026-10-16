@extends('layouts.seller')
@section('title', 'Transaction History')
@section('page-title', 'Transaction History')
@section('content')
@php($commissionRateLabel = rtrim(rtrim(number_format((float) $commissionRate, 2, '.', ''), '0'), '.'))
<div class="page-heading finance-page-heading">
    <div>
        <a class="back-link" href="{{ route('seller.finance.earnings') }}"><i data-lucide="arrow-left"></i>My Earnings</a>
        <h2>Transaction History</h2>
        <p>Seller-scoped transaction records from the same Finance ledger Admin uses for commissions, payouts, and reports.</p>
    </div>
    <a class="seller-secondary-button" href="{{ route('seller.reports.financial') }}"><i data-lucide="chart-no-axes-combined"></i>Financial Report</a>
</div>
<section class="seller-panel finance-ledger" data-finance-ledger>
    <div class="finance-ledger-toolbar">
        <label><i data-lucide="search"></i><input type="search" placeholder="Search order or status" data-finance-search></label>
        <select data-finance-status aria-label="Filter transaction status">
            <option value="">All statuses</option>
            <option value="available">Available</option>
            <option value="posted">Posted</option>
            <option value="withdrawn">Withdrawn</option>
            <option value="pending">Pending</option>
            <option value="reversed">Reversed</option>
            <option value="cancelled">Cancelled</option>
        </select>
        <button type="button" data-finance-reset><i data-lucide="rotate-ccw"></i>Reset</button>
    </div>
    <div class="finance-table-wrap">
        <table class="finance-table finance-ledger-table">
            <thead><tr><th>Date / Order</th><th>Products</th><th>Gross Sale</th><th>Seller Discount</th><th>Refund</th><th>Commissionable</th><th>Commission</th><th>Commission Reversal</th><th>Seller Net</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($transactions as $row)
                    <tr data-finance-row data-status="{{ $row['status_key'] }}" data-search="{{ strtolower($row['id'].' '.$row['order'].' '.$row['status'].' '.$row['date']) }}">
                        <td><strong>{{ $row['order'] }}</strong><small>{{ $row['id'] }} · {{ $row['date'] }}</small></td>
                        <td>@if(count($row['items']))@include('partials.order-item-preview-list', ['items' => $row['items']])@else<span class="finance-product-unavailable">No product details recorded.</span>@endif</td>
                        <td>₱{{ number_format($row['gross_minor'] / 100, 2) }}</td>
                        <td>{{ $row['discount_minor'] > 0 ? '−' : '' }}₱{{ number_format($row['discount_minor'] / 100, 2) }}</td>
                        <td>{{ $row['refund_minor'] > 0 ? '−' : '' }}₱{{ number_format($row['refund_minor'] / 100, 2) }}</td>
                        <td><strong>{{ $row['commissionable_minor'] < 0 ? '−' : '' }}₱{{ number_format(abs($row['commissionable_minor']) / 100, 2) }}</strong></td>
                        <td>{{ $row['commission_minor'] > 0 ? '−' : '' }}₱{{ number_format(abs($row['commission_minor']) / 100, 2) }}</td>
                        <td>{{ $row['commission_reversal_minor'] > 0 ? '+' : '—' }}@if($row['commission_reversal_minor'] > 0)₱{{ number_format($row['commission_reversal_minor'] / 100, 2) }}@endif</td>
                        <td><strong>{{ $row['recognized'] ? (($row['net_minor'] < 0 ? '−' : '').'₱'.number_format(abs($row['net_minor']) / 100, 2)) : '—' }}</strong><small>{{ $row['note'] }}</small></td>
                        <td><span class="finance-status is-{{ $row['status_key'] }}">{{ $row['status'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="10"><div class="finance-settlement-empty"><strong>No transaction history yet</strong><span>Posted seller transactions will appear here when recorded in Finance.</span></div></td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="finance-empty" data-finance-empty hidden><i data-lucide="search-x"></i><strong>No matching transactions</strong><span>Try changing the search or status filter.</span></div>
    </div>
    <footer class="finance-ledger-footer"><span>Showing <strong data-finance-count>{{ count($transactions) }}</strong> transactions</span><span>Platform commission rate: {{ $commissionRateLabel }}%</span></footer>
</section>
@endsection
