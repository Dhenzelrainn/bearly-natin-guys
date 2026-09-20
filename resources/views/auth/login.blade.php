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

    {{-- Page heading --}}
    <div class="login-intro">
        <h1>Welcome back to Bearly</h1>

        <p>
            One secure sign-in for shopping, selling, and delivering.
        </p>
    </div>

    <div class="login-stage">

        {{-- Login form --}}
        <form
            class="login-form"
            data-demo-login
            novalidate
        >
            <div class="access-bar">

                {{-- Email --}}
                <label class="access-field">
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
                        placeholder="Email address"
                        autocomplete="email"
                        required
                    >
                </label>

                {{-- Password --}}
                <label class="access-field">
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

                {{-- Sign-in button --}}
                <button
                    type="submit"
                    class="sign-in-button"
                >
                    Sign In
                </button>
            </div>

            {{-- Login options --}}
            <div class="login-options">
                <label class="check-label">
                    <input
                        type="checkbox"
                        name="remember"
                        value="1"
                    >

                    <span>Remember me</span>
                </label>

                <a href="{{ route('password.request') }}">
                    Forgot password?
                </a>

                <span class="secure-note">
                    <img
                        src="{{ asset('images/icon-secure-small.png') }}"
                        alt=""
                        aria-hidden="true"
                    >

                    <span>Secure access</span>
                </span>
            </div>

            {{-- Divider --}}
            <div class="divider">
                <span>or</span>
            </div>

            {{-- Google button --}}
            <button
                type="button"
                class="google-button"
            >
                <img
                   src="{{ asset('images/google-icon.png') }}" alt="" class="google-icon"aria-hidden="true">

                <span>Continue with Google</span>
            </button>

            {{-- Registration link --}}
            <p class="switch-auth">
                New to Bearly?

                <a href="{{ route('register') }}">
                    Create an account
                </a>
            </p>

            {{-- Frontend-only preview message --}}
            <div
                class="demo-message"
                data-login-message
                hidden
                role="status"
            >
                Login UI is working. Database connection will be added later.
            </div>
        </form>

        {{-- Bottom e-commerce illustration --}}
        <img
            src="{{ asset('images/bearly-auth-scene.png') }}"
            alt=""
            class="login-scene"
            aria-hidden="true"
        >
    </div>

    {{-- Account benefits --}}
    <div
        class="trust-row"
        aria-label="Bearly account benefits"
    >
        <div>
            <span class="trust-icon">
                <img
                    src="{{ asset('images/icon-one-account.png') }}"
                    alt=""
                    aria-hidden="true"
                >
            </span>

            <strong>One account</strong>
        </div>

        <div>
            <span class="trust-icon">
                <img
                    src="{{ asset('images/icon-role-aware.png') }}"
                    alt=""
                    aria-hidden="true"
                >
            </span>

            <strong>Role-aware access</strong>
        </div>

        <div>
            <span class="trust-icon secure">
                <img
                    src="{{ asset('images/icon-secure.png') }}"
                    alt=""
                    aria-hidden="true"
                >
            </span>

            <strong>Secure sign-in</strong>
        </div>
    </div>

</section>
@endsection