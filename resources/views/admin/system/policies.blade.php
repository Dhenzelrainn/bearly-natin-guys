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

            <form method="POST" action="{{ route('admin.policies.update', $policy['database_id']) }}">
            @csrf
            @method('PATCH')
            <h3 class="section-subtitle">
                Policy information
            </h3>

            <div class="detail-grid">

                <div>
                    <span>Title</span>
                    <input class="text-field" name="title" value="{{ $policy['title'] }}" maxlength="180" required>
                </div>

                <div>
                    <span>Category</span>
                    <select class="select-field" name="category" required>@foreach ($categories as $category)<option value="{{ $category }}" @selected($policy['category'] === $category)>{{ $category }}</option>@endforeach</select>
                </div>

                <div>
                    <span>Version</span>
                    <input class="text-field" name="version" value="{{ $policy['version'] }}" maxlength="20" required>
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
                <textarea class="text-field" name="summary" rows="4" maxlength="2000">{{ $policy['summary'] }}</textarea>
            </div>

            <div class="detail-note">
                <span>Policy content</span>

                <textarea class="text-field" name="body" rows="10" maxlength="50000" required>{{ $policy['body'] }}</textarea>
            </div>

            <div class="modal-footer decision-footer"><button type="submit" class="button button-secondary"><i data-lucide="save"></i> Save Draft</button></div>
            </form>

        </div>


        <div
            class="modal-footer decision-footer"
            data-policy-actions
        >

            @if ($policy['version_status'] === 'draft')
                <form method="POST" action="{{ route('admin.policies.publish', $policy['database_id']) }}">@csrf<button type="submit" class="button button-primary"><i data-lucide="send"></i> Publish</button></form>
            @endif
            @if ($policy['status'] !== 'Archived')
                <form method="POST" action="{{ route('admin.policies.archive', $policy['database_id']) }}" onsubmit="return confirm('Archive this policy?')">@csrf<button type="submit" class="button button-danger-soft"><i data-lucide="archive"></i> Archive</button></form>
            @endif

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


        <form method="POST" action="{{ route('admin.policies.store') }}">
        @csrf
        <div class="policy-form">

            <div class="form-grid two-column-form">

                <label class="form-field">
                    <span>Policy title</span>

                    <input
                        type="text"
                        class="text-field"
                        name="title"
                        placeholder="Enter policy title"
                    >
                </label>


                <label class="form-field">
                    <span>Category</span>

                    <select
                        class="select-field"
                        name="category"
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
                        name="version"
                        placeholder="e.g. v1.0"
                    >
                </label>


                <label class="form-field">
                    <span>Status</span>

                    <select
                        class="select-field"
                        name="status"
                    >
                        <option value="draft">Draft</option>
                        <option value="published">Active</option>
                    </select>
                </label>

            </div>


            <label class="form-field policy-summary-field">
                <span>Summary</span>

                <textarea
                    class="text-field"
                    rows="4"
                    name="summary"
                    placeholder="Briefly describe this policy..."
                ></textarea>
            </label>


            <label class="form-field policy-content-field">
                <span>Policy content</span>

                <textarea
                    class="text-field"
                    rows="10"
                    name="body"
                    placeholder="Write the complete policy..."
                ></textarea>
            </label>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="submit"
                class="button button-ghost"
                name="status"
                value="draft"
            >
                <i data-lucide="save"></i>
                Save Draft
            </button>

            <button
                type="submit"
                class="button button-primary"
                name="status"
                value="published"
            >
                <i data-lucide="send"></i>
                Publish Policy
            </button>

        </div>
        </form>

    </section>

</div>

@endsection
