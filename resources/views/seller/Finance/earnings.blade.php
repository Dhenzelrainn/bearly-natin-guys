@extends('layouts.seller')
@section('title', 'My Earnings')
@section('page-title', 'My Earnings')
@section('content')
@php($commissionRateLabel = rtrim(rtrim(number_format((float) $commissionRate, 2, '.', ''), '0'), '.'))
<div class="page-heading finance-page-heading">
    <div>
        <span class="section-kicker">Seller finance</span>
        <h2>My Earnings</h2>
        <p>Live earnings from your posted Seller Finance transactions, matched to the records Admin uses for reconciliation and settlement.</p>
    </div>
    <div class="finance-heading-actions">
        <a class="seller-primary-button" href="{{ route('seller.finance.wallet') }}"><i data-lucide="wallet-cards" aria-hidden="true"></i>Open Wallet</a>
        <a class="seller-secondary-button" href="{{ route('seller.finance.transactions') }}"><i data-lucide="receipt-text" aria-hidden="true"></i>Transaction History</a>
    </div>
</div>

<section class="commission-rule-banner">
    <span class="commission-rule-icon"><i data-lucide="badge-percent"></i></span>
    <div>
        <span class="section-kicker">Bearly platform rule</span>
        <h3>{{ $commissionRateLabel }}% Platform Commission</h3>
        <p>Only posted transactions with confirmed payments are included in earnings. Refund and Seller recovery entries remain visible in the ledger; outstanding recoveries are not automatically deducted from Wallet balances.</p>
    </div>
</section>

<section class="finance-summary-grid" aria-label="Earnings summary">
    @foreach ($summary as $index => $item)
        <article class="finance-summary-card {{ $index === 3 ? 'is-primary' : '' }}">
            <span>{{ $item['label'] }}</span>
            <strong>{{ $item['value'] }}</strong>
            <small>{{ $item['note'] }}</small>
        </article>
    @endforeach
</section>

<section class="finance-main-grid">
    <article class="seller-panel earnings-bridge-card">
        <div class="seller-panel-heading">
            <div><span class="section-kicker">Earnings bridge</span><h3>How your earnings are calculated</h3></div>
            <a href="{{ route('seller.reports.financial') }}">Financial report</a>
        </div>
        <dl class="earnings-bridge-list">
            <div><dt>Gross finalized sales</dt><dd>₱{{ number_format($finance['gross_sales_minor'] / 100, 2) }}</dd></div>
            <div class="is-deduction"><dt>Seller-funded discounts</dt><dd>{{ $finance['seller_discounts_minor'] > 0 ? '−' : '' }}₱{{ number_format($finance['seller_discounts_minor'] / 100, 2) }}</dd></div>
            <div class="is-deduction"><dt>Completed refunds</dt><dd>{{ $finance['refunds_minor'] > 0 ? '−' : '' }}₱{{ number_format($finance['refunds_minor'] / 100, 2) }}</dd></div>
            <div class="is-subtotal"><dt>Commissionable sales</dt><dd>{{ $finance['commissionable_sales_minor'] < 0 ? '−' : '' }}₱{{ number_format(abs($finance['commissionable_sales_minor']) / 100, 2) }}</dd></div>
            <div class="is-deduction"><dt>Net platform commission ({{ $commissionRateLabel }}%)</dt><dd>{{ $finance['net_commission_minor'] < 0 ? '+' : '−' }}₱{{ number_format(abs($finance['net_commission_minor']) / 100, 2) }}</dd></div>
            <div class="is-total"><dt><span>Net Earnings</span><small>Posted seller-ledger amount before payouts</small></dt><dd>{{ $finance['net_earnings_minor'] < 0 ? '−' : '' }}₱{{ number_format(abs($finance['net_earnings_minor']) / 100, 2) }}</dd></div>
        </dl>
        <p class="finance-definition-note"><i data-lucide="info"></i> These are ledger earnings, not accounting profit or cash already paid out. Available, pending, reserved, and withdrawn balances are shown in Seller Wallet.</p>
    </article>

    <article class="seller-panel settlement-rules-card">
        <div class="seller-panel-heading"><div><span class="section-kicker">Settlement logic</span><h3>When earnings change</h3></div></div>
        <ol class="settlement-rule-list">
            <li><span>1</span><div><strong>Payment is confirmed and the ledger is posted</strong><p>Your sale earnings appear in the shared Finance ledger and Wallet Pending balance.</p></div></li>
            <li><span>2</span><div><strong>Seller Order is completed</strong><p>COD earnings also require exact Finance reconciliation for each associated outbound COD shipment.</p></div></li>
            <li><span>3</span><div><strong>Return and refund checks are clear</strong><p>The seven-day return period must end and no open return/refund case may remain before earnings move to Available.</p></div></li>
            <li><span>4</span><div><strong>Request a bank withdrawal</strong><p>Available funds are reserved for Finance review. Bearly does not send a transfer automatically.</p></div></li>
        </ol>
    </article>
</section>

<section class="seller-panel finance-settlements-card">
    <div class="seller-panel-heading">
        <div><span class="section-kicker">Legacy history</span><h3>Previous Seller settlements</h3><p>Historical settlement batches remain available for reference. New withdrawals are requested and tracked in Seller Wallet.</p></div>
    </div>
    <div class="finance-table-wrap">
        <table class="finance-table finance-settlement-table">
            <thead><tr><th>Settlement / coverage</th><th>Net payout</th><th>Payment / reference</th><th>Status / next step</th><th>Paid on</th><th>Proof</th></tr></thead>
            <tbody>
            @forelse ($settlements as $settlement)
                <tr>
                    <td><strong>{{ $settlement['payout_no'] }}</strong><small>{{ $settlement['period'] }}</small></td>
                    <td><strong>₱{{ number_format($settlement['net_minor'] / 100, 2) }}</strong></td>
                    <td>
                        @if ($settlement['status'] === 'Paid')
                            {{ $settlement['method'] ?? 'Payment' }}
                            <small>{{ $settlement['reference_label'] }}: {{ $settlement['reference'] ?: 'Not recorded' }}</small>
                        @else
                            <span>Not paid yet</span>
                        @endif
                    </td>
                    <td><span class="finance-status is-{{ $settlement['status_key'] }}">{{ $settlement['status'] }}</span><small>{{ $settlement['next_step'] }}</small></td>
                    <td>{{ $settlement['paid_on'] ?? '—' }}</td>
                    <td>
                        @if ($settlement['has_receipt'])
                            <a class="finance-receipt-link" href="{{ route('seller.finance.settlements.receipt', $settlement['id']) }}">Download payout proof</a>
                        @else
                            <span class="finance-proof-pending">{{ $settlement['status'] === 'Paid' ? 'Ask Admin Finance for the proof.' : 'Available after Finance records payment.' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="finance-settlement-empty"><strong>No settlement batches yet</strong><span>When Admin Finance creates a batch for your eligible earnings, its status will appear here.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="seller-panel finance-recent-card">
    <div class="seller-panel-heading"><div><span class="section-kicker">Recent activity</span><h3>Recent earnings transactions</h3></div><a href="{{ route('seller.finance.transactions') }}">View all transactions</a></div>
    <div class="finance-table-wrap">
        <table class="finance-table">
            <thead><tr><th>Date / Order</th><th>Products / Subcategory</th><th>Commissionable</th><th>Platform Commission</th><th>Net Earnings</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($recentTransactions as $row)
                <tr>
                    <td><strong>{{ $row['order'] }}</strong><small>{{ $row['date'] }}</small></td>
                    <td>
                        <ul class="finance-product-list" aria-label="Products in this seller order">
                            @forelse ($row['items'] as $item)
                                <li>
                                    @if ($item['image'] !== '')
                                        <img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" width="42" height="42" loading="lazy">
                                    @else
                                        <span class="finance-product-placeholder" aria-hidden="true"><i data-lucide="package"></i></span>
                                    @endif
                                    <span class="finance-product-copy"><strong>{{ $item['name'] }}</strong><small>{{ $item['variant'] }} · {{ $item['subcategory'] }}</small></span>
                                </li>
                            @empty
                                <li><span class="finance-product-placeholder" aria-hidden="true"><i data-lucide="package"></i></span><span class="finance-product-copy"><strong>Product details unavailable</strong><small>Order item snapshots were not found.</small></span></li>
                            @endforelse
                        </ul>
                    </td>
                    <td>{{ $row['commissionable_minor'] < 0 ? '−' : '' }}₱{{ number_format(abs($row['commissionable_minor']) / 100, 2) }}</td>
                    <td>{{ $row['commission_minor'] > 0 ? '−' : '' }}₱{{ number_format(abs($row['commission_minor']) / 100, 2) }}
                        @if ($row['commission_reversal_minor'] > 0)<small>Commission reversal +₱{{ number_format($row['commission_reversal_minor'] / 100, 2) }}</small>@endif
                    </td>
                    <td><strong>{{ $row['recognized'] ? (($row['net_minor'] < 0 ? '−' : '').'₱'.number_format(abs($row['net_minor']) / 100, 2)) : '—' }}</strong><small>{{ $row['note'] }}</small></td>
                    <td><span class="finance-status is-{{ $row['status_key'] }}">{{ $row['status'] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="finance-settlement-empty"><strong>No earnings transactions yet</strong><span>Confirmed seller transactions from the Admin Finance ledger will appear here.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
