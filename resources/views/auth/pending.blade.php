@extends('layouts.auth')

@php
    $fallback = session('marketplace_application', session('logistics_application', session('rider_application', [])));
    $review = $applicationReview ?? null;
    $role = $review['role'] ?? ($fallback['role'] ?? (session('logistics_application') ? 'logistics' : (session('rider_application') ? 'rider' : 'buyer')));
    $state = $review['state'] ?? 'pending';

    if ($review === null && $fallback === []) {
        $state = 'unavailable';
    }

    $isHistoricalBuyerApplication = $role === 'buyer' && in_array($state, ['pending', 'under_review', 'needs_revision'], true);
    $isApproved = $state === 'approved';
    $needsRevision = $state === 'needs_revision';
    $isFinal = (bool) ($review['final'] ?? ($state === 'unavailable'));
    $statusUrl = $review && ! $isHistoricalBuyerApplication ? route('application.pending.status') : null;
    $statusLabel = $review['status_label'] ?? ($state === 'unavailable' ? 'Status unavailable' : 'Pending review');
    $heading = $review['heading'] ?? ($state === 'unavailable'
        ? 'Application status unavailable'
        : ($role === 'buyer' ? 'Historical Buyer application status' : 'Application received'));
    $message = $review['message'] ?? ($state === 'unavailable'
        ? 'Open this page from the registration or sign-in flow so Bearly can securely check the correct application.'
        : ($role === 'buyer'
            ? 'This Buyer application was submitted before immediate activation took effect. Its status and documents remain on record, but it is no longer in the active Admin registration queues. New Buyer registrations activate after email verification; contact Support about this existing account.'
            : 'Your application was submitted successfully. The reviewing team will check your details before dashboard access is activated.'));
    $roleLabel = $review['role_label'] ?? ucfirst($role);
    $applicant = $review['name'] ?? ($fallback['name'] ?? ($fallback['full_name'] ?? 'Applicant'));
    $company = $review['company'] ?? ($fallback['business_name'] ?? ($fallback['logistics_partner'] ?? null));
    $authority = $review['authority'] ?? ($role === 'rider'
        ? ($fallback['logistics_partner'] ?? 'Selected Logistics provider')
        : 'Bearly Administrator');
    $reviewNote = $review['review_note'] ?? null;
    $statusClass = match ($state) {
        'approved' => 'is-approved',
        'rejected' => 'is-rejected',
        'needs_revision' => 'is-revision',
        'under_review' => 'is-review',
        'unavailable' => 'is-unavailable',
        default => 'is-pending',
    };
    $kicker = $isHistoricalBuyerApplication
        ? 'Historical application'
        : ($isApproved
            ? 'Account Verified'
            : ($state === 'unavailable'
                ? 'Status unavailable'
                : ($state === 'rejected' ? 'Application not approved' : 'Application submitted')));
@endphp

@section('title', $heading.' | Bearly')

@section('header-action')
    <a href="{{ route('login') }}" class="header-link">{{ $isApproved ? 'Sign in' : 'Return to sign in' }}</a>
@endsection

@push('scripts')
    @vite('resources/js/application-pending.js')
@endpush

@section('content')
<section
    class="login-page pending-status-page"
    aria-labelledby="pending-title"
    data-application-status
    data-final="{{ $isFinal ? '1' : '0' }}"
    data-poll-interval="12000"
    @if($statusUrl) data-status-url="{{ $statusUrl }}" @endif
>
    <div class="pending-receipt">
        <span class="pending-check {{ $statusClass }}" aria-hidden="true">
            {{ $isApproved ? '✓' : ($state === 'rejected' ? '!' : ($state === 'unavailable' ? '?' : '…')) }}
        </span>
        <p class="pending-eyebrow">{{ $roleLabel }} · {{ $kicker }}</p>
        <h1 id="pending-title">{{ $heading }}</h1>
        <p class="pending-intro">{{ $message }}</p>

        <dl class="pending-details">
            <div><dt>Applicant</dt><dd>{{ $applicant }}</dd></div>
            @if($company)<div><dt>Business</dt><dd>{{ $company }}</dd></div>@endif
            <div><dt>Application status</dt><dd><span class="pending-status {{ $statusClass }}">{{ $statusLabel }}</span></dd></div>
            @if($isHistoricalBuyerApplication)
                <div><dt>Record</dt><dd>Historical application; not in the active Admin queue</dd></div>
            @else
                <div><dt>Approval authority</dt><dd>{{ $authority }}</dd></div>
            @endif
            @if(!empty($review['submitted_at']))<div><dt>Submitted</dt><dd>{{ $review['submitted_at'] }}</dd></div>@endif
            @if(!empty($review['application_no']))<div><dt>Application</dt><dd>{{ $review['application_no'] }}</dd></div>@endif
        </dl>

        @if($reviewNote)
            <div class="pending-review-note" role="note">
                <strong>{{ $needsRevision ? 'What needs updating' : 'Decision note' }}</strong>
                <p>{{ $reviewNote }}</p>
            </div>
        @endif

        @if(!$isFinal && $statusUrl)
            <p class="pending-live" data-application-live aria-live="polite">Checking for a review decision automatically.</p>
        @endif

        @if($isApproved)
            <a class="primary-button pending-signin" href="{{ route('login') }}">Proceed to Sign In <span aria-hidden="true">→</span></a>
        @else
            <a class="primary-button pending-signin" href="{{ route('login') }}">Return to sign in <span aria-hidden="true">→</span></a>
        @endif
        <p class="pending-note">Need help? <a href="{{ route('contact') }}">Contact Bearly</a></p>
    </div>
</section>
@endsection
