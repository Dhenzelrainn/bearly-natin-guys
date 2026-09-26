@extends('layouts.auth')

@section('title', 'Choose New Password | Bearly')

@section('header-action')
    <a href="{{ route('login') }}" class="header-link">
        <span aria-hidden="true">←</span>
        <span>Back to sign in</span>
    </a>
@endsection

@section('content')
<section class="login-page">
    <div class="login-landscape" aria-hidden="true"></div>

    <div class="login-shell">
        <div class="login-surface">
            <div class="login-intro">
                <h1>Choose a new password</h1>
                <p>
                    Use at least 8 characters with an uppercase letter,
                    lowercase letter, and a number.
                </p>
            </div>

            <form
                class="login-form"
                method="POST"
                action="{{ route('password.update') }}"
            >
                @csrf

                <input type="hidden" name="token" value="{{ $token }}">

                @if ($errors->any())
                    <div
                        class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                        role="alert"
                    >
                        {{ $errors->first() }}
                    </div>
                @endif

                <label class="login-field">
                    <svg
                        class="field-icon"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/>
                        <path d="m22 7-10 6L2 7"/>
                    </svg>

                    <span class="sr-only">Email address</span>

                    <input
                        type="email"
                        name="email"
                        value="{{ old('email', $email) }}"
                        placeholder="Email address"
                        autocomplete="email"
                        required
                    >
                </label>

                <label class="login-field">
                    <svg
                        class="field-icon"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <rect x="4" y="10" width="16" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                    </svg>

                    <span class="sr-only">New password</span>

                    <input
                        id="reset-password"
                        type="password"
                        name="password"
                        placeholder="New password"
                        autocomplete="new-password"
                        minlength="8"
                        pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}"
                        title="Use at least 8 characters with one uppercase letter, one lowercase letter, and one number."
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-toggle-password="reset-password"
                        aria-label="Show password"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </label>

                <label class="login-field">
                    <svg
                        class="field-icon"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <rect x="4" y="10" width="16" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                    </svg>

                    <span class="sr-only">Confirm new password</span>

                    <input
                        id="reset-password-confirmation"
                        type="password"
                        name="password_confirmation"
                        placeholder="Confirm new password"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-toggle-password="reset-password-confirmation"
                        aria-label="Show password"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </label>

                <div class="rounded-xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-600">
                    Password must contain 8+ characters, at least one uppercase
                    letter, one lowercase letter, and one number.
                </div>

                <button type="submit" class="sign-in-button">
                    <span>Update password</span>
                    <span class="sign-in-arrow" aria-hidden="true">→</span>
                </button>
            </form>
        </div>
    </div>
</section>
@endsection
