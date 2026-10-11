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

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">COD custody</span><h2>Cash awaiting Finance reconciliation</h2><p>Seller proceeds are not settlement-eligible until Finance confirms the Logistics remittance.</p></div></div>
    <div class="table-wrap"><table class="admin-table">
        <thead><tr><th>Collection / order</th><th>Provider / Rider</th><th>Due</th><th>Collected</th><th>Difference</th><th>Custody status</th><th>Finance action</th></tr></thead>
        <tbody>
        @forelse($codCollections as $collection)
            @php $difference = (int) $collection->discrepancy_minor; @endphp
            <tr>
                <td><strong>{{ $collection->collection_no }}</strong><small class="table-subline">{{ $collection->order?->order_no ?? 'Order' }} · {{ $collection->sellerOrder?->store?->name ?? 'Seller' }}</small></td>
                <td>{{ $collection->logisticsProfile?->display_name ?? 'Provider' }}<small class="table-subline">{{ $collection->riderProfile?->user?->name ?? 'Rider' }}</small></td>
                <td>₱{{ number_format((int) $collection->amount_due_minor / 100, 2) }}</td>
                <td>₱{{ number_format((int) $collection->amount_collected_minor / 100, 2) }}</td>
                <td>@if($difference === 0)<span class="status-badge badge-success">Matched</span>@else<span class="status-badge badge-danger">{{ $difference < 0 ? 'Short' : 'Over' }} ₱{{ number_format(abs($difference) / 100, 2) }}</span>@endif</td>
                <td><span class="status-badge {{ $collection->status === 'remitted_to_finance' ? 'badge-info' : ($collection->status === 'reconciled' ? 'badge-success' : 'badge-warning') }}">{{ str($collection->status)->replace('_', ' ')->title() }}</span>
                    @if($collection->logistics_reference)<small class="table-subline">Logistics: {{ $collection->logistics_reference }}</small>@endif
                    @if($collection->finance_remittance_reference)<small class="table-subline">Remittance: {{ $collection->finance_remittance_reference }}</small>@endif
                </td>
                <td>
                    @if($collection->status === 'remitted_to_finance')
                        <details class="finance-action-details"><summary class="button button-secondary button-small">Reconcile received cash</summary>
                            <form method="POST" action="{{ route('admin.payments.cod.reconcile', $collection) }}" class="finance-action-form">
                                @csrf
                                <label>Cash physically received (₱)<input type="number" name="received_amount" min="0" step="0.01" inputmode="decimal" value="{{ number_format((int) $collection->amount_collected_minor / 100, 2, '.', '') }}" required></label>
                                <label>Finance receipt / reconciliation reference<input type="text" name="reference" maxlength="150" required></label>
                                <button class="button button-primary button-small" type="submit">Confirm reconciliation</button>
                            </form>
                        </details>
                    @elseif($collection->status === 'reconciled')
                        <span>Finance: {{ $collection->finance_reference }}</span>
                    @else
                        <span class="empty-copy">Awaiting Logistics remittance</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7"><p class="empty-copy">No COD collections are waiting for Finance.</p></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">Seller settlement eligibility</span><h2>COD proceeds reconciled</h2><p>Only completed Seller Orders whose COD collection has been reconciled are included. Refund recoveries are not automatically deducted.</p></div></div>
    <div class="table-wrap"><table class="admin-table">
        <thead><tr><th>Seller</th><th>Eligible orders</th><th class="align-right">Gross</th><th class="align-right">Commission</th><th class="align-right">Net due</th><th>Action</th></tr></thead>
        <tbody>
        @forelse($eligibleSellers as $seller)
            <tr>
                <td><strong>{{ $seller['seller'] }}</strong><small class="table-subline">Seller profile #{{ $seller['seller_profile_id'] }}</small></td>
                <td>{{ $seller['orders'] }}</td>
                <td class="align-right">₱{{ number_format($seller['gross_minor'] / 100, 2) }}</td>
                <td class="align-right">−₱{{ number_format($seller['commission_minor'] / 100, 2) }}</td>
                <td class="align-right"><strong>₱{{ number_format($seller['net_minor'] / 100, 2) }}</strong></td>
                <td><details class="finance-action-details"><summary class="button button-primary button-small">Create pending settlement</summary>
                    <form method="POST" action="{{ route('admin.payments.seller-settlements.store') }}" class="finance-action-form">
                        @csrf
                        <input type="hidden" name="seller_profile_id" value="{{ $seller['seller_profile_id'] }}">
                        <p>Creates an auditable pending record only; no money is sent.</p>
                        <label>Reason<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label>
                        <button class="button button-primary button-small" type="submit">Generate settlement</button>
                    </form>
                </details></td>
            </tr>
        @empty
            <tr><td colspan="6"><p class="empty-copy">No COD Seller proceeds are ready. Reconcile all related cash with Finance first.</p></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">Approved COD refunds</span><h2>Finance to Logistics cash handoff</h2><p>Record this step only after the exact refund cash has physically been handed to the case's original outbound Logistics provider.</p></div></div>
    <div class="table-wrap"><table class="admin-table">
        <thead><tr><th>Refund / Buyer</th><th>Seller / responsibility</th><th>Amount</th><th>Issue cash to Logistics</th></tr></thead>
        <tbody>
        @forelse($refundsReadyForCashDispatch as $refund)
            <tr>
                <td><strong>{{ $refund->refund_no }}</strong><small class="table-subline">{{ $refund->order?->order_no }} · {{ $refund->order?->buyer?->name ?? 'Buyer' }}</small></td>
                <td>{{ $refund->returnRequest?->store?->name ?? 'Seller' }}<small class="table-subline">{{ ucfirst($refund->financially_responsible_party) }} responsible; no automatic payout offset</small></td>
                <td><strong>₱{{ number_format((int) ($refund->payout_amount_minor ?? $refund->amount_minor) / 100, 2) }}</strong></td>
                <td><form method="POST" action="{{ route('admin.payments.refunds.issue-cash', $refund) }}" class="finance-action-form">
                    @csrf
                    <p class="empty-copy">Bearly generates a unique handoff reference after you record the confirmed cash handoff. Logistics can see the reference in its cash workspace.</p>
                    <label class="finance-confirm"><input type="checkbox" name="cash_issued_confirmed" value="1" required> I confirm the exact cash was physically handed to Logistics.</label>
                    <button class="button button-primary button-small" type="submit">Record cash handoff</button>
                </form></td>
            </tr>
        @empty
            <tr><td colspan="4"><p class="empty-copy">No paid COD refunds are ready for cash dispatch. Seller response, Admin approval, and required Seller inspection must be complete first.</p></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

@if($refundCashPayouts->isNotEmpty())
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Refund cash custody</span><h2>Open payout assignments</h2><p>Refund stays pending until signed Buyer receipt proof is reviewed by Logistics and verified by Finance.</p></div></div>
        <div class="table-wrap"><table class="admin-table finance-refund-cash-table">
            <thead><tr><th>Cash payout / refund</th><th>Buyer / provider</th><th>Amount</th><th>Status</th><th>Finance action</th></tr></thead>
            <tbody>
            @foreach($refundCashPayouts as $payout)
                <tr>
                    <td>
                        <dl class="finance-cash-meta">
                            <div><dt>Cash payout</dt><dd><code>{{ $payout->payout_no }}</code></dd></div>
                            <div><dt>Refund</dt><dd><code>{{ $payout->refund?->refund_no ?? 'Not available' }}</code></dd></div>
                            <div><dt>Finance handoff</dt><dd>{{ $payout->finance_issue_reference ?: 'Not recorded' }}</dd></div>
                        </dl>
                    </td>
                    <td>
                        <dl class="finance-cash-meta">
                            <div><dt>Buyer</dt><dd>{{ $payout->refund?->order?->buyer?->name ?? 'Buyer' }}</dd></div>
                            <div><dt>Logistics provider</dt><dd>{{ $payout->logisticsProfile?->display_name ?? 'Not available' }}</dd></div>
                        </dl>
                    </td>
                    <td>₱{{ number_format((int) $payout->amount_minor / 100, 2) }}</td>
                    <td>
                        <div class="finance-cash-status">
                            <span class="status-badge {{ $payout->status === 'awaiting_finance_completion' ? 'badge-warning' : 'badge-info' }}">{{ str($payout->status)->replace('_', ' ')->title() }}</span>
                            @if($payout->acknowledgment_reference)
                                <div class="finance-cash-ack"><span>Buyer acknowledgment reference:</span><code>{{ $payout->acknowledgment_reference }}</code></div>
                            @endif
                        </div>
                    </td>
                    <td>
                        @if($payout->status === 'awaiting_finance_completion')
                            <a class="button button-secondary button-small" href="{{ route('admin.payments.refund-cash.acknowledgment', $payout) }}">Review signed acknowledgment</a>
                            <form method="POST" action="{{ route('admin.payments.refund-cash.complete', $payout) }}" class="finance-action-form finance-refund-review-form">
                                @csrf
                                <label class="finance-confirm"><input type="checkbox" name="buyer_receipt_verified" value="1" required> I verified the genuine Buyer receipt proof and exact refund amount.</label>
                                <label>Finance verification notes<textarea name="completion_reason" minlength="5" maxlength="1000" required></textarea></label>
                                <button class="button button-primary button-small" type="submit">Complete verified refund</button>
                            </form>
                        @else
                            <span class="empty-copy">Awaiting Logistics and Rider custody steps.</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
@endif

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
                <td class="align-right">
                    @if($payment['status'] === 'Pending' && $payment['record_id'] && $payment['item_count'] > 0)
                        <details class="finance-action-details"><summary class="button button-secondary button-small">Record paid externally</summary>
                            <form method="POST" action="{{ route('admin.payments.seller-settlements.record-paid', $payment['record_id']) }}" class="finance-action-form" enctype="multipart/form-data">
                                @csrf
                                <label>Actual amount (₱)<input type="number" min="0.01" step="0.01" inputmode="decimal" name="actual_amount" value="{{ number_format($payment['net'], 2, '.', '') }}" required></label>
                                <label>External method<select name="method" required><option value="">Select</option><option value="gcash">GCash</option><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="other">Other</option></select></label>
                                <label>Transfer / receipt reference<input type="text" name="reference" maxlength="150" required></label>
                                <label>Actual paid date<input type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
                                <label>Receipt or Seller acknowledgment<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required><small>Private file, max 5 MB.</small></label>
                                <label>Reason<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label>
                                <label class="finance-confirm"><input type="checkbox" name="external_payout_confirmed" value="1" required> I confirm the Seller was actually paid outside Bearly.</label>
                                <button class="button button-primary button-small" type="submit">Record verified payout</button>
                            </form>
                        </details>
                    @elseif($payment['status'] === 'Paid' && $payment['has_receipt'])
                        <a class="button button-secondary button-small" href="{{ route('admin.payments.seller-settlements.receipt', $payment['record_id']) }}">Private receipt</a>
                    @elseif($payment['status'] === 'Pending' && $payment['record_id'])
                        <span class="empty-copy">Legacy payout: review required</span>
                    @else
                        <select class="select-field" data-payment-status aria-label="{{ $payment['id'] }} status" disabled title="Payout status changes require a verified settlement workflow"><option selected>{{ $payment['status'] }}</option></select>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table><div class="table-empty" data-finance-empty hidden><i data-lucide="search-x"></i><strong>No payments found</strong><span>Adjust the search or status filter.</span></div></div>
</section>

@endsection
