@extends('layouts.admin')

@section('title', 'Product Violations')
@section('page-title', 'Product Violations')

@section('content')

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

        <table class="admin-table" id="violations-table">

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
            >
                @csrf
                <input type="hidden" name="violation_id" value="{{ $violation['database_id'] }}">

                @if ($reopenViolationModal && $errors->any())
                    <div class="admin-violation-form-error" role="alert">
                        {{ $errors->first('note') ?: $errors->first('decision') ?: $errors->first() }}
                    </div>
                @endif

                <label class="form-field" for="violation-note-{{ $violation['database_id'] }}">
                    <span>Decision reason <strong>Required</strong></span>
                    <textarea
                        id="violation-note-{{ $violation['database_id'] }}"
                        name="note"
                        rows="3"
                        maxlength="2000"
                        required
                        placeholder="Explain the evidence and why this decision is appropriate."
                    >{{ $reopenViolationModal ? old('note') : '' }}</textarea>
                    <small>The reason is saved with the Admin account and decision history.</small>
                </label>

                <div class="modal-footer decision-footer admin-violation-decision-actions">
                    <button type="submit" class="button button-secondary" name="decision" value="dismiss">
                        <i data-lucide="shield-check"></i> Dismiss as false positive
                    </button>
                    <button type="submit" class="button button-warning" name="decision" value="request_correction">
                        <i data-lucide="rotate-ccw"></i> Request correction
                    </button>
                    <button type="submit" class="button button-warning" name="decision" value="warn_seller">
                        <i data-lucide="badge-alert"></i> Issue Seller warning
                    </button>
                    <button type="submit" class="button button-danger" name="decision" value="remove_product">
                        <i data-lucide="package-x"></i> Block &amp; require removal
                    </button>
                </div>
                <p class="admin-violation-removal-note">
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
