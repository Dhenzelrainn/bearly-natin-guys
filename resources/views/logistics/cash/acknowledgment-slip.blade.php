@extends('logistics.layouts.app')

@section('title', 'Buyer refund acknowledgment '.$payout->acknowledgment_reference)
@section('page-title', 'Print acknowledgment slip')

@section('content')
<div class="refund-slip-workspace">
    <section class="refund-slip-toolbar" aria-label="Print instructions">
        <div>
            <span class="eyebrow">Logistics dispatch form</span>
            <h2>Print and give this slip to the assigned Rider</h2>
            <p>Use your printer, or choose “Save as PDF” in the print dialog. The Rider must bring the paper form to the Buyer and return the signed proof.</p>
        </div>
        <div class="refund-slip-toolbar-actions">
            <a class="button button-secondary" href="{{ route('logistics.cash.index') }}">Back to cash custody</a>
            <button class="button button-primary" type="button" data-print-refund-slip>
                <i data-lucide="printer" aria-hidden="true"></i>
                Print / Save as PDF
            </button>
        </div>
    </section>

    <article class="refund-ack-document" aria-label="Buyer cash refund acknowledgment form">
        <header class="refund-ack-document-header">
            <div class="refund-ack-brand">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly Marketplace">
                <div>
                    <span>BEARLY MARKETPLACE</span>
                    <strong>Cash refund acknowledgment</strong>
                </div>
            </div>
            <div class="refund-ack-reference">
                <span>ACKNOWLEDGMENT REFERENCE</span>
                <strong>{{ $payout->acknowledgment_reference }}</strong>
                <small>Delivery attempt {{ (int) $payout->delivery_attempt_count + 1 }}</small>
            </div>
        </header>

        <div class="refund-ack-accent" aria-hidden="true"></div>

        <p class="refund-ack-intro">Please review the details below. Sign only after you have counted and received the cash refund from the Rider.</p>

        <dl class="refund-ack-details">
            <div class="refund-ack-detail-amount">
                <dt>Cash refund amount</dt>
                <dd>₱{{ number_format((int) $payout->amount_minor / 100, 2) }}</dd>
            </div>
            <div>
                <dt>Buyer / recipient</dt>
                <dd>{{ $payout->refund?->order?->recipient_name ?: ($payout->refund?->order?->buyer?->name ?? 'Buyer') }}</dd>
            </div>
            <div>
                <dt>Refund ID</dt>
                <dd>{{ $payout->refund?->refund_no ?? 'Not available' }}</dd>
            </div>
            <div>
                <dt>Cash payout ID</dt>
                <dd>{{ $payout->payout_no }}</dd>
            </div>
            <div>
                <dt>Order ID</dt>
                <dd>{{ $payout->refund?->order?->order_no ?? 'Not available' }}</dd>
            </div>
            <div>
                <dt>Assigned Rider</dt>
                <dd>{{ $payout->riderProfile?->user?->name ?? 'Assigned Rider' }}</dd>
            </div>
            <div>
                <dt>Logistics provider</dt>
                <dd>{{ $payout->logisticsProfile?->display_name ?? 'Bearly Logistics' }}</dd>
            </div>
        </dl>

        <section class="refund-ack-confirmation" aria-labelledby="refund-ack-confirmation-title">
            <span class="refund-ack-section-label" id="refund-ack-confirmation-title">Buyer confirmation</span>
            <p>I confirm that I personally received the cash refund amount shown on this form in full.</p>
        </section>

        <section class="refund-ack-signatures" aria-label="Acknowledgment signatures">
            <div class="refund-ack-signature-block">
                <h3>Received by Buyer</h3>
                <div class="refund-ack-signature-line"><span>Printed name</span></div>
                <div class="refund-ack-signature-line"><span>Signature</span></div>
                <div class="refund-ack-signature-line"><span>Date and time received</span></div>
            </div>
            <div class="refund-ack-signature-block">
                <h3>Delivered by Rider</h3>
                <div class="refund-ack-signature-line"><span>Rider signature</span></div>
                <div class="refund-ack-signature-line"><span>Date and time delivered</span></div>
            </div>
        </section>

        <footer class="refund-ack-document-footer">
            <span>Keep this reference visible on the signed form.</span>
            <span>{{ $payout->acknowledgment_reference }}</span>
        </footer>
    </article>
</div>
@endsection
