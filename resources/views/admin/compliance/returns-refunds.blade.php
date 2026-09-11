@extends('layouts.admin')

@section('title', 'Returns & Refunds')
@section('page-title', 'Returns & Refunds')

@section('content')

<section class="page-hero">

    <div>
        <span class="eyebrow">Compliance & Disputes</span>
        <h1>Returns & refunds</h1>

        <p>
            Review escalated return and refund cases that require
            administrative oversight or a final platform decision.
        </p>
    </div>


    <div class="hero-summary-card">
        <span class="metric-icon">
            <i data-lucide="rotate-ccw"></i>
        </span>

        <div>
            <strong>{{ count($cases) }}</strong>
            <small>Return & refund cases</small>
        </div>
    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Case management</span>
            <h2>Escalated cases</h2>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search case..."
                    data-table-search="returns-table"
                >
            </label>


            <select
                class="select-field"
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
                data-table-filter="returns-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Escalated">Escalated</option>
                <option value="Under Review">Under Review</option>
                <option value="Awaiting Seller">Awaiting Seller</option>
                <option value="Resolved">Resolved</option>
            </select>

        </div>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="returns-table">

            <thead>
                <tr>
                    <th>Case</th>
                    <th>Buyer</th>
                    <th>Seller</th>
                    <th>Request</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>


            <tbody>

                @foreach ($cases as $case)

                    <tr
                        data-table-row
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
                            <strong>{{ $case['id'] }}</strong>
                            <small>
                                {{ $case['order'] }} • {{ $case['requested'] }}
                            </small>
                        </td>

                        <td>
                            {{ $case['buyer'] }}
                        </td>

                        <td>
                            {{ $case['seller'] }}
                        </td>

                        <td>
                            <strong>{{ $case['type'] }}</strong>
                            <small>{{ $case['reason'] }}</small>
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
                                        : ($case['status'] === 'Escalated'
                                            ? 'badge-danger'
                                            : 'badge-warning')
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
            data-table-empty="returns-table"
            hidden
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
    data-modal="return-case-{{ $loop->index }}"
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
                <span class="eyebrow">{{ $case['id'] }}</span>
                <h2>Return & refund review</h2>
            </div>

            <button
                type="button"
                class="icon-button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-details">

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
                    <span>Order amount</span>
                    <strong>{{ $case['amount'] }}</strong>
                </div>

                <div>
                    <span>Reason</span>
                    <strong>{{ $case['reason'] }}</strong>
                </div>

                <div>
                    <span>Date requested</span>
                    <strong>{{ $case['requested'] }}</strong>
                </div>

                <div>
                    <span>Current status</span>
                    <strong>{{ $case['status'] }}</strong>
                </div>

            </div>


            <div class="detail-note">
                <span>Buyer request</span>
                <p>{{ $case['buyer_request'] }}</p>
            </div>


            <div class="detail-note">
                <span>Seller response</span>
                <p>{{ $case['seller_response'] }}</p>
            </div>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-secondary"
                data-mock-action="{{ $case['id'] }} approved for refund."
            >
                <i data-lucide="circle-check"></i>
                Approve Refund
            </button>


            <button
                type="button"
                class="button button-danger-soft"
                data-mock-action="{{ $case['id'] }} refund request rejected."
            >
                <i data-lucide="circle-x"></i>
                Reject Request
            </button>


            <button
                type="button"
                class="button button-ghost"
                data-mock-action="Additional evidence requested for {{ $case['id'] }}."
            >
                <i data-lucide="file-search"></i>
                Request Evidence
            </button>

        </div>

    </section>

</div>

@endforeach

@endsection