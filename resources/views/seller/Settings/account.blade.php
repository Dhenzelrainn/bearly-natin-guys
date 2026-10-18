@extends('layouts.seller')

@section('title', 'Seller Account')
@section('page-title', 'Account')

@section('content')
<div class="page-heading account-page-heading">
    <div>
        <span class="section-kicker">Account management</span>
        <h2>Seller Account</h2>
        <p>Review your registration details and update the contact number used for account communication.</p>
    </div>
</div>

<section class="account-profile-card" aria-labelledby="account-profile-title">
    <div class="account-avatar-wrap">
        <span class="account-avatar">{{ $seller['initials'] }}</span>
    </div>
    <div class="account-profile-copy">
        <div class="account-profile-title">
            <h3 id="account-profile-title">{{ $account['full_name'] }}</h3>
            <span class="account-badge is-verified"><i data-lucide="{{ $account['identity_verified'] ? 'badge-check' : 'clock-3' }}"></i>{{ $account['verification'] }}</span>
            <span class="account-badge is-active"><i data-lucide="circle-check"></i>{{ $account['status'] }}</span>
        </div>
        <p>{{ $account['email'] }}</p>
        <span>Seller since {{ $account['member_since'] }}</span>
    </div>
    <dl class="account-profile-meta">
        <div><dt>Role</dt><dd>Seller</dd></div>
        <div><dt>Last sign-in</dt><dd>{{ $account['last_sign_in'] }}</dd></div>
    </dl>
</section>

<div class="account-layout">
    <section class="account-panel" aria-labelledby="personal-information-title">
        <div class="account-panel-heading">
            <div><span class="section-kicker">Profile</span><h3 id="personal-information-title">Personal Information</h3></div>
        </div>

        <form class="account-form" method="POST" action="{{ route('seller.settings.account.update') }}">
            @csrf
            @method('PATCH')

            <div class="account-form-grid">
                <label class="seller-field">
                    <span>Full name</span>
                    <span class="account-input-wrap"><input name="full_name" value="{{ $account['full_name'] }}" disabled><i data-lucide="lock"></i></span>
                    <small>Verified during registration. Contact the administrator to change it.</small>
                </label>
                <label class="seller-field">
                    <span>Email address</span>
                    <span class="account-input-wrap"><input type="email" name="email" value="{{ $account['email'] }}" disabled><i data-lucide="lock"></i></span>
                    <small>Your verified sign-in and account recovery email.</small>
                </label>
                <label class="seller-field" id="contact">
                    <span>Contact number</span>
                    <input name="contact" type="tel" inputmode="tel" maxlength="17" placeholder="+63 9XX XXX XXXX" value="{{ $account['phone'] }}" required>
                    @error('contact')<small class="field-error">{{ $message }}</small>@enderror
                    <small>Used for order and delivery coordination. Include the +63 country code.</small>
                </label>
                <label class="seller-field">
                    <span>Birthday</span>
                    <span class="account-input-wrap"><input name="birthday" value="{{ $account['birthday'] }}" disabled><i data-lucide="lock"></i></span>
                </label>
                <label class="seller-field">
                    <span>Sex</span>
                    <span class="account-input-wrap"><input name="sex" value="{{ $account['sex'] }}" disabled><i data-lucide="lock"></i></span>
                </label>
                <label class="seller-field account-address-field">
                    <span>Registered address</span>
                    <textarea name="address" rows="3" disabled>{{ $account['address'] }}</textarea>
                    <small>Used for account verification. Your public pickup address is managed under Store Profile.</small>
                </label>
            </div>

            <div class="account-form-actions">
                <button class="seller-primary-button" type="submit"><i data-lucide="save"></i>Save contact number</button>
            </div>
        </form>
    </section>

    <aside class="account-side-column">
        <section class="account-panel account-verification-panel" aria-labelledby="verification-title">
            <div class="account-panel-heading"><div><span class="section-kicker">Compliance</span><h3 id="verification-title">Verification</h3></div></div>
            <ul class="account-check-list">
                <li class="{{ $account['identity_verified'] ? '' : 'is-pending' }}">
                    <i data-lucide="{{ $account['identity_verified'] ? 'circle-check-big' : 'circle-alert' }}"></i>
                    <span>
                        <strong>{{ $account['identity_verified'] ? 'Identity verified' : 'Identity under review' }}</strong>
                        <small>{{ $account['identity_verified'] ? 'Valid ID reviewed during registration' : 'Registration review is still in progress' }}</small>
                    </span>
                </li>
                <li class="{{ $account['email_verified'] ? '' : 'is-pending' }}">
                    <i data-lucide="{{ $account['email_verified'] ? 'circle-check-big' : 'circle-alert' }}"></i>
                    <span>
                        <strong>{{ $account['email_verified'] ? 'Email verified' : 'Email not verified' }}</strong>
                        <small>{{ $account['email_verified'] ? 'Primary email is confirmed' : 'Confirm your email to receive account notices' }}</small>
                    </span>
                </li>
                <li class="{{ $account['contact_verified'] ? '' : 'is-pending' }}">
                    <i data-lucide="{{ $account['contact_verified'] ? 'circle-check-big' : 'circle-alert' }}"></i>
                    <span>
                        <strong>{{ $account['contact_verified'] ? 'Contact verified' : 'Contact not verified' }}</strong>
                        <small>{{ $account['contact_verified'] ? 'Mobile number is confirmed' : 'Completed during registration review' }}</small>
                    </span>
                </li>
            </ul>
            <p class="account-note"><i data-lucide="info"></i>Business information and permits are managed under Store Profile to avoid duplicate records.</p>
        </section>

        <section class="account-panel account-access-panel" aria-labelledby="access-title">
            <div class="account-panel-heading"><div><span class="section-kicker">Access</span><h3 id="access-title">Account Access</h3></div></div>
            <a href="{{ route('seller.settings.security') }}"><span><i data-lucide="shield-check"></i><strong>Security settings</strong><small>Password and recent account activity</small></span><i data-lucide="chevron-right"></i></a>
            <a href="{{ route('seller.settings.notifications') }}"><span><i data-lucide="bell"></i><strong>Notification preferences</strong><small>Order, stock, pickup, and account alerts</small></span><i data-lucide="chevron-right"></i></a>
        </section>
    </aside>
</div>

<section class="account-audit-bar" aria-label="Account record information">
    <i data-lucide="history"></i>
    <div><strong>Account record last updated</strong><span>{{ $account['last_updated'] }}</span></div>
</section>
@endsection
