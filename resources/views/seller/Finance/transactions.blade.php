@extends('layouts.seller')
@section('title', 'Transaction History')
@section('page-title', 'Transaction History')
@section('content')
<div class="page-heading finance-page-heading">
    <div>
        <a class="back-link" href="{{ route('seller.finance.earnings') }}"><i data-lucide="arrow-left"></i>My Earnings</a>
        <h2>Transaction History</h2>
        <p>One financial ledger for completed, pending, cancelled, and reversed seller transactions.</p>
    </div>
    <a class="seller-secondary-button" href="{{ route('seller.reports.financial') }}"><i data-lucide="chart-no-axes-combined"></i>Financial Report</a>
</div>

<section class="finance-ledger-note">
    <i data-lucide="database"></i>
    <div><strong>Single source of truth</strong><p>Seller and Admin should read the same order/commission transaction. The seller sees net earnings; Admin later sees the same record from the platform commission side.</p></div>
</section>

<section class="seller-panel finance-ledger" data-finance-ledger>
    <div class="finance-ledger-toolbar">
        <label><i data-lucide="search"></i><input type="search" placeholder="Search order or status" data-finance-search></label>
        <select data-finance-status aria-label="Filter transaction status">
            <option value="">All statuses</option>
            <option value="available">Available</option>
            <option value="pending">Pending</option>
            <option value="reversed">Reversed</option>
            <option value="cancelled">Cancelled</option>
        </select>
        <button type="button" data-finance-reset><i data-lucide="rotate-ccw"></i>Reset</button>
    </div>
    <div class="finance-table-wrap">
        <table class="finance-table finance-ledger-table">
            <thead><tr><th>Date / Order</th><th>Gross Sale</th><th>Seller Discount</th><th>Refund</th><th>Commissionable</th><th>Commission</th><th>Commission Reversal</th><th>Seller Net</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($transactions as $row)
                    <tr data-finance-row data-status="{{ $row['status_key'] }}" data-search="{{ strtolower($row['order'].' '.$row['status'].' '.$row['date']) }}">
                        <td><strong>{{ $row['order'] }}</strong><small>{{ $row['date'] }}</small></td>
                        <td>{{ $row['gross'] }}</td><td>{{ $row['discount'] }}</td><td>{{ $row['refund'] }}</td><td><strong>{{ $row['commissionable'] }}</strong></td>
                        <td>{{ $row['commission'] }}</td><td>{{ $row['commission_reversal'] }}</td><td><strong>{{ $row['net'] }}</strong><small>{{ $row['note'] }}</small></td>
                        <td><span class="finance-status is-{{ $row['status_key'] }}">{{ $row['status'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="finance-empty" data-finance-empty hidden><i data-lucide="search-x"></i><strong>No matching transactions</strong><span>Try changing the search or status filter.</span></div>
    </div>
    <footer class="finance-ledger-footer"><span>Showing <strong data-finance-count>{{ count($transactions) }}</strong> transactions</span><span>Platform commission rate: {{ number_format($commissionRate, 0) }}%</span></footer>
</section>
@endsection
