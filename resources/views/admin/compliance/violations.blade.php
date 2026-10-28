@extends('layouts.admin')

@section('title', 'Product Violations')
@section('page-title', 'Product Violations')

@section('content')
@php
    $decisionReasonPrompts = [
        'dismiss' => [
            'guidance' => 'Tell us why this product is okay.',
            'placeholder' => 'This product is okay because [what you checked].',
            'submit_label' => 'Confirm dismissal',
        ],
        'request_correction' => [
            'guidance' => 'Tell the Seller what to fix.',
            'placeholder' => 'Please fix [the problem].',
            'submit_label' => 'Request correction',
        ],
        'warn_seller' => [
            'guidance' => 'Tell us what rule the Seller broke.',
            'placeholder' => 'The Seller broke [rule] by [what they did].',
            'submit_label' => 'Issue Seller warning',
        ],
        'remove_product' => [
            'guidance' => 'Tell us why this product must be removed.',
            'placeholder' => 'Remove this product because [what is not allowed].',
            'submit_label' => 'Block & require removal',
        ],
    ];
@endphp

@if (session('success'))
    <div class="admin-violation-feedback is-success" role="status">
        <i data-lucide="circle-check"></i>
        <span>{{ session('success') }}</span>
    </div>
@endif

@if ($errors->any() && ! old('violation_id'))
    <div class="admin-violation-feedback is-error" role="alert">
        <i data-lucide="triangle-alert"></i>
        <span>{{ $errors->first() }}</span>
    </div>
@endif

<section class="page-hero">
    <div>
        <span class="eyebrow">Compliance & Disputes</span>

        <h1>Product violations</h1>

        <p>
            Review persisted compliance flags, document your decision, and
            preserve a traceable history for each product and Seller.
        </p>
    </div>

    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="shield-alert"></i>
        </span>

        <div>
            <strong>{{ $openViolationCount }}</strong>
            <span>Open violations</span>
        </div>
    </div>
</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Enforcement queue</span>
            <h2>Flagged product listings</h2>
        </div>

        <form method="GET" action="{{ route('admin.compliance.violations') }}" class="table-toolbar admin-violation-filters">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search violation..."
                    name="search"
                    value="{{ request('search') }}"
                    aria-label="Search violations"
                >
            </label>

            <select
                class="select-field"
                name="risk"
                aria-label="Filter by risk level"
            >
                <option value="">All risk levels</option>
                <option value="critical" @selected(request('risk') === 'critical')>Critical</option>
                <option value="high" @selected(request('risk') === 'high')>High</option>
                <option value="medium" @selected(request('risk') === 'medium')>Medium</option>
                <option value="low" @selected(request('risk') === 'low')>Low</option>
            </select>

            <select
                class="select-field"
                name="status"
                aria-label="Filter by violation status"
            >
                <option value="">All statuses</option>
                <option value="flagged" @selected(request('status') === 'flagged')>Pending Review</option>
                <option value="under_review" @selected(request('status') === 'under_review')>Under Review</option>
                <option value="correction_requested" @selected(request('status') === 'correction_requested')>Correction Requested</option>
                <option value="confirmed" @selected(request('status') === 'confirmed')>Confirmed</option>
                <option value="corrective_action" @selected(request('status') === 'corrective_action')>Corrective Action</option>
                <option value="dismissed" @selected(request('status') === 'dismissed')>Dismissed</option>
                <option value="resolved" @selected(request('status') === 'resolved')>Resolved</option>
            </select>

            <button class="button button-primary button-small" type="submit">
                <i data-lucide="filter"></i> Apply
            </button>
            @if (request()->hasAny(['search', 'risk', 'status']))
                <a class="button button-ghost button-small" href="{{ route('admin.compliance.violations') }}">Clear</a>
            @endif
        </form>
    </div>


    <div class="table-wrap">

        <table
            class="admin-table"
            id="violations-table"
            data-image-advisory-poll
            data-image-advisories-url="{{ route('admin.compliance.image-advisories') }}"
        >

            <thead>
                <tr>
                    <th>Product</th>
                    <th>Seller</th>
                    <th>Violation</th>
                    <th>Risk</th>
                    <th>Warnings</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>

            <tbody>

                @foreach ($violations as $violation)

                    <tr
                        data-table-row
                        data-violation-id="{{ $violation['database_id'] }}"
                        data-image-advisory-product-id="{{ $violation['product_record_id'] }}"
                        data-risk="{{ $violation['risk'] }}"
                        data-status="{{ $violation['status_value'] }}"
                        data-warnings="{{ $violation['warnings'] }}"
                        data-search="{{
                            strtolower($violation['id'].' '.$violation['product_id'].' '.$violation['product'].' '.$violation['seller'].' '.$violation['violation'].' '.$violation['reason'])
                        }}"
                    >

                        <td>
                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    <i data-lucide="package"></i>
                                </span>

                                <div>
                                    <strong>{{ $violation['product'] }}</strong>

                                    <small>
                                        {{ $violation['product_id'] }}
                                        •
                                        {{ $violation['id'] }}
                                    </small>
                                </div>

                            </div>
                        </td>


                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $violation['seller'] }}</strong>

                                <small>
                                    Reported {{ $violation['reported'] }}
                                </small>
                            </div>
                        </td>


                        <td>
                            {{ $violation['violation'] }}
                        </td>


                        <td>
                            <span
                                class="risk-badge
                                risk-{{ strtolower($violation['risk']) }}"
                            >
                                {{ $violation['risk'] }}
                            </span>
                        </td>


                        <td>
                            <strong data-violation-warning-count>
                                {{ $violation['warnings'] }}
                            </strong>
                        </td>


                        <td>
                            <span
                                class="status-badge js-violation-status {{ $violation['status_class'] }}"
                            >
                                {{ $violation['status'] }}
                            </span>
                        </td>


                        <td class="align-right">
                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="violation-{{ $violation['database_id'] }}"
                                aria-label="Review {{ $violation['product'] }} violation {{ $violation['id'] }}"
                            >
                                <i data-lucide="eye"></i>
                                Review
                            </button>
                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="table-empty"
            data-table-empty="violations-table"
            @unless ($violations->isEmpty()) hidden @endunless
        >
            <i data-lucide="shield-check"></i>

            <strong>No violations found</strong>

            <span>
                No records match the current filters.
            </span>
        </div>

    </div>

</section>

<div class="panel-footer admin-violation-pagination">
    {{ $violations->links() }}
</div>


@foreach ($violations as $violation)

@php
    $reopenViolationModal = (string) old('violation_id') === (string) $violation['database_id'];
@endphp

<div
    class="modal-shell admin-violation-modal-shell"
    data-modal="violation-{{ $violation['database_id'] }}"
    data-violation-modal
    data-violation-id="{{ $violation['database_id'] }}"
    data-image-advisory-product-id="{{ $violation['product_record_id'] }}"
    @unless ($reopenViolationModal) hidden @endunless
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="violation-title-{{ $violation['database_id'] }}"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">
                    {{ $violation['id'] }}
                </span>

                <h2 id="violation-title-{{ $violation['database_id'] }}">
                    Violation review
                </h2>
            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-grid">

            <div class="review-profile">

                <span class="avatar avatar-large avatar-warm">
                    <i data-lucide="shield-alert"></i>
                </span>

                <h3>
                    {{ $violation['product'] }}
                </h3>

                <p>
                    {{ $violation['seller'] }}
                </p>

                <span
                    class="risk-badge
                    risk-{{ strtolower($violation['risk']) }}"
                >
                    {{ $violation['risk'] }} Risk
                </span>

            </div>


            <div class="review-details">

                <h3 class="section-subtitle">
                    Violation information
                </h3>


                <div class="detail-grid">

                    <div>
                        <span>Violation ID</span>

                        <strong>
                            {{ $violation['id'] }}
                        </strong>
                    </div>


                    <div>
                        <span>Product ID</span>

                        <strong>
                            {{ $violation['product_id'] }}
                        </strong>
                    </div>


                    <div>
                        <span>Seller</span>

                        <strong>
                            {{ $violation['seller'] }}
                        </strong>
                    </div>


                    <div>
                        <span>Violation type</span>

                        <strong>
                            {{ $violation['violation'] }}
                        </strong>
                    </div>

                    <div>
                        <span>Flag source</span>

                        <strong>{{ $violation['source'] }}</strong>
                    </div>


                    <div>
                        <span>Registered category</span>

                        <strong>
                            {{ $violation['registered_category'] }}
                        </strong>
                    </div>


                    <div>
                        <span>Listed category</span>

                        <strong>
                            {{ $violation['listed_category'] }}
                        </strong>
                    </div>

                    <div>
                        <span>Product status</span>

                        <strong>{{ str($violation['product_status'])->replace('_', ' ')->title() }}</strong>
                    </div>

                    <div>
                        <span>Compliance status</span>

                        <strong>{{ str($violation['compliance_status'])->replace('_', ' ')->title() }}</strong>
                    </div>


                    <div>
                        <span>Previous warnings</span>

                        <strong data-modal-violation-warnings>
                            {{ $violation['warnings'] }}
                        </strong>
                    </div>


                    <div>
                        <span>Current status</span>

                        <strong data-modal-violation-status>
                            {{ $violation['status'] }}
                        </strong>
                    </div>

                </div>

                <div class="detail-note admin-violation-image-advisory" aria-live="polite" aria-atomic="true">
                    <span>Image check (advisory)</span>
                    <p>
                        <span class="status-badge {{ $violation['image_advisory_class'] }}" data-image-advisory-status data-image-advisory-code="{{ $violation['image_advisory_code'] }}">
                            {{ $violation['image_advisory_status'] }}
                        </span>
                    </p>
                    <p data-image-advisory-description data-image-advisory-description-prefix="Visible item: " @if (! $violation['image_advisory_description']) hidden @endif>Visible item: {{ $violation['image_advisory_description'] }}</p>
                    <p data-image-advisory-reason @if (! $violation['image_advisory_reason']) hidden @endif>{{ $violation['image_advisory_reason'] }}</p>
                    <small class="table-subtext" data-image-advisory-helper @if (! $violation['image_advisory_helper']) hidden @endif>{{ $violation['image_advisory_helper'] }}</small>
                </div>


                <div class="detail-note">
                    <span>
                        Reason for flagging
                    </span>

                    <p>
                        {{ $violation['reason'] }}
                    </p>
                </div>

                @if ($violation['detected_excerpt'])
                    <div class="detail-note admin-violation-excerpt">
                        <span>Detected text</span>
                        <p>{{ $violation['detected_excerpt'] }}</p>
                        @if ($violation['content_mismatch'])
                            <small class="admin-violation-excerpt-note">
                                Both categories are the Seller's registered category — Sellers cannot list outside it.
                                This hold comes from the listing text matching the configured category-content
                                mismatch rule, not from a category change. Review the listing wording before deciding.
                            </small>
                        @endif
                    </div>
                @endif

                @if (count($violation['actions']))
                    <section class="admin-violation-history" aria-label="Decision history">
                        <h3>Decision history</h3>
                        <ol>
                            @foreach ($violation['actions'] as $action)
                                <li>
                                    <div>
                                        <strong>{{ $action['label'] }}</strong>
                                        <span>{{ $action['actor'] }} · {{ $action['created_at'] }}</span>
                                    </div>
                                    <p>{{ $action['reason'] }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

            </div>

        </div>


        @if ($violation['actionable'])
            <form
                method="POST"
                action="{{ route('admin.compliance.decide', $violation['database_id']) }}"
                class="admin-violation-decision-form"
                data-admin-violation-decision-form
            >
                @csrf
                <input type="hidden" name="violation_id" value="{{ $violation['database_id'] }}">

                @if ($reopenViolationModal && $errors->any())
                    <div class="admin-violation-form-error" role="alert">
                        {{ $errors->first('note') ?: $errors->first('decision') ?: $errors->first() }}
                    </div>
                @endif

                @php
                    $selectedDecision = $reopenViolationModal ? old('decision') : null;
                    $selectedPrompt = is_string($selectedDecision)
                        ? ($decisionReasonPrompts[$selectedDecision] ?? null)
                        : null;
                @endphp

                <fieldset class="admin-violation-decision-picker">
                    <legend class="sr-only">Choose a compliance decision</legend>
                    <div class="admin-violation-decision-options">
                        <label class="admin-violation-decision-option">
                            <input type="radio" name="decision" value="dismiss" required data-decision-choice
                                data-reason-guidance="{{ $decisionReasonPrompts['dismiss']['guidance'] }}"
                                data-reason-placeholder="{{ $decisionReasonPrompts['dismiss']['placeholder'] }}"
                                data-submit-label="{{ $decisionReasonPrompts['dismiss']['submit_label'] }}"
                                @checked($selectedDecision === 'dismiss')>
                            <span class="button button-secondary"><i data-lucide="shield-check" aria-hidden="true"></i> Dismiss as false positive</span>
                        </label>
                        <label class="admin-violation-decision-option">
                            <input type="radio" name="decision" value="request_correction" required data-decision-choice
                                data-reason-guidance="{{ $decisionReasonPrompts['request_correction']['guidance'] }}"
                                data-reason-placeholder="{{ $decisionReasonPrompts['request_correction']['placeholder'] }}"
                                data-submit-label="{{ $decisionReasonPrompts['request_correction']['submit_label'] }}"
                                @checked($selectedDecision === 'request_correction')>
                            <span class="button button-warning"><i data-lucide="rotate-ccw" aria-hidden="true"></i> Request correction</span>
                        </label>
                        <label class="admin-violation-decision-option">
                            <input type="radio" name="decision" value="warn_seller" required data-decision-choice
                                data-reason-guidance="{{ $decisionReasonPrompts['warn_seller']['guidance'] }}"
                                data-reason-placeholder="{{ $decisionReasonPrompts['warn_seller']['placeholder'] }}"
                                data-submit-label="{{ $decisionReasonPrompts['warn_seller']['submit_label'] }}"
                                @checked($selectedDecision === 'warn_seller')>
                            <span class="button button-warning"><i data-lucide="badge-alert" aria-hidden="true"></i> Issue Seller warning</span>
                        </label>
                        <label class="admin-violation-decision-option">
                            <input type="radio" name="decision" value="remove_product" required data-decision-choice
                                data-reason-guidance="{{ $decisionReasonPrompts['remove_product']['guidance'] }}"
                                data-reason-placeholder="{{ $decisionReasonPrompts['remove_product']['placeholder'] }}"
                                data-submit-label="{{ $decisionReasonPrompts['remove_product']['submit_label'] }}"
                                @checked($selectedDecision === 'remove_product')>
                            <span class="button button-danger"><i data-lucide="package-x" aria-hidden="true"></i> Block &amp; require removal</span>
                        </label>
                    </div>
                    <p id="violation-decision-guidance-{{ $violation['database_id'] }}" class="admin-violation-decision-guidance" data-decision-reason-guide aria-live="polite">
                        {{ $selectedPrompt['guidance'] ?? 'Pick a button to see what to write. Use your own facts.' }}
                    </p>
                </fieldset>

                <label class="form-field" for="violation-note-{{ $violation['database_id'] }}">
                    <span>Decision reason <strong>Required</strong></span>
                    <textarea
                        id="violation-note-{{ $violation['database_id'] }}"
                        name="note"
                        rows="3"
                        maxlength="2000"
                        required
                        data-decision-reason
                        aria-describedby="violation-decision-guidance-{{ $violation['database_id'] }}"
                        placeholder="{{ $selectedPrompt['placeholder'] ?? 'Pick a button to see what to write.' }}"
                    >{{ $reopenViolationModal ? old('note') : '' }}</textarea>
                    <small>This note is saved with your decision.</small>
                </label>

                <div class="modal-footer decision-footer admin-violation-decision-actions">
                    <button type="submit" class="button button-primary admin-violation-decision-confirm" data-decision-submit>
                        {{ $selectedPrompt['submit_label'] ?? 'Record decision' }}
                    </button>
                </div>
                <p class="admin-violation-removal-note" data-decision-removal-note @if ($selectedDecision !== 'remove_product') hidden @endif>
                    Blocking immediately removes the listing from Buyer-facing catalog results; the product record and violation history are retained.
                </p>
            </form>
        @else
            @if ($reopenViolationModal && $errors->any())
                <div class="admin-violation-form-error" role="alert">
                    {{ $errors->first('note') ?: $errors->first('decision') ?: $errors->first() }}
                </div>
            @endif

            <div class="admin-violation-final-state" role="status">
                This violation has a final decision. Its decision history is retained above.
            </div>
        @endif

    </section>

</div>

@endforeach

@endsection
