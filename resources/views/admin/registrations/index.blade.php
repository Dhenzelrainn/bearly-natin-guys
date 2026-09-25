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
        <span class="hero-context-icon"><i data-lucide="clipboard-check"></i></span>
        <div><strong>{{ $applications->total() }}</strong><span>Applications awaiting review</span></div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Application queue</span><h2>Pending registrations</h2></div>
        <form method="GET" action="{{ route('admin.registrations') }}" class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search applicant..."></label>
            <select class="select-field" name="role">
                <option value="">All roles</option>
                <option value="buyer" @selected($filters['role'] === 'buyer')>Buyer</option>
                <option value="seller" @selected($filters['role'] === 'seller')>Seller</option>
                <option value="logistics" @selected($filters['role'] === 'logistics')>Logistics</option>
            </select>
            <select class="select-field" name="status">
                <option value="">All statuses</option>
                <option value="submitted" @selected($filters['status'] === 'submitted')>Submitted</option>
                <option value="under_review" @selected($filters['status'] === 'under_review')>Under Review</option>
                <option value="needs_revision" @selected($filters['status'] === 'needs_revision')>Needs Revision</option>
            </select>
            <button class="button button-secondary button-small" type="submit">Apply filters</button>
            @if (array_filter($filters))
                <a class="button button-ghost button-small" href="{{ route('admin.registrations') }}">Clear</a>
            @endif
        </form>
    </div>

    <div class="table-wrap">
        <table class="admin-table" id="registration-table">
            <thead><tr><th>Applicant</th><th>Role</th><th>Submitted</th><th>Requirements</th><th>Status</th><th class="align-right">Actions</th></tr></thead>
            <tbody>
            @forelse ($applications as $application)
                @php
                    $displayName = $application->business_name ?: $application->user->name;
                    $roleLabel = ucfirst($application->requestedRole->name);
                    $statusLabel = match ($application->status) {
                        'under_review' => 'Under Review',
                        'needs_revision' => 'Needs Revision',
                        default => 'Submitted',
                    };
                @endphp
                <tr>
                    <td><div class="identity-cell"><span class="avatar avatar-soft">{{ collect(explode(' ', $displayName))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') }}</span><div><strong>{{ $displayName }}</strong><small>{{ $application->application_no }} • {{ $application->user->email }}</small></div></div></td>
                    <td><span class="role-badge role-{{ $application->requestedRole->name }}">{{ $roleLabel }}</span></td>
                    <td>{{ $application->submitted_at?->format('M j, Y') ?? 'Not submitted' }}</td>
                    <td><div class="document-pills">@forelse ($application->documents as $document)<span><i data-lucide="file-check-2"></i>{{ str($document->document_type)->replace('_', ' ')->title() }}</span>@empty<span>No documents</span>@endforelse</div></td>
                    <td><span class="status-badge {{ $application->status === 'submitted' ? 'badge-warning' : 'badge-info' }}">{{ $statusLabel }}</span></td>
                    <td class="align-right"><div class="row-actions">
                        <button type="button" class="button button-ghost button-small" data-open-modal="application-{{ $application->id }}"><i data-lucide="eye"></i> Review</button>
                        <button type="button" class="icon-button table-more" data-registration-menu-toggle="registration-menu-{{ $application->id }}" aria-label="More actions for {{ $displayName }}" aria-expanded="false"><i data-lucide="ellipsis"></i></button>
                        <div class="registration-action-menu" data-registration-menu="registration-menu-{{ $application->id }}" hidden>
                            <button type="button" data-open-modal="application-{{ $application->id }}"><i data-lucide="eye"></i><span>View application</span></button>
                            <button type="button" data-copy-application-id="{{ $application->application_no }}"><i data-lucide="copy"></i><span>Copy application ID</span></button>
                        </div>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="table-empty"><i data-lucide="search-x"></i><strong>No applications found</strong><span>Try a different search or filter.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($applications->hasPages())
        <div class="panel-footer">{{ $applications->links() }}</div>
    @endif
</section>

@foreach ($applications as $application)
@php
    $displayName = $application->business_name ?: $application->user->name;
    $roleLabel = ucfirst($application->requestedRole->name);
    $statusLabel = match ($application->status) {
        'under_review' => 'Under Review',
        'needs_revision' => 'Needs Revision',
        default => 'Submitted',
    };
@endphp
<div class="modal-shell" data-modal="application-{{ $application->id }}" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card modal-wide" role="dialog" aria-modal="true" aria-label="Review {{ $displayName }}">
        <div class="modal-heading"><div><span class="eyebrow">{{ $application->application_no }}</span><h2>Review {{ $roleLabel }} application</h2></div><button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <div class="review-grid">
            <div class="review-profile">
                <span class="avatar avatar-large avatar-warm">{{ collect(explode(' ', $displayName))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') }}</span>
                <h3>{{ $displayName }}</h3><p>{{ $application->user->email }}</p>
                <span class="role-badge role-{{ $application->requestedRole->name }}">{{ $roleLabel }}</span>
            </div>
            <div class="review-details">
                <div class="detail-grid">
                    <div><span>Application ID</span><strong>{{ $application->application_no }}</strong></div>
                    <div><span>Submitted</span><strong>{{ $application->submitted_at?->format('M j, Y g:i A') ?? 'Not submitted' }}</strong></div>
                    <div><span>Business category</span><strong>{{ $application->businessCategory?->name ?? '—' }}</strong></div>
                    <div><span>Review status</span><strong>{{ $statusLabel }}</strong></div>
                </div>
                <h3 class="section-subtitle">Verification documents</h3>
                <div class="document-preview-grid">
                    @forelse ($application->documents as $document)
                        <div>
                            <a class="document-preview" href="{{ route('admin.application-documents.show', [$application, $document]) }}" target="_blank" rel="noopener">
                                <span><i data-lucide="file-text"></i></span>
                                <div>
                                    <strong>{{ str($document->document_type)->replace('_', ' ')->title() }}</strong>
                                    <small>{{ $document->original_name }} • {{ str($document->verification_status)->replace('_', ' ')->title() }}</small>
                                </div>
                                <i data-lucide="external-link"></i>
                            </a>
                            @if ($document->rejection_reason)
                                <small>{{ $document->rejection_reason }}</small>
                            @endif
                            <div class="row-actions">
                                <form method="POST" action="{{ route('admin.application-documents.update', [$application, $document]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="verification_status" value="verified">
                                    <button type="submit" class="button button-primary button-small"><i data-lucide="badge-check"></i> Verify</button>
                                </form>
                                <form method="POST" action="{{ route('admin.application-documents.update', [$application, $document]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="verification_status" value="rejected">
                                    <input class="select-field" name="rejection_reason" maxlength="1000" required placeholder="Reason for rejection">
                                    <button type="submit" class="button button-danger-soft button-small"><i data-lucide="circle-x"></i> Reject</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p>No verification documents were submitted.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="modal-footer decision-footer">
            <form method="POST" action="{{ route('admin.applications.request-revision', $application) }}">
                @csrf
                <input class="select-field" name="revision_notes" maxlength="1000" required placeholder="Required revisions">
                <button type="submit" class="button button-secondary"><i data-lucide="file-warning"></i> Request revision</button>
            </form>
            <form method="POST" action="{{ route('admin.applications.reject', $application) }}">
                @csrf
                <input class="select-field" name="reason" maxlength="1000" required placeholder="Reason for rejection">
                <button type="submit" class="button button-danger-soft"><i data-lucide="circle-x"></i> Reject</button>
            </form>
            <form method="POST" action="{{ route('admin.applications.approve', $application) }}">
                @csrf
                <button type="submit" class="button button-primary"><i data-lucide="circle-check"></i> Approve application</button>
            </form>
        </div>
    </section>
</div>
@endforeach
@endsection
