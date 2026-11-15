@extends('layouts.seller')

@section('title', $acknowledgmentRequired ? 'Seller Policy Review' : 'Seller Policies')
@section('page-title', $acknowledgmentRequired ? 'Seller Policy Review' : 'Seller Policies')

@section('content')
<div class="seller-policy-page">
    <section class="page-heading seller-policy-page-heading">
        <div>
            <span class="section-kicker">Seller Compliance · Version {{ $version->version }}</span>
            <h2>{{ $acknowledgmentRequired ? 'Review this Seller policy' : 'Current Seller policy' }}</h2>
            <p>{{ $version->summary ?: ($acknowledgmentRequired ? 'Read the active policy before continuing to Seller Center.' : 'Read the active policy that applies to your Seller account.') }}</p>
        </div>
        @if ($acknowledgmentRequired)
            <span class="seller-policy-required-badge">
                <i data-lucide="shield-check" aria-hidden="true"></i>
                Required to continue
            </span>
        @else
            <span class="seller-policy-acknowledged-badge">
                <i data-lucide="check" aria-hidden="true"></i>
                Acknowledged
            </span>
        @endif
    </section>

    @if (session('policy_required'))
        <div class="info-box seller-policy-required-notice" role="status">
            Please review and acknowledge this active version to continue using Seller Center.
        </div>
    @elseif (! $acknowledgmentRequired)
        <div class="seller-policy-acknowledged-notice" role="status">
            You acknowledged version {{ $version->version }}. This read-only page is available anytime from Seller Policies.
        </div>
    @endif

    <section class="panel seller-policy-panel" aria-labelledby="seller-policy-content-heading">
        <div class="panel-heading seller-policy-panel-heading">
            <div>
                <span class="eyebrow">Active Platform Policy</span>
                <h3 id="seller-policy-content-heading">{{ $version->policy?->title ?: $version->title }}</h3>
            </div>
            <span class="seller-policy-version">{{ $version->version }}</span>
        </div>

        <div class="seller-policy-content">{{ $version->body }}</div>

        @if ($acknowledgmentRequired)
            <form method="POST" action="{{ route('seller.policies.accept') }}" class="seller-policy-form">
                @csrf
                <div class="seller-policy-consent-row">
                    <label class="seller-policy-consent" for="seller-policy-accepted">
                        <input
                            id="seller-policy-accepted"
                            type="checkbox"
                            name="accepted"
                            value="1"
                            aria-describedby="seller-policy-consent-help"
                            required
                            @checked(old('accepted'))
                        >
                        <span class="seller-policy-consent-copy">
                            <strong>I have read and acknowledge this Seller policy.</strong>
                            <small id="seller-policy-consent-help">Your acknowledgment applies to version {{ $version->version }}.</small>
                        </span>
                    </label>

                    <div class="seller-policy-submit">
                        @error('accepted')
                            <small class="field-error" role="alert">{{ $message }}</small>
                        @enderror
                        <button class="seller-primary-button" type="submit">
                            Acknowledge and continue
                            <i data-lucide="arrow-right" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </form>
        @endif
    </section>
</div>
@endsection
