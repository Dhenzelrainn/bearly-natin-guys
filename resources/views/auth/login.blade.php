@extends('layouts.auth')

@section('title', 'Sign In | Bearly')

@section('header-action')
    <a href="{{ url('/') }}" class="header-link">
        <span aria-hidden="true">←</span>
        <span>Back to shop</span>
    </a>
@endsection

@section('content')
<section class="login-page">
    <div class="login-landscape" aria-hidden="true"></div>

    <img
        src="{{ asset('images/bearly-login-bear.png') }}"
        alt=""
        class="login-bear"
        aria-hidden="true"
    >

    <img
        src="{{ asset('images/bearly-login-truck.png') }}"
        alt=""
        class="login-truck"
        aria-hidden="true"
    >

    <div class="login-shell">
        <div class="login-surface">
            <div class="login-intro">
                <h1>Welcome back to Bearly</h1>
                <p>Sign in to continue shopping or managing your account.</p>
            </div>

            <form
                class="login-form"
                method="POST"
                action="{{ route('login.submit') }}"
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

                <label class="login-field">
                    <svg
                        class="field-icon"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <rect
                            x="4"
                            y="10"
                            width="16"
                            height="11"
                            rx="2"
                        />
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                    </svg>

                    <span class="sr-only">Password</span>

                    <input
                        id="login-password"
                        type="password"
                        name="password"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-toggle-password="login-password"
                        aria-label="Show password"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </label>

                <div class="login-options">
                    <label class="check-label">
                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                            @checked(old('remember'))
                        >
                        <span>Remember me</span>
                    </label>

                    <a href="{{ route('password.request') }}">
                        Forgot password?
                    </a>
                </div>

                <button
                    type="submit"
                    class="sign-in-button"
                >
                    <span>Sign In</span>
                    <span class="sign-in-arrow" aria-hidden="true">→</span>
                </button>

                <div class="divider">
                    <span>or</span>
                </div>

                <a
                    href="{{ route('google.redirect') }}"
                    class="google-button"
                >
                    <img
                        src="{{ asset('images/google-icon.png') }}"
                        alt=""
                        class="google-icon"
                        aria-hidden="true"
                    >
                    <span>Continue with Google</span>
                </a>

                <p class="switch-auth">
                    New to Bearly?
                    <a href="{{ route('register') }}">
                        Create an account
                    </a>
                </p>
            </form>
        </div>

        <div class="login-mobile-bear" aria-hidden="true">
            <img
                src="{{ asset('images/bearly-login-bear.png') }}"
                alt=""
            >
        </div>
    </div>
</section>
@endsection
