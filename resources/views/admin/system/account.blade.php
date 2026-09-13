@extends('layouts.admin')

@section('title', 'Account Management')
@section('page-title', 'Account Management')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">System Management</span>
        <h1>Account management</h1>
        <p>Manage the administrator profile and update account security settings.</p>
    </div>

    <div class="hero-summary-card">
        <span
            class="avatar avatar-large avatar-warm"
            data-profile-avatar
            data-current-admin-avatar
        >
            {{ $admin['initials'] }}
        </span>

        <div>
            <strong data-profile-name data-current-admin-name>
                {{ $admin['name'] }}
            </strong>
            <small>{{ $admin['role'] }}</small>
        </div>
    </div>
</section>

<section class="account-grid">
    <article class="panel profile-edit-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Admin profile</span>
                <h2>View / edit profile</h2>
            </div>

            <button
                class="button button-secondary button-small"
                type="button"
                data-profile-edit
            >
                <i data-lucide="pencil"></i>
                Edit
            </button>
        </div>

        <div class="profile-banner">
            <span
                class="avatar avatar-xxl avatar-warm"
                data-profile-avatar
                data-current-admin-avatar
            >
                {{ $admin['initials'] }}
            </span>

            <div>
                <h3 data-profile-name data-current-admin-name>
                    {{ $admin['name'] }}
                </h3>

                <p data-profile-email data-current-admin-email>
                    {{ $admin['email'] }}
                </p>

                <span class="status-badge badge-success">
                    Active administrator
                </span>
            </div>
        </div>

        <div class="form-grid two-column-form">
            <label class="form-field">
                <span>First name</span>
                <input
                    type="text"
                    value="{{ $profile['first_name'] }}"
                    data-profile-field="first_name"
                    readonly
                >
            </label>

            <label class="form-field">
                <span>Last name</span>
                <input
                    type="text"
                    value="{{ $profile['last_name'] }}"
                    data-profile-field="last_name"
                    readonly
                >
            </label>

            <label class="form-field">
                <span>Email address</span>
                <input
                    type="email"
                    value="{{ $profile['email'] }}"
                    data-profile-field="email"
                    readonly
                >
            </label>

            <label class="form-field">
                <span>Contact number</span>
                <input
                    type="text"
                    value="{{ $profile['phone'] }}"
                    data-profile-field="phone"
                    readonly
                >
            </label>
        </div>

        <label class="form-field">
            <span>Role</span>
            <input
                type="text"
                value="{{ $profile['role'] }}"
                readonly
            >
        </label>

        <div class="panel-footer-actions">
            <button
                class="button button-primary"
                type="button"
                data-profile-save
                disabled
            >
                <i data-lucide="save"></i>
                Save changes
            </button>
        </div>
    </article>

    <aside class="panel security-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Security</span>
                <h2>Change password</h2>
            </div>
        </div>

        <label class="form-field">
            <span>Current password</span>
            <input
                type="password"
                placeholder="••••••••"
                data-password-current
            >
        </label>

        <label class="form-field">
            <span>New password</span>
            <input
                type="password"
                placeholder="At least 8 characters"
                data-password-new
            >
        </label>

        <label class="form-field">
            <span>Confirm new password</span>
            <input
                type="password"
                placeholder="Repeat new password"
                data-password-confirm
            >
        </label>

        <div class="password-rules">
            <span>
                <i data-lucide="check"></i>
                8+ characters
            </span>

            <span>
                <i data-lucide="check"></i>
                Mix letters and numbers
            </span>
        </div>

        <button
            class="button button-primary full-button"
            type="button"
            data-password-update
        >
            Update password
        </button>

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