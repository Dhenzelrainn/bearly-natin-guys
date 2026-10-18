@extends('layouts.admin')

@section('title', 'Account Management')
@section('page-title', 'Account Management')

@section('content')

@if (session('success'))
    <div class="panel" role="status">{{ session('success') }}</div>
@endif

<section class="page-hero">
    <div>
        <span class="eyebrow">System Management</span>
        <h1>Account management</h1>
        <p>Review the administrator identity used across Bearly and update the account password.</p>
    </div>

    <div class="hero-summary-card">
        <span class="avatar avatar-large avatar-warm">
            {{ $admin['initials'] }}
        </span>

        <div>
            <strong>
                {{ $admin['name'] }}
            </strong>
            <small>{{ $admin['role'] }}</small>
        </div>
    </div>
</section>

<section class="account-grid">
    <article class="panel profile-edit-panel">
        <div class="panel-heading panel-heading-wrap">

            <div>
                <span class="eyebrow">Admin profile</span>
                <h2>Administrator identity</h2>
            </div>

            <div class="profile-heading-actions">

                <div
                    class="profile-save-state"
                    data-state="saved"
                >
                    <i data-lucide="shield-check"></i>

                    <span>
                        System managed
                    </span>
                </div>

            </div>

        </div>

        <div class="profile-banner">
            <span class="avatar avatar-xxl avatar-warm">
                {{ $admin['initials'] }}
            </span>

            <div>
                <h3>{{ $admin['name'] }}</h3>

                <p>{{ $admin['email'] }}</p>

                <span class="status-badge badge-success">
                    Active administrator
                </span>
            </div>
        </div>

        <div class="form-grid two-column-form">
            <label class="form-field">
                <span>First name</span>
                <input type="text" value="{{ $profile['first_name'] }}" readonly>
            </label>

            <label class="form-field">
                <span>Last name</span>
                <input type="text" value="{{ $profile['last_name'] }}" readonly>
            </label>

            <label class="form-field">
                <span>Email address</span>
                <input type="email" value="{{ $profile['email'] }}" readonly>
            </label>

            <label class="form-field">
                <span>Contact number</span>
                <input type="text" value="{{ $profile['phone'] !== '' ? $profile['phone'] : 'Not set' }}" readonly>
            </label>
        </div>

        <label class="form-field">
            <span>Role</span>
            <input type="text" value="{{ $profile['role'] }}" readonly>
        </label>

        <div class="review-details">
            <p class="detail-note">
                Administrator identity fields come from the signed-in account and are
                managed by the platform, so they are read-only here. Last sign-in:
                {{ $profile['last_sign_in'] }}.
            </p>
        </div>
    </article>

    <aside class="panel security-panel" id="security">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Security</span>
                <h2>Change password</h2>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.account.password') }}">
            @csrf
            @method('PATCH')

            <label class="form-field">
                <span>Current password</span>
                <input
                    type="password"
                    name="current_password"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    required
                >
                @error('current_password')<small class="field-error">{{ $message }}</small>@enderror
            </label>

            <label class="form-field">
                <span>New password</span>
                <input
                    type="password"
                    name="new_password"
                    autocomplete="new-password"
                    placeholder="At least 8 characters"
                    minlength="8"
                    required
                >
                @error('new_password')<small class="field-error">{{ $message }}</small>@enderror
            </label>

            <label class="form-field">
                <span>Confirm new password</span>
                <input
                    type="password"
                    name="new_password_confirmation"
                    autocomplete="new-password"
                    placeholder="Repeat new password"
                    minlength="8"
                    required
                >
            </label>

            <button
                class="button button-primary full-button"
                type="submit"
            >
                Update password
            </button>
        </form>

        <div class="security-callout">
            <i data-lucide="shield-check"></i>

            <div>
                <strong>Account security</strong>
                <p>
                    Keep administrator credentials secure and update the account password regularly.
                </p>
            </div>
        </div>
    </aside>
</section>

@endsection
