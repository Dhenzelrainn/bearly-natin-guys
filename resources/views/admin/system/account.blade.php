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
            @if($profile->avatar_path)
                <img src="{{ Storage::url($profile->avatar_path) }}" alt="{{ $admin['name'] }} profile photo">
            @else
                {{ $admin['initials'] }}
            @endif
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
        <div class="panel-heading panel-heading-wrap">

            <div>
                <span class="eyebrow">Admin profile</span>
                <h2>View / edit profile</h2>
            </div>

            <div class="profile-heading-actions">

                <div
                    class="profile-save-state"
                    data-profile-state
                    data-state="saved"
                >
                    <i
                        data-lucide="circle-check"
                        data-profile-state-icon
                    ></i>

                    <span data-profile-state-label>
                        Profile saved
                    </span>
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

        </div>

        <form method="POST" action="{{ route('admin.account.profile') }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')
        <div class="profile-banner">
            <span
                class="avatar avatar-xxl avatar-warm"
                data-profile-avatar
                data-current-admin-avatar
            >
                @if($profile->avatar_path)
                    <img src="{{ Storage::url($profile->avatar_path) }}" alt="{{ $admin['name'] }} profile photo">
                @else
                    {{ $admin['initials'] }}
                @endif
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

                <label class="button button-secondary button-small" style="margin-top: .75rem;">
                    <i data-lucide="camera"></i>
                    Change photo
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" hidden>
                </label>
            </div>
        </div>

        <div class="form-grid two-column-form">
            <label class="form-field">
                <span>First name</span>
                <input
                    type="text"
                    name="first_name"
                    value="{{ old('first_name', $profile->first_name) }}"
                    data-profile-field="first_name"
                    readonly
                >
            </label>

            <label class="form-field">
                <span>Last name</span>
                <input
                    type="text"
                    name="last_name"
                    value="{{ old('last_name', $profile->last_name) }}"
                    data-profile-field="last_name"
                    readonly
                >
            </label>

            <label class="form-field">
                <span>Email address</span>
                <input
                    type="email"
                    name="email"
                    value="{{ old('email', $profile->email) }}"
                    data-profile-field="email"
                    readonly
                >
            </label>

            <label class="form-field">
                <span>Contact number</span>
                <input
                    type="text"
                    name="contact_number"
                    value="{{ old('contact_number', $profile->contact_number) }}"
                    data-profile-field="phone"
                    readonly
                >
            </label>
        </div>

        <label class="form-field">
            <span>Role</span>
            <input
                type="text"
                value="Administrator"
                readonly
            >
        </label>

        <div class="panel-footer-actions">
            <button
                class="button button-primary"
                type="submit"
                data-profile-save
                disabled
            >
                <i data-lucide="save"></i>
                Save changes
            </button>
        </div>
        </form>
    </article>

    <aside class="panel security-panel">
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
                placeholder="••••••••"
                data-password-current
            >
        </label>

        <label class="form-field">
            <span>New password</span>
            <input
                type="password"
                name="password"
                placeholder="At least 8 characters"
                data-password-new
            >
        </label>

        <label class="form-field">
            <span>Confirm new password</span>
            <input
                type="password"
                name="password_confirmation"
                placeholder="Repeat new password"
                data-password-confirm
            >
        </label>

        <div class="password-rules">

            <span
                data-password-rule="length"
                data-valid="false"
            >
                <i data-lucide="circle"></i>
                8+ characters
            </span>

            <span
                data-password-rule="mixed"
                data-valid="false"
            >
                <i data-lucide="circle"></i>
                Mix letters and numbers
            </span>

            <span
                data-password-rule="match"
                data-valid="false"
            >
                <i data-lucide="circle"></i>
                Passwords match
            </span>

        </div>

        <button
            class="button button-primary full-button"
            type="submit"
            data-password-update
            disabled
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
