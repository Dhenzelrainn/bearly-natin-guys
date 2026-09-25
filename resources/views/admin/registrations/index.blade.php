@extends('layouts.admin')

@section('title', 'Account Registrations')
@section('page-title', 'Manage Account Registrations')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Registration Management</span>
        <h1>Manage account registrations</h1>
        <p>Review Buyer, Seller, and Logistics applications before granting platform access. Rider applications are approved by the selected Logistics partner.</p>
    </div>

    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="clipboard-check"></i>
        </span>

        <div>
            <strong>{{ count($applications) }}</strong>
            <span>Pending applications</span>
        </div>
    </div>
</section>

@if (session('success'))
    <div class="info-box" role="status">
        <strong>Done.</strong> {{ session('success') }}
    </div>
@endif

@if ($errors->any())
    <div class="info-box" role="alert">
        <strong>Action could not be completed.</strong> {{ $errors->first() }}
    </div>
@endif

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Application queue</span>
            <h2>Pending registrations</h2>
        </div>

        <div class="table-toolbar">
            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>
                <input
                    type="search"
                    placeholder="Search applicant..."
                    data-table-search="registration-table"
                >
            </label>

            <select
                class="select-field"
                data-table-filter="registration-table"
                data-filter-key="role"
            >
                <option value="">All roles</option>
                <option>Buyer</option>
                <option>Seller</option>
                <option>Logistics</option>
            </select>

            <select
                class="select-field"
                data-table-filter="registration-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option>Pending</option>
                <option>Needs Review</option>
            </select>
        </div>
    </div>

    <div class="table-wrap">
        <table class="admin-table" id="registration-table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Role</th>
                    <th>Submitted</th>
                    <th>Requirements</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>

            <tbody>
            @foreach ($applications as $application)
                <tr
                    data-table-row
                    data-role="{{ $application['role'] }}"
                    data-status="{{ $application['status'] }}"
                    data-search="{{ strtolower($application['name'].' '.$application['email'].' '.$application['id']) }}"
                >
                    <td>
                        <div class="identity-cell">
                            <span class="avatar avatar-soft">
                                {{ collect(explode(' ', $application['name']))
                                    ->filter()
                                    ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                                    ->take(2)
                                    ->implode('') }}
                            </span>

                            <div>
                                <strong>{{ $application['name'] }}</strong>
                                <small>{{ $application['id'] }} • {{ $application['email'] }}</small>
                            </div>
                        </div>
                    </td>

                    <td>
                        <span class="role-badge role-{{ strtolower($application['role']) }}">
                            {{ $application['role'] }}
                        </span>
                    </td>

                    <td>{{ $application['submitted'] }}</td>

                    <td>
                        <div class="document-pills">
                            @forelse ($application['documents'] as $document)
                                <span>
                                    <i data-lucide="file-check-2"></i>
                                    {{ $document['label'] }}
                                </span>
                            @empty
                                <span>No documents</span>
                            @endforelse
                        </div>
                    </td>

                    <td>
                        <span class="status-badge {{ $application['status'] === 'Pending' ? 'badge-warning' : 'badge-info' }}">
                            {{ $application['status'] }}
                        </span>
                    </td>

                    <td class="align-right">
                        <button
                            type="button"
                            class="button button-ghost button-small"
                            data-open-modal="application-{{ $application['database_id'] }}"
                        >
                            <i data-lucide="eye"></i>
                            Review
                        </button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if (count($applications) === 0)
            <div class="table-empty">
                <i data-lucide="inbox"></i>
                <strong>No pending applications</strong>
                <span>New Buyer, Seller, or Logistics applications will appear here automatically.</span>
            </div>
        @else
            <div
                class="table-empty"
                data-table-empty="registration-table"
                hidden
            >
                <i data-lucide="search-x"></i>
                <strong>No applications found</strong>
                <span>Try a different search or filter.</span>
            </div>
        @endif
    </div>
</section>

@foreach ($applications as $application)
<div
    class="modal-shell"
    data-modal="application-{{ $application['database_id'] }}"
    hidden
>
    <button class="modal-backdrop" type="button" data-close-modal></button>

    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
        aria-label="Review {{ $application['name'] }}"
    >
        <div class="modal-heading">
            <div>
                <span class="eyebrow">{{ $application['id'] }}</span>
                <h2>Review {{ $application['role'] }} application</h2>
            </div>

            <button
                class="icon-button"
                type="button"
                data-close-modal
                aria-label="Close"
            >
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="review-grid">
            <div class="review-profile">
                <span class="avatar avatar-large avatar-warm">
                    {{ collect(explode(' ', $application['name']))
                        ->filter()
                        ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                        ->take(2)
                        ->implode('') }}
                </span>

                <h3>{{ $application['name'] }}</h3>
                <p>{{ $application['email'] }}</p>

                <span class="role-badge role-{{ strtolower($application['role']) }}">
                    {{ $application['role'] }}
                </span>
            </div>

            <div class="review-details">
                <div class="detail-grid">
                    <div>
                        <span>Application ID</span>
                        <strong>{{ $application['id'] }}</strong>
                    </div>

                    <div>
                        <span>Submitted</span>
                        <strong>{{ $application['submitted'] }}</strong>
                    </div>

                    <div>
                        <span>Category</span>
                        <strong>{{ $application['category'] }}</strong>
                    </div>

                    <div>
                        <span>Review status</span>
                        <strong>{{ $application['status'] }}</strong>
                    </div>
                </div>

                <h3 class="section-subtitle">Verification documents</h3>

                <div class="document-preview-grid">
                    @forelse ($application['documents'] as $document)
                        <a
                            class="document-preview"
                            href="{{ route('admin.applications.document', [
                                'user' => $application['database_id'],
                                'type' => $document['type'],
                            ]) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            <span>
                                <i data-lucide="file-text"></i>
                            </span>

                            <div>
                                <strong>{{ $document['label'] }}</strong>
                                <small>Open submitted file</small>
                            </div>

                            <i data-lucide="external-link"></i>
                        </a>
                    @empty
                        <p>No uploaded document is available for this application.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="modal-footer decision-footer">
            <form
                method="POST"
                action="{{ route('admin.applications.reject', $application['database_id']) }}"
            >
                @csrf

                <label class="form-field">
                    <span>Reason for disapproval</span>
                    <textarea
                        name="reason"
                        rows="2"
                        required
                        placeholder="Enter the reason for rejecting this application."
                    ></textarea>
                </label>

                <button type="submit" class="button button-danger-soft">
                    <i data-lucide="circle-x"></i>
                    Disapprove
                </button>
            </form>

            <form
                method="POST"
                action="{{ route('admin.applications.approve', $application['database_id']) }}"
            >
                @csrf

                <button type="submit" class="button button-primary">
                    <i data-lucide="circle-check"></i>
                    Approve application
                </button>
            </form>
        </div>
    </section>
</div>
@endforeach
@endsection
