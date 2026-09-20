@extends('layouts.admin')

@section('title', 'Product Violations')
@section('page-title', 'Product Violations')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">Compliance & Disputes</span>

        <h1>Product violations</h1>

        <p>
            Review flagged product listings, investigate marketplace policy
            violations, and apply appropriate enforcement actions.
        </p>
    </div>

    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="shield-alert"></i>
        </span>

        <div>
            <strong>{{ count($violations) }}</strong>
            <span>Open violation records</span>
        </div>
    </div>
</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Enforcement queue</span>
            <h2>Flagged product listings</h2>
        </div>

        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search violation..."
                    data-table-search="violations-table"
                >
            </label>

            <select
                class="select-field"
                data-table-filter="violations-table"
                data-filter-key="risk"
            >
                <option value="">All risk levels</option>
                <option value="High">High</option>
                <option value="Medium">Medium</option>
                <option value="Low">Low</option>
            </select>

            <select
                class="select-field"
                data-table-filter="violations-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Pending Review">Pending Review</option>
                <option value="Under Review">Under Review</option>
                <option value="Removal Required">Removal Required</option>
                <option value="Escalated">Escalated</option>
                <option value="Resolved">Resolved</option>
            </select>

        </div>
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
                        data-violation-id="{{ $violation['id'] }}"
                        data-risk="{{ $violation['risk'] }}"
                        data-status="{{ $violation['status'] }}"
                        data-warnings="{{ $violation['warnings'] }}"
                        data-search="{{
                            strtolower(
                                $violation['id'].' '.
                                $violation['product_id'].' '.
                                $violation['product'].' '.
                                $violation['seller'].' '.
                                $violation['violation']
                            )
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
                                class="status-badge js-violation-status
                                {{
                                    $violation['status'] === 'Resolved'
                                        ? 'badge-success'
                                        : (in_array(
                                            $violation['status'],
                                            ['Escalated', 'Removal Required']
                                        )
                                            ? 'badge-danger'
                                            : ($violation['status'] === 'Pending Review'
                                                ? 'badge-warning'
                                                : 'badge-info'))
                                }}"
                            >
                                {{ $violation['status'] }}
                            </span>
                        </td>


                        <td class="align-right">
                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="violation-{{ $loop->index }}"
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
            hidden
        >
            <i data-lucide="shield-check"></i>

            <strong>No violations found</strong>

            <span>
                No records match the current filters.
            </span>
        </div>

    </div>

</section>



@foreach ($violations as $violation)

<div
    class="modal-shell"
    data-modal="violation-{{ $loop->index }}"
    data-violation-modal
    data-violation-id="{{ $violation['id'] }}"
    hidden
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
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">
                    {{ $violation['id'] }}
                </span>

                <h2>
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

            </div>

        </div>


        <div
            class="modal-footer decision-footer"
            data-violation-actions
        >

            <button
                type="button"
                class="button button-warning"
                data-violation-action="warning"
                data-violation-button="warning"
                data-mock-action="Warning issued to {{ $violation['seller'] }}."
            >
                <i data-lucide="triangle-alert"></i>
                Issue Warning
            </button>


            <button
                type="button"
                class="button button-danger-soft"
                data-violation-action="removal"
                data-violation-button="removal"
                data-mock-action="{{ $violation['product'] }} marked for removal."
            >
                <i data-lucide="package-x"></i>
                Require Removal
            </button>


            <button
                type="button"
                class="button button-danger"
                data-violation-action="escalate"
                data-violation-button="escalate"
                data-mock-action="{{ $violation['seller'] }} has been escalated for account review."
            >
                <i data-lucide="shield-x"></i>
                Escalate Seller
            </button>


            <button
                type="button"
                class="button button-secondary"
                data-violation-state-indicator
                hidden
                disabled
            >
                <i data-lucide="circle-check"></i>

                <span data-violation-state-label>
                    Status updated
                </span>
            </button>

        </div>

    </section>

</div>

@endforeach

@endsection