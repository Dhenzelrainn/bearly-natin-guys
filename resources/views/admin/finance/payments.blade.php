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
    <div><span class="eyebrow">Seller Wallet · Finance</span><h1>Review Seller withdrawals</h1><p>New Seller payouts are requested from Seller Wallet and sent manually by bank transfer. COD custody reconciliation remains separate.</p></div>
    <div class="hero-actions"><a class="button button-primary" href="{{ route('admin.seller-payouts.index') }}"><i data-lucide="wallet" aria-hidden="true"></i>Open Seller Payouts</a><button class="button button-secondary" type="button" data-finance-export="payments"><i data-lucide="download"></i> Export legacy payments</button></div>
</section>

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="clock-3"></i></span><div><strong>₱{{ number_format($pendingTotal, 2) }}</strong><small>Pending / processing</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-check-big"></i></span><div><strong>₱{{ number_format($paidTotal, 2) }}</strong><small>Paid settlements</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-pause"></i></span><div><strong>₱{{ number_format($heldTotal, 2) }}</strong><small>On hold</small></div></article>
    <article class="mini-stat"><span><i data-lucide="calendar-check"></i></span><div><strong>{{ collect($payments)->where('status', 'Paid')->count() }}/{{ count($payments) }}</strong><small>Settlements released</small></div></article>
</section>

<section class="panel finance-payout-guide" aria-labelledby="finance-payout-guide-title">
    <div class="panel-heading"><div><span class="eyebrow">Manual bank payout</span><h2 id="finance-payout-guide-title">What to do next</h2></div></div>
    <ol class="finance-payout-steps">
        <li class="finance-payout-step">
            <div class="finance-payout-step-heading">
                <span class="finance-payout-step-number" aria-hidden="true">1</span>
                <span class="finance-payout-step-icon" aria-hidden="true"><i data-lucide="wallet"></i></span>
                <h3>Open withdrawal</h3>
            </div>
            <p>Open a <strong>Pending Review</strong> Seller Wallet withdrawal.</p>
        </li>
        <li class="finance-payout-step">
            <div class="finance-payout-step-heading">
                <span class="finance-payout-step-number" aria-hidden="true">2</span>
                <span class="finance-payout-step-icon" aria-hidden="true"><i data-lucide="file-text"></i></span>
                <h3>Review details</h3>
            </div>
            <p>Review the Seller's bank details, approve the exact amount, and start processing.</p>
        </li>
        <li class="finance-payout-step">
            <div class="finance-payout-step-heading">
                <span class="finance-payout-step-number" aria-hidden="true">3</span>
                <span class="finance-payout-step-icon" aria-hidden="true"><i data-lucide="send"></i></span>
                <h3>Make bank transfer</h3>
            </div>
            <p>Make the bank transfer outside Bearly, then record the actual date, exact amount, reference, and optional proof.</p>
        </li>
        <li class="finance-payout-step">
            <div class="finance-payout-step-heading">
                <span class="finance-payout-step-number" aria-hidden="true">4</span>
                <span class="finance-payout-step-icon" aria-hidden="true"><i data-lucide="shield-check"></i></span>
                <h3>Verify &amp; complete</h3>
            </div>
            <p>Independently verify the successful transfer before completing it. A reference by itself is not proof of success.</p>
        </li>
    </ol>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">COD custody</span><h2>COD custody &amp; Finance receipts</h2><p>Confirm physical cash received from Logistics here. Reconciled receipts can be printed again from their row; Seller proceeds are not settlement-eligible until Finance confirms the remittance.</p></div></div>
    <div class="table-wrap"><table class="admin-table finance-cod-custody-table">
        <thead><tr><th>Collection / order</th><th>Products</th><th>Provider / Rider</th><th>Due</th><th>Collected</th><th>Difference</th><th>Custody status</th><th>Finance action</th></tr></thead>
        <tbody>
        @forelse($codCollections as $collection)
            @php
                $difference = (int) $collection->discrepancy_minor;
                $isOldReconcile = (int) old('collection_id', 0) === (int) $collection->id;
            @endphp
            <tr>
                <td><strong>{{ $collection->collection_no }}</strong><small class="table-subline">{{ $collection->order?->order_no ?? 'Order' }} · {{ $collection->sellerOrder?->store?->name ?? 'Seller' }}</small></td>
                <td class="finance-cod-products-cell">@include('partials.order-item-preview-list', ['items' => $collection->order_item_previews])</td>
                <td>{{ $collection->logisticsProfile?->display_name ?? 'Provider' }}<small class="table-subline">{{ $collection->riderProfile?->user?->name ?? 'Rider' }}</small></td>
                <td>₱{{ number_format((int) $collection->amount_due_minor / 100, 2) }}</td>
                <td>₱{{ number_format((int) $collection->amount_collected_minor / 100, 2) }}</td>
                <td>@if($difference === 0)<span class="status-badge badge-success">Matched</span>@else<span class="status-badge badge-danger">{{ $difference < 0 ? 'Short' : 'Over' }} ₱{{ number_format(abs($difference) / 100, 2) }}</span>@endif</td>
                <td>
                    <div class="finance-cod-custody-status">
                        <span class="status-badge {{ $collection->status === 'remitted_to_finance' ? 'badge-info' : ($collection->status === 'reconciled' ? 'badge-success' : 'badge-warning') }}">{{ str($collection->status)->replace('_', ' ')->title() }}</span>
                        <div class="finance-cod-custody-references">
                            @if($collection->logistics_reference)
                                <div class="finance-cod-custody-reference">
                                    <span>Rider-to-Logistics:</span>
                                    <code>{{ $collection->logistics_reference }}</code>
                                </div>
                            @endif
                            @if($collection->finance_remittance_reference)
                                <div class="finance-cod-custody-reference">
                                    <span>{{ str_starts_with($collection->finance_remittance_reference, 'L2F-') ? 'Logistics-to-Finance:' : 'Existing remittance reference:' }}</span>
                                    <code>{{ $collection->finance_remittance_reference }}</code>
                                </div>
                            @endif
                        </div>
                    </div>
                </td>
                <td>
                    @if($collection->status === 'remitted_to_finance')
                        <details class="finance-action-details"><summary class="button button-secondary button-small">Reconcile received cash</summary>
                            <form method="POST" action="{{ route('admin.payments.cod.reconcile', $collection) }}" class="finance-action-form">
                                @csrf
                                <input type="hidden" name="collection_id" value="{{ $collection->id }}">
                                <label for="cod-finance-amount-{{ $collection->id }}">Cash physically received (₱)</label>
                                <input
                                    id="cod-finance-amount-{{ $collection->id }}"
                                    type="text"
                                    name="received_amount"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    spellcheck="false"
                                    value="{{ $isOldReconcile && old('received_amount') !== null ? old('received_amount') : number_format((int) $collection->amount_remitted_to_finance_minor / 100, 2, '.', ',') }}"
                                    pattern="(?:[0-9]{1,8}|[0-9]{1,3},[0-9]{3}|[0-9]{1,2}(?:,[0-9]{3}){2})(?:\.[0-9]{1,2})?"
                                    title="Enter an amount with up to eight digits and two decimal places; commas may separate thousands."
                                    aria-describedby="cod-finance-amount-help-{{ $collection->id }} @if($isOldReconcile && $errors->has('received_amount'))cod-finance-amount-error-{{ $collection->id }}@endif"
                                    aria-invalid="{{ $isOldReconcile && $errors->has('received_amount') ? 'true' : 'false' }}"
                                    data-finance-cash-amount
                                    required
                                >
                                <small id="cod-finance-amount-help-{{ $collection->id }}">Enter the amount Finance physically counted. Commas are accepted; the amount is stored as centavos.</small>
                                @if($isOldReconcile)
                                    @error('received_amount')<small id="cod-finance-amount-error-{{ $collection->id }}" class="cash-remittance-error">{{ $message }}</small>@enderror
                                @endif
                                <label class="finance-confirm">
                                    <input type="checkbox" name="cash_received_confirmed" value="1" @checked($isOldReconcile && old('cash_received_confirmed')) required>
                                    I confirm Finance physically received and counted this cash.
                                </label>
                                @if($isOldReconcile)
                                    @error('cash_received_confirmed')<small class="cash-remittance-error">{{ $message }}</small>@enderror
                                @endif
                                <small>Bearly creates an internal Finance receipt number after confirmation. It is not a tax invoice or bank deposit receipt.</small>
                                <button class="button button-primary button-small" type="submit">Confirm receipt &amp; reconcile</button>
                            </form>
                        </details>
                    @elseif($collection->status === 'reconciled')
                        @php $hasBearlyReceipt = str_starts_with((string) $collection->finance_reference, 'BFR-'); @endphp
                        <div class="finance-cod-receipt-action">
                            <span>{{ $hasBearlyReceipt ? 'Bearly Finance receipt:' : 'Finance reference:' }} <code>{{ $collection->finance_reference }}</code></span>
                            <a class="button button-secondary button-small" href="{{ route('admin.payments.cod.receipt', $collection) }}">{{ $hasBearlyReceipt ? 'Print Bearly receipt' : 'Print reconciliation record' }}</a>
                        </div>
                    @else
                        <span class="empty-copy">Awaiting Logistics remittance</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><p class="empty-copy">No COD collections are waiting for Finance.</p></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<section class="panel finance-payout-guide">
    <div class="panel-heading"><div><span class="eyebrow">Seller Wallet</span><h2>New requests are Seller-owned</h2><p>Finance reviews withdrawal requests instead of generating payout batches from COD proceeds. Eligible earnings move from Pending to Available only after order completion, Finance reconciliation, and return/refund checks.</p></div></div>
    <a class="button button-primary" href="{{ route('admin.seller-payouts.index') }}">Review withdrawal queue</a>
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
            <thead><tr><th>Legacy payout / Seller</th><th>Coverage</th><th class="align-right">Gross</th><th class="align-right">Commission</th><th class="align-right">Adjustments</th><th class="align-right">Net payout</th><th>Due / Payment reference</th><th>Status</th><th class="align-right">Action</th></tr></thead>
        <tbody>
        @foreach ($payments as $payment)
            @php $badge = match($payment['status']) { 'Paid' => 'badge-success', 'On Hold' => 'badge-danger', 'Processing' => 'badge-info', default => 'badge-warning' }; @endphp
            <tr data-finance-row data-payment-id="{{ $payment['id'] }}" data-status="{{ $payment['status'] }}" data-search="{{ strtolower($payment['id'].' '.$payment['seller'].' '.$payment['period'].' '.$payment['reference'].' '.$payment['receiving_bank_name']) }}">
                <td><strong>{{ $payment['seller'] }}</strong><small class="table-subline">{{ $payment['id'] }}</small></td><td>{{ $payment['period'] }}</td>
                <td class="align-right">₱{{ number_format($payment['gross'], 2) }}</td><td class="align-right commission-value">−₱{{ number_format($payment['commission'], 2) }}</td><td class="align-right">{{ $payment['adjustments'] < 0 ? '−' : '' }}₱{{ number_format(abs($payment['adjustments']), 2) }}</td><td class="align-right"><strong>₱{{ number_format($payment['net'], 2) }}</strong></td>
                <td><strong>{{ $payment['due'] ? date('M d, Y', strtotime($payment['due'])) : '—' }}</strong><small class="table-subline">{{ $payment['method'] ?? 'Not paid yet' }}</small><small class="table-subline" data-payment-reference>{{ $payment['reference'] }}</small>@if($payment['receiving_bank_name'])<small class="table-subline">Receiving bank: {{ $payment['receiving_bank_name'] }}</small>@endif</td><td><span class="status-badge js-payment-status {{ $badge }}">{{ $payment['status'] }}</span></td>
                <td class="align-right">
                    @if($payment['status'] === 'Pending' && $payment['record_id'] && $payment['item_count'] > 0)
                        <details class="finance-action-details finance-payout-details" data-finance-payout-details>
                            <summary class="button button-secondary button-small">Record payout</summary>
                            <button class="button button-secondary button-small" type="button" data-finance-payout-close hidden>Close payout form</button>
                            <p class="finance-payout-form-help">Legacy settlement records may only be closed after Finance confirms the completed bank transfer. New withdrawals must use the Seller Payouts queue.</p>
                            <form method="POST" action="{{ route('admin.payments.seller-settlements.record-paid', $payment['record_id']) }}" class="finance-action-form" enctype="multipart/form-data">
                                @csrf
                                <input type="hidden" name="payout_id" value="{{ $payment['record_id'] }}">
                                @if((int) old('payout_id', 0) === (int) $payment['record_id'] && $errors->any())
                                    <div class="finance-payout-errors" role="alert"><strong>Please fix this:</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
                                @endif
                                <label>Actual amount paid (₱)<input type="number" min="0.01" step="0.01" inputmode="decimal" name="actual_amount" value="{{ number_format($payment['net'], 2, '.', '') }}" required></label>
                                <input type="hidden" name="method" value="bank_transfer">
                                <label>Receiving bank name<input type="text" name="receiving_bank_name" maxlength="120" autocomplete="organization" value="{{ old('receiving_bank_name') }}" placeholder="e.g. MariBank Philippines, Inc." required><small>Enter the Seller’s bank where the completed transfer was received. Do not enter an account number here.</small></label>
                                <label>Bank-transfer reference<input type="text" name="reference" maxlength="150" autocomplete="off" required><small>Do not use CODH-, L2F-, or BFR- cash-custody references.</small></label>
                                <label>Date the transfer completed<input type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
                                <label>Bank transfer proof<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required><small>Upload private proof of the completed bank transfer, max 5 MB.</small></label>
                                <label>Finance verification note<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label>
                                <label class="finance-confirm"><input type="checkbox" name="seller_payout_confirmed" value="1" required> I confirm I verified the Seller received the exact bank-transfer amount.</label>
                                <button class="button button-primary button-small" type="submit">Record paid</button>
                            </form>
                        </details>
                    @elseif($payment['status'] === 'Paid' && $payment['has_receipt'])
                        <a class="button button-secondary button-small" href="{{ route('admin.payments.seller-settlements.receipt', $payment['record_id']) }}">View payout proof</a>
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
