@extends('layouts.seller')

@section('title', $case['id'].' · Return Case')
@section('page-title', 'Return or refund request')
@section('content')
@php
    $status = $case['status_raw'];
    $canRespond = $status === 'awaiting_seller';
    $sellerEvidenceRequested = $status === 'awaiting_evidence'
        && in_array($case['evidence_requested_from'], ['seller', 'both'], true)
        && ! $case['seller_evidence_submitted'];
    $canInspect = $status === 'awaiting_inspection' && $case['return_status'] === 'delivered';
    $hasSellerAction = $canRespond || $sellerEvidenceRequested || $canInspect || $status === 'awaiting_inspection';
    $isPhysicalReturn = $case['request'] === 'Return & Refund';
    $showFinancialImpact = $case['commission_effect'] !== 'No finalized financial adjustment'
        || $case['earnings_effect'] !== 'No finalized financial adjustment';

    [$stageTitle, $stageDescription, $stageLabel, $stageValue, $stageClass, $stageIcon] = match (true) {
        $canRespond => [
            'Your reply is needed',
            'Reply with a short message and at least one photo or file.',
            'Reply by',
            $case['deadline'],
            '',
            'message-square-text',
        ],
        $sellerEvidenceRequested => [
            'More information requested',
            'Bearly needs a note or file from you.',
            'Requested from',
            $case['evidence_requested_from'] === 'both' ? 'You and the Buyer' : 'You',
            '',
            'file-plus-2',
        ],
        $status === 'awaiting_evidence' && $case['seller_evidence_submitted'] => [
            'Your files were received',
            'Waiting for the Buyer to reply.',
            'Status',
            'Waiting for Buyer',
            'is-review',
            'hourglass',
        ],
        $status === 'awaiting_evidence' => [
            'Waiting for the Buyer',
            'No action is needed from you right now.',
            'Status',
            'Waiting for Buyer',
            'is-review',
            'hourglass',
        ],
        $status === 'under_review' => [
            'Bearly is reviewing the request',
            'No action is needed from you right now.',
            'Status',
            'In review',
            'is-review',
            'scan-search',
        ],
        in_array($status, ['return_pickup_requested', 'return_in_transit', 'awaiting_inspection'], true) => [
            $canInspect
                ? 'Check the returned items'
                : ($status === 'return_pickup_requested' ? 'Pickup is being arranged' : 'Return delivery in progress'),
            $canInspect
                ? 'Check each item before Bearly processes the refund.'
                : 'The Logistics provider assigned to the original shipment is bringing the items back to you.',
            'Delivery status',
            $case['return_status'] ? ucwords(str_replace('_', ' ', $case['return_status'])) : 'Being arranged',
            'is-shipping',
            $canInspect ? 'clipboard-check' : 'truck',
        ],
        $status === 'refund_pending' => [
            'Refund is being processed',
            'Bearly will mark it complete after confirming payment to the Buyer.',
            'Status',
            'Processing',
            'is-review',
            'wallet-cards',
        ],
        $status === 'resolved' => [
            'Request complete',
            $case['resolution'],
            'Status',
            'Complete',
            'is-complete',
            'badge-check',
        ],
        $status === 'rejected' => [
            $isPhysicalReturn ? 'Return request declined' : 'Refund request declined',
            $case['resolution'],
            'Status',
            'Closed',
            'is-review',
            'circle-x',
        ],
        default => [
            $case['status'],
            $case['resolution'],
            'Status',
            $case['status'],
            'is-review',
            'clipboard-list',
        ],
    };

@endphp

<div class="returns-refined return-case-page">
    <header class="page-heading case-page-heading">
        <div>
            <div class="case-title-row">
                <h2>{{ $case['id'] }}</h2>
            </div>
            <p>Order {{ $case['order'] }} · Seller order {{ $case['seller_order'] }}</p>
        </div>
        <a class="seller-secondary-button" href="{{ route('seller.orders.returns') }}">
            <i data-lucide="arrow-left" aria-hidden="true"></i>Back to cases
        </a>
    </header>

    @if (session('success'))
        <p class="seller-alert seller-alert-success" role="status">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <div class="seller-alert seller-alert-error" role="alert" aria-labelledby="return-case-errors-title">
            <strong id="return-case-errors-title">Check the information below.</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($showFinancialImpact)
        <div class="case-financial-summary" aria-label="Current case financial impact">
            <p><span>Platform fee change</span><strong>{{ $case['commission_effect'] }}</strong></p>
            <p><span>Your earnings change</span><strong>{{ $case['earnings_effect'] }}</strong></p>
        </div>
    @endif

    <section class="case-deadline-banner {{ $stageClass }}" aria-label="Current request status">
        <span><i data-lucide="{{ $stageIcon }}" aria-hidden="true"></i></span>
        <div><strong>{{ $stageTitle }}</strong><p>{{ $stageDescription }}</p></div>
        <div><small>{{ $stageLabel }}</small><strong>{{ $stageValue }}</strong></div>
    </section>

    <div class="case-details-layout {{ $hasSellerAction ? 'has-action' : '' }}">
        <main class="case-main-column">
            <section class="case-panel case-main-panel">
                <header class="case-panel-heading">
                    <div><i data-lucide="package-search" aria-hidden="true"></i><span><strong>Request details</strong><small>{{ $case['customer'] }} · {{ str_replace('&', 'and', $case['request']) }} · {{ $case['reason'] }}</small></span></div>
                    <span class="case-claim-amount"><small>Total refund requested</small><strong>{{ $case['amount'] }}</strong></span>
                </header>
                <div class="case-main-content">
                    <div class="case-buyer-statement"><span>Buyer’s note</span><p>{{ $case['buyer_note'] }}</p></div>

                    <section class="case-content-section" aria-labelledby="case-items-heading">
                        <h3 id="case-items-heading">Items included in the request</h3>
                        <ul class="case-item-list">
                            @forelse ($case['items'] as $item)
                                <li>
                                    <span class="case-item-thumb" aria-hidden="true">
                                        @if (!empty($item['image']))
                                            <img src="{{ $item['image'] }}" alt="" loading="lazy" decoding="async">
                                        @else
                                            <i data-lucide="package" aria-hidden="true"></i>
                                        @endif
                                    </span>
                                    <span class="case-item-copy"><strong>{{ $item['name'] }}</strong><small>{{ $item['variant'] }} · Item code {{ $item['sku'] }}</small></span>
                                    <span class="case-item-metrics">
                                        <span><small>Quantity requested</small><strong>{{ $item['quantity'] }} {{ $item['quantity'] === 1 ? 'item' : 'items' }}</strong></span>
                                        <span><small>Refund for this item</small><strong>{{ $item['amount'] }}</strong></span>
                                    </span>
                                </li>
                            @empty
                                <li class="case-item-empty">No item details are available for this case.</li>
                            @endforelse
                        </ul>
                    </section>

                    <section class="case-content-section" aria-labelledby="case-evidence-heading">
                        <h3 id="case-evidence-heading">Photos and files <span>{{ $case['evidence'] }}</span></h3>
                        @if (count($case['evidence_files']))
                            <ul class="case-evidence-list">
                                @foreach ($case['evidence_files'] as $evidence)
                                    <li>
                                        @if ($evidence['is_image'])
                                            <button class="case-evidence-preview" type="button" data-case-image-preview data-preview-url="{{ $evidence['view_url'] }}" data-preview-name="{{ $evidence['name'] }}" aria-label="Preview {{ $evidence['name'] }}">
                                                <img src="{{ $evidence['view_url'] }}" alt="" loading="lazy" decoding="async">
                                            </button>
                                        @else
                                            <i data-lucide="file-text" aria-hidden="true"></i>
                                        @endif
                                        <span><strong>{{ $evidence['name'] }}</strong><small>{{ ucwords(str_replace('_', ' ', $evidence['type'])) }} · {{ number_format($evidence['size'] / 1024, 1) }} KB</small></span>
                                        @if ($evidence['is_image'])
                                            <button class="case-evidence-open" type="button" data-case-image-preview data-preview-url="{{ $evidence['view_url'] }}" data-preview-name="{{ $evidence['name'] }}" aria-label="Preview {{ $evidence['name'] }}">Preview</button>
                                        @else
                                            <a href="{{ $evidence['view_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="View file {{ $evidence['name'] }}">View file</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="case-empty-note">No photos or files have been added yet.</p>
                        @endif
                        <dialog class="case-image-preview-dialog" data-case-image-dialog aria-labelledby="case-image-preview-title">
                            <header>
                                <h3 id="case-image-preview-title" data-case-image-title>Image preview</h3>
                                <button type="button" data-case-image-close aria-label="Close image preview"><i data-lucide="x" aria-hidden="true"></i></button>
                            </header>
                            <div><img data-case-image-full alt=""></div>
                        </dialog>
                    </section>

                    @if ($status !== 'awaiting_seller' && $case['seller_response'] !== 'No Seller response submitted yet.')
                        <section class="case-content-section">
                            <h3>Your reply <span class="case-locked-label"><i data-lucide="lock" aria-hidden="true"></i>Sent</span></h3>
                            <p class="case-saved-response">{{ $case['seller_response'] }}</p>
                        </section>
                    @endif

                    @if ($isPhysicalReturn && $case['tracking'] !== 'Awaiting Admin approval')
                        <section class="case-content-section" aria-labelledby="case-shipment-heading">
                            <h3 id="case-shipment-heading">Return delivery</h3>
                            <p class="case-shipment-summary"><span><strong>{{ $case['tracking'] }}</strong><small>Tracking number · Original shipment’s Logistics provider</small></span><span><strong>{{ $case['return_status'] ? ucfirst(str_replace('_', ' ', $case['return_status'])) : 'Not yet assigned' }}</strong><small>Delivery status · No return fee</small></span></p>
                            @if (count($case['shipment_events']))
                                <ol class="case-shipment-events">
                                    @foreach (array_reverse($case['shipment_events']) as $event)
                                        <li><span><strong>{{ $event['label'] }}</strong>@if ($event['note'])<small>{{ $event['note'] }}</small>@endif</span><time>{{ $event['at'] }}</time></li>
                                    @endforeach
                                </ol>
                            @endif
                        </section>
                    @endif

                    @if (in_array($status, ['refund_pending', 'resolved', 'rejected'], true)
                        && $case['resolution'] !== 'No final resolution has been recorded.')
                        <section class="case-content-section">
                            <h3>Decision</h3>
                            <p class="case-saved-response">{{ $case['resolution'] }}</p>
                        </section>
                    @endif

                    @if ($case['refund'])
                        <section class="case-content-section">
                            <h3>Refund and financial responsibility</h3>
                            <p class="case-refund-summary">{{ $case['refund']['status'] === 'completed' ? ($case['refund']['payout_amount'] ?? $case['refund']['amount']).' paid' : $case['refund']['amount'].' approved' }} · {{ ucwords(str_replace('_', ' ', $case['refund']['status'])) }}</p>
                            <dl class="case-refund-details">
                                <div><dt>Financially responsible</dt><dd>{{ $case['refund']['financially_responsible_party'] ? ucfirst($case['refund']['financially_responsible_party']) : 'Not recorded' }}</dd></div>
                                @if ($case['refund']['financial_responsibility_reason'])<div><dt>Admin’s reason</dt><dd>{{ $case['refund']['financial_responsibility_reason'] }}</dd></div>@endif
                                @if ($case['refund']['status'] === 'completed')
                                    <div><dt>Paid externally by Admin</dt><dd>{{ ucfirst(str_replace('_', ' ', $case['refund']['method'])) }} · {{ $case['refund']['paid_on'] ?? 'Date unavailable' }}</dd></div>
                                    <div><dt>Buyer payout reference</dt><dd>{{ $case['refund']['reference'] ?: 'Not recorded' }}</dd></div>
                                @else
                                    <div><dt>Buyer payout</dt><dd>Not yet recorded as paid</dd></div>
                                @endif
                            </dl>
                            @if ($case['refund']['seller_recovery'])
                                <div class="case-recovery-details">
                                    <strong>Recoverable Seller balance</strong>
                                    <p>₱{{ number_format($case['refund']['seller_recovery']['amount_minor'] / 100, 2) }} assigned · ₱{{ number_format($case['refund']['seller_recovery']['recovered_amount_minor'] / 100, 2) }} recovered · ₱{{ number_format($case['refund']['seller_recovery']['outstanding_minor'] / 100, 2) }} outstanding.</p>
                                    <small>This is tracked separately and is not automatically deducted from your settlements.</small>
                                    @if (count($case['refund']['seller_recovery']['events']))
                                        <ul>
                                            @foreach ($case['refund']['seller_recovery']['events'] as $recoveryEvent)
                                                <li>{{ ucfirst(str_replace('_', ' ', $recoveryEvent['method'])) }} · ₱{{ number_format($recoveryEvent['amount_minor'] / 100, 2) }} · {{ $recoveryEvent['recovered_on'] }} · {{ $recoveryEvent['reference'] }}@if ($recoveryEvent['payout_no']) · {{ $recoveryEvent['payout_no'] }}@endif</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @elseif ($case['refund']['status'] === 'completed' && $case['refund']['financially_responsible_party'] === 'platform')
                                <p class="case-recovery-details">Platform responsibility recorded. No Seller recovery balance was created.</p>
                            @endif
                        </section>
                    @endif

                    @if (count($case['events']))
                        <section class="case-content-section case-activity-section" aria-labelledby="case-activity-heading">
                            <h3 id="case-activity-heading">Updates</h3>
                            <ol class="return-timeline case-timeline">
                                @foreach (array_reverse($case['events']) as $event)
                                    <li><span aria-hidden="true"></span><div><strong>{{ $event['type'] }} · {{ $event['actor'] }}</strong><small>{{ $event['at'] }}@if ($event['note']) · {{ $event['note'] }}@endif</small></div></li>
                                @endforeach
                            </ol>
                        </section>
                    @endif
                </div>
            </section>
        </main>

        @if ($hasSellerAction)
            <aside class="case-side-column">
            @if ($canRespond)
                <section class="case-panel case-action-panel" aria-labelledby="seller-response-heading">
                    <header class="case-panel-heading"><div><i data-lucide="reply" aria-hidden="true"></i><span><strong id="seller-response-heading">Your reply</strong></span></div></header>
                    <form method="POST" action="{{ route('seller.orders.returns.respond', $case['database_id']) }}" enctype="multipart/form-data" class="case-response-form">
                        @csrf
                        <label class="case-response-field">
                            <span>Your reply <b>Required</b></span>
                            <textarea name="response" rows="5" minlength="5" maxlength="2000" required placeholder="Tell us how you handled the Buyer’s concern." data-case-response>{{ old('response') }}</textarea>
                            <small><span data-case-character-count>{{ strlen(old('response', '')) }}</span>/2000 characters</small>
                        </label>
                        <label class="case-upload">
                            <input type="file" name="evidence[]" multiple required accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/quicktime" data-case-evidence-input>
                            <i data-lucide="upload-cloud" aria-hidden="true"></i>
                            <span>
                                <strong>Add photos or files <b>Required</b></strong>
                                <small class="case-upload-help">Add 1–5 files that support your reply, such as item/packing photos or dispatch records. JPG, PNG, WebP, PDF, MP4, or MOV · max 10 MB each.</small>
                                <small data-case-evidence-name aria-live="polite"></small>
                            </span>
                        </label>
                        <button class="seller-primary-button" type="submit"><i data-lucide="send" aria-hidden="true"></i>Send reply</button>
                        <p class="case-form-note">Bearly reviews the request and decides whether a refund is approved.</p>
                    </form>
                </section>
            @elseif ($sellerEvidenceRequested)
                <section class="case-panel case-action-panel" aria-labelledby="seller-evidence-heading">
                    <header class="case-panel-heading"><div><i data-lucide="file-plus-2" aria-hidden="true"></i><span><strong id="seller-evidence-heading">Send the requested information</strong></span></div></header>
                    <form method="POST" action="{{ route('seller.orders.returns.evidence.store', $case['database_id']) }}" enctype="multipart/form-data" class="case-response-form">
                        @csrf
                        <label class="case-response-field">
                            <span>Message <small>Optional if you attach a file</small></span>
                            <textarea name="note" rows="4" maxlength="1000" placeholder="Add a short message for Bearly.">{{ old('note') }}</textarea>
                        </label>
                        <label class="case-upload">
                            <input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/quicktime" data-case-evidence-input>
                            <i data-lucide="upload-cloud" aria-hidden="true"></i>
                            <span>
                                <strong>Add photos or files</strong>
                                <small class="case-upload-help">Send the files Bearly requested. JPG, PNG, WebP, PDF, MP4, or MOV · up to 5 files, 10 MB each.</small>
                                <small data-case-evidence-name aria-live="polite"></small>
                            </span>
                        </label>
                        <button class="seller-primary-button" type="submit"><i data-lucide="send" aria-hidden="true"></i>Send to Bearly</button>
                        <p class="case-form-note">Send a message, files, or both. Review continues after everyone replies.</p>
                    </form>
                </section>
            @elseif ($canInspect)
                <section class="case-panel case-action-panel" aria-labelledby="seller-inspection-heading">
                    <header class="case-panel-heading"><div><i data-lucide="clipboard-check" aria-hidden="true"></i><span><strong id="seller-inspection-heading">Check returned items</strong></span></div></header>
                    <form method="POST" action="{{ route('seller.orders.returns.inspect', $case['database_id']) }}" class="case-response-form case-inspection-form">
                        @csrf
                        @foreach ($case['items'] as $item)
                            <fieldset class="case-inspection-item">
                                <legend>{{ $item['name'] }} · {{ $item['variant'] }} · {{ $item['quantity'] }} {{ $item['quantity'] === 1 ? 'item' : 'items' }}</legend>
                                <label><span>Item condition</span>
                                    <select name="items[{{ $item['id'] }}][condition_code]" required>
                                        <option value="">Select condition</option>
                                        <option value="sellable">Can be sold</option>
                                        <option value="damaged">Damaged</option>
                                        <option value="opened">Opened</option>
                                        <option value="missing_parts">Missing parts</option>
                                    </select>
                                </label>
                                <label><span>What should happen next?</span>
                                    <select name="items[{{ $item['id'] }}][resolution]" required>
                                        <option value="">Select disposition</option>
                                        <option value="restock">Put back in stock</option>
                                        <option value="quarantine">Hold for review</option>
                                        <option value="dispose">Discard</option>
                                    </select>
                                </label>
                            </fieldset>
                        @endforeach
                        <p class="case-form-note">Only items in good condition can be put back in stock. Hold or discard damaged or incomplete items.</p>
                        <button class="seller-primary-button" type="submit"><i data-lucide="clipboard-check" aria-hidden="true"></i>Save item checks</button>
                    </form>
                </section>
            @elseif ($status === 'awaiting_inspection')
                <p class="case-help-inline"><i data-lucide="truck" aria-hidden="true"></i><span><strong>Waiting for the return</strong><small>You can check the items after the assigned Logistics provider delivers them.</small></span></p>
            @endif
            </aside>
        @endif
    </div>
</div>
@endsection
