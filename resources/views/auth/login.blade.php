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
        class="login-bear login-art-light"
        aria-hidden="true"
    >

    <img
        src="{{ asset('images/bearly-login-bear-cutout.png') }}"
        alt=""
        class="login-bear login-art-dark"
        aria-hidden="true"
    >

    <img
        src="{{ asset('images/bearly-login-truck.png') }}"
        alt=""
        class="login-truck login-art-light"
        aria-hidden="true"
    >

    <img
        src="{{ asset('images/bearly-login-truck-cutout.png') }}"
        alt=""
        class="login-truck login-art-dark"
        aria-hidden="true"
    >

    <div class="login-shell">
        <div class="login-surface">
            <div class="login-intro">
                <h1>Welcome back to Bearly</h1>
                <p>Sign in to continue shopping or managing your account.</p>
            </div>

            @if (session('status'))
                <div class="auth-feedback is-success" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="auth-feedback is-error" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @if ($recentAccounts->isNotEmpty() && ! $showLoginForm)
                <section class="recent-accounts" aria-labelledby="recent-accounts-title">
                    <header class="recent-accounts-heading">
                        <h2 id="recent-accounts-title">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="10" cy="7" r="4"/>
                                <path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            Recent Accounts
                        </h2>
                        <span>{{ $recentAccounts->count() }} saved</span>
                    </header>

                    <p class="recent-accounts-notice">
                        Choose an account to sign in on this browser. Anyone with access to this browser can use saved accounts; avoid shared devices.
                    </p>

                    <div class="recent-account-list">
                        @foreach ($recentAccounts->take(2) as $account)
                            @include('auth.partials.recent-account', ['account' => $account])
                        @endforeach
                    </div>

                    @if ($recentAccounts->count() > 2)
                        <details class="recent-accounts-more">
                            <summary>View all {{ $recentAccounts->count() }} accounts</summary>
                            <div class="recent-account-list recent-account-list-extra">
                                @foreach ($recentAccounts->skip(2) as $account)
                                    @include('auth.partials.recent-account', ['account' => $account])
                                @endforeach
                            </div>
                        </details>
                    @endif

                    <a class="recent-account-another" href="{{ route('login', ['another' => 1]) }}">
                        <svg class="recent-account-add-icon" viewBox="0 0 20 20" aria-hidden="true">
                            <path d="M10 4v12M4 10h12"/>
                        </svg>
                        <span class="recent-account-link-label">Use another account</span>
                    </a>

                    <form method="POST" action="{{ route('login.recent.clear') }}" class="recent-accounts-clear-form">
                        @csrf
                        <button type="submit" class="recent-accounts-clear">Clear saved accounts on this browser</button>
                    </form>
                </section>
            @else
                @if ($recentAccounts->isNotEmpty())
                    <a class="recent-account-back" href="{{ route('login') }}">← Back to Recent Accounts</a>
                @endif

                <form
                    class="login-form"
                    method="POST"
                    action="{{ route('login.submit') }}"
                >
                    @csrf

                    <label class="login-field">
                        <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true">
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
                        <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="4" y="10" width="16" height="11" rx="2"/>
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
                                id="remember-account"
                                type="checkbox"
                                name="remember"
                                value="1"
                                data-remember-account
                                @checked(old('remember'))
                                aria-describedby="remember-account-notice"
                            >
                            <span>Remember me</span>
                        </label>

                        <a href="{{ route('password.request') }}">Forgot password?</a>
                    </div>

                    <p class="remember-account-notice" id="remember-account-notice">
                        Saves this account for one-tap sign-in on this browser, even after you sign out. Don’t use on shared devices.
                    </p>

                    <button type="submit" class="sign-in-button">
                        <span>Sign In</span>
                        <span class="sign-in-arrow" aria-hidden="true">→</span>
                    </button>
                </form>

                <div class="divider"><span>or</span></div>

                <form
                    method="POST"
                    action="{{ route('google.remembered.redirect') }}"
                    class="google-signin-form"
                >
                    @csrf
                    <input
                        type="hidden"
                        name="remember"
                        value="{{ old('remember') ? '1' : '0' }}"
                        data-google-remember
                    >
                    <button type="submit" class="google-button">
                        <img
                            src="{{ asset('images/google-icon.png') }}"
                            alt=""
                            class="google-icon"
                            aria-hidden="true"
                        >
                        <span>Continue with Google</span>
                    </button>
                </form>

                <p class="switch-auth">
                    New to Bearly?
                    <a href="{{ route('register') }}">Create an account</a>
                </p>
            @endif
        </div>

        <div class="login-mobile-bear" aria-hidden="true">
            <img src="{{ asset('images/bearly-login-bear.png') }}" alt="" class="login-art-light">
            <img src="{{ asset('images/bearly-login-bear-cutout.png') }}" alt="" class="login-art-dark">
        </div>
    </div>
</section>
@endsection
