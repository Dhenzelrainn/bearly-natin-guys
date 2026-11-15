@extends('layouts.admin')

@section('title', 'Seller Payouts')
@section('page-title', 'Seller Payouts')

@section('content')
@php
    $money = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $label = fn (string $status): string => str($status)->replace('_', ' ')->title()->toString();
    $pendingCount = (int) ($summary->get('pending_review')?->requests_count ?? 0);
    $processingCount = (int) ($summary->get('processing')?->requests_count ?? 0);
    $completedAmount = (int) ($summary->get('completed')?->amount_minor ?? 0);
@endphp

<section class="page-hero">
    <div><span class="eyebrow">Finance · Seller Wallet</span><h1>Review and pay withdrawals</h1><p>Approve a Seller request, manually send the bank transfer, and record independent success verification. Bearly does not initiate transfers.</p></div>
    <div class="hero-actions"><a class="button button-secondary" href="{{ route('admin.payments') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Seller Payments &amp; COD</a></div>
</section>

<section class="finance-withdrawal-summary" aria-label="Withdrawal queue summary">
    <div><span>Pending review</span><strong>{{ $pendingCount }}</strong></div>
    <div><span>Processing</span><strong>{{ $processingCount }}</strong></div>
    <div><span>Completed payouts</span><strong>{{ $money($completedAmount) }}</strong></div>
</section>

<section class="panel finance-withdrawal-queue" aria-labelledby="seller-withdrawals-title">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Withdrawal queue</span><h2 id="seller-withdrawals-title">Seller Withdrawal Requests</h2><p>{{ $withdrawals->total() }} requests</p></div>
        <form method="GET" class="finance-withdrawal-filters">
            <label class="field-with-icon compact-field"><i data-lucide="search" aria-hidden="true"></i><input type="search" name="q" value="{{ $search }}" placeholder="Seller or reference"></label>
            <label class="sr-only" for="seller-withdrawal-status">Filter by status</label>
            <select class="select-field" id="seller-withdrawal-status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $option)
                    <option value="{{ $option->value }}" @selected($selectedStatus === $option->value)>{{ $label($option->value) }}</option>
                @endforeach
            </select>
            <button class="button button-secondary button-small" type="submit">Filter</button>
            @if($search !== '' || $selectedStatus)<a class="button button-secondary button-small" href="{{ route('admin.seller-payouts.index') }}">Reset</a>@endif
        </form>
    </div>

    <div class="table-wrap">
        <table class="admin-table finance-withdrawal-table">
            <thead><tr><th>Withdrawal reference</th><th>Seller</th><th class="align-right">Requested</th><th>Bank account</th><th>Request date</th><th>Status</th><th class="align-right">Action</th></tr></thead>
            <tbody>
            @forelse($withdrawals as $withdrawal)
                <tr>
                    <td><strong>{{ $withdrawal->withdrawal_no }}</strong>@if($withdrawal->transfer?->bank_reference)<small class="table-subline">{{ $withdrawal->transfer->bank_reference }}</small>@endif</td>
                    <td><strong>{{ $withdrawal->sellerProfile?->store?->name ?: $withdrawal->sellerProfile?->user?->name ?: 'Seller' }}</strong><small class="table-subline">{{ $withdrawal->sellerProfile?->user?->name }}</small></td>
                    <td class="align-right"><strong>{{ $money((int) $withdrawal->amount_minor) }}</strong></td>
                    <td>{{ $withdrawal->bank_name_snapshot }}<small class="table-subline">•••• {{ $withdrawal->account_number_last4 }}</small></td>
                    <td>{{ $withdrawal->requested_at?->format('M j, Y g:i A') }}</td>
                    <td><span class="status-badge {{ match($withdrawal->status) { 'completed' => 'badge-success', 'rejected', 'failed' => 'badge-danger', 'processing' => 'badge-info', default => 'badge-warning' } }}">{{ $label($withdrawal->status) }}</span></td>
                    <td class="align-right"><a class="button button-secondary button-small" href="{{ route('admin.seller-payouts.show', $withdrawal) }}">Review</a></td>
                </tr>
            @empty
                <tr><td colspan="7"><p class="empty-copy">No withdrawal requests match these filters.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="finance-withdrawal-pagination">{{ $withdrawals->links() }}</div>
</section>
@endsection
