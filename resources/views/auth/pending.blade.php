@extends('layouts.auth')

@php
    $fallback = session('marketplace_application', session('logistics_application', session('rider_application', [])));
    $review = $applicationReview ?? null;
    $role = $review['role'] ?? ($fallback['role'] ?? (session('logistics_application') ? 'logistics' : (session('rider_application') ? 'rider' : 'buyer')));
    $state = $review['state'] ?? 'pending';

    if ($review === null && $fallback === []) {
        $state = 'unavailable';
    }

    $isBuyer = $role === 'buyer' && $state !== 'unavailable';
    $isApproved = $state === 'approved';
    $isRejected = $state === 'rejected';
    $needsRevision = $state === 'needs_revision';
    $isFinal = (bool) ($review['final'] ?? ($state === 'unavailable'));
    $statusUrl = $review ? route('application.pending.status') : null;
    $statusLabel = $review['status_label'] ?? ($state === 'unavailable' ? 'Status unavailable' : 'Pending review');
    $heading = $review['heading'] ?? ($state === 'unavailable' ? 'Application status unavailable' : 'Application received');
    $message = $review['message'] ?? ($state === 'unavailable'
        ? 'Open this page from the registration or sign-in flow so Bearly can securely check the correct application.'
        : 'Your application was submitted successfully. The reviewing team will check your details before dashboard access is activated.');
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
    $timelineReviewState = $isApproved
        ? 'is-complete'
        : ($isRejected || $needsRevision ? 'is-complete' : 'is-current');
    $timelineDecisionState = $isApproved
        ? 'is-complete'
        : ($isRejected ? 'is-rejected' : 'is-upcoming');
@endphp

@section('title', $heading.' | Bearly')

@section('header-action')
    @if(! $isBuyer)
        <a href="{{ route('login') }}" class="header-link">{{ $isApproved ? 'Sign in' : 'Return to sign in' }}</a>
    @endif
@endsection

@push('scripts')
    @vite('resources/js/application-pending.js')
@endpush

@section('content')
@if($isBuyer)
<section
    class="buyer-pending"
    aria-labelledby="pending-title"
    data-application-status
    data-final="{{ $isFinal ? '1' : '0' }}"
    data-poll-interval="12000"
    @if($statusUrl) data-status-url="{{ $statusUrl }}" @endif
>
    <div class="pending-receipt">
        <span class="pending-check" aria-hidden="true">{{ $isApproved ? '✓' : ($isRejected ? '!' : '…') }}</span>
        <p class="pending-eyebrow">{{ $roleLabel }} registration</p>
        <h1 id="pending-title">{{ $heading }}</h1>
        <p class="pending-intro">Thanks, {{ $applicant }}. {{ $message }}</p>

        <dl class="pending-details">
            <div><dt>Applicant</dt><dd>{{ $applicant }}</dd></div>
            @if($company)<div><dt>Business</dt><dd>{{ $company }}</dd></div>@endif
            <div><dt>Application status</dt><dd><span class="pending-status {{ $statusClass }}">{{ $statusLabel }}</span></dd></div>
            <div><dt>Reviewed by</dt><dd>{{ $authority }}</dd></div>
            @if(!empty($review['submitted_at']))<div><dt>Submitted</dt><dd>{{ $review['submitted_at'] }}</dd></div>@endif
            @if(!empty($review['application_no']))<div><dt>Application</dt><dd>{{ $review['application_no'] }}</dd></div>@endif
        </dl>

        @if($reviewNote)
            <div class="pending-review-note" role="note">
                <strong>{{ $needsRevision ? 'What needs updating' : 'Decision note' }}</strong>
                <p>{{ $reviewNote }}</p>
            </div>
        @endif

        <ol class="pending-timeline" aria-label="Application progress">
            <li class="is-complete"><span aria-hidden="true">✓</span><div><strong>Application submitted</strong><p>Your details and identity documents have been received.</p></div></li>
            <li class="{{ $timelineReviewState }}" @if($timelineReviewState === 'is-current') aria-current="step" @endif><span aria-hidden="true">2</span><div><strong>{{ $isApproved || $isRejected ? 'Application reviewed' : ($needsRevision ? 'Update needed' : 'Under review') }}</strong><p>{{ $authority }} checks your application against Bearly requirements.</p></div></li>
            <li class="{{ $timelineDecisionState }}" @if($timelineDecisionState === 'is-upcoming') aria-current="step" @endif><span aria-hidden="true">3</span><div><strong>{{ $isApproved ? 'Account approved' : ($isRejected ? 'Application not approved' : 'Decision') }}</strong><p>{{ $isApproved ? 'Your account is active and ready to use.' : ($isRejected ? 'Contact Support if you need help understanding the decision.' : 'Dashboard access becomes available only after approval.') }}</p></div></li>
        </ol>

        <div class="pending-next">
            <h2>What happens next?</h2>
            <p>
                @if($isApproved)
                    Your {{ strtolower($roleLabel) }} account is active. Sign in with your registered email and password to continue.
                @elseif($isRejected)
                    This application was not approved. You can return to sign in or contact Bearly Support for guidance.
                @elseif($needsRevision)
                    Review the note above and contact Bearly Support with the requested correction. Do not submit a duplicate account.
                @else
                    You can close this page and return later. Keep this page open to let the status update automatically.
                @endif
            </p>
        </div>

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
@else
<section
    class="login-page"
    data-application-status
    data-final="{{ $isFinal ? '1' : '0' }}"
    data-poll-interval="12000"
    @if($statusUrl) data-status-url="{{ $statusUrl }}" @endif
>
    <div class="login-intro">
        <p class="pending-eyebrow">{{ $roleLabel }} registration</p>
        <h1>{{ $heading }}</h1>
        <p>Your Bearly account stays locked until the correct reviewing authority approves it.</p>
    </div>
    <div class="login-stage">
        <div class="login-form">
            <div class="approval-callout">
                <div>
                    <strong><span class="pending-status {{ $statusClass }}">{{ $statusLabel }}</span></strong>
                    <p>{{ $message }}</p>
                </div>
            </div>

            <div class="application-summary">
                <div class="review-groups">
                    <section class="review-group">
                        <dl>
                            <div><dt>Applicant</dt><dd>{{ $applicant }}</dd></div>
                            @if($company)<div><dt>Business</dt><dd>{{ $company }}</dd></div>@endif
                            <div><dt>Reviewed by</dt><dd>{{ $authority }}</dd></div>
                            @if(!empty($review['submitted_at']))<div><dt>Submitted</dt><dd>{{ $review['submitted_at'] }}</dd></div>@endif
                            @if(!empty($review['application_no']))<div><dt>Application</dt><dd>{{ $review['application_no'] }}</dd></div>@endif
                        </dl>
                    </section>
                </div>
            </div>

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
                <a class="sign-in-button" href="{{ route('login') }}">Proceed to Sign In</a>
            @else
                <a class="sign-in-button" href="{{ route('login') }}">Return to sign in</a>
            @endif
            <p class="pending-note">Need help? <a href="{{ route('contact') }}">Contact Bearly</a></p>
        </div>
    </div>
</section>
@endif
@endsection
