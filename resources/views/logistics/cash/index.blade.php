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
    <div class="cash-custody-card-list" role="list" aria-label="COD cash collections">
        @forelse($codCollections as $collection)
            @php
                $amount = number_format((int) $collection->amount_collected_minor / 100, 2, '.', '');
                $due = number_format((int) $collection->amount_due_minor / 100, 2);
                $difference = (int) $collection->discrepancy_minor;
                $collectionStatusLabel = str($collection->status)->replace('_', ' ')->title();
            @endphp
            <article class="cash-custody-card" role="listitem">
                <section class="cash-custody-card-column" aria-labelledby="cod-collection-details-{{ $collection->id }}">
                    <div class="cash-custody-card-heading">
                        <span class="cash-custody-card-icon" aria-hidden="true"><i data-lucide="receipt-text"></i></span>
                        <div>
                            <h3 id="cod-collection-details-{{ $collection->id }}">Collection details</h3>
                            <p>Order, Rider, and cash reference information.</p>
                        </div>
                    </div>
                    <dl class="cash-custody-reference-list">
                        <div class="cash-custody-reference-item">
                            <dt>COD collection ID</dt>
                            <dd><code>{{ $collection->collection_no }}</code><button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $collection->collection_no }}" aria-label="Copy COD collection ID"><i data-lucide="copy" aria-hidden="true"></i></button></dd>
                        </div>
                        <div class="cash-custody-reference-item">
                            <dt>Order / Seller</dt>
                            <dd><span>{{ $collection->order?->order_no ?? 'Order' }} · {{ $collection->sellerOrder?->store?->name ?? 'Seller' }}</span>@if($collection->order?->order_no)<button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $collection->order->order_no }}" aria-label="Copy order ID"><i data-lucide="copy" aria-hidden="true"></i></button>@endif</dd>
                        </div>
                        <div class="cash-custody-reference-item">
                            <dt>Rider</dt>
                            <dd>{{ $collection->riderProfile?->user?->name ?? 'Rider' }}</dd>
                        </div>
                        <div class="cash-custody-reference-item">
                            <dt>Rider-to-Logistics reference</dt>
                            <dd>@if($collection->logistics_reference)<code>{{ $collection->logistics_reference }}</code><button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $collection->logistics_reference }}" aria-label="Copy Rider-to-Logistics reference"><i data-lucide="copy" aria-hidden="true"></i></button>@elseif(in_array($collection->status, ['collected', 'disputed'], true))<span class="empty-copy">Generated after cash is acknowledged</span>@else<span class="empty-copy">Not recorded</span>@endif</dd>
                        </div>
                        @if($collection->finance_remittance_reference)
                            <div class="cash-custody-reference-item">
                                <dt>Logistics-to-Finance reference</dt>
                                <dd><code>{{ $collection->finance_remittance_reference }}</code><button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $collection->finance_remittance_reference }}" aria-label="Copy Logistics-to-Finance reference"><i data-lucide="copy" aria-hidden="true"></i></button></dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="cash-custody-card-column" aria-labelledby="cod-collection-amount-{{ $collection->id }}">
                    <div class="cash-custody-card-heading">
                        <span class="cash-custody-card-icon" aria-hidden="true"><i data-lucide="coins"></i></span>
                        <div>
                            <h3 id="cod-collection-amount-{{ $collection->id }}">Amount &amp; status</h3>
                            <p>Compare the Rider's collection with the order amount.</p>
                        </div>
                    </div>
                    <div class="cash-custody-amount-card cod-cash-amount-card">
                        <div class="cod-cash-amount-item">
                            <span>Expected amount</span>
                            <strong>₱{{ $due }}</strong>
                        </div>
                        <div class="cod-cash-amount-item">
                            <span>Collected amount</span>
                            <strong>₱{{ number_format((int) $collection->amount_collected_minor / 100, 2) }}</strong>
                        </div>
                    </div>
                    <div class="cash-custody-status-card cod-cash-difference-card">
                        <span class="cash-custody-status-label">Difference</span>
                        @if($difference === 0)
                            <span class="status-badge badge-success"><i data-lucide="circle-check" aria-hidden="true"></i>Matched</span>
                        @else
                            <span class="status-badge badge-danger"><i data-lucide="triangle-alert" aria-hidden="true"></i>{{ $difference < 0 ? 'Short' : 'Over' }} ₱{{ number_format(abs($difference) / 100, 2) }}</span>
                        @endif
                        <small>Collection status</small>
                        <strong>{{ $collectionStatusLabel }}</strong>
                    </div>
                </section>

                <section class="cash-custody-card-column cash-custody-card-action" aria-labelledby="cod-collection-action-{{ $collection->id }}">
                    <div class="cash-custody-card-heading">
                        <span class="cash-custody-card-icon" aria-hidden="true"><i data-lucide="shield-check"></i></span>
                        <div>
                            <h3 id="cod-collection-action-{{ $collection->id }}">Custody action</h3>
                            <p>Confirm the cash handover using the amount you counted.</p>
                        </div>
                    </div>
                    @if(in_array($collection->status, ['collected', 'disputed'], true))
                        <form method="POST" action="{{ route('logistics.cash.cod.receive', $collection) }}" class="cash-action-form cash-custody-action-form">
                            @csrf
                            <label for="cod-cash-received-{{ $collection->id }}">Cash physically received <input id="cod-cash-received-{{ $collection->id }}" type="number" min="0" step="0.01" inputmode="decimal" name="actual_amount" value="{{ $amount }}" required></label>
                            <p class="cash-custody-action-note">Count the cash and enter the amount received. Then acknowledge the receipt.</p>
                            <button class="button button-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Acknowledge receipt <i data-lucide="arrow-right" aria-hidden="true"></i></button>
                        </form>
                    @elseif($collection->status === 'received_by_logistics')
                        @if($selectedCodCollection && (int) $selectedCodCollection->id === (int) $collection->id)
                            <span class="status-badge badge-info" aria-current="true">Selected for Finance handoff</span>
                        @else
                            <a
                                class="button button-secondary cash-custody-action-button cash-remittance-select"
                                href="{{ route('logistics.cash.index', ['cod_remittance' => $collection->id]) }}#finance-remittance"
                                aria-label="Select COD collection {{ $collection->collection_no }} for remittance"
                            ><i data-lucide="arrow-right-left" aria-hidden="true"></i>Select for Finance remittance</a>
                        @endif
                    @else
                        <span class="status-badge badge-info">{{ $collectionStatusLabel }}</span>
                        @if($collection->logistics_reference)<p class="cash-custody-action-note">Rider-to-Logistics: {{ $collection->logistics_reference }}</p>@endif
                        @if($collection->finance_remittance_reference)<p class="cash-custody-action-note">Logistics-to-Finance: {{ $collection->finance_remittance_reference }}</p>@endif
                    @endif
                </section>
            </article>
        @empty
            <div class="cash-custody-assignment-empty"><i data-lucide="wallet-cards" aria-hidden="true"></i><strong>No COD cash is waiting for provider custody.</strong></div>
        @endforelse
    </div>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">Buyer refunds</span><h2>Assigned cash deliveries</h2><p>After the Rider gives the refund to the Buyer, check the signed receipt here. Finance can finish the record after you confirm it.</p></div></div>
    <div class="cash-custody-card-list" role="list" aria-label="Refund cash assignments" data-refund-cash-list data-status-url="{{ route('logistics.cash.refunds.status-version') }}" data-status-version="{{ $refundCashStatusVersion }}">
        @forelse($refundPayouts as $payout)
            @php
                $refundStatusLabel = str($payout->status)->replace('_', ' ')->title();
                $refundStatusWarning = in_array($payout->status, ['cash_return_pending', 'logistics_return_verification'], true);
            @endphp
            <article class="cash-custody-card" role="listitem">
                <section class="cash-custody-card-column" aria-labelledby="refund-cash-details-{{ $payout->id }}">
                    <div class="cash-custody-card-heading">
                        <span class="cash-custody-card-icon" aria-hidden="true"><i data-lucide="file-text"></i></span>
                        <div>
                            <h3 id="refund-cash-details-{{ $payout->id }}">Cash payout details</h3>
                            <p>Reference information for this refund.</p>
                        </div>
                    </div>
                    <dl class="cash-custody-reference-list">
                        <div class="cash-custody-reference-item">
                            <dt>Cash payout ID</dt>
                            <dd><code>{{ $payout->payout_no }}</code><button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $payout->payout_no }}" aria-label="Copy cash payout ID"><i data-lucide="copy" aria-hidden="true"></i></button></dd>
                        </div>
                        <div class="cash-custody-reference-item">
                            <dt>Refund ID</dt>
                            <dd><code>{{ $payout->refund?->refund_no ?? 'Not available' }}</code>@if($payout->refund?->refund_no)<button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $payout->refund->refund_no }}" aria-label="Copy refund ID"><i data-lucide="copy" aria-hidden="true"></i></button>@endif</dd>
                        </div>
                        <div class="cash-custody-reference-item">
                            <dt>Buyer</dt>
                            <dd>{{ $payout->refund?->order?->buyer?->name ?? 'Buyer' }}</dd>
                        </div>
                        <div class="cash-custody-reference-item">
                            <dt>Buyer acknowledgment reference</dt>
                            <dd>@if($payout->acknowledgment_reference)<code>{{ $payout->acknowledgment_reference }}</code><button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $payout->acknowledgment_reference }}" aria-label="Copy Buyer acknowledgment reference"><i data-lucide="copy" aria-hidden="true"></i></button>@else<span class="empty-copy">Generated when Rider is assigned</span>@endif</dd>
                        </div>
                        <div class="cash-custody-reference-item">
                            <dt>Finance handoff reference</dt>
                            <dd>@if($payout->finance_issue_reference)<code>{{ $payout->finance_issue_reference }}</code><button class="cash-custody-copy" type="button" data-copy-cash-reference data-copy-value="{{ $payout->finance_issue_reference }}" aria-label="Copy Finance handoff reference"><i data-lucide="copy" aria-hidden="true"></i></button>@else<span class="empty-copy">Not recorded</span>@endif</dd>
                        </div>
                    </dl>
                </section>

                <section class="cash-custody-card-column" aria-labelledby="refund-cash-status-{{ $payout->id }}">
                    <div class="cash-custody-card-heading">
                        <span class="cash-custody-card-icon" aria-hidden="true"><i data-lucide="coins"></i></span>
                        <div>
                            <h3 id="refund-cash-status-{{ $payout->id }}">Amount &amp; status</h3>
                            <p>Refund amount and current status.</p>
                        </div>
                    </div>
                    <div class="cash-custody-amount-card">
                        <span>Refund amount</span>
                        <strong>₱{{ number_format((int) $payout->amount_minor / 100, 2) }}</strong>
                        <i data-lucide="coins" aria-hidden="true"></i>
                    </div>
                    <div class="cash-custody-status-card">
                        <span class="cash-custody-status-label">Status</span>
                        <span class="status-badge {{ $refundStatusWarning ? 'badge-warning' : 'badge-info' }}">
                            <i data-lucide="{{ $refundStatusWarning ? 'triangle-alert' : 'clock-3' }}" aria-hidden="true"></i>
                            {{ $refundStatusLabel }}
                        </span>
                        @if($payout->riderProfile?->user)
                            <small>Assigned Rider</small>
                            <strong>{{ $payout->riderProfile->user->name }}</strong>
                        @endif
                    </div>
                </section>

                <section class="cash-custody-card-column cash-custody-card-action" aria-labelledby="refund-cash-action-{{ $payout->id }}">
                    <div class="cash-custody-card-heading">
                        <span class="cash-custody-card-icon" aria-hidden="true"><i data-lucide="shield-check"></i></span>
                        <div>
                            <h3 id="refund-cash-action-{{ $payout->id }}">Custody action</h3>
                            <p>Confirm the cash handover using the receipt.</p>
                        </div>
                    </div>
                    @if($payout->status === 'awaiting_logistics_receipt')
                        <form method="POST" action="{{ route('logistics.cash.refunds.receive', $payout) }}" class="cash-action-form cash-custody-action-form">
                            @csrf
                            <label for="refund-cash-received-{{ $payout->id }}">Cash received <input id="refund-cash-received-{{ $payout->id }}" type="number" min="0.01" step="0.01" inputmode="decimal" name="actual_amount" value="{{ number_format((int) $payout->amount_minor / 100, 2, '.', '') }}" required></label>
                            <label for="refund-cash-reference-{{ $payout->id }}">Receipt reference <input id="refund-cash-reference-{{ $payout->id }}" type="text" name="reference" maxlength="150" placeholder="Enter receipt reference" required></label>
                            <button class="button button-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Acknowledge cash <i data-lucide="arrow-right" aria-hidden="true"></i></button>
                        </form>
                    @elseif($payout->status === 'awaiting_rider_assignment')
                        @if($riders->isNotEmpty())
                            <form method="POST" action="{{ route('logistics.cash.refunds.assign', $payout) }}" class="cash-action-form cash-custody-action-form">
                                @csrf
                                <label for="refund-cash-rider-{{ $payout->id }}">Approved available Rider
                                    <select id="refund-cash-rider-{{ $payout->id }}" name="rider_user_id" required>
                                        <option value="">Select Rider</option>
                                        @foreach($riders as $rider)<option value="{{ $rider->user_id }}">{{ $rider->user?->name }} · {{ $rider->user?->email }}</option>@endforeach
                                    </select>
                                </label>
                                <button class="button button-primary" type="submit"><i data-lucide="bike" aria-hidden="true"></i>Assign refund cash <i data-lucide="arrow-right" aria-hidden="true"></i></button>
                            </form>
                        @else
                            <p class="cash-custody-action-note">No approved Rider is available for this cash delivery.</p>
                        @endif
                    @elseif(in_array($payout->status, ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery'], true))
                        @if($payout->acknowledgment_reference)
                            <p class="cash-custody-action-note">Print this form and give it to the assigned Rider with the cash before they leave.</p>
                            <a class="button button-secondary cash-custody-action-button" href="{{ route('logistics.cash.refunds.acknowledgment-slip', $payout) }}" target="_blank" rel="noopener">
                                <i data-lucide="printer" aria-hidden="true"></i>Print / Save as PDF
                            </a>
                        @else
                            <form method="POST" action="{{ route('logistics.cash.refunds.acknowledgment-slip.prepare', $payout) }}" class="cash-action-form cash-custody-action-form">
                                @csrf
                                <p class="cash-custody-action-note">Create the paper form before the Rider leaves.</p>
                                <button class="button button-primary" type="submit"><i data-lucide="file-plus-2" aria-hidden="true"></i>Prepare acknowledgment slip</button>
                            </form>
                        @endif
                    @elseif($payout->status === 'logistics_return_verification')
                        <form method="POST" action="{{ route('logistics.cash.refunds.confirm-return', $payout) }}" class="cash-action-form cash-custody-action-form">
                            @csrf
                            <label for="refund-cash-returned-{{ $payout->id }}">Cash physically returned <input id="refund-cash-returned-{{ $payout->id }}" type="number" min="0.01" step="0.01" inputmode="decimal" name="actual_amount" value="{{ number_format((int) $payout->amount_minor / 100, 2, '.', '') }}" required></label>
                            <label for="refund-cash-return-reference-{{ $payout->id }}">Logistics receipt reference <input id="refund-cash-return-reference-{{ $payout->id }}" type="text" name="reference" maxlength="150" required></label>
                            <button class="button button-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Confirm cash return <i data-lucide="arrow-right" aria-hidden="true"></i></button>
                        </form>
                    @elseif($payout->status === 'delivered_pending_verification')
                        <p class="cash-custody-action-note">Check that the number above matches the number on the Buyer's signed receipt.</p>
                        <a class="button button-secondary cash-custody-action-button" href="{{ route('logistics.cash.refunds.acknowledgment', $payout) }}"><i data-lucide="file-check-2" aria-hidden="true"></i>Review signed acknowledgment</a>
                        <form method="POST" action="{{ route('logistics.cash.refunds.verify-receipt', $payout) }}" class="cash-action-form cash-custody-action-form">
                            @csrf
                            <label for="refund-cash-verification-{{ $payout->id }}">Verification notes <textarea id="refund-cash-verification-{{ $payout->id }}" name="verification_note" minlength="5" maxlength="1000" required></textarea></label>
                            <button class="button button-primary" type="submit"><i data-lucide="shield-check" aria-hidden="true"></i>Verify Buyer receipt <i data-lucide="arrow-right" aria-hidden="true"></i></button>
                        </form>
                    @else
                        @if($payout->cash_return_reference)<p class="cash-custody-action-note">Rider return: {{ $payout->cash_return_reference }}</p>@endif
                        @if($payout->logistics_cash_return_reference)<p class="cash-custody-action-note">Logistics receipt: {{ $payout->logistics_cash_return_reference }}</p>@endif
                        <p class="cash-custody-action-note">No action is available at this custody step.</p>
                    @endif
                </section>
            </article>
        @empty
            <div class="cash-custody-assignment-empty"><i data-lucide="wallet-cards" aria-hidden="true"></i><strong>No refund cash assignments are active.</strong></div>
        @endforelse
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
