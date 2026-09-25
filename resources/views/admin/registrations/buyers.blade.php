@extends('layouts.admin')

@section('title', 'Buyer Applications')
@section('page-title', 'Buyer Applications')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Registration Management</span>
        <h1>Buyer applications</h1>
        <p>Review Buyer account applications, verify submitted personal information and identification documents, and approve or disapprove registrations.</p>
    </div>

    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="user-plus"></i></span>
        <div>
            <strong>{{ count($applications) }}</strong>
            <span>Buyer Applications</span>
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
            <span class="eyebrow">Buyer registration queue</span>
            <h2>Applications for review</h2>
        </div>

        <div class="table-toolbar">
            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>
                <input
                    type="search"
                    placeholder="Search buyer..."
                    data-table-search="buyer-applications-table"
                >
            </label>

            <select
                class="select-field"
                data-table-filter="buyer-applications-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Pending">Pending</option>
                <option value="Needs Review">Needs Review</option>
            </select>
        </div>
    </div>

    <div class="table-wrap">
        <table class="admin-table" id="buyer-applications-table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    
                    <th>Contact</th>
                    <th>Location</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>

            <tbody>
            @foreach ($applications as $application)
                <tr
                    data-table-row
                    data-status="{{ $application['status'] }}"
                    data-search="{{ strtolower(
                        $application['name'].' '.
                        $application['email'].' '.
                        $application['contact'].' '.
                        $application['municipality'].' '.
                        $application['province'].' '.
                        $application['business_name'].' '.
                        $application['business_category']
                    ) }}"
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
                        <div class="table-primary-secondary">
                            <strong>{{ $application['contact'] }}</strong>
                            <small>Age {{ $application['age'] }}</small>
                        </div>
                    </td>

                    <td>
                        <div class="table-primary-secondary">
                            <strong>{{ $application['municipality'] }}</strong>
                            <small>{{ $application['province'] }}</small>
                        </div>
                    </td>

                    <td>{{ $application['submitted'] }}</td>

                    <td>
                        <span class="status-badge {{ $application['status'] === 'Pending' ? 'badge-warning' : 'badge-info' }}">
                            {{ $application['status'] }}
                        </span>
                    </td>

                    <td class="align-right">
                        <button
                            type="button"
                            class="button button-ghost button-small"
                            data-open-modal="buyer-application-{{ $application['database_id'] }}"
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
                <strong>No pending buyer applications</strong>
                <span>New registrations will appear here automatically from the database.</span>
            </div>
        @else
            <div class="table-empty" data-table-empty="buyer-applications-table" hidden>
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
    data-modal="buyer-application-{{ $application['database_id'] }}"
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
                <h2>Review Buyer application</h2>
            </div>

            <button class="icon-button" type="button" data-close-modal aria-label="Close">
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
                
                <small>{{ $application['email'] }}</small>

                <span class="role-badge role-buyer">
                    Buyer
                </span>
            </div>

            <div class="review-details">
                <h3 class="section-subtitle">Personal information</h3>

                <div class="detail-grid">
                    <div><span>Last name</span><strong>{{ $application['last_name'] }}</strong></div>
                    <div><span>First name</span><strong>{{ $application['first_name'] }}</strong></div>
                    <div><span>Middle initial</span><strong>{{ $application['middle_initial'] }}</strong></div>
                    <div><span>Sex</span><strong>{{ $application['sex'] }}</strong></div>
                    <div><span>Email</span><strong>{{ $application['email'] }}</strong></div>
                    <div><span>Contact number</span><strong>{{ $application['contact'] }}</strong></div>
                    <div><span>Birthday</span><strong>{{ $application['birthday'] }}</strong></div>
                    <div><span>Age</span><strong>{{ $application['age'] }}</strong></div>
                </div>

                

                <h3 class="section-subtitle">Address</h3>

                <div class="detail-grid">
                    <div><span>Province</span><strong>{{ $application['province'] }}</strong></div>
                    <div><span>Municipality / City</span><strong>{{ $application['municipality'] }}</strong></div>
                    <div><span>Barangay</span><strong>{{ $application['barangay'] }}</strong></div>
                    <div><span>Street address</span><strong>{{ $application['street_address'] }}</strong></div>
                    <div><span>Application submitted</span><strong>{{ $application['submitted'] }}</strong></div>
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
                            <span><i data-lucide="file-text"></i></span>
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
