@extends('layouts.auth')

@section('title', 'Application Pending | Bearly')

@php($application = session('marketplace_application', session('logistics_application', session('rider_application', []))))

@section('header-action')
    @if(($application['role'] ?? null) !== 'buyer')
    <a href="{{ route('login') }}" class="header-link">Return to sign in</a>
    @endif
@endsection

@section('content')
@if(($application['role'] ?? null) === 'buyer')
<section class="buyer-pending" aria-labelledby="pending-title">
    <div class="pending-receipt">
        <span class="pending-check" aria-hidden="true">✓</span>
        <p class="pending-eyebrow">Buyer registration</p>
        <h1 id="pending-title">{{ ($application['status'] ?? '') === 'needs_revision' ? 'Your application needs an update' : 'Application received' }}</h1>
        <p class="pending-intro">Thanks, {{ $application['name'] ?? 'buyer' }}. Your application has been submitted to Bearly.</p>
        <dl class="pending-details">
            <div><dt>Applicant</dt><dd>{{ $application['name'] ?? 'Buyer' }}</dd></div>
            <div><dt>Application status</dt><dd><span class="pending-status">{{ ($application['status'] ?? '') === 'needs_revision' ? 'Needs revision' : 'Pending review' }}</span></dd></div>
        </dl>
        <ol class="pending-timeline" aria-label="Application progress">
            <li class="is-complete"><span aria-hidden="true">✓</span><div><strong>Application submitted</strong><p>Your details and ID have been received.</p></div></li>
            <li aria-current="step"><span aria-hidden="true">2</span><div><strong>Admin review</strong><p>A Bearly administrator will review your application.</p></div></li>
            <li class="is-upcoming"><span aria-hidden="true">3</span><div><strong>Decision</strong><p>Buyer access becomes available after approval.</p></div></li>
        </ol>
        <div class="pending-next"><h2>What happens next?</h2><p>@if(($application['status'] ?? '') === 'needs_revision') Contact Bearly to find out which application details need updating. @else You can sign in once your application is approved. You do not need to submit another application. @endif</p></div>
        <a class="primary-button pending-signin" href="{{ route('login') }}">Return to sign in <span aria-hidden="true">→</span></a>
        <p class="pending-note">Need help? <a href="{{ route('contact') }}">Contact Bearly</a></p>
    </div>
</section>
@else
<section class="login-page">
    <div class="login-intro">
        <h1>Application received</h1>
        <p>Your Bearly account stays locked until the correct reviewing authority approves it.</p>
    </div>
    <div class="login-stage">
        <div class="login-form">
            <div class="approval-callout">
                <div>
                    <strong>Pending approval</strong>
                    <p>
                        @if(($application['role'] ?? null) === 'rider' || isset($application['logistics_partner']))
                            Your selected Logistics / Sorting Center will review your Rider application.
                        @else
                            Bearly Admin will review your marketplace application.
                        @endif
                    </p>
                </div>
            </div>
            @if($application !== [])
                <div class="application-summary">
                    <div class="review-groups">
                        <section class="review-group">
                            <dl>
                                @if(isset($application['name']))<div><dt>Applicant</dt><dd>{{ $application['name'] }}</dd></div>@endif
                                @if(isset($application['business_name']))<div><dt>Business</dt><dd>{{ $application['business_name'] }}</dd></div>@endif
                                @if(isset($application['full_name']))<div><dt>Applicant</dt><dd>{{ $application['full_name'] }}</dd></div>@endif
                                <div><dt>Status</dt><dd>Pending approval</dd></div>
                            </dl>
                        </section>
                    </div>
                </div>
            @endif
            <a class="sign-in-button" href="{{ route('login') }}">Return to sign in</a>
        </div>
    </div>
</section>
@endif
@endsection
