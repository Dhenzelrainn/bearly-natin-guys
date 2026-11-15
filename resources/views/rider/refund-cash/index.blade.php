@extends('rider.layouts.app')

@section('title', 'Cash custody')
@section('page-title', 'Cash custody')

@section('content')
<div class="refund-cash-workspace">
    <section class="page-hero refund-cash-hero">
        <div>
            <span class="eyebrow">Rider cash register</span>
            <h1>Refund cash handoffs</h1>
            <p>Track cash received from Logistics through Buyer delivery. Record each handoff only after it physically happens.</p>
        </div>
    </section>

    @if($errors->any())
        <div class="refund-cash-errors" role="alert" aria-labelledby="refund-cash-error-title">
            <strong id="refund-cash-error-title">We couldn't update this assignment.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @forelse($payouts as $payout)
        @php
            $order = $payout->refund?->order;
            $buyerName = $order?->recipient_name ?: ($order?->buyer?->name ?? 'Buyer');
            $buyerPhone = $order?->recipient_phone;
            $address = collect([$order?->address_line, $order?->barangay, $order?->city_municipality, $order?->province, $order?->postal_code])->filter()->implode(', ');
            $expectedAmount = number_format((int) $payout->amount_minor / 100, 2, '.', '');
            $statusLabel = match ($payout->status) {
                'assigned_to_rider' => 'Assigned to you',
                'cash_with_rider' => 'Cash received',
                'out_for_delivery' => 'Out for delivery',
                'cash_return_pending' => 'Return cash to Logistics',
                'logistics_return_verification' => 'Awaiting Logistics confirmation',
                'delivered_pending_verification' => 'Delivery awaiting verification',
                default => str($payout->status)->replace('_', ' ')->title(),
            };
        @endphp
        <article class="panel refund-cash-card" id="refund-cash-payout-{{ $payout->id }}">
            <header class="refund-cash-card-header">
                <div class="refund-cash-card-title">
                    <span class="eyebrow">Buyer refund</span>
                    <h2>₱{{ number_format((int) $payout->amount_minor / 100, 2) }} <span>for {{ $buyerName }}</span></h2>
                    <p>
                        @if($payout->status === 'assigned_to_rider')
                            Logistics should give you the numbered, printed acknowledgment form with the cash.
                        @elseif($payout->status === 'cash_with_rider')
                            Cash receipt is recorded. Use the acknowledgment form issued by Logistics for the Buyer signature.
                        @elseif($payout->status === 'out_for_delivery')
                            Confirm the full amount and Buyer acknowledgment at handoff.
                        @elseif($payout->status === 'cash_return_pending')
                            Return the full cash amount to Logistics after the failed delivery.
                        @else
                            Review the assignment details and current status.
                        @endif
                    </p>
                </div>
                <span class="status-badge badge-info" data-status="{{ $payout->status }}">{{ $statusLabel }}</span>
            </header>

            <dl class="refund-cash-references" aria-label="Refund references">
                <div>
                    <dt>Cash payout ID</dt>
                    <dd><code>{{ $payout->payout_no }}</code></dd>
                </div>
                <div>
                    <dt>Refund ID</dt>
                    <dd><code>{{ $payout->refund?->refund_no ?? 'Not available' }}</code></dd>
                </div>
                @if($payout->acknowledgment_reference)
                    <div>
                        <dt>Buyer acknowledgment reference</dt>
                        <dd><code>{{ $payout->acknowledgment_reference }}</code></dd>
                    </div>
                @endif
                <div>
                    <dt>Finance-to-Logistics handoff reference</dt>
                    <dd>
                        @if($payout->finance_issue_reference)
                            <code>{{ $payout->finance_issue_reference }}</code>
                        @else
                            <span class="refund-cash-muted">Not recorded</span>
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="refund-cash-card-content">
                <section class="refund-cash-details" aria-labelledby="refund-cash-details-{{ $payout->id }}">
                    <h3 id="refund-cash-details-{{ $payout->id }}">Assignment details</h3>
                    <dl class="refund-cash-detail-list">
                        <div>
                            <dt>Buyer</dt>
                            <dd>{{ $buyerName }}</dd>
                        </div>
                        <div>
                            <dt>Buyer contact</dt>
                            <dd>{{ $buyerPhone ?: 'No phone number provided' }}</dd>
                        </div>
                        <div class="refund-cash-detail-wide">
                            <dt>Delivery address</dt>
                            <dd>{{ $address ?: 'Use the delivery address on the order.' }}</dd>
                        </div>
                        <div>
                            <dt>Seller</dt>
                            <dd>{{ $payout->returnRequest?->store?->name ?? 'Bearly Seller' }}</dd>
                        </div>
                        <div>
                            <dt>Logistics provider</dt>
                            <dd>{{ $payout->logisticsProfile?->display_name ?? 'Assigned provider' }}</dd>
                        </div>
                    </dl>

                    @if($payout->failure_reason)
                        <div class="delivery-action-note is-warning refund-cash-previous-attempt" role="note">
                            <i data-lucide="triangle-alert"></i>
                            <span><strong>Previous attempt</strong><small>{{ $payout->failure_reason }}</small></span>
                        </div>
                    @endif
                </section>

                <aside class="refund-cash-next-step" aria-label="Next step">
                    @if($payout->status === 'assigned_to_rider')
                        <div class="refund-cash-action-heading">
                            <span class="eyebrow">Next step</span>
                            <h3>Confirm cash received</h3>
                            <p>Count the cash from Logistics first. Enter the amount you physically received.</p>
                        </div>
                        @if($payout->acknowledgment_reference)
                            <div class="delivery-action-note is-warning" role="note">
                                <i data-lucide="file-check-2" aria-hidden="true"></i>
                                <span><strong>Logistics-issued form</strong><small>Check that the printed form you received shows {{ $payout->acknowledgment_reference }} before accepting the cash.</small></span>
                            </div>
                            <form method="POST" action="{{ route('rider.refund-cash.receive', $payout) }}" class="cash-rider-form">
                                @csrf
                                <label for="refund-cash-received-{{ $payout->id }}">Cash actually received (₱)</label>
                                <small class="refund-cash-field-help">Expected amount: ₱{{ number_format((int) $payout->amount_minor / 100, 2) }}. Enter the exact amount received; it must match.</small>
                                <input id="refund-cash-received-{{ $payout->id }}" type="number" name="actual_amount" min="0.01" step="0.01" inputmode="decimal" placeholder="{{ $expectedAmount }}" aria-invalid="{{ $errors->has('actual_amount') ? 'true' : 'false' }}" required>
                                @error('actual_amount')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror
                                <label for="refund-cash-paper-ref-{{ $payout->id }}">Reference printed on the paper form</label>
                                <input id="refund-cash-paper-ref-{{ $payout->id }}" type="text" name="acknowledgment_reference" maxlength="150" autocomplete="off" placeholder="{{ $payout->acknowledgment_reference }}" aria-invalid="{{ $errors->has('acknowledgment_reference') ? 'true' : 'false' }}" required>
                                @error('acknowledgment_reference')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror
                                <button class="button button-primary" type="submit">Confirm cash and form received</button>
                            </form>
                        @else
                            <div class="delivery-action-note is-warning" role="status">
                                <i data-lucide="triangle-alert" aria-hidden="true"></i>
                                <span><strong>Wait for Logistics</strong><small>Ask Logistics to prepare and hand you the numbered acknowledgment form before accepting cash.</small></span>
                            </div>
                        @endif
                    @elseif($payout->status === 'cash_with_rider')
                        <div class="refund-cash-action-heading">
                            <span class="eyebrow">Next step</span>
                            <h3>Deliver the refund</h3>
                            <p>You confirmed receipt of ₱{{ number_format((int) $payout->amount_minor / 100, 2) }}. Start when you are ready to head to the Buyer.</p>
                        </div>
                        @if($payout->acknowledgment_reference)
                            <form method="POST" action="{{ route('rider.refund-cash.start', $payout) }}" class="refund-cash-simple-action">
                                @csrf
                                <button class="button button-primary" type="submit">Start Buyer delivery</button>
                            </form>
                        @else
                            <div class="delivery-action-note is-warning" role="status">
                                <i data-lucide="triangle-alert" aria-hidden="true"></i>
                                <span><strong>Logistics form required</strong><small>Ask Logistics to prepare and hand you the numbered acknowledgment form before leaving.</small></span>
                            </div>
                        @endif
                    @elseif($payout->status === 'out_for_delivery')
                        <div class="refund-cash-action-heading">
                            <span class="eyebrow">Next step</span>
                            <h3>Record Buyer handoff</h3>
                            <p>Complete this after the Buyer receives the full cash amount and signs the acknowledgment.</p>
                        </div>
                        @if($payout->acknowledgment_reference)
                            <div class="delivery-action-note is-warning" role="note">
                                <i data-lucide="file-check-2" aria-hidden="true"></i>
                                <span><strong>Use the printed form from Logistics</strong><small>Confirm it shows {{ $payout->acknowledgment_reference }}. The Buyer must sign it after receiving the full cash amount.</small></span>
                            </div>

                            <form method="POST" action="{{ route('rider.refund-cash.deliver', $payout) }}" enctype="multipart/form-data" class="cash-rider-form">
                                @csrf
                                <label for="refund-cash-delivered-{{ $payout->id }}">Cash delivered (₱)</label>
                                <small class="refund-cash-field-help">Expected amount: ₱{{ number_format((int) $payout->amount_minor / 100, 2) }}.</small>
                                <input id="refund-cash-delivered-{{ $payout->id }}" type="number" name="actual_amount" min="0.01" step="0.01" inputmode="decimal" placeholder="{{ $expectedAmount }}" aria-invalid="{{ $errors->has('actual_amount') ? 'true' : 'false' }}" required>
                                @error('actual_amount')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror

                                <label for="refund-cash-recipient-{{ $payout->id }}">Received by</label>
                                <input id="refund-cash-recipient-{{ $payout->id }}" type="text" name="recipient_name" maxlength="160" autocomplete="name" aria-invalid="{{ $errors->has('recipient_name') ? 'true' : 'false' }}" required>
                                @error('recipient_name')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror

                                <label for="refund-cash-ack-file-{{ $payout->id }}">Upload signed Buyer acknowledgment</label>
                                <input id="refund-cash-ack-file-{{ $payout->id }}" type="file" name="acknowledgment" accept="image/jpeg,image/png,image/webp,application/pdf" aria-invalid="{{ $errors->has('acknowledgment') ? 'true' : 'false' }}" required>
                                <small class="refund-cash-field-help">Upload a clear photo or scan of the Logistics-issued signed form. JPG, PNG, WebP, or PDF up to 5 MB; stored privately.</small>
                                @error('acknowledgment')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror
                                <button class="button button-primary" type="submit">Record Buyer handoff</button>
                            </form>

                            <details class="cash-rider-failure">
                                <summary>Unable to deliver?</summary>
                                <form method="POST" action="{{ route('rider.refund-cash.fail', $payout) }}" class="cash-rider-form">
                                    @csrf
                                    <label for="refund-cash-failure-{{ $payout->id }}">Reason for failed attempt</label>
                                    <textarea id="refund-cash-failure-{{ $payout->id }}" name="failure_reason" minlength="5" maxlength="1000" aria-invalid="{{ $errors->has('failure_reason') ? 'true' : 'false' }}" required></textarea>
                                    @error('failure_reason')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror
                                    <button class="button button-secondary" type="submit">Record failed attempt</button>
                                    <small class="refund-cash-field-help">Return the full amount to Logistics before it can be reassigned.</small>
                                </form>
                            </details>
                        @else
                            <div class="delivery-action-note is-warning" role="status">
                                <i data-lucide="triangle-alert" aria-hidden="true"></i>
                                <span><strong>Logistics acknowledgment form missing</strong><small>Contact Logistics and ask them to prepare and hand you the numbered form before completing this delivery attempt.</small></span>
                            </div>
                        @endif
                    @elseif($payout->status === 'cash_return_pending')
                        <div class="refund-cash-action-heading">
                            <span class="eyebrow">Next step</span>
                            <h3>Return cash to Logistics</h3>
                            <p>Hand back the full amount. Logistics must confirm receipt before another Rider can be assigned.</p>
                        </div>
                        <form method="POST" action="{{ route('rider.refund-cash.return-cash', $payout) }}" class="cash-rider-form">
                            @csrf
                            <label for="refund-cash-returned-{{ $payout->id }}">Cash returned (₱)</label>
                            <small class="refund-cash-field-help">Expected amount: ₱{{ number_format((int) $payout->amount_minor / 100, 2) }}.</small>
                            <input id="refund-cash-returned-{{ $payout->id }}" type="number" name="actual_amount" min="0.01" step="0.01" inputmode="decimal" placeholder="{{ $expectedAmount }}" aria-invalid="{{ $errors->has('actual_amount') ? 'true' : 'false' }}" required>
                            @error('actual_amount')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror
                            <label for="refund-cash-return-ref-{{ $payout->id }}">Cash handoff reference</label>
                            <input id="refund-cash-return-ref-{{ $payout->id }}" type="text" name="reference" maxlength="150" aria-invalid="{{ $errors->has('reference') ? 'true' : 'false' }}" required>
                            @error('reference')<small class="refund-cash-field-error" role="alert">{{ $message }}</small>@enderror
                            <button class="button button-primary" type="submit">Record cash return</button>
                        </form>
                    @else
                        <div class="refund-cash-action-heading">
                            <span class="eyebrow">Assignment status</span>
                            <h3>{{ $statusLabel }}</h3>
                            <p>No Rider action is available for this status. Contact your Logistics provider if you need help.</p>
                        </div>
                    @endif
                </aside>
            </div>

        </article>
    @empty
        <section class="panel refund-cash-empty">
            <div class="panel-body">
                <div class="empty-state">
                    <i data-lucide="hand-coins"></i>
                    <h2>No refund cash assigned</h2>
                    <p>Refund delivery tasks will appear here after Finance and Logistics authorize and assign one to you.</p>
                </div>
            </div>
        </section>
    @endforelse
</div>

@endsection
