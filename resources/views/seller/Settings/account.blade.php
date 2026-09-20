@extends('layouts.seller')

@section('title', 'Seller Account')
@section('page-title', 'Account')

@section('content')
<div class="page-heading account-page-heading">
    <div>
        <span class="section-kicker">Account management</span>
        <h2>Seller Account</h2>
        <p>Manage your personal contact details and review your account verification.</p>
    </div>
    <button class="seller-primary-button" type="button" data-account-edit>
        <i data-lucide="pencil"></i><span>Edit profile</span>
    </button>
</div>

<section class="account-profile-card" aria-labelledby="account-profile-title">
    <div class="account-avatar-wrap">
        <span class="account-avatar" data-account-avatar>{{ $seller['initials'] }}</span>
        <label class="account-avatar-action" title="Choose profile photo">
            <i data-lucide="camera"></i>
            <span class="sr-only">Choose profile photo</span>
            <input type="file" accept="image/png,image/jpeg,image/webp" data-account-photo>
        </label>
    </div>
    <div class="account-profile-copy">
        <div class="account-profile-title">
            <h3 id="account-profile-title">{{ $account['full_name'] }}</h3>
            <span class="account-badge is-verified"><i data-lucide="badge-check"></i>{{ $account['verification'] }}</span>
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
            <span class="account-edit-state" data-account-edit-state>View only</span>
        </div>

        <form class="account-form" data-account-form>
            <div class="account-form-grid">
                <label class="seller-field">
                    <span>Full name</span>
                    <span class="account-input-wrap"><input name="full_name" value="{{ $account['full_name'] }}" disabled data-account-input><i data-lucide="lock"></i></span>
                    <small>Verified during registration. Contact the administrator to change it.</small>
                </label>
                <label class="seller-field">
                    <span>Email address</span>
                    <span class="account-input-wrap"><input type="email" name="email" value="{{ $account['email'] }}" disabled data-account-input><i data-lucide="lock"></i></span>
                    <small>Your verified sign-in and account recovery email.</small>
                </label>
                <label class="seller-field">
                    <span>Contact number</span>
                    <input name="phone" value="{{ $account['phone'] }}" disabled data-account-editable>
                </label>
                <label class="seller-field">
                    <span>Birthday</span>
                    <span class="account-input-wrap"><input name="birthday" value="{{ $account['birthday'] }}" disabled data-account-input><i data-lucide="lock"></i></span>
                </label>
                <label class="seller-field">
                    <span>Sex</span>
                    <span class="account-input-wrap"><input name="sex" value="{{ $account['sex'] }}" disabled data-account-input><i data-lucide="lock"></i></span>
                </label>
                <label class="seller-field account-address-field">
                    <span>Registered address</span>
                    <textarea name="address" rows="3" disabled data-account-editable>{{ $account['address'] }}</textarea>
                    <small>Used for account verification. Your public pickup address is managed under Store Profile.</small>
                </label>
            </div>

            <div class="account-form-actions" data-account-actions hidden>
                <button class="seller-secondary-button" type="button" data-account-cancel>Cancel</button>
                <button class="seller-primary-button" type="submit"><i data-lucide="save"></i>Save preview</button>
            </div>
        </form>
    </section>

    <aside class="account-side-column">
        <section class="account-panel account-verification-panel" aria-labelledby="verification-title">
            <div class="account-panel-heading"><div><span class="section-kicker">Compliance</span><h3 id="verification-title">Verification</h3></div></div>
            <ul class="account-check-list">
                <li><i data-lucide="circle-check-big"></i><span><strong>Identity verified</strong><small>Valid ID reviewed during registration</small></span></li>
                <li><i data-lucide="circle-check-big"></i><span><strong>Email verified</strong><small>Primary email is confirmed</small></span></li>
                <li><i data-lucide="circle-check-big"></i><span><strong>Contact verified</strong><small>Mobile number is confirmed</small></span></li>
            </ul>
            <p class="account-note"><i data-lucide="info"></i>Business information and permits are managed under Store Profile to avoid duplicate records.</p>
        </section>

        <section class="account-panel account-access-panel" aria-labelledby="access-title">
            <div class="account-panel-heading"><div><span class="section-kicker">Access</span><h3 id="access-title">Account Access</h3></div></div>
            <a href="{{ route('seller.settings.security') }}"><span><i data-lucide="shield-check"></i><strong>Security settings</strong><small>Password, two-step verification, and sessions</small></span><i data-lucide="chevron-right"></i></a>
            <a href="{{ route('seller.settings.notifications') }}"><span><i data-lucide="bell"></i><strong>Notification preferences</strong><small>Order, stock, pickup, and account alerts</small></span><i data-lucide="chevron-right"></i></a>
        </section>
    </aside>
</div>

<section class="account-audit-bar" aria-label="Account record information">
    <i data-lucide="history"></i>
    <div><strong>Account record last updated</strong><span>{{ $account['last_updated'] }}</span></div>
    <span>Frontend preview only · Changes reset after refresh</span>
</section>
@endsection
