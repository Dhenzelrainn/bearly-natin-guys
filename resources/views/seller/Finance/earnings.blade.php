@extends('layouts.seller')
@section('title', 'My Earnings')
@section('page-title', 'My Earnings')
@section('content')
<div class="page-heading finance-page-heading">
    <div>
        <span class="section-kicker">Seller finance</span>
        <h2>My Earnings</h2>
        <p>See how completed sales become your seller earnings after discounts, refunds, and Bearly's platform commission.</p>
    </div>
    <a class="seller-secondary-button" href="{{ route('seller.finance.transactions') }}"><i data-lucide="receipt-text"></i>Transaction History</a>
</div>

<section class="commission-rule-banner">
    <span class="commission-rule-icon"><i data-lucide="badge-percent"></i></span>
    <div>
        <span class="section-kicker">Bearly platform rule</span>
        <h3>{{ number_format($commissionRate, 0) }}% Platform Commission</h3>
        <p>Commission becomes final only when an order reaches <strong>COMPLETED</strong>. Cancelled orders have no commission, while a finalized full refund reverses the related commission.</p>
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
            <div><dt>Gross completed sales</dt><dd>₱{{ number_format($finance['grossSales'], 2) }}</dd></div>
            <div class="is-deduction"><dt>Seller-funded discounts</dt><dd>−₱{{ number_format($finance['sellerDiscounts'], 2) }}</dd></div>
            <div class="is-deduction"><dt>Finalized refunds</dt><dd>−₱{{ number_format($finance['refunds'], 2) }}</dd></div>
            <div class="is-subtotal"><dt>Commissionable sales</dt><dd>₱{{ number_format($finance['netCommissionableSales'], 2) }}</dd></div>
            <div class="is-deduction"><dt>Platform commission ({{ number_format($commissionRate, 0) }}%)</dt><dd>−₱{{ number_format($finance['netCommission'], 2) }}</dd></div>
            <div class="is-total"><dt><span>Net Earnings</span><small>Seller amount after platform deductions</small></dt><dd>₱{{ number_format($finance['netEarnings'], 2) }}</dd></div>
        </dl>
        <p class="finance-definition-note"><i data-lucide="info"></i> Net Earnings is not the same as accounting profit. True profit would also require product cost / cost-of-goods data.</p>
    </article>

    <article class="seller-panel settlement-rules-card">
        <div class="seller-panel-heading"><div><span class="section-kicker">Settlement logic</span><h3>When earnings change</h3></div></div>
        <ol class="settlement-rule-list">
            <li><span>1</span><div><strong>Order is placed or being fulfilled</strong><p>Earnings and commission are still pending.</p></div></li>
            <li><span>2</span><div><strong>Courier marks DELIVERED</strong><p>Still pending while Bearly waits for buyer confirmation.</p></div></li>
            <li><span>3</span><div><strong>Buyer confirms receipt → COMPLETED</strong><p>The {{ number_format($commissionRate, 0) }}% commission is finalized and seller earnings become available.</p></div></li>
            <li><span>4</span><div><strong>Full refund is finalized</strong><p>The related seller earnings and platform commission are reversed.</p></div></li>
        </ol>
    </article>
</section>

<section class="seller-panel finance-recent-card">
    <div class="seller-panel-heading"><div><span class="section-kicker">Recent activity</span><h3>Recent earnings transactions</h3></div><a href="{{ route('seller.finance.transactions') }}">View all transactions</a></div>
    <div class="finance-table-wrap">
        <table class="finance-table">
            <thead><tr><th>Date / Order</th><th>Commissionable</th><th>Platform Commission</th><th>Net Earnings</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($recentTransactions as $row)
                <tr>
                    <td><strong>{{ $row['order'] }}</strong><small>{{ $row['date'] }}</small></td>
                    <td>{{ $row['commissionable'] }}</td>
                    <td>{{ $row['commission'] }}</td>
                    <td><strong>{{ $row['net'] }}</strong></td>
                    <td><span class="finance-status is-{{ $row['status_key'] }}">{{ $row['status'] }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
