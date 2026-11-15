@extends('layouts.auth')

@section('title', 'Forgot Password | Bearly')

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
                <h1>Reset your password</h1>
                <p>
                    Enter the email connected to your Bearly account.
                    We’ll send a secure reset link if the account exists.
                </p>
            </div>

            <form
                class="login-form"
                method="POST"
                action="{{ route('password.email') }}"
            >
                @csrf

                @if (session('status'))
                    <div
                        class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                        role="status"
                    >
                        {{ session('status') }}
                    </div>
                @endif

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
                        value="{{ old('email') }}"
                        placeholder="Email address"
                        autocomplete="email"
                        required
                        autofocus
                    >
                </label>

                <button type="submit" class="sign-in-button">
                    <span>Send reset link</span>
                    <span class="sign-in-arrow" aria-hidden="true">→</span>
                </button>

                <p class="switch-auth">
                    Remembered your password?
                    <a href="{{ route('login') }}">Sign in</a>
                </p>
            </form>
        </div>
    </div>
</section>
@endsection
