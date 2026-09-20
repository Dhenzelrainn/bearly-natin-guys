@extends('layouts.auth')

@section('title', 'Application Pending | Bearly')

@section('header-action')
    <a href="{{ route('login') }}" class="header-link">Return to sign in</a>
@endsection

@section('content')
@php($application = session('marketplace_application', session('logistics_application', session('rider_application', []))))
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
@endsection
