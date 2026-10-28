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
        @php($refund = $case->refunds->sortByDesc('id')->first())
        <section class="buyer-return-card buyer-refund-transaction" aria-labelledby="buyer-refund-transaction-heading">
            <div class="buyer-return-section-heading"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><div><h2 id="buyer-refund-transaction-heading">Refund transaction</h2><p>{{ $refund->status === 'completed' ? 'Admin recorded the completed external payment.' : 'The approved refund has not been recorded as paid yet.' }}</p></div></div>
            <dl class="buyer-return-meta">
                <div><dt>Refund number</dt><dd>{{ $refund->refund_no }}</dd></div>
                <div><dt>{{ $refund->status === 'completed' ? 'Amount paid' : 'Approved amount' }}</dt><dd>₱{{ number_format((int) ($refund->payout_amount_minor ?? $refund->amount_minor) / 100, 2) }}</dd></div>
                <div><dt>Status</dt><dd>{{ $refund->status === 'completed' ? 'Paid externally' : Str::headline($refund->status) }}</dd></div>
                @if ($refund->status === 'completed')
                    <div><dt>Payment method</dt><dd>{{ Str::headline(str_replace('_', ' ', $refund->method)) }}</dd></div>
                    <div><dt>Transaction / receipt reference</dt><dd>{{ $refund->provider_reference ?: 'Not recorded' }}</dd></div>
                    <div><dt>Date paid</dt><dd>{{ $refund->external_paid_on?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                @endif
            </dl>
            @if ($refund->status === 'completed' && $refund->payout_receipt_path)
                <a class="buyer-return-primary buyer-refund-receipt-link" href="{{ route('buyer.returns.refund-receipt', $refund->id) }}"><span class="material-symbols-outlined" aria-hidden="true">download</span>Download payout receipt</a>
            @endif
            <p class="buyer-return-info"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>Admin sends the refund outside Bearly and records the completed transaction here. Bearly does not transfer funds.</span></p>
        </section>
    @endif
</main>
</body>
</html>
