<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $case->return_no }} · Return case</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/css/checkout.css', 'resources/css/buyer-return.css'])
</head>
<body class="bh buyer-return-page">
@include('buyer.partials.buyer-header', ['headerClass' => 'checkout-header', 'headerSearchInputId' => 'return-search-input', 'headerSearchAction' => route('products.index')])
<main class="buyer-return-shell">
    <a class="checkout-back" href="{{ route('buyer.profile') }}#purchases"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>My Purchases</a>
    @if (session('success'))<p class="buyer-return-alert is-success" role="status">{{ session('success') }}</p>@endif
    @if ($errors->any())<div class="buyer-return-alert is-error" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="buyer-return-heading"><span class="section-kicker">Return case</span><div class="buyer-return-title-row"><h1>{{ $case->return_no }}</h1><span class="buyer-return-status">{{ Str::headline($case->status) }}</span></div><p>Order {{ $case->order?->order_no ?? '—' }} · {{ $case->store?->name ?? 'Seller' }} · {{ Str::headline($case->request_type) }}</p></header>

    <section class="buyer-return-card"><div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><div><h2>Requested items</h2><p>Requested refund: <strong>₱{{ number_format($case->requested_amount_minor / 100, 2) }}</strong></p></div></div>
        @foreach ($case->items as $item)<article class="buyer-return-item"><span class="buyer-return-image-fallback material-symbols-outlined" aria-hidden="true">shopping_bag</span><div class="buyer-return-item-copy"><strong>{{ $item->orderItem?->product_name ?? 'Purchased item' }}</strong><span>{{ $item->orderItem?->variant_name ?? 'Standard' }} · Qty {{ $item->quantity }}</span><small>Case value ₱{{ number_format($item->refund_amount_minor / 100, 2) }}</small></div></article>@endforeach
        <dl class="buyer-return-meta"><div><dt>Reason</dt><dd>{{ Str::headline($case->reason_code) }}</dd></div><div><dt>Buyer note</dt><dd>{{ $case->buyer_note ?: 'No additional note.' }}</dd></div><div><dt>Seller response</dt><dd>{{ $case->seller_response ?: 'Waiting for the Seller to respond.' }}</dd></div><div><dt>Resolution</dt><dd>{{ $case->resolution ?: 'The case is under review.' }}</dd></div></dl>
    </section>

    @if ($case->returnShipment)
        <section class="buyer-return-card"><div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">local_shipping</span><div><h2>Return shipment tracking</h2><p>Waybill {{ $case->returnShipment->waybill?->waybill_no ?? 'Preparing' }} · {{ Str::headline($case->returnShipment->status) }}</p></div></div>
            <ol class="buyer-return-timeline">@foreach ($case->returnShipment->events as $event)<li><strong>{{ Str::headline(str_replace(['shipment_', 'parcel_'], '', $event->event_type)) }}</strong><small>{{ $event->occurred_at?->format('M j, Y · g:i A') ?? 'Time unavailable' }}</small>@if($event->notes)<p>{{ $event->notes }}</p>@endif</li>@endforeach</ol>
            @if ($case->request_type === 'return_refund' && data_get($case->returnShipment->return_shipping_quote, 'quote_kind') === 'return_shipping_v1')
                @php
                    $refund = $case->refunds->sortByDesc('id')->first();
                @endphp
                <p class="buyer-return-policy">Return delivery fee: ₱{{ number_format((int) $case->returnShipment->shipping_fee_minor / 100, 2) }} · covered by {{ ucfirst($refund?->financially_responsible_party ?? 'the responsible party') }}. It is not deducted from your approved refund.</p>
            @endif
            @if ($case->return_delivery_method === 'buyer_dropoff' && $case->status === 'return_dropoff_pending')
                <section class="buyer-return-dropoff" aria-labelledby="buyer-return-dropoff-title">
                    <h3 id="buyer-return-dropoff-title">Drop off your return at Logistics</h3>
                    @if (is_array($dropoffCenter) && is_array($dropoffCenter['address'] ?? null))
                        <p><strong>{{ $dropoffCenter['name'] ?? 'Assigned Logistics Sorting Center' }}</strong><br>{{ $dropoffCenter['address']['street'] ?? '' }} {{ $dropoffCenter['address']['house_number'] ?? '' }} · {{ $dropoffCenter['address']['barangay'] ?? '' }}, {{ $dropoffCenter['address']['city_municipality'] ?? '' }}, {{ $dropoffCenter['address']['province'] ?? '' }}</p>
                    @else
                        <p>The assigned Logistics center is temporarily unavailable. Contact Bearly Support before handing over the parcel.</p>
                    @endif
                    <p>Bring the sealed parcel and present this return waybill to Logistics:</p>
                    <code>{{ $case->returnShipment->waybill?->barcode_value ?: $case->returnShipment->waybill?->waybill_no }}</code>
                    @if ($case->dropoff_due_at)
                        <p>Drop-off deadline: <strong>{{ $case->dropoff_due_at->format('M j, Y · g:i A') }}</strong></p>
                    @endif
                    @if ($case->dropoff_due_at && now()->greaterThan($case->dropoff_due_at))
                        <form method="POST" action="{{ route('buyer.returns.switch-to-pickup', $case->id) }}">
                            @csrf
                            <button class="buyer-return-primary" type="submit">Switch to Rider pickup</button>
                        </form>
                    @endif
                </section>
            @elseif ($case->return_delivery_method === 'rider_pickup' && $case->status === 'return_pickup_requested')
                <p class="buyer-return-policy">Logistics is arranging Rider pickup from your order address. The Seller or Platform covers the return delivery fee; it will not be deducted from your refund.</p>
            @endif
        </section>
    @endif

    <section class="buyer-return-card"><div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">photo_library</span><div><h2>Case evidence</h2><p>Private files shared only with the case participants.</p></div></div>
        <ul class="buyer-return-evidence">@forelse ($case->evidence as $evidence)<li><span>{{ $evidence->original_name }}</span><small>{{ Str::headline($evidence->type) }} · {{ number_format(($evidence->size_bytes ?? 0) / 1024, 0) }} KB</small><a href="{{ route('buyer.returns.evidence.download', $evidence->id) }}">Download</a></li>@empty<li>No evidence uploaded.</li>@endforelse</ul>
    </section>

    @if ($case->status === 'awaiting_evidence' && in_array($evidenceRequestedFrom, ['buyer', 'both'], true) && ! $buyerEvidenceSubmitted)
        <section class="buyer-return-card"><div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">upload_file</span><div><h2>Additional evidence requested</h2><p>{{ $case->resolution ?: 'Please add the information requested by Admin.' }}</p></div></div>
            <form method="post" action="{{ route('buyer.returns.evidence.store', $case->id) }}" enctype="multipart/form-data" class="buyer-return-fields">@csrf
                <label for="additional-note">Additional note<textarea id="additional-note" name="note" rows="3" maxlength="1000"></textarea></label>
                <label class="buyer-return-upload" for="additional-evidence"><span class="material-symbols-outlined" aria-hidden="true">add_a_photo</span><span><strong>Upload more photos</strong><small>JPG, PNG, or WebP · up to 5 photos · 10 MB each (50 MB total max)</small></span><input id="additional-evidence" type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple></label>
                <button class="buyer-return-primary" type="submit">Send additional evidence</button>
            </form>
        </section>
    @endif
    @if ($case->status === 'awaiting_evidence' && in_array($evidenceRequestedFrom, ['buyer', 'both'], true) && $buyerEvidenceSubmitted)
        <p class="buyer-return-info"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span><span>Your additional evidence was received. Admin review resumes after all requested parties respond.</span></p>
    @endif

    <section class="buyer-return-card"><div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">timeline</span><div><h2>Case activity</h2><p>Recorded status changes and notes.</p></div></div>
        <ol class="buyer-return-timeline">@foreach ($case->events as $event)<li><strong>{{ Str::headline($event->event_type) }}</strong><small>{{ $event->created_at?->format('M j, Y · g:i A') ?? 'Time unavailable' }} · {{ $event->actor?->name ?? 'Bearly' }}</small>@if($event->note)<p>{{ $event->note }}</p>@endif</li>@endforeach</ol>
    </section>

    @if ($case->refunds->isNotEmpty())
        @php
            $refund = $case->refunds->sortByDesc('id')->first();
            $buyerRefundReceiptIssue = $case->events->where('event_type', 'buyer_refund_receipt_not_received')->last();
            $isCashCustodyRefund = $refund->method === 'cash' && $case->cashPayout !== null;
        @endphp
        <section class="buyer-return-card buyer-refund-transaction" aria-labelledby="buyer-refund-transaction-heading">
            <div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><div><h2 id="buyer-refund-transaction-heading">Refund transaction</h2><p>{{ $refund->status === 'processing' ? 'Admin recorded this external payout as sent. Please confirm whether the full amount reached you.' : ($refund->status === 'completed' && in_array($refund->method, ['gcash', 'bank_transfer', 'other'], true) ? 'You confirmed receipt of the full external payout; the case is resolved.' : ($refund->status === 'completed' ? 'Finance completed the cash refund after Logistics verified the Buyer acknowledgment.' : 'The approved refund has not been recorded as paid yet.')) }}</p></div></div>
            <dl class="buyer-return-meta">
                <div><dt>Refund number</dt><dd>{{ $refund->refund_no }}</dd></div>
                <div><dt>{{ in_array($refund->status, ['processing', 'completed'], true) ? 'Payout amount recorded' : 'Approved amount' }}</dt><dd>₱{{ number_format((int) ($refund->payout_amount_minor ?? $refund->amount_minor) / 100, 2) }}</dd></div>
                <div><dt>Status</dt><dd>{{ $refund->status === 'processing' ? 'Awaiting your receipt confirmation' : Str::headline($refund->status) }}</dd></div>
                @if (in_array($refund->status, ['processing', 'completed'], true))
                    <div><dt>Payment method</dt><dd>{{ Str::headline(str_replace('_', ' ', $refund->method)) }}</dd></div>
                    @unless ($isCashCustodyRefund)
                        <div><dt>Transaction / receipt reference</dt><dd>{{ $refund->provider_reference ?: 'Not recorded' }}</dd></div>
                    @endunless
                    <div><dt>Date paid</dt><dd>{{ $refund->external_paid_on?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                @endif
            </dl>
            @if (! $isCashCustodyRefund && $refund->status === 'completed' && $refund->payout_receipt_path)
                <a class="buyer-return-primary buyer-refund-receipt-link" href="{{ route('buyer.returns.refund-receipt', $refund->id) }}"><span class="material-symbols-outlined" aria-hidden="true">download</span>Download payout receipt</a>
            @elseif (! $isCashCustodyRefund && $refund->status === 'processing' && $refund->payout_receipt_path)
                <a class="buyer-return-primary buyer-refund-receipt-link" href="{{ route('buyer.returns.refund-receipt', $refund->id) }}"><span class="material-symbols-outlined" aria-hidden="true">download</span>Review payout receipt</a>
            @endif
            @if ($refund->status === 'processing' && $case->status === 'refund_pending' && in_array($refund->method, ['gcash', 'bank_transfer', 'other'], true))
                <div class="buyer-refund-confirmation" aria-labelledby="buyer-refund-confirmation-heading">
                    <h3 id="buyer-refund-confirmation-heading">Did you receive the full refund?</h3>
                    <p>Confirm only if the full ₱{{ number_format((int) $refund->payout_amount_minor / 100, 2) }} has reached your account. Admin’s record and receipt are not your confirmation.</p>
                    <form method="POST" action="{{ route('buyer.returns.refund-receipt.confirm', $case->id) }}" class="buyer-refund-confirm-form">
                        @csrf
                        <label><input type="checkbox" name="buyer_received_confirmed" value="1" required> I confirm I received the full refund amount shown above.</label>
                        <button class="buyer-return-primary" type="submit">Confirm refund received</button>
                    </form>
                    @if ($buyerRefundReceiptIssue)
                        <p class="buyer-refund-reported" role="status">You reported that the payout was not received on {{ $buyerRefundReceiptIssue->created_at?->format('M j, Y · g:i A') }}. Admin was notified; the case remains open. You can still confirm above if the full amount reaches you.</p>
                    @else
                        <form method="POST" action="{{ route('buyer.returns.refund-receipt.report-not-received', $case->id) }}" class="buyer-refund-report-form">
                            @csrf
                            <label for="buyer-refund-not-received-note">Haven’t received it or something is wrong?</label>
                            <textarea id="buyer-refund-not-received-note" name="note" rows="3" minlength="5" maxlength="1000" required placeholder="Tell Admin what happened so they can follow up."></textarea>
                            <button class="buyer-refund-report-button" type="submit">Report a payout problem</button>
                        </form>
                    @endif
                </div>
            @endif
            <p class="buyer-return-info"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>Admin records external transfers; Bearly does not move funds. A payout marked “processing” stays open until you confirm receipt.</span></p>
        </section>
    @endif
    @if ($case->cashPayout)
        @php
            $cashRefundStatusLabels = [
                'awaiting_logistics_receipt' => 'Logistics is confirming receipt of the refund cash',
                'awaiting_rider_assignment' => 'Logistics is arranging Rider delivery',
                'assigned_to_rider' => 'A Rider is assigned; cash handoff is pending',
                'cash_with_rider' => 'The Rider has received the cash',
                'out_for_delivery' => 'The Rider is out for delivery',
                'cash_return_pending' => 'Delivery was unsuccessful; cash return to Logistics is pending',
                'logistics_return_verification' => 'Logistics is verifying the returned cash',
                'delivered_pending_verification' => 'The Rider reported delivery; Logistics verification is pending',
                'awaiting_finance_completion' => 'Logistics verified delivery; Finance completion is pending',
                'completed' => 'Finance completed the cash refund',
            ];
            $cashRefundEventLabels = [
                'cash_issued_to_logistics' => 'Finance handed the refund cash to Logistics',
                'cash_received_by_logistics' => 'Logistics received the refund cash',
                'cash_assigned_to_rider' => 'A Rider was assigned for delivery',
                'refund_cash_acknowledgment_reference_generated' => 'Logistics prepared the acknowledgment form',
                'cash_received_by_rider' => 'The Rider received the cash handoff from Logistics',
                'refund_cash_delivery_started' => 'The Rider started delivery',
                'refund_cash_delivery_failed' => 'The Rider could not complete the delivery attempt',
                'cash_returned_to_logistics' => 'The Rider returned the cash to Logistics',
                'cash_return_received_by_logistics' => 'Logistics confirmed receipt of the returned cash',
                'refund_cash_delivered_to_buyer' => 'The Rider reported the refund delivered',
                'buyer_acknowledgment_verified_by_logistics' => 'Logistics verified the signed acknowledgment',
                'cash_refund_completed_by_finance' => 'Finance completed the cash refund',
            ];
            $cashRefundCurrentStatus = $cashRefundStatusLabels[$case->cashPayout->status]
                ?? Str::headline($case->cashPayout->status);
        @endphp
        <section class="buyer-return-card buyer-refund-cash-tracking" aria-labelledby="buyer-cash-refund-tracking-heading">
            <div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span><div><h2 id="buyer-cash-refund-tracking-heading">Cash refund delivery tracking</h2><p>Status history for your COD cash refund. This is not live Rider location.</p></div></div>
            <p class="buyer-refund-cash-current" role="status"><span>Current status</span><strong>{{ $cashRefundCurrentStatus }}</strong></p>
            <p class="buyer-refund-cash-note">A Rider delivery report remains pending until Logistics verifies the acknowledgment and Finance completes the refund.</p>
            <ol class="buyer-return-timeline" aria-label="Cash refund status history">
                @forelse ($case->cashPayout->events as $cashEvent)
                    @continue(! isset($cashRefundEventLabels[$cashEvent->event_type]))
                    <li>
                        <strong>{{ $cashRefundEventLabels[$cashEvent->event_type] }}</strong>
                        <small>{{ $cashEvent->occurred_at?->format('M j, Y · g:i A') ?? 'Time unavailable' }}</small>
                    </li>
                @empty
                    <li><strong>Cash refund tracking will appear here when Logistics begins processing it.</strong></li>
                @endforelse
            </ol>
        </section>
    @endif
</main>
</body>
</html>
