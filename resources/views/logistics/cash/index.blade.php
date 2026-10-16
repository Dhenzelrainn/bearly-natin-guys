@extends('logistics.layouts.app')

@section('title', 'Cash custody')
@section('page-title', 'Cash custody')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Provider cash register</span>
        <h1>Cash custody and handoffs</h1>
        <p>Count and confirm the cash from the Rider. When you hand it to Finance, record the amount here; Bearly creates a handoff reference for Finance to reconcile. Give refund cash only to an approved Rider from your provider.</p>
    </div>
</section>

<div class="cash-custody-layout">
    <div class="cash-custody-lists">
<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Cash on delivery</span>
            <h2>Rider collections</h2>
            @if($codCollections->count() === 1)
                <p>1 COD cash handoff needs your attention.</p>
            @else
                <p>{{ $codCollections->count() }} COD cash handoffs need your attention.</p>
            @endif
        </div>
    </div>
    <div class="cash-custody-table-wrap" role="region" aria-label="COD cash collections" tabindex="0">
        <table class="cash-custody-table cash-custody-table--cod">
            <thead><tr><th>Collection / order</th><th>Rider</th><th>Expected amount</th><th>Collected amount</th><th>Difference</th><th>Custody action</th></tr></thead>
            <tbody>
            @forelse($codCollections as $collection)
                @php
                    $amount = number_format((int) $collection->amount_collected_minor / 100, 2, '.', '');
                    $due = number_format((int) $collection->amount_due_minor / 100, 2);
                    $difference = (int) $collection->discrepancy_minor;
                @endphp
                <tr>
                    <td>
                        <strong>{{ $collection->collection_no }}</strong>
                        <small class="table-subline">{{ $collection->order?->order_no ?? 'Order' }} · {{ $collection->sellerOrder?->store?->name ?? 'Seller' }}</small>
                        @if($collection->logistics_reference)
                            <small class="table-subline">Rider-to-Logistics reference: <code>{{ $collection->logistics_reference }}</code></small>
                        @elseif(in_array($collection->status, ['collected', 'disputed'], true))
                            <small class="table-subline">Bearly creates this number after you confirm the Rider's cash.</small>
                        @endif
                    </td>
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
                                <small class="table-subline">Count the cash. Enter the amount you received. It must match the Rider's record. Then select “Acknowledge receipt.”</small>
                                <button class="button button-primary button-small" type="submit">Acknowledge receipt</button>
                            </form>
                        @elseif($collection->status === 'received_by_logistics')
                            @if($selectedCodCollection && (int) $selectedCodCollection->id === (int) $collection->id)
                                <span class="status-badge badge-info" aria-current="true">Selected</span>
                            @else
                                <a
                                    class="button button-secondary button-small cash-remittance-select"
                                    href="{{ route('logistics.cash.index', ['cod_remittance' => $collection->id]) }}#finance-remittance"
                                    aria-label="Select COD collection {{ $collection->collection_no }} for remittance"
                                >Select</a>
                            @endif
                        @else
                            <span class="status-badge badge-info">{{ str($collection->status)->replace('_', ' ')->title() }}</span>
                            @if($collection->logistics_reference)<small class="table-subline">Rider-to-Logistics: {{ $collection->logistics_reference }}</small>@endif
                            @if($collection->finance_remittance_reference)<small class="table-subline">Logistics-to-Finance: {{ $collection->finance_remittance_reference }}</small>@endif
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
    <div class="panel-heading"><div><span class="eyebrow">Buyer refunds</span><h2>Assigned cash deliveries</h2><p>After the Rider gives the refund to the Buyer, check the signed receipt here. Finance can finish the record after you confirm it.</p></div></div>
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
                                <span class="empty-copy">No approved Rider is available for this cash delivery.</span>
                            @endif
                        @elseif(in_array($payout->status, ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery'], true))
                            @if($payout->acknowledgment_reference)
                                <small class="table-subline">Print this form. Give it to the assigned Rider with the cash before they leave.</small>
                                <a class="button button-secondary button-small" href="{{ route('logistics.cash.refunds.acknowledgment-slip', $payout) }}" target="_blank" rel="noopener">
                                    <i data-lucide="printer" aria-hidden="true"></i>
                                    Print / Save as PDF
                                </a>
                            @else
                                <form method="POST" action="{{ route('logistics.cash.refunds.acknowledgment-slip.prepare', $payout) }}" class="cash-action-form">
                                    @csrf
                                    <small class="table-subline">Create the paper form before the Rider leaves.</small>
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
                            <small class="table-subline">Check that the number above matches the number on the Buyer's signed receipt.</small>
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
    </div>

    <aside class="panel cash-remittance-panel" id="finance-remittance" aria-labelledby="cash-remittance-title">
        @if($selectedCodCollection)
            @php
                $selectedAmount = number_format((int) $selectedCodCollection->amount_collected_minor / 100, 2, '.', ',');
                $selectedDue = number_format((int) $selectedCodCollection->amount_due_minor / 100, 2);
                $selectedDifference = (int) $selectedCodCollection->discrepancy_minor;
            @endphp

            <div class="cash-remittance-heading">
                <span class="cash-remittance-icon"><i data-lucide="coins" aria-hidden="true"></i></span>
                <div>
                    <span class="eyebrow">Next step</span>
                    <h2 id="cash-remittance-title">Finance handoff</h2>
                    <p>Use this after you give the COD cash to Finance.</p>
                </div>
            </div>

            <section class="cash-remittance-details" aria-labelledby="cash-remittance-details-title">
                <div class="cash-remittance-details-heading">
                    <h3 id="cash-remittance-details-title">Collection details</h3>
                    @if($selectedDifference === 0)
                        <span class="status-badge badge-success">Matched</span>
                    @else
                        <span class="status-badge badge-danger">{{ $selectedDifference < 0 ? 'Short' : 'Over' }} ₱{{ number_format(abs($selectedDifference) / 100, 2) }}</span>
                    @endif
                </div>
                <dl class="cash-remittance-grid">
                    <div>
                        <dt>COD collection</dt>
                        <dd><code>{{ $selectedCodCollection->collection_no }}</code></dd>
                    </div>
                    <div>
                        <dt>Order / Seller</dt>
                        <dd>{{ $selectedCodCollection->order?->order_no ?? 'Order' }} · {{ $selectedCodCollection->sellerOrder?->store?->name ?? 'Seller' }}</dd>
                    </div>
                    <div>
                        <dt>Rider</dt>
                        <dd>{{ $selectedCodCollection->riderProfile?->user?->name ?? 'Rider' }}</dd>
                    </div>
                    <div>
                        <dt>Expected amount</dt>
                        <dd>₱{{ $selectedDue }}</dd>
                    </div>
                    <div>
                        <dt>Collected amount</dt>
                        <dd>₱{{ number_format((int) $selectedCodCollection->amount_collected_minor / 100, 2) }}</dd>
                    </div>
                    <div>
                        <dt>Difference</dt>
                        <dd>₱{{ number_format(abs($selectedDifference) / 100, 2) }}</dd>
                    </div>
                    <div class="cash-remittance-grid-wide">
                        <dt>Rider-to-Logistics reference</dt>
                        <dd><code>{{ $selectedCodCollection->logistics_reference ?: 'Not recorded' }}</code></dd>
                    </div>
                </dl>
            </section>

            <form method="POST" action="{{ route('logistics.cash.cod.remit', $selectedCodCollection) }}" class="cash-action-form cash-remittance-form">
                @csrf
                <label for="cash-remitted-amount">Cash given to Finance</label>
                <input id="cash-remitted-amount" type="text" inputmode="decimal" autocomplete="off" spellcheck="false" name="actual_amount" value="{{ old('actual_amount', $selectedAmount) }}" pattern="(?:[0-9]{1,8}|[0-9]{1,3},[0-9]{3}|[0-9]{1,2}(?:,[0-9]{3}){2})(?:\.[0-9]{1,2})?" title="Enter an amount with up to eight digits and two decimal places." data-cash-amount required>
                <small class="table-subline">After you hand the cash to Finance, enter the amount they accepted. It must match the amount the Rider gave you.</small>
                @error('actual_amount')<small class="cash-remittance-error">{{ $message }}</small>@enderror

                <div class="cash-remittance-instructions" aria-label="Steps to record the Finance handoff">
                    <i data-lucide="info" aria-hidden="true"></i>
                    <div>
                        <strong>Do these steps:</strong>
                        <ol>
                            <li>Give the cash to Finance.</li>
                            <li>Enter the amount Finance accepted, then record the handoff.</li>
                            <li>Bearly creates a unique L2F (Logistics-to-Finance) reference. Finance adds its receipt reference when reconciling.</li>
                        </ol>
                    </div>
                </div>

                <button class="button button-primary button-small" type="submit">Record remittance</button>
            </form>
        @else
            <div class="cash-remittance-empty">
                <span class="cash-remittance-icon"><i data-lucide="coins" aria-hidden="true"></i></span>
                <h2 id="cash-remittance-title">Finance handoff</h2>
                <p>First, confirm the cash from the Rider. When the row says “Received by Logistics,” select that COD record to continue here.</p>
            </div>
        @endif
    </aside>
</div>

@endsection
