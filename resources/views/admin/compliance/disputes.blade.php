@extends('layouts.admin')

@section('title', 'Complaints & Disputes')
@section('page-title', 'Manage Complaints & Disputes')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 05</span><h1>Manage complaints and disputes</h1><p>Review complaint details and evidence, then coordinate with Buyer, Seller, and Courier from one static resolution workspace.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-mock-action="Dispute queue refreshed."><i data-lucide="refresh-cw"></i> Refresh cases</button></div>
</section>

<section class="dispute-layout">
    <aside class="panel dispute-list-panel">
        <div class="panel-heading"><div><span class="eyebrow">Open cases</span><h2>Resolution queue</h2></div><span class="status-badge badge-warning"><span data-dispute-open-count>{{ count($disputes) }}</span> open</span></div>
        <label class="field-with-icon"><i data-lucide="search"></i><input type="search" placeholder="Search case ID or subject..." data-dispute-search></label>
        <div class="case-list">
            @foreach ($disputes as $index => $dispute)
                <button type="button" class="case-card {{ $index === 0 ? 'is-active' : '' }}" data-case-card data-case-id="{{ $dispute['id'] }}" data-case-search="{{ strtolower($dispute['id'].' '.$dispute['subject'].' '.$dispute['buyer'].' '.$dispute['seller']) }}">
                    <div class="case-card-top"><span>{{ $dispute['id'] }}</span><span class="priority-dot priority-{{ strtolower($dispute['priority']) }}">{{ $dispute['priority'] }}</span></div>
                    <strong>{{ $dispute['subject'] }}</strong><small>{{ $dispute['buyer'] }} vs {{ $dispute['seller'] }}</small>
                    <div class="case-card-bottom"><span>{{ $dispute['opened'] }}</span><span>{{ $dispute['status'] }}</span></div>
                </button>
            @endforeach
        </div>
    </aside>

    <div class="dispute-workspace">
        @php $active = $disputes[0]; @endphp
        <article class="panel dispute-detail-card">
            <div class="panel-heading">
                <div><span class="eyebrow" data-dispute-id>{{ $active['id'] }}</span><h2 data-dispute-subject>{{ $active['subject'] }}</h2><p><span data-dispute-status>{{ $active['status'] }}</span> • <span data-dispute-opened>{{ $active['opened'] }}</span></p></div>
                <div class="row-actions"><span class="status-badge badge-danger" data-dispute-priority>{{ $active['priority'] }} priority</span><button class="button button-primary button-small" type="button" data-dispute-resolve><i data-lucide="circle-check"></i> Resolve case</button></div>
            </div>
            <div class="detail-grid dispute-summary-grid">
                <div><span>Buyer</span><strong data-dispute-buyer>{{ $active['buyer'] }}</strong></div><div><span>Seller</span><strong data-dispute-seller>{{ $active['seller'] }}</strong></div><div><span>Courier</span><strong data-dispute-courier>{{ $active['courier'] }}</strong></div><div><span>Order value</span><strong data-dispute-amount>{{ $active['amount'] }}</strong></div>
            </div>
            <div class="complaint-copy"><span class="section-label">Complaint summary</span><p data-dispute-summary>The buyer reported visible package damage and product defects after delivery. The seller requested additional evidence, while the courier submitted delivery proof. This mock workspace shows how the admin can compare evidence before deciding the outcome.</p></div>
        </article>

        <div class="dashboard-grid dashboard-grid-secondary dispute-subgrid">
            <article class="panel">
                <div class="panel-heading"><div><span class="eyebrow">Supporting evidence</span><h2>Case files</h2></div></div>
                <div class="evidence-grid" data-dispute-evidence>
                    @foreach ($evidence as $item)
                        <button type="button" class="evidence-card" data-mock-action="{{ $item['label'] }} preview opened."><span><i data-lucide="{{ $item['type'] === 'Image' ? 'image' : 'file-text' }}"></i></span><div><strong>{{ $item['label'] }}</strong><small>{{ $item['meta'] }}</small></div><i data-lucide="external-link"></i></button>
                    @endforeach
                </div>
            </article>
            <article class="panel">
                <div class="panel-heading"><div><span class="eyebrow">Case timeline</span><h2>Recent updates</h2></div></div>
                <div class="timeline-list" data-dispute-timeline>
                    @foreach ($timeline as $item)
                        <div class="timeline-row"><span>{{ $item['time'] }}</span><div><i></i><p>{{ $item['text'] }}</p></div></div>
                    @endforeach
                </div>
            </article>
        </div>

        <article class="panel coordination-panel">
            <div class="panel-heading"><div><span class="eyebrow">Multi-party coordination</span><h2>Contact participants</h2></div><span class="status-badge badge-info">Static messaging preview</span></div>
            <div class="coordination-grid">
                <div class="party-card">
                    <span class="party-icon"><i data-lucide="shopping-bag"></i></span>
                    <div><small>Buyer</small><strong data-party-buyer>{{ $active['buyer'] }}</strong></div>
                    <button type="button" class="button button-ghost button-small" data-party-message="buyer"><i data-lucide="message-circle"></i> Message</button>
                </div>
                <div class="party-card">
                    <span class="party-icon"><i data-lucide="store"></i></span>
                    <div><small>Seller</small><strong data-party-seller>{{ $active['seller'] }}</strong></div>
                    <button type="button" class="button button-ghost button-small" data-party-message="seller"><i data-lucide="message-circle"></i> Message</button>
                </div>
                <div class="party-card">
                    <span class="party-icon"><i data-lucide="bike"></i></span>
                    <div><small>Courier</small><strong data-party-courier>{{ $active['courier'] }}</strong></div>
                    <button type="button" class="button button-ghost button-small" data-party-message="courier"><i data-lucide="message-circle"></i> Message</button>
                </div>
            </div>
            <label class="form-field">
                <span>Internal resolution note</span>
                <textarea rows="4" placeholder="Document the admin decision or next coordination step..." data-dispute-note></textarea>
            </label>
            <div class="panel-footer-actions">
                <button class="button button-secondary" type="button" data-dispute-save-note>Save note</button>
                <button class="button button-primary" type="button" data-dispute-send-update><i data-lucide="send"></i> Send case update</button>
            </div>
        </article>
    </div>
</section>


<div class="modal-shell" data-modal="resolve-dispute" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card modal-card-medium">
        <div class="modal-heading">
            <div>
                <span class="eyebrow">Case resolution</span>
                <h2>Resolve <span data-resolve-case-id>case</span></h2>
            </div>
            <button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <label class="form-field">
                <span>Resolution outcome</span>
                <select class="select-field" data-resolution-outcome>
                    <option value="">Select outcome</option>
                    <option value="Buyer refunded">Buyer refunded</option>
                    <option value="Seller favored">Seller favored</option>
                    <option value="Partial refund">Partial refund</option>
                    <option value="Replacement arranged">Replacement arranged</option>
                    <option value="Courier investigation">Courier investigation</option>
                </select>
            </label>
            <label class="form-field">
                <span>Resolution note</span>
                <textarea rows="5" placeholder="Explain the final administrative decision..." data-resolution-note></textarea>
                <small data-resolution-error hidden>Please select an outcome and add a resolution note.</small>
            </label>
        </div>
        <div class="modal-footer">
            <button class="button button-secondary" type="button" data-close-modal>Cancel</button>
            <button class="button button-primary" type="button" data-confirm-resolution><i data-lucide="circle-check"></i> Confirm resolution</button>
        </div>
    </section>
</div>

<script type="application/json" id="dispute-preview-data">
{!! json_encode(collect($disputes)->mapWithKeys(function ($d) use ($evidence, $timeline) {
    $extra = [
        'DSP-1048' => [
            'summary' => 'The buyer reported visible package damage and product defects after delivery. The seller requested additional evidence, while the courier submitted delivery proof.',
            'evidence' => $evidence, 'timeline' => $timeline,
        ],
        'DSP-1047' => [
            'summary' => 'The buyer reported that an expected accessory was missing from the delivered package. The seller is being asked to verify the packed contents and provide supporting evidence.',
            'evidence' => [
                ['label'=>'Buyer photo','meta'=>'missing-accessory.jpg • 1.2 MB','type'=>'Image'],
                ['label'=>'Order invoice','meta'=>'invoice-1047.pdf • 251 KB','type'=>'Document'],
                ['label'=>'Seller packing record','meta'=>'packing-record.pdf • 386 KB','type'=>'Document'],
            ],
            'timeline' => [
                ['time'=>'3:42 PM','text'=>'Buyer reported a missing accessory after opening the package.'],
                ['time'=>'4:05 PM','text'=>'Admin requested the seller packing record.'],
                ['time'=>'4:31 PM','text'=>'Seller was notified and the case is awaiting a response.'],
            ],
        ],
        'DSP-1046' => [
            'summary' => 'The buyer reported that the delivery was marked completed before the parcel was actually received. The admin is coordinating with the courier and seller to verify the handoff.',
            'evidence' => [
                ['label'=>'Delivery status screenshot','meta'=>'delivery-status.jpg • 940 KB','type'=>'Image'],
                ['label'=>'Order invoice','meta'=>'invoice-1046.pdf • 233 KB','type'=>'Document'],
                ['label'=>'Courier delivery record','meta'=>'courier-log.pdf • 418 KB','type'=>'Document'],
            ],
            'timeline' => [
                ['time'=>'11:07 AM','text'=>'Buyer reported that the order was marked delivered too early.'],
                ['time'=>'11:24 AM','text'=>'Admin requested delivery confirmation from the courier.'],
                ['time'=>'12:02 PM','text'=>'Courier coordination started and the case remains open.'],
            ],
        ],
    ];
    return [$d['id'] => array_merge($d, $extra[$d['id']] ?? [
        'summary'=>'This dispute is currently under administrative review.',
        'evidence'=>$evidence, 'timeline'=>$timeline
    ])];
}), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>

@endsection
