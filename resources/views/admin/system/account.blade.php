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
        <p>Manage your Admin Center profile photo and account password. Identity details remain platform-managed.</p>
    </div>

    <div class="hero-summary-card">
        <span class="avatar avatar-large avatar-warm">
            @if (!empty($admin['photo_url']))
                <img class="avatar-photo" src="{{ $admin['photo_url'] }}" alt="">
            @else
                {{ $admin['initials'] }}
            @endif
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

                    <span>Identity fields read-only</span>
                </div>

            </div>

        </div>

        <div class="profile-banner admin-profile-banner">
            <span class="avatar avatar-xxl avatar-warm">
                @if (!empty($admin['photo_url']))
                    <img class="avatar-photo" src="{{ $admin['photo_url'] }}" alt="">
                @else
                    {{ $admin['initials'] }}
                @endif
            </span>

            <div class="admin-profile-banner-identity">
                <h3>{{ $admin['name'] }}</h3>

                <span class="admin-profile-banner-role">
                    <i data-lucide="crown" aria-hidden="true"></i>
                    Administrator
                </span>
            </div>

            <a class="admin-profile-banner-action" href="#profile-photo-editor" aria-label="Go to profile photo controls">
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </a>
        </div>

        <section class="admin-account-photo-editor" id="profile-photo-editor" aria-labelledby="admin-photo-heading">
            <div class="admin-account-photo-copy">
                <strong id="admin-photo-heading">Profile photo</strong>
                <p>This photo appears in your Admin Center profile menu. JPG, PNG, or WebP; maximum 10 MB.</p>
            </div>

            <div class="admin-account-photo-actions">
                <form class="admin-account-photo-form" method="POST" action="{{ route('admin.account.photo.update') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PATCH')

                    <label class="form-field">
                        <span>Choose a photo</span>
                        <input id="admin-profile-photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required aria-describedby="admin-photo-help{{ $errors->has('profile_photo') ? ' admin-photo-error' : '' }}">
                        <small id="admin-photo-help">Select a clear square image for best results.</small>
                        @error('profile_photo')<small class="field-error" id="admin-photo-error" role="alert">{{ $message }}</small>@enderror
                    </label>

                    <button class="button button-primary" type="submit">Save photo</button>
                </form>

                @if (!empty($admin['photo_url']))
                    <form class="admin-account-photo-remove" method="POST" action="{{ route('admin.account.photo.destroy') }}">
                        @csrf
                        @method('DELETE')
                        <button class="button button-secondary" type="submit">Remove photo</button>
                    </form>
                @endif
            </div>
        </section>

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
