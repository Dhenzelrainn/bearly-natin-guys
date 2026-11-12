<div class="recent-account-row {{ $account['recommended'] ? 'is-recommended' : '' }}">
    <form method="POST" action="{{ route('login.recent', $account['id']) }}" class="recent-account-login-form">
        @csrf
        <button
            type="submit"
            class="recent-account-select"
            aria-label="Sign in as {{ $account['name'] }}, {{ $account['email'] }}"
        >
            <span class="recent-account-avatar" aria-hidden="true">{{ $account['initials'] }}</span>
            <span class="recent-account-identity">
                <strong>{{ $account['name'] }}</strong>
                <small>{{ $account['email'] }}</small>
            </span>
            <span class="recent-account-indicator" aria-hidden="true">
                <svg viewBox="0 0 20 20">
                    <path d="m7.5 4 6 6-6 6"/>
                </svg>
            </span>
        </button>
    </form>

    <form method="POST" action="{{ route('login.recent.forget', $account['id']) }}" class="recent-account-remove-form">
        @csrf
        <button
            type="submit"
            class="recent-account-remove"
            aria-label="Remove {{ $account['email'] }} from saved accounts on this browser"
            title="Remove saved account"
        >
            <svg viewBox="0 0 20 20" aria-hidden="true">
                <path d="m5.5 5.5 9 9m0-9-9 9"/>
            </svg>
        </button>
    </form>
</div>
