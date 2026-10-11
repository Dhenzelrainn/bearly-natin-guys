@extends('logistics.layouts.app')

@section('title', 'Cash custody')
@section('page-title', 'Cash custody')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Provider cash register</span>
        <h1>Cash custody and handoffs</h1>
        <p>Acknowledge the exact cash physically received, record every handoff reference, and keep refund cash assigned only to an approved Rider in this provider.</p>
    </div>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">Cash on delivery</span><h2>Rider collections</h2><p>{{ $codCollections->count() }} COD cash record(s) require custody tracking.</p></div></div>
    <div class="cash-custody-table-wrap" role="region" aria-label="COD cash collections" tabindex="0">
        <table class="cash-custody-table cash-custody-table--cod">
            <thead><tr><th>Collection / order</th><th>Rider</th><th>Due</th><th>Collected</th><th>Difference</th><th>Custody action</th></tr></thead>
            <tbody>
            @forelse($codCollections as $collection)
                @php
                    $amount = number_format((int) $collection->amount_collected_minor / 100, 2, '.', '');
                    $due = number_format((int) $collection->amount_due_minor / 100, 2);
                    $difference = (int) $collection->discrepancy_minor;
                @endphp
                <tr>
                    <td><strong>{{ $collection->collection_no }}</strong><small class="table-subline">{{ $collection->order?->order_no ?? 'Order' }} · {{ $collection->sellerOrder?->store?->name ?? 'Seller' }}</small></td>
                    <td>{{ $collection->riderProfile?->user?->name ?? 'Rider' }}</td>
                    <td>₱{{ $due }}</td>
                    <td>₱{{ number_format((int) $collection->amount_collected_minor / 100, 2) }}</td>
                    <td>
                        @if($difference === 0)
                            <span class="status-badge badge-success">Matched</span>
                        @else
                            <span class="status-badge badge-danger">{{ $difference < 0 ? 'Short' : 'Over' }} ₱{{ number_format(abs($difference) / 100, 2) }}</span>
                        @endif
                    </td>
                    <td>
                        @if(in_array($collection->status, ['collected', 'disputed'], true))
                            <form method="POST" action="{{ route('logistics.cash.cod.receive', $collection) }}" class="cash-action-form">
                                @csrf
                                <label>Cash physically received <input type="number" min="0" step="0.01" inputmode="decimal" name="actual_amount" value="{{ $amount }}" required></label>
                                <label>Handoff reference <input type="text" name="reference" maxlength="150" required></label>
                                <button class="button button-primary button-small" type="submit">Acknowledge receipt</button>
                            </form>
                        @elseif($collection->status === 'received_by_logistics')
                            <form method="POST" action="{{ route('logistics.cash.cod.remit', $collection) }}" class="cash-action-form">
                                @csrf
                                <label>Cash remitted <input type="number" min="0" step="0.01" inputmode="decimal" name="actual_amount" value="{{ $amount }}" required></label>
                                <label>Finance handoff reference <input type="text" name="reference" maxlength="150" required></label>
                                <button class="button button-primary button-small" type="submit">Record Finance remittance</button>
                            </form>
                        @else
                            <span class="status-badge badge-info">{{ str($collection->status)->replace('_', ' ')->title() }}</span>
                            @if($collection->logistics_reference)<small class="table-subline">Logistics: {{ $collection->logistics_reference }}</small>@endif
                            @if($collection->finance_remittance_reference)<small class="table-subline">Finance: {{ $collection->finance_remittance_reference }}</small>@endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><p class="empty-copy">No COD cash is waiting for provider custody.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">Buyer refunds</span><h2>Assigned cash deliveries</h2><p>Refunds stay pending until Logistics verifies the signed Buyer acknowledgment and Finance completes the record.</p></div></div>
    <div class="cash-custody-table-wrap" role="region" aria-label="Refund cash assignments" tabindex="0">
        <table class="cash-custody-table cash-custody-table--refund">
            <thead><tr><th>Cash payout details</th><th>Amount</th><th>Status</th><th>Custody action</th></tr></thead>
            <tbody>
            @forelse($refundPayouts as $payout)
                <tr>
                    <td>
                        <dl class="cash-reference-list">
                            <div><dt>Cash payout ID</dt><dd><code>{{ $payout->payout_no }}</code></dd></div>
                            <div><dt>Refund ID</dt><dd><code>{{ $payout->refund?->refund_no ?? 'Not available' }}</code></dd></div>
                            <div><dt>Buyer</dt><dd>{{ $payout->refund?->order?->buyer?->name ?? 'Buyer' }}</dd></div>
                            <div><dt>Buyer acknowledgment reference</dt><dd>@if($payout->acknowledgment_reference)<code>{{ $payout->acknowledgment_reference }}</code>@else<span class="empty-copy">Generated when Rider is assigned</span>@endif</dd></div>
                            <div><dt>Finance handoff reference</dt><dd>@if($payout->finance_issue_reference)<code>{{ $payout->finance_issue_reference }}</code>@else<span class="empty-copy">Not recorded</span>@endif</dd></div>
                        </dl>
                    </td>
                    <td>₱{{ number_format((int) $payout->amount_minor / 100, 2) }}</td>
                    <td><span class="status-badge {{ in_array($payout->status, ['cash_return_pending', 'logistics_return_verification'], true) ? 'badge-warning' : 'badge-info' }}">{{ str($payout->status)->replace('_', ' ')->title() }}</span>
                        @if($payout->riderProfile?->user)<small class="table-subline">Rider: {{ $payout->riderProfile->user->name }}</small>@endif
                    </td>
                    <td>
                        @if($payout->status === 'awaiting_logistics_receipt')
                            <form method="POST" action="{{ route('logistics.cash.refunds.receive', $payout) }}" class="cash-action-form">
                                @csrf
                                <label>Cash received <input type="number" min="0.01" step="0.01" inputmode="decimal" name="actual_amount" value="{{ number_format((int) $payout->amount_minor / 100, 2, '.', '') }}" required></label>
                                <label>Receipt reference <input type="text" name="reference" maxlength="150" required></label>
                                <button class="button button-primary button-small" type="submit">Acknowledge cash</button>
                            </form>
                        @elseif($payout->status === 'awaiting_rider_assignment')
                            @if($riders->isNotEmpty())
                                <form method="POST" action="{{ route('logistics.cash.refunds.assign', $payout) }}" class="cash-action-form">
                                    @csrf
                                    <label>Approved available Rider
                                        <select name="rider_user_id" required>
                                            <option value="">Select Rider</option>
                                            @foreach($riders as $rider)<option value="{{ $rider->user_id }}">{{ $rider->user?->name }} · {{ $rider->user?->email }}</option>@endforeach
                                        </select>
                                    </label>
                                    <button class="button button-primary button-small" type="submit">Assign refund cash</button>
                                </form>
                            @else
                                <span class="empty-copy">No approved, available Riders for this provider.</span>
                            @endif
                        @elseif(in_array($payout->status, ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery'], true))
                            @if($payout->acknowledgment_reference)
                                <small class="table-subline">Print this numbered form and hand it to the assigned Rider before dispatch.</small>
                                <a class="button button-secondary button-small" href="{{ route('logistics.cash.refunds.acknowledgment-slip', $payout) }}" target="_blank" rel="noopener">
                                    <i data-lucide="printer" aria-hidden="true"></i>
                                    Print / Save as PDF
                                </a>
                            @else
                                <form method="POST" action="{{ route('logistics.cash.refunds.acknowledgment-slip.prepare', $payout) }}" class="cash-action-form">
                                    @csrf
                                    <small class="table-subline">Prepare the numbered paper acknowledgment before the Rider leaves.</small>
                                    <button class="button button-primary button-small" type="submit">Prepare acknowledgment slip</button>
                                </form>
                            @endif
                        @elseif($payout->status === 'logistics_return_verification')
                            <form method="POST" action="{{ route('logistics.cash.refunds.confirm-return', $payout) }}" class="cash-action-form">
                                @csrf
                                <label>Cash physically returned <input type="number" min="0.01" step="0.01" inputmode="decimal" name="actual_amount" value="{{ number_format((int) $payout->amount_minor / 100, 2, '.', '') }}" required></label>
                                <label>Logistics receipt reference <input type="text" name="reference" maxlength="150" required></label>
                                <button class="button button-primary button-small" type="submit">Confirm cash return</button>
                            </form>
                        @elseif($payout->status === 'delivered_pending_verification')
                            <small class="table-subline">Match the Buyer acknowledgment reference above to the signed document before verifying.</small>
                            <a class="button button-secondary button-small" href="{{ route('logistics.cash.refunds.acknowledgment', $payout) }}">Review signed acknowledgment</a>
                            <form method="POST" action="{{ route('logistics.cash.refunds.verify-receipt', $payout) }}" class="cash-action-form">
                                @csrf
                                <label>Verification notes <textarea name="verification_note" minlength="5" maxlength="1000" required></textarea></label>
                                <button class="button button-primary button-small" type="submit">Verify Buyer receipt</button>
                            </form>
                        @else
                            @if($payout->cash_return_reference)<small class="table-subline">Rider return: {{ $payout->cash_return_reference }}</small>@endif
                            @if($payout->logistics_cash_return_reference)<small class="table-subline">Logistics receipt: {{ $payout->logistics_cash_return_reference }}</small>@endif
                            @if($payout->acknowledgment_reference)<small class="table-subline">Buyer acknowledgment: {{ $payout->acknowledgment_reference }}</small>@endif
                            <span class="empty-copy">No action available at this custody step.</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4"><p class="empty-copy">No refund cash assignments are active.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

@endsection
