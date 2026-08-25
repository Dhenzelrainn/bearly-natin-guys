@extends('layouts.admin')

@section('title', 'Account Registrations')
@section('page-title', 'Manage Account Registrations')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Module 02</span>
        <h1>Manage account registrations</h1>
        <p>Review Buyer, Seller, and Courier applications, verify submitted requirements, and simulate approval decisions.</p>
    </div>
    <div class="hero-summary-card">
        <span class="metric-icon"><i data-lucide="clipboard-check"></i></span>
        <div><strong>{{ count($applications) }}</strong><small>Applications in mock queue</small></div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Application queue</span>
            <h2>Pending registrations</h2>
        </div>
        <div class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Search applicant..." data-table-search="registration-table"></label>
            <select class="select-field" data-table-filter="registration-table" data-filter-key="role">
                <option value="">All roles</option><option>Buyer</option><option>Seller</option><option>Courier</option>
            </select>
            <select class="select-field" data-table-filter="registration-table" data-filter-key="status">
                <option value="">All statuses</option><option>Pending</option><option>Needs Review</option>
            </select>
        </div>
    </div>

    <div class="table-wrap">
        <table class="admin-table" id="registration-table">
            <thead><tr><th>Applicant</th><th>Role</th><th>Submitted</th><th>Requirements</th><th>Status</th><th class="align-right">Actions</th></tr></thead>
            <tbody>
            @foreach ($applications as $application)
                <tr data-table-row data-role="{{ $application['role'] }}" data-status="{{ $application['status'] }}" data-search="{{ strtolower($application['name'].' '.$application['email'].' '.$application['id']) }}">
                    <td><div class="identity-cell"><span class="avatar avatar-soft">{{ collect(explode(' ', $application['name']))->map(fn($part) => strtoupper(substr($part,0,1)))->take(2)->implode('') }}</span><div><strong>{{ $application['name'] }}</strong><small>{{ $application['id'] }} • {{ $application['email'] }}</small></div></div></td>
                    <td><span class="role-badge role-{{ strtolower($application['role']) }}">{{ $application['role'] }}</span></td>
                    <td>{{ $application['submitted'] }}</td>
                    <td><div class="document-pills">@foreach ($application['documents'] as $doc)<span><i data-lucide="file-check-2"></i>{{ $doc }}</span>@endforeach</div></td>
                    <td><span class="status-badge {{ $application['status'] === 'Pending' ? 'badge-warning' : 'badge-info' }}">{{ $application['status'] }}</span></td>
                    <td class="align-right"><div class="row-actions">
                        <button type="button" class="button button-ghost button-small" data-open-modal="application-{{ $loop->index }}"><i data-lucide="eye"></i> Review</button>
                        <button type="button" class="icon-button table-more" data-mock-action="More application actions opened."><i data-lucide="ellipsis"></i></button>
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="table-empty" data-table-empty="registration-table" hidden><i data-lucide="search-x"></i><strong>No applications found</strong><span>Try a different search or filter.</span></div>
    </div>
</section>

@foreach ($applications as $application)
<div class="modal-shell" data-modal="application-{{ $loop->index }}" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card modal-wide" role="dialog" aria-modal="true" aria-label="Review {{ $application['name'] }}">
        <div class="modal-heading">
            <div><span class="eyebrow">{{ $application['id'] }}</span><h2>Review {{ $application['role'] }} application</h2></div>
            <button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button>
        </div>
        <div class="review-grid">
            <div class="review-profile">
                <span class="avatar avatar-large avatar-warm">{{ collect(explode(' ', $application['name']))->map(fn($part) => strtoupper(substr($part,0,1)))->take(2)->implode('') }}</span>
                <h3>{{ $application['name'] }}</h3><p>{{ $application['email'] }}</p>
                <span class="role-badge role-{{ strtolower($application['role']) }}">{{ $application['role'] }}</span>
            </div>
            <div class="review-details">
                <div class="detail-grid">
                    <div><span>Application ID</span><strong>{{ $application['id'] }}</strong></div>
                    <div><span>Submitted</span><strong>{{ $application['submitted'] }}</strong></div>
                    <div><span>Category / Vehicle</span><strong>{{ $application['category'] }}</strong></div>
                    <div><span>Review status</span><strong>{{ $application['status'] }}</strong></div>
                </div>
                <h3 class="section-subtitle">Verification documents</h3>
                <div class="document-preview-grid">
                    @foreach ($application['documents'] as $doc)
                        <button type="button" class="document-preview" data-mock-action="{{ $doc }} preview opened."><span><i data-lucide="file-text"></i></span><div><strong>{{ $doc }}</strong><small>Mock verification file • PDF/JPG</small></div><i data-lucide="maximize-2"></i></button>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="modal-footer decision-footer">
            <button type="button" class="button button-danger-soft" data-close-modal data-open-modal="email-decision" data-decision="Disapproved" data-applicant="{{ $application['name'] }}"><i data-lucide="circle-x"></i> Disapprove</button>
            <button type="button" class="button button-primary" data-close-modal data-open-modal="email-decision" data-decision="Approved" data-applicant="{{ $application['name'] }}"><i data-lucide="circle-check"></i> Approve application</button>
        </div>
    </section>
</div>
@endforeach

<div class="modal-shell" data-modal="email-decision" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-label="Decision email preview">
        <div class="modal-heading"><div><span class="eyebrow">Mock email notification</span><h2>Notify applicant</h2></div><button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <label class="form-field"><span>Recipient</span><input type="text" data-decision-recipient value="Applicant" readonly></label>
        <label class="form-field"><span>Decision</span><input type="text" data-decision-status value="Approved" readonly></label>
        <label class="form-field"><span>Email message</span><textarea rows="5" data-decision-message>Thank you for submitting your Bearly registration. Your application has been reviewed by the administrator.</textarea></label>
        <div class="modal-footer"><button class="button button-secondary" type="button" data-close-modal>Cancel</button><button class="button button-primary" type="button" data-close-modal data-mock-action="Decision saved and mock email notification sent."><i data-lucide="send"></i> Confirm & send</button></div>
    </section>
</div>
@endsection
