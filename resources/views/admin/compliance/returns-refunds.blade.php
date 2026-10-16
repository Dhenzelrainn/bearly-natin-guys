@extends('layouts.admin')

@section('title', 'Returns & Refunds')
@section('page-title', 'Returns & Refunds')

@section('content')

<div class="returns-refunds-page">
@if (session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger" role="alert"><strong>Action could not be completed.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="page-hero">

    <div>
        <span class="eyebrow">Compliance & Disputes</span>
        <h1>Returns & refunds</h1>

        <p>
            Review Seller responses, decide claims, and record external payouts after the required return steps are complete.
        </p>
    </div>


    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="rotate-ccw"></i>
        </span>

        <div>
            <strong>{{ count($cases) }}</strong>
            <span>Return & refund cases</span>
        </div>
    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Case management</span>
            <h2>Return and refund cases</h2>
            <p>Review both parties’ information and take the next action allowed for each case.</p>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search" aria-hidden="true"></i>

                <input
                    type="search"
                    placeholder="Search case..."
                    aria-label="Search return and refund cases"
                    data-table-search="returns-table"
                >
            </label>


            <select
                class="select-field"
                aria-label="Filter by request type"
                data-table-filter="returns-table"
                data-filter-key="type"
            >
                <option value="">All request types</option>
                <option value="Return & Refund">Return & Refund</option>
                <option value="Partial Refund">Partial Refund</option>
                <option value="Refund Only">Refund Only</option>
            </select>


            <select
                class="select-field"
                aria-label="Filter by case status"
                data-table-filter="returns-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Under Review">Under Review</option>
                <option value="Awaiting Seller">Awaiting Seller</option>
                <option value="Awaiting Evidence">Awaiting Evidence</option>
                <option value="Return Pickup Requested">Return Pickup Requested</option>
                <option value="Return In Transit">Return In Transit</option>
                <option value="Awaiting Seller Inspection">Awaiting Seller Inspection</option>
                <option value="Refund Payout Pending">Refund Payout Pending</option>
                <option value="Rejected">Rejected</option>
                <option value="Resolved">Resolved</option>
            </select>

        </div>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="returns-table">

            <thead>
                <tr>
                    <th>Case / order</th>
                    <th>Buyer</th>
                    <th>Seller / store</th>
                    <th>Request</th>
                    <th>Requested refund</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>


            <tbody>

                @foreach ($cases as $case)

                    <tr
                        data-table-row
                        data-return-id="{{ $case['id'] }}"
                        data-type="{{ $case['type'] }}"
                        data-status="{{ $case['status'] }}"
                        data-search="{{
                            strtolower(
                                $case['id'].' '.
                                $case['order'].' '.
                                $case['buyer'].' '.
                                $case['seller'].' '.
                                $case['reason']
                            )
                        }}"
                    >

                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $case['id'] }}</strong>
                                <small>
                                    {{ $case['order'] }} · Submitted {{ $case['requested'] }}
                                </small>
                            </div>
                        </td>

                        <td>
                            {{ $case['buyer'] }}
                        </td>

                        <td>
                            {{ $case['seller'] }}
                        </td>

                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $case['type'] }}</strong>
                                <small>{{ $case['reason'] }}</small>
                            </div>
                        </td>

                        <td>
                            <strong>{{ $case['amount'] }}</strong>
                        </td>

                        <td>
                            <span
                                class="status-badge
                                {{
                                    $case['status'] === 'Resolved'
                                        ? 'badge-success'
                                         : (in_array($case['status'], ['Rejected'])
                                            ? 'badge-danger'
                                             : ($case['status'] === 'Under Review'
                                                 ? 'badge-info'
                                                 : 'badge-warning'))
                                }}"
                            >
                                {{ $case['status'] }}
                            </span>
                        </td>

                        <td class="align-right">

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="return-case-{{ $loop->index }}"
                                aria-haspopup="dialog"
                                aria-controls="return-case-{{ $loop->index }}"
                                aria-label="Review case {{ $case['id'] }}"
                            >
                                <i data-lucide="eye" aria-hidden="true"></i>
                                Review case
                            </button>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="table-empty"
            data-table-empty="returns-table"
            @if (count($cases) > 0) hidden @endif
        >
            <i data-lucide="search-x"></i>
            <strong>No cases found</strong>
            <span>No return or refund cases match the current filters.</span>
        </div>

    </div>

</section>


@foreach ($cases as $case)

<div
    class="modal-shell"
    id="return-case-{{ $loop->index }}"
    data-modal="return-case-{{ $loop->index }}"
    data-return-modal
    data-return-id="{{ $case['id'] }}"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
        aria-label="Close return case review"
    ></button>


    <section
        class="modal-card modal-wide return-review-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="return-review-title-{{ $loop->index }}"
        aria-describedby="return-review-description-{{ $loop->index }}"
        tabindex="-1"
    >

        <header class="modal-heading return-review-header">
            <div>
                <div class="return-review-id-row">
                    <span class="eyebrow">{{ $case['id'] }}</span>
                    <span class="status-badge {{ $case['status'] === 'Resolved' ? 'badge-success' : ($case['status'] === 'Rejected' ? 'badge-danger' : ($case['status'] === 'Under Review' ? 'badge-info' : 'badge-warning')) }}">{{ $case['status'] }}</span>
                </div>
                <h2 id="return-review-title-{{ $loop->index }}">Review return request</h2>
                <p id="return-review-description-{{ $loop->index }}">Compare the Buyer’s request, Seller’s response, and submitted evidence before taking action.</p>
            </div>
            <button
                type="button"
                class="icon-button"
                data-close-modal
                aria-label="Close return case review"
            >
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </header>


        <div class="return-review-content">
        <div class="review-details return-review-main">

            <h3 class="section-subtitle">
                Case information
            </h3>


            <div class="detail-grid">

                <div>
                    <span>Case ID</span>
                    <strong>{{ $case['id'] }}</strong>
                </div>

                <div>
                    <span>Order ID</span>
                    <strong>{{ $case['order'] }}</strong>
                </div>

                <div>
                    <span>Buyer</span>
                    <strong>{{ $case['buyer'] }}</strong>
                </div>

                <div>
                    <span>Seller</span>
                    <strong>{{ $case['seller'] }}</strong>
                </div>

                <div>
                    <span>Request type</span>
                    <strong>{{ $case['type'] }}</strong>
                </div>

                <div>
                    <span>Reason</span>
                    <strong>{{ $case['reason'] }}</strong>
                </div>

                <div>
                    <span>Date requested</span>
                    <strong>{{ $case['requested'] }}</strong>
                </div>

            </div>


            <div class="detail-note return-party-note return-party-note-buyer">
                <span>Buyer request</span>
                <p>{{ $case['buyer_request'] }}</p>
            </div>


            <div class="detail-note return-party-note return-party-note-seller">
                <span>Seller response</span>
                <p>{{ $case['seller_response'] }}</p>
            </div>

            <div class="detail-note return-items-note">
                <span>Selected items and requested value</span>
                @if (count($case['items']))
                    <div class="return-items-table-wrap">
                        <table class="return-items-table" aria-label="Items and quantities in this return request">
                            <thead><tr><th>Item</th><th>Quantity requested</th><th>Refund requested</th></tr></thead>
                            <tbody>
                                @foreach ($case['items'] as $item)
                                    <tr>
                                        <td>
                                            <div class="admin-return-item-product">
                                                <span class="admin-return-item-image">
                                                    @if(filled($item['image_url']))
                                                        <img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">
                                                    @else
                                                        <i data-lucide="package" aria-hidden="true"></i>
                                                    @endif
                                                </span>
                                                <span><strong>{{ $item['name'] }}</strong><small>{{ $item['variant'] }} · SKU {{ $item['sku'] }}</small></span>
                                            </div>
                                        </td>
                                        <td>{{ $item['quantity'] }}</td>
                                        <td><strong>{{ $item['amount'] }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>No line-item details are available.</p>
                @endif
            </div>

            <div class="detail-note return-evidence-note">
                <span>Private evidence files</span>
                @forelse ($case['evidence_files'] as $evidence)
                    <div class="return-evidence-row">
                        @if ($evidence['is_image'])
                            <button class="return-evidence-thumb" type="button" data-return-evidence-preview data-preview-url="{{ $evidence['preview_url'] }}" data-preview-name="{{ $evidence['name'] }}" aria-label="Preview {{ \Illuminate\Support\Str::headline($evidence['type']) }} image {{ $evidence['name'] }}">
                                <img src="{{ $evidence['preview_url'] }}" alt="" loading="lazy" decoding="async">
                            </button>
                        @else
                            <span class="return-evidence-icon" aria-hidden="true"><i data-lucide="file-text"></i></span>
                        @endif
                        <span class="return-evidence-copy"><strong>{{ $evidence['name'] }}</strong><small>{{ \Illuminate\Support\Str::headline($evidence['type']) }} · {{ number_format($evidence['size'] / 1024, 0) }} KB</small></span>
                        <span class="return-evidence-actions">
                            @if ($evidence['is_image'])
                                <button class="return-evidence-preview-button" type="button" data-return-evidence-preview data-preview-url="{{ $evidence['preview_url'] }}" data-preview-name="{{ $evidence['name'] }}" aria-label="Preview {{ \Illuminate\Support\Str::headline($evidence['type']) }} image {{ $evidence['name'] }}">Preview</button>
                            @endif
                            <a class="return-evidence-download" href="{{ $evidence['url'] }}" aria-label="Download {{ $evidence['name'] }}">Download</a>
                        </span>
                    </div>
                @empty
                    <p>No evidence files have been submitted.</p>
                @endforelse
            </div>

            @if (count($case['events']))
                <div class="detail-note return-history-note">
                    <span>Case history</span>
                    <ol class="return-history-list">
                        @foreach ($case['events'] as $event)
                            <li>
                                <span class="return-history-marker" aria-hidden="true"></span>
                                <div>
                                    <strong>{{ \Illuminate\Support\Str::headline($event['type']) }}</strong>
                                    <small>{{ $event['actor'] }} · {{ $event['at'] }}</small>
                                    @if ($event['note'])<p>{{ $event['note'] }}</p>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

        </div>
        <aside class="return-review-rail" aria-label="Review summary">
            <div class="return-refund-summary">
                <span>Refund requested</span>
                <strong>{{ $case['amount'] }}</strong>
                <small>Requested by the Buyer</small>
            </div>
            <section class="return-review-rail-section">
                <h3>Current status</h3>
                <p><span class="status-badge {{ $case['status'] === 'Resolved' ? 'badge-success' : ($case['status'] === 'Rejected' ? 'badge-danger' : ($case['status'] === 'Under Review' ? 'badge-info' : 'badge-warning')) }}" data-return-modal-status>{{ $case['status'] }}</span></p>
            </section>
            @if ($case['return_waybill'])
                <section class="return-review-rail-section">
                    <h3>Return shipment</h3>
                    <dl>
                        <div><dt>Waybill</dt><dd>{{ $case['return_waybill'] }}</dd></div>
                        <div><dt>Status</dt><dd>{{ \Illuminate\Support\Str::headline($case['return_shipment_status']) }}</dd></div>
                    </dl>
                </section>
            @endif
            @if ($case['refund_id'])
                <section class="return-review-rail-section">
                    <h3>Refund record</h3>
                    <dl>
                        <div><dt>Refund ID</dt><dd>{{ $case['refund_no'] }}</dd></div>
                        <div><dt>Refund amount</dt><dd>{{ $case['refund_amount'] }}</dd></div>
                        <div><dt>Status</dt><dd>{{ \Illuminate\Support\Str::headline($case['refund_status']) }}</dd></div>
                        <div><dt>Financially responsible</dt><dd>{{ $case['financially_responsible_party'] ? \Illuminate\Support\Str::headline($case['financially_responsible_party']) : 'Not recorded' }}</dd></div>
                        @if ($case['financial_responsibility_reason'])<div><dt>Responsibility reason</dt><dd>{{ $case['financial_responsibility_reason'] }}</dd></div>@endif
                        @if ($case['payout_date'])<div><dt>Externally paid</dt><dd>{{ $case['payout_date'] }} · {{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $case['payout_method'])) }}</dd></div>@endif
                        @if ($case['payout_reference'])<div><dt>Buyer payout reference</dt><dd>{{ $case['payout_reference'] }}</dd></div>@endif
                        @if ($case['payout_amount'])<div><dt>Actual amount paid</dt><dd>{{ $case['payout_amount'] }}</dd></div>@endif
                        @if ($case['payout_receipt_url'])<div><dt>Payout proof</dt><dd><a href="{{ $case['payout_receipt_url'] }}">Download receipt</a></dd></div>@endif
                    </dl>
                    @if ($case['seller_recovery'])
                        <div class="return-recovery-summary">
                            <strong>Seller recoverable balance</strong>
                            <span>₱{{ number_format($case['seller_recovery']['amount_minor'] / 100, 2) }} assigned · ₱{{ number_format($case['seller_recovery']['recovered_amount_minor'] / 100, 2) }} recovered · ₱{{ number_format($case['seller_recovery']['outstanding_minor'] / 100, 2) }} outstanding</span>
                            @foreach ($case['seller_recovery']['events'] as $recoveryEvent)
                                <small>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $recoveryEvent['method'])) }} · ₱{{ number_format($recoveryEvent['amount_minor'] / 100, 2) }} · {{ $recoveryEvent['reference'] }} · {{ $recoveryEvent['recovered_on'] }}</small>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </aside>
        </div>


        <div
            class="modal-footer decision-footer return-decision-footer"
            data-return-actions
        >
            @if ($case['can_decide'])
                <div class="return-decision-intro">
                    <div><strong>Admin decision</strong><span>Choose one next step. The decision or evidence request is recorded in the case history.</span></div>
                </div>
                <div class="return-action-grid">
            <form
                method="POST"
                action="{{ route(
                    'admin.compliance.returns-refunds.approve',
                    $case['database_id']
                ) }}"
                data-return-form
                class="return-action-approve"
            >
                @csrf
                <p>Approve the requested {{ $case['type'] === 'Return & Refund' ? 'return and refund' : 'refund' }}.</p>
                <label for="return-liable-party-{{ $loop->index }}">Who is financially responsible?
                    <select id="return-liable-party-{{ $loop->index }}" name="financially_responsible_party" required>
                        <option value="">Choose responsible party</option>
                        <option value="seller">Seller · seller-attributable issue</option>
                        <option value="platform">Platform · platform/logistics-attributable issue</option>
                    </select>
                </label>
                <label for="return-liability-reason-{{ $loop->index }}">Reason for assigning responsibility
                    <textarea id="return-liability-reason-{{ $loop->index }}" name="financial_responsibility_reason" rows="2" minlength="5" maxlength="1000" placeholder="Explain the evidence supporting this assignment" required></textarea>
                </label>
                <small class="return-liability-help">Admin sends the Buyer’s external payout either way. This decision only records who bears the refund cost.</small>
                <button
                    type="submit"
                    class="button button-primary"
                    data-return-action="approve"
                    data-return-button="approve"
                    data-functional-action="return-decision"
                >
                    <i data-lucide="circle-check" aria-hidden="true"></i>
                    {{ $case['type'] === 'Return & Refund' ? 'Approve return & refund' : 'Approve refund' }}
                </button>
            </form>


            <form
                method="POST"
                action="{{ route(
                    'admin.compliance.returns-refunds.reject',
                    $case['database_id']
                ) }}"
                data-return-form
                class="return-action-reject"
            >
                @csrf

                <label for="return-reject-reason-{{ $loop->index }}">Reason for rejection</label>
                <input id="return-reject-reason-{{ $loop->index }}" name="reason" type="text" minlength="5" maxlength="1000" placeholder="Explain the decision" required>

                <button
                    type="submit"
                    class="button button-danger-soft"
                    data-return-action="reject"
                    data-return-button="reject"
                    data-functional-action="return-decision"
                >
                    <i data-lucide="circle-x" aria-hidden="true"></i>
                    Reject request
                </button>
            </form>


            <form
                method="POST"
                action="{{ route(
                    'admin.compliance.returns-refunds.request-evidence',
                    $case['database_id']
                ) }}"
                data-return-form
                class="return-action-evidence"
            >
                @csrf

                <label for="return-evidence-note-{{ $loop->index }}">What evidence is needed?</label>
                <textarea id="return-evidence-note-{{ $loop->index }}" name="note" minlength="5" maxlength="1000" rows="2" placeholder="Tell the parties what to provide" required></textarea>
                <div class="return-evidence-request-bottom">
                <label for="return-evidence-from-{{ $loop->index }}">Request from
                    <select id="return-evidence-from-{{ $loop->index }}" name="request_from" required><option value="buyer">Buyer</option><option value="seller">Seller</option><option value="both">Both parties</option></select>
                </label>

                <button
                    type="submit"
                    class="button button-ghost"
                    data-return-action="evidence"
                    data-return-button="evidence"
                    data-functional-action="return-decision"
                >
                    <i data-lucide="file-search" aria-hidden="true"></i>
                    Request evidence
                </button>
                </div>
            </form>
                </div>

            @elseif ($case['can_record_payout'])
                <div class="return-payout-notice"><i data-lucide="info" aria-hidden="true"></i><p><strong>External payout only · {{ $case['refund_amount'] }}</strong><span>Record one payout for the exact approved amount, only after the Buyer has received it outside Bearly. Admin records the actual method, reference, date, and receipt; Bearly does not transfer money.</span></p></div>
                <details class="return-payout-disclosure">
                    <summary class="return-payout-disclosure-trigger"><i data-lucide="receipt-text" aria-hidden="true"></i>Record completed external payout</summary>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.compliance.returns-refunds.record-payout', $case['refund_id']) }}" class="return-payout-form">
                        @csrf
                        <label class="return-payout-confirmation" for="refund-external-payout-confirmed-{{ $loop->index }}">
                            <input id="refund-external-payout-confirmed-{{ $loop->index }}" type="checkbox" name="external_payout_confirmed" value="1" required>
                            <span class="return-payout-confirmation-copy"><strong>I confirm this exact payout was already sent to the Buyer.</strong><small>Submitting records the payout, updates financial records, and resolves the case. It does not send money.</small></span>
                        </label>
                        <label for="refund-method-{{ $loop->index }}">Payout method<select id="refund-method-{{ $loop->index }}" name="method" required><option value="">Choose method</option><option value="gcash">GCash</option><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="other">Other</option></select></label>
                        <label for="refund-reference-{{ $loop->index }}">External payout reference<input id="refund-reference-{{ $loop->index }}" name="provider_reference" type="text" maxlength="150" required></label>
                        <label for="refund-paid-on-{{ $loop->index }}">Date paid<input id="refund-paid-on-{{ $loop->index }}" name="external_paid_on" type="date" max="{{ now()->toDateString() }}" required></label>
                        <label for="refund-receipt-{{ $loop->index }}">Payout receipt / Buyer acknowledgment<input id="refund-receipt-{{ $loop->index }}" name="receipt" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required><small>JPG, PNG, WebP, or PDF · max 5 MB. Cash still requires a genuine receipt or Buyer acknowledgment and its reference.</small></label>
                        <button type="submit" class="button button-primary"><i data-lucide="check-check" aria-hidden="true"></i>Confirm &amp; record payout</button>
                    </form>
                </details>
            @elseif ($case['can_record_responsibility'])
                <div class="return-payout-notice"><i data-lucide="info" aria-hidden="true"></i><p><strong>Financial responsibility is missing</strong><span>This legacy approved refund must have a documented Seller or Platform responsibility decision before Admin can record a payout.</span></p></div>
                <form method="POST" action="{{ route('admin.compliance.returns-refunds.responsibility', $case['refund_id']) }}" class="return-responsibility-form">
                    @csrf
                    <label for="legacy-liable-party-{{ $loop->index }}">Who is financially responsible?
                        <select id="legacy-liable-party-{{ $loop->index }}" name="financially_responsible_party" required><option value="">Choose responsible party</option><option value="seller">Seller</option><option value="platform">Platform</option></select>
                    </label>
                    <label for="legacy-liability-reason-{{ $loop->index }}">Reason<textarea id="legacy-liability-reason-{{ $loop->index }}" name="financial_responsibility_reason" rows="3" minlength="5" maxlength="1000" required></textarea></label>
                    <button type="submit" class="button button-primary">Save responsibility decision</button>
                </form>
            @else
                <div class="return-case-state-note">
                    <span class="return-state-check" aria-hidden="true"><i data-lucide="clock-3"></i></span>
                    <p><strong>{{ $case['status'] }}</strong><span>{{ $case['resolution'] ?: 'No Admin decision is available at this stage. The case will update when the required next step is completed.' }}</span></p>
                </div>
            @endif

            @if ($case['seller_recovery'] && $case['seller_recovery']['outstanding_minor'] > 0)
                <section class="return-seller-recovery-action" aria-labelledby="seller-recovery-heading-{{ $loop->index }}">
                    <h3 id="seller-recovery-heading-{{ $loop->index }}">Seller recoverable balance</h3>
                    <p>₱{{ number_format($case['seller_recovery']['outstanding_minor'] / 100, 2) }} remains outstanding. Do not use this action until you have verified that the Seller paid or the offset was applied to a paid settlement. Bearly will not deduct or transfer funds.</p>
                    <details class="return-payout-disclosure">
                        <summary class="return-payout-disclosure-trigger"><i data-lucide="wallet-cards" aria-hidden="true"></i>Record verified Seller recovery</summary>
                        <form method="POST" action="{{ route('admin.compliance.returns-refunds.seller-recovery', $case['refund_id']) }}" class="return-payout-form return-recovery-form">
                            @csrf
                            <label class="return-payout-confirmation" for="recovery-confirmed-{{ $loop->index }}">
                                <input id="recovery-confirmed-{{ $loop->index }}" type="checkbox" name="recovery_confirmed" value="1" required>
                                <span class="return-payout-confirmation-copy"><strong>I verified that this Seller recovery already occurred.</strong><small>This only records a completed collection or an offset already applied to a paid Seller payout. It does not perform a deduction.</small></span>
                            </label>
                            <label for="recovery-amount-{{ $loop->index }}">Amount recovered (₱)<input id="recovery-amount-{{ $loop->index }}" name="amount" type="number" inputmode="decimal" min="0.01" max="{{ number_format($case['seller_recovery']['outstanding_minor'] / 100, 2, '.', '') }}" step="0.01" required></label>
                            <label for="recovery-method-{{ $loop->index }}">Recovery method<select id="recovery-method-{{ $loop->index }}" name="method" required><option value="">Choose method</option><option value="external_collection">External collection from Seller</option><option value="seller_payout_offset">Offset already applied to paid Seller payout</option></select></label>
                            <label for="recovery-reference-{{ $loop->index }}">Receipt / payout reference<input id="recovery-reference-{{ $loop->index }}" name="external_reference" type="text" maxlength="150" required></label>
                            <label for="recovery-paid-on-{{ $loop->index }}">Date recovered<input id="recovery-paid-on-{{ $loop->index }}" name="recovered_on" type="date" max="{{ now()->toDateString() }}" required></label>
                            <label for="recovery-payout-{{ $loop->index }}">Paid Seller payout, if this was an offset<select id="recovery-payout-{{ $loop->index }}" name="seller_payout_id"><option value="">Not an offset</option>@foreach ($case['eligible_recovery_payouts'] as $sellerPayout)<option value="{{ $sellerPayout['id'] }}">{{ $sellerPayout['number'] }} · paid {{ $sellerPayout['paid_at'] }}@if ($sellerPayout['reference']) · {{ $sellerPayout['reference'] }}@endif</option>@endforeach</select><small>Required when the recovery method is payout offset.</small></label>
                            <label class="return-recovery-note" for="recovery-note-{{ $loop->index }}">Verification note<textarea id="recovery-note-{{ $loop->index }}" name="note" rows="2" minlength="5" maxlength="1000" required></textarea></label>
                            <button type="submit" class="button button-primary">Record verified recovery</button>
                        </form>
                    </details>
                </section>
            @endif

            <div class="return-modal-state-indicator" data-return-state-indicator hidden aria-live="polite">
                <i data-lucide="circle-check" aria-hidden="true"></i>
                <span data-return-state-label>Status updated</span>
            </div>

        </div>

        <dialog class="return-evidence-image-dialog" data-return-evidence-dialog aria-labelledby="return-evidence-preview-title-{{ $loop->index }}">
            <header>
                <h3 id="return-evidence-preview-title-{{ $loop->index }}" data-return-evidence-title>Evidence preview</h3>
                <button type="button" data-return-evidence-close aria-label="Close evidence preview"><i data-lucide="x" aria-hidden="true"></i></button>
            </header>
            <div><img data-return-evidence-full alt=""></div>
        </dialog>

    </section>

</div>

@endforeach

</div>

@endsection
