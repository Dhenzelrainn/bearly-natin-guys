@extends('layouts.admin')

@section('title', 'Platform Policies')
@section('page-title', 'Platform Policies')

@section('content')

@if (session('success'))
    <div class="panel" role="status">{{ session('success') }}</div>
@endif

@if ($errors->any() && ! old('form'))
    <div class="panel" role="alert">
        <strong>Please correct the following:</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="page-hero">
    <div>
        <span class="eyebrow">System Management</span>
        <h1>Platform policies</h1>

        <p>
            Review, update, and maintain policies that govern platform usage,
            seller compliance, account conduct, and marketplace transactions.
            Every update creates a new version and keeps previous versions in
            the policy history.
        </p>
    </div>

    <div class="hero-context-card policy-hero-stat">
        <span class="hero-context-icon">
            <i data-lucide="file-text"></i>
        </span>

        <div class="hero-context-copy">
            <small>Policy records</small>

            <strong>
                {{ count($policies) }}
            </strong>

            <span>Managed platform policies</span>
        </div>
    </div>
</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">
                Policy management
            </span>

            <h2>
                Policy records
            </h2>
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
                aria-label="Filter by category"
            >

                <option value="">
                    All categories
                </option>

                @foreach ($categories as $category)
                    <option value="{{ $category }}">
                        {{ $category }}
                    </option>
                @endforeach

            </select>


            <select
                class="select-field"
                data-table-filter="policies-table"
                data-filter-key="status"
                aria-label="Filter by status"
            >

                <option value="">
                    All statuses
                </option>

                <option value="active">
                    Active
                </option>

                <option value="draft">
                    Draft
                </option>

                <option value="archived">
                    Archived
                </option>

            </select>


            <button type="button" class="button button-primary" data-open-modal="policy-create">
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


            <tbody>

                @forelse ($policies as $policy)

                    <tr
                        data-table-row
                        data-category="{{ $policy['category'] }}"
                        data-status="{{ $policy['status_value'] }}"
                        data-search="{{ strtolower($policy['code'].' '.$policy['title'].' '.$policy['category'].' '.($policy['latest']['author'] ?? '')) }}"
                    >

                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    <i data-lucide="file-text"></i>
                                </span>

                                <div class="table-primary-secondary">
                                    <strong>{{ $policy['title'] }}</strong>
                                    <small>{{ $policy['code'] }}</small>
                                </div>

                            </div>

                        </td>

                        <td>
                            {{ $policy['category'] }}
                        </td>

                        <td>

                            @if ($policy['latest'])

                                <div class="table-primary-secondary">
                                    <strong>{{ $policy['latest']['version'] }}</strong>
                                    <small>{{ $policy['latest']['status'] }}</small>
                                </div>

                            @else
                                —
                            @endif

                        </td>

                        <td>
                            {{ $policy['latest']['author'] ?? '—' }}
                        </td>

                        <td>

                            @if ($policy['latest'])

                                <div class="table-primary-secondary">
                                    <strong>{{ $policy['latest']['updated_at'] }}</strong>
                                </div>

                            @else
                                —
                            @endif

                        </td>

                        <td>

                            <span class="status-badge {{ $policy['status_value'] === 'active' ? 'badge-success' : ($policy['status_value'] === 'draft' ? 'badge-warning' : 'badge-neutral') }}">
                                {{ $policy['status'] }}
                            </span>

                        </td>

                        <td class="align-right">

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="policy-{{ $policy['id'] }}"
                            >
                                <i data-lucide="eye"></i>
                                View
                            </button>

                        </td>

                    </tr>

                @empty

                    <tr>
                        <td colspan="7">

                            <div class="table-empty">
                                <i data-lucide="file-text"></i>
                                <strong>No policies yet</strong>
                                <span>Create the first platform policy to start tracking its versions.</span>
                            </div>

                        </td>
                    </tr>

                @endforelse

            </tbody>

        </table>


        <div class="table-empty" data-table-empty="policies-table" hidden>
            <i data-lucide="search"></i>
            <strong>No matching policies</strong>
            <span>No policy records match the current search or filters.</span>
        </div>

    </div>

</section>


@foreach ($policies as $policy)

    @php
        $editFailed = old('form') === 'update' && (int) old('policy_id') === $policy['id'];
    @endphp

    <div class="modal-shell" data-modal="policy-{{ $policy['id'] }}" hidden>

        <button type="button" class="modal-backdrop" data-close-modal aria-label="Close policy details"></button>

        <section class="modal-card modal-wide" role="dialog" aria-modal="true">

            <div class="modal-heading">

                <div>
                    <span class="eyebrow">{{ $policy['code'] }}</span>
                    <h2>{{ $policy['title'] }}</h2>
                </div>

                <button type="button" class="icon-button" data-close-modal aria-label="Close">
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
                        <strong>{{ $policy['category'] }}</strong>
                    </div>

                    <div>
                        <span>Current version</span>
                        <strong>{{ $policy['latest']['version'] ?? '—' }}</strong>
                    </div>

                    <div>
                        <span>Version status</span>
                        <strong>{{ $policy['latest']['status'] ?? '—' }}</strong>
                    </div>

                    <div>
                        <span>Policy status</span>
                        <strong>{{ $policy['status'] }}</strong>
                    </div>

                    <div>
                        <span>Last updated</span>
                        <strong>{{ $policy['latest']['updated_at'] ?? '—' }}</strong>
                    </div>

                    <div>
                        <span>Updated by</span>
                        <strong>{{ $policy['latest']['author'] ?? '—' }}</strong>
                    </div>

                    <div>
                        <span>Published</span>
                        <strong>{{ $policy['latest']['published_at'] ?? 'Not published' }}</strong>
                    </div>

                </div>


                @if ($policy['latest']['summary'] ?? null)

                    <h3 class="section-subtitle">
                        Summary
                    </h3>

                    <p class="detail-note">
                        {{ $policy['latest']['summary'] }}
                    </p>

                @endif


                <h3 class="section-subtitle">
                    Current content
                </h3>

                <p class="detail-note">
                    {!! nl2br(e($policy['latest']['body'] ?? 'No content recorded.')) !!}
                </p>


                <h3 class="section-subtitle">
                    Version history
                </h3>

                <div class="table-wrap">

                    <table class="admin-table">

                        <thead>

                            <tr>
                                <th>Version</th>
                                <th>Status</th>
                                <th>Author</th>
                                <th>Created</th>
                                <th>Published</th>
                            </tr>

                        </thead>

                        <tbody>

                            @forelse ($policy['versions'] as $version)

                                <tr>
                                    <td>
                                        <strong>{{ $version['version'] }}</strong>
                                    </td>

                                    <td>

                                        <span class="status-badge {{ $version['status_value'] === 'published' ? 'badge-success' : ($version['status_value'] === 'draft' ? 'badge-warning' : 'badge-neutral') }}">
                                            {{ $version['status'] }}
                                        </span>

                                    </td>

                                    <td>
                                        {{ $version['author'] }}
                                    </td>

                                    <td>
                                        {{ $version['created_at'] }}
                                    </td>

                                    <td>
                                        {{ $version['published_at'] ?? '—' }}
                                    </td>
                                </tr>

                            @empty

                                <tr>
                                    <td colspan="5">
                                        No versions recorded for this policy.
                                    </td>
                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>


            <div class="modal-footer decision-footer">

                @if ($policy['status_value'] !== 'archived')
                    <button
                        type="button"
                        class="button button-secondary"
                        data-close-modal
                        data-open-modal="policy-edit-{{ $policy['id'] }}"
                    >
                        <i data-lucide="pencil"></i>
                        New Version
                    </button>
                @endif

                <button type="button" class="button button-ghost" data-close-modal>
                    Close
                </button>

            </div>


            @if ($policy['status_value'] !== 'archived' && ($policy['latest']['status_value'] ?? null) === 'draft')

                <form
                    method="POST"
                    action="{{ route('admin.policies.publish', $policy['id']) }}"
                    onsubmit="return confirm('Publish version {{ $policy['latest']['version'] }} of {{ $policy['code'] }}? Previously published versions will be archived.')"
                >
                    @csrf

                    <div class="modal-footer">
                        <button class="button button-primary" type="submit">
                            <i data-lucide="send"></i>
                            Publish {{ $policy['latest']['version'] }}
                        </button>
                    </div>
                </form>

            @endif


            @if ($policy['status_value'] !== 'archived')

                <form
                    method="POST"
                    action="{{ route('admin.policies.archive', $policy['id']) }}"
                    onsubmit="return confirm('Archive {{ $policy['code'] }}? Its versions stay preserved in the policy history.')"
                >
                    @csrf

                    <div class="modal-footer">
                        <button class="button button-danger-soft" type="submit">
                            <i data-lucide="archive"></i>
                            Archive Policy
                        </button>
                    </div>
                </form>

            @endif

        </section>

    </div>


    <div class="modal-shell" data-modal="policy-edit-{{ $policy['id'] }}" @if (! $editFailed) hidden @endif>

        <button type="button" class="modal-backdrop" data-close-modal aria-label="Close policy editor"></button>

        <section class="modal-card modal-wide" role="dialog" aria-modal="true">

            <div class="modal-heading">

                <div>
                    <span class="eyebrow">{{ $policy['code'] }} • {{ $policy['latest']['version'] ?? 'no version' }} is current</span>
                    <h2>New version draft</h2>
                </div>

                <button type="button" class="icon-button" data-close-modal aria-label="Close">
                    <i data-lucide="x"></i>
                </button>

            </div>


            <form method="POST" action="{{ route('admin.policies.update', $policy['id']) }}">

                @csrf
                @method('PATCH')

                <input type="hidden" name="form" value="update">
                <input type="hidden" name="policy_id" value="{{ $policy['id'] }}">


                <div class="announcement-form">

                    @if ($editFailed && $errors->any())
                        <div class="detail-note">
                            <strong>Please correct the following:</strong>
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif


                    <div class="form-grid two-column-form">

                        <label class="form-field"><span>Policy title</span><input type="text" class="text-field" name="title" value="{{ $editFailed ? old('title') : $policy['title'] }}" maxlength="180" required></label>

                        <label class="form-field"><span>Category</span><select class="select-field" name="category" required>@foreach ($categories as $category)<option value="{{ $category }}" @selected(($editFailed ? old('category') : $policy['category']) === $category)>{{ $category }}</option>@endforeach</select></label>

                        <label class="form-field"><span>New version label</span><input type="text" class="text-field" name="version" value="{{ $editFailed ? old('version') : $policy['next_version'] }}" maxlength="20" placeholder="e.g. v1.1" required></label>

                    </div>


                    <label class="form-field"><span>Summary (optional)</span><textarea class="text-field" name="summary" rows="3" maxlength="2000" placeholder="Short description of this revision">{{ $editFailed ? old('summary') : ($policy['latest']['summary'] ?? '') }}</textarea></label>

                    <label class="form-field announcement-message-field"><span>Policy content</span><textarea class="text-field" name="body" rows="10" placeholder="Write the updated policy content..." required>{{ $editFailed ? old('body') : ($policy['latest']['body'] ?? '') }}</textarea></label>

                </div>


                <p class="detail-note">
                    Saving creates a new draft version. Previous versions are kept
                    and remain visible in the policy history.
                </p>


                <div class="modal-footer decision-footer">
                    <button type="submit" class="button button-primary">
                        <i data-lucide="save"></i>
                        Save New Draft
                    </button>
                    <button type="button" class="button button-ghost" data-close-modal>
                        Cancel
                    </button>
                </div>

            </form>

        </section>

    </div>

@endforeach


<div class="modal-shell" data-modal="policy-create" @if (old('form') !== 'create') hidden @endif>

    <button type="button" class="modal-backdrop" data-close-modal aria-label="Close policy editor"></button>

    <section class="modal-card modal-wide" role="dialog" aria-modal="true">

        <div class="modal-heading">

            <div>
                <span class="eyebrow">System Management</span>
                <h2>Create policy</h2>
            </div>

            <button type="button" class="icon-button" data-close-modal aria-label="Close">
                <i data-lucide="x"></i>
            </button>

        </div>


        <form method="POST" action="{{ route('admin.policies.store') }}">

            @csrf

            <input type="hidden" name="form" value="create">


            <div class="announcement-form">

                @if (old('form') === 'create' && $errors->any())
                    <div class="detail-note">
                        <strong>Please correct the following:</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif


                <div class="form-grid two-column-form">

                    <label class="form-field"><span>Policy title</span><input type="text" class="text-field" name="title" value="{{ old('title') }}" maxlength="180" placeholder="e.g. Marketplace Policy" required></label>

                    <label class="form-field"><span>Category</span><select class="select-field" name="category" required>@foreach ($categories as $category)<option value="{{ $category }}" @selected(old('category', 'Marketplace') === $category)>{{ $category }}</option>@endforeach</select></label>

                    <label class="form-field"><span>Version label</span><input type="text" class="text-field" name="version" value="{{ old('version', 'v1.0') }}" maxlength="20" placeholder="e.g. v1.0" required></label>

                </div>


                <label class="form-field"><span>Summary (optional)</span><textarea class="text-field" name="summary" rows="3" maxlength="2000" placeholder="Short description of this policy">{{ old('summary') }}</textarea></label>

                <label class="form-field announcement-message-field"><span>Policy content</span><textarea class="text-field" name="body" rows="10" placeholder="Write the policy content..." required>{{ old('body') }}</textarea></label>

            </div>


            <div class="modal-footer decision-footer">
                <button type="submit" name="action" value="draft" class="button button-secondary">
                    <i data-lucide="save"></i>
                    Save as Draft
                </button>
                <button type="submit" name="action" value="publish" class="button button-primary">
                    <i data-lucide="send"></i>
                    Publish Now
                </button>
                <button type="button" class="button button-ghost" data-close-modal>
                    Cancel
                </button>
            </div>

        </form>

    </section>

</div>

@endsection
