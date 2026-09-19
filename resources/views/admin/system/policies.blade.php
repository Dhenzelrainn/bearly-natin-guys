@extends('layouts.admin')

@section('title', 'Platform Policies')
@section('page-title', 'Platform Policies')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">System Management</span>
        <h1>Platform policies</h1>

        <p>
            Review, update, and maintain policies that govern platform usage,
            seller compliance, account conduct, and marketplace transactions.
        </p>
    </div>

    <div class="hero-context-card policy-hero-stat">
        <span class="hero-context-icon">
            <i data-lucide="file-text"></i>
        </span>

        <div class="hero-context-copy">
            <small>Policy records</small>

            <strong data-policy-record-count>
                {{ count($policies) }}
            </strong>

            <span>Managed platform policies</span>
        </div>
    </div>
</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Policy library</span>
            <h2>Current platform policies</h2>
        </div>

        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search policy..."
                    data-table-search="policies-table"
                >
            </label>

            <select
                class="select-field"
                data-table-filter="policies-table"
                data-filter-key="category"
            >
                <option value="">All categories</option>
                <option value="Marketplace">Marketplace</option>
                <option value="Seller Compliance">Seller Compliance</option>
                <option value="Transactions">Transactions</option>
                <option value="Account">Account</option>
            </select>

            <select
                class="select-field"
                data-table-filter="policies-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Active">Active</option>
                <option value="Draft">Draft</option>
                <option value="Archived">Archived</option>
            </select>

            <button
                type="button"
                class="button button-primary"
                data-open-modal="create-policy"
            >
                <i data-lucide="plus"></i>
                New Policy
            </button>

        </div>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="policies-table">

            <thead>
                <tr>
                    <th>Policy</th>
                    <th>Category</th>
                    <th>Version</th>
                    <th>Updated By</th>
                    <th>Last Updated</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>

            <tbody data-record-rows="policy">

                @foreach ($policies as $policy)

                    <tr
                        data-table-row
                        data-category="{{ $policy['category'] }}"
                        data-status="{{ $policy['status'] }}"
                        data-search="{{
                            strtolower(
                                $policy['id'].' '.
                                $policy['title'].' '.
                                $policy['category'].' '.
                                $policy['version'].' '.
                                $policy['updated_by'].' '.
                                $policy['summary']
                            )
                        }}"
                    >

                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    <i data-lucide="file-text"></i>
                                </span>

                                <div>
                                    <strong>{{ $policy['title'] }}</strong>
                                    <small>{{ $policy['id'] }}</small>
                                </div>

                            </div>

                        </td>

                        <td>
                            {{ $policy['category'] }}
                        </td>

                        <td>
                            <strong>{{ $policy['version'] }}</strong>
                        </td>

                        <td>
                            {{ $policy['updated_by'] }}
                        </td>

                        <td>
                            {{ $policy['updated'] }}
                        </td>

                        <td>

                            <span
                                class="status-badge
                                {{
                                    $policy['status'] === 'Active'
                                        ? 'badge-success'
                                        : ($policy['status'] === 'Draft'
                                            ? 'badge-warning'
                                            : 'badge-neutral')
                                }}"
                            >
                                {{ $policy['status'] }}
                            </span>

                        </td>

                        <td class="align-right">

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="policy-{{ $loop->index }}"
                            >
                                <i data-lucide="eye"></i>
                                View
                            </button>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="table-empty"
            data-table-empty="policies-table"
            hidden
        >
            <i data-lucide="search-x"></i>
            <strong>No policies found</strong>
            <span>Try another search or filter.</span>
        </div>

    </div>

</section>


@foreach ($policies as $policy)

<div
    class="modal-shell"
    data-modal="policy-{{ $loop->index }}"
    data-policy-modal
    data-policy-id="{{ $policy['id'] }}"
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
                <span class="eyebrow">{{ $policy['id'] }}</span>
                <h2 data-policy-title>
                    {{ $policy['title'] }}
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


        <div class="review-details">

            <h3 class="section-subtitle">
                Policy information
            </h3>

            <div class="detail-grid">

                <div>
                    <span>Category</span>
                    <strong data-policy-category>
                        {{ $policy['category'] }}
                    </strong>
                </div>

                <div>
                    <span>Version</span>
                    <strong data-policy-version>
                        {{ $policy['version'] }}
                    </strong>
                </div>

                <div>
                    <span>Status</span>
                    <strong data-policy-status>
                        {{ $policy['status'] }}
                    </strong>
                </div>

                <div>
                    <span>Last updated</span>
                    <strong data-policy-updated>
                        {{ $policy['updated'] }}
                    </strong>
                </div>

                <div>
                    <span>Updated by</span>
                    <strong data-policy-author>
                        {{ $policy['updated_by'] }}
                    </strong>
                </div>

            </div>


            <div class="detail-note">
                <span>Summary</span>

                <p data-policy-summary>
                    {{ $policy['summary'] }}
                </p>
            </div>

            <div class="detail-note">
                <span>Policy content</span>

                <p data-policy-body>
                    {{ $policy['body'] }}
                </p>
            </div>

        </div>


        <div
            class="modal-footer decision-footer"
            data-policy-actions
        >

            <button
                type="button"
                class="button button-secondary"
                data-policy-action="edit"
                data-mock-action="{{ $policy['title'] }} opened for editing."
            >
                <i data-lucide="pencil"></i>
                Edit Policy
            </button>


            <button
                type="button"
                class="button button-primary"
                data-policy-action="publish"
                data-mock-action="{{ $policy['title'] }} published."
            >
                <i data-lucide="send"></i>
                Publish
            </button>


            <button
                type="button"
                class="button button-danger-soft"
                data-policy-action="archive"
                data-mock-action="{{ $policy['title'] }} archived."
            >
                <i data-lucide="archive"></i>
                Archive
            </button>


            <button
                type="button"
                class="button button-secondary"
                data-policy-state-indicator
                hidden
                disabled
            >
                <i data-lucide="circle-check"></i>

                <span data-policy-state-label>
                    Policy updated
                </span>
            </button>

        </div>

    </section>

</div>

@endforeach


<div
    class="modal-shell"
    data-modal="create-policy"
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
                <span class="eyebrow">System Management</span>
                <h2>Create platform policy</h2>
            </div>

            <button
                type="button"
                class="icon-button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="policy-form">

            <div class="form-grid two-column-form">

                <label class="form-field">
                    <span>Policy title</span>

                    <input
                        type="text"
                        class="text-field"
                        data-record-field="title"
                        placeholder="Enter policy title"
                    >
                </label>


                <label class="form-field">
                    <span>Category</span>

                    <select
                        class="select-field"
                        data-record-field="category"
                    >
                        <option>Marketplace</option>
                        <option>Seller Compliance</option>
                        <option>Transactions</option>
                        <option>Account</option>
                    </select>
                </label>


                <label class="form-field">
                    <span>Version</span>

                    <input
                        type="text"
                        class="text-field"
                        data-record-field="version"
                        placeholder="e.g. v1.0"
                    >
                </label>


                <label class="form-field">
                    <span>Status</span>

                    <select
                        class="select-field"
                        data-record-field="status"
                    >
                        <option>Draft</option>
                        <option>Active</option>
                    </select>
                </label>

            </div>


            <label class="form-field policy-summary-field">
                <span>Summary</span>

                <textarea
                    class="text-field"
                    rows="4"
                    data-record-field="summary"
                    placeholder="Briefly describe this policy..."
                ></textarea>
            </label>


            <label class="form-field policy-content-field">
                <span>Policy content</span>

                <textarea
                    class="text-field"
                    rows="10"
                    data-record-field="body"
                    placeholder="Write the complete policy..."
                ></textarea>
            </label>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-ghost"
                data-mock-action="Policy saved as draft."
                data-save-record="policy"
                data-record-status="Draft"
            >
                <i data-lucide="save"></i>
                Save Draft
            </button>

            <button
                type="button"
                class="button button-primary"
                data-mock-action="Platform policy published."
                data-save-record="policy"
                data-record-status="Active"
            >
                <i data-lucide="send"></i>
                Publish Policy
            </button>

        </div>

    </section>

</div>

@endsection
