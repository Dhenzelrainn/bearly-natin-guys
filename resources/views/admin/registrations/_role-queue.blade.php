<section class="page-hero">
    <div>
        <span class="eyebrow">Registration Management</span>
        <h1>{{ $roleLabel }} applications</h1>
        <p>{{ $description }}</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="{{ $icon }}"></i></span>
        <div><strong>{{ $applications->total() }}</strong><span>{{ $roleLabel }} applications awaiting review</span></div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">{{ $roleLabel }} registration queue</span><h2>Applications for review</h2></div>
        <form method="GET" action="{{ route($queueRoute) }}" class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search {{ strtolower($roleLabel) }}..."></label>
            @if ($role === 'buyer')
                <select class="select-field" name="sex">
                    <option value="">All sexes</option>
                    <option value="female" @selected($filters['sex'] === 'female')>Female</option>
                    <option value="male" @selected($filters['sex'] === 'male')>Male</option>
                    <option value="prefer_not_to_say" @selected($filters['sex'] === 'prefer_not_to_say')>Prefer not to say</option>
                </select>
            @endif
            @if ($role === 'seller')
                <select class="select-field" name="category">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            @endif
            <select class="select-field" name="status">
                <option value="">All statuses</option>
                <option value="submitted" @selected($filters['status'] === 'submitted')>Submitted</option>
                <option value="under_review" @selected($filters['status'] === 'under_review')>Under Review</option>
                <option value="needs_revision" @selected($filters['status'] === 'needs_revision')>Needs Revision</option>
            </select>
            <button class="button button-secondary button-small" type="submit">Apply filters</button>
            @if (array_filter($filters))
                <a class="button button-ghost button-small" href="{{ route($queueRoute) }}">Clear</a>
            @endif
        </form>
    </div>

    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Applicant</th><th>Business / Category</th><th>Contact</th><th>Submitted</th><th>Requirements</th><th>Status</th><th class="align-right">Actions</th></tr></thead>
            <tbody>
            @forelse ($applications as $application)
                @php
                    $displayName = $application->business_name ?: $application->user->name;
                    $statusLabel = match ($application->status) {
                        'under_review' => 'Under Review',
                        'needs_revision' => 'Needs Revision',
                        default => 'Submitted',
                    };
                @endphp
                <tr>
                    <td><div class="identity-cell"><span class="avatar avatar-soft">{{ collect(explode(' ', $displayName))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') }}</span><div><strong>{{ $displayName }}</strong><small>{{ $application->application_no }} • {{ $application->user->name }}</small></div></div></td>
                    <td><strong>{{ $application->business_name ?? '—' }}</strong><small>{{ $application->businessCategory?->name ?? ucfirst($application->user->sex ?? '—') }}</small></td>
                    <td><strong>{{ $application->user->email }}</strong><small>{{ $application->user->contact_number }}</small></td>
                    <td>{{ $application->submitted_at?->format('M j, Y') ?? 'Not submitted' }}</td>
                    <td><div class="document-pills">@forelse ($application->documents as $document)<span><i data-lucide="file-check-2"></i>{{ str($document->document_type)->replace('_', ' ')->title() }}</span>@empty<span>No documents</span>@endforelse</div></td>
                    <td><span class="status-badge {{ $application->status === 'submitted' ? 'badge-warning' : 'badge-info' }}">{{ $statusLabel }}</span></td>
                    <td class="align-right"><button type="button" class="button button-ghost button-small" data-open-modal="role-application-{{ $application->id }}"><i data-lucide="eye"></i> Review</button></td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="table-empty"><i data-lucide="search-x"></i><strong>No {{ $roleLabel }} applications found</strong><span>Try a different search or filter.</span></div></td></tr>
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
    $statusLabel = match ($application->status) {
        'under_review' => 'Under Review',
        'needs_revision' => 'Needs Revision',
        default => 'Submitted',
    };
@endphp
<div class="modal-shell" data-modal="role-application-{{ $application->id }}" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card modal-wide" role="dialog" aria-modal="true" aria-label="Review {{ $displayName }}">
        <div class="modal-heading"><div><span class="eyebrow">{{ $application->application_no }}</span><h2>Review {{ $roleLabel }} application</h2></div><button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <div class="review-grid">
            <div class="review-profile">
                <span class="avatar avatar-large avatar-warm">{{ collect(explode(' ', $displayName))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') }}</span>
                <h3>{{ $displayName }}</h3><p>{{ $application->user->email }}</p>
                <span class="role-badge role-{{ $role }}">{{ $roleLabel }}</span>
            </div>
            <div class="review-details">
                <div class="detail-grid">
                    <div><span>Applicant</span><strong>{{ $application->user->name }}</strong></div>
                    <div><span>Contact</span><strong>{{ $application->user->contact_number }}</strong></div>
                    <div><span>Location</span><strong>{{ collect([$application->user->barangay, $application->user->city, $application->user->province])->filter()->implode(', ') }}</strong></div>
                    <div><span>Review status</span><strong>{{ $statusLabel }}</strong></div>
                </div>
                <h3 class="section-subtitle">Verification documents</h3>
                <div class="document-preview-grid">
                    @forelse ($application->documents as $document)
                        <div>
                            <a class="document-preview" href="{{ route('admin.application-documents.show', [$application, $document]) }}" target="_blank" rel="noopener"><span><i data-lucide="file-text"></i></span><div><strong>{{ str($document->document_type)->replace('_', ' ')->title() }}</strong><small>{{ $document->original_name }} • {{ str($document->verification_status)->replace('_', ' ')->title() }}</small></div><i data-lucide="external-link"></i></a>
                            @if ($document->rejection_reason)<small>{{ $document->rejection_reason }}</small>@endif
                            <div class="row-actions">
                                <form method="POST" action="{{ route('admin.application-documents.update', [$application, $document]) }}">@csrf @method('PATCH')<input type="hidden" name="verification_status" value="verified"><button type="submit" class="button button-primary button-small">Verify</button></form>
                                <form method="POST" action="{{ route('admin.application-documents.update', [$application, $document]) }}">@csrf @method('PATCH')<input type="hidden" name="verification_status" value="rejected"><input class="select-field" name="rejection_reason" maxlength="1000" required placeholder="Reason for rejection"><button type="submit" class="button button-danger-soft button-small">Reject</button></form>
                            </div>
                        </div>
                    @empty
                        <p>No verification documents were submitted.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="modal-footer decision-footer">
            <form method="POST" action="{{ route('admin.applications.request-revision', $application) }}">@csrf<input class="select-field" name="revision_notes" maxlength="1000" required placeholder="Required revisions"><button type="submit" class="button button-secondary">Request revision</button></form>
            <form method="POST" action="{{ route('admin.applications.reject', $application) }}">@csrf<input class="select-field" name="reason" maxlength="1000" required placeholder="Reason for rejection"><button type="submit" class="button button-danger-soft">Reject</button></form>
            <form method="POST" action="{{ route('admin.applications.approve', $application) }}">@csrf<button type="submit" class="button button-primary">Approve application</button></form>
        </div>
    </section>
</div>
@endforeach
