@extends('layouts.seller')

@section('title', 'Security')
@section('page-title', 'Security')

@section('content')
<div class="page-heading settings-page-heading">
    <div><span class="section-kicker">Account protection</span><h2>Security</h2><p>Update your password and review recorded account activity.</p></div>
    <span class="settings-security-status"><i data-lucide="shield-check"></i>Account protected</span>
</div>

<div class="settings-layout">
    <div class="settings-main-column">
        <section class="settings-panel" id="password" aria-labelledby="password-title">
            <div class="settings-panel-heading"><div><span class="settings-icon"><i data-lucide="key-round"></i></span><div><h3 id="password-title">Password</h3><p>Use at least 8 characters for your account password.</p></div></div></div>
            <form class="settings-form" method="POST" action="{{ route('seller.settings.security.password') }}">
                @csrf
                @method('PATCH')
                <div class="settings-form-grid">
                    <label class="seller-field"><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" placeholder="Enter current password" required>@error('current_password')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label class="seller-field"><span>New password</span><input type="password" name="new_password" autocomplete="new-password" placeholder="At least 8 characters" minlength="8" required>@error('new_password')<small class="field-error">{{ $message }}</small>@enderror<small>Use uppercase, lowercase, a number, and a symbol.</small></label>
                    <label class="seller-field"><span>Confirm new password</span><input type="password" name="new_password_confirmation" autocomplete="new-password" placeholder="Repeat new password" minlength="8" required></label>
                </div>
                <div class="settings-form-actions"><button class="seller-primary-button" type="submit"><i data-lucide="save"></i>Update password</button></div>
            </form>
        </section>

        <section class="settings-panel" aria-labelledby="sessions-title">
            <div class="settings-panel-heading"><div><span class="settings-icon"><i data-lucide="monitor-smartphone"></i></span><div><h3 id="sessions-title">Active Sessions</h3><p>The browser session you are using right now.</p></div></div></div>
            <div class="session-list">
                <article class="session-row">
                    <span class="session-device-icon"><i data-lucide="monitor"></i></span>
                    <div><strong>Current browser session</strong><small>Last sign-in: {{ $security['last_sign_in'] }}</small></div>
                    <span class="session-current">Active now</span>
                </article>
            </div>
            <p class="settings-note"><i data-lucide="info"></i>Signing out ends this session. Device tracking and remote session revocation are not connected to a backend yet.</p>
        </section>
    </div>

    <aside class="settings-side-column">
        <section class="settings-panel" aria-labelledby="two-factor-title">
            <div class="settings-panel-heading compact"><div><span class="settings-icon"><i data-lucide="smartphone-nfc"></i></span><div><h3 id="two-factor-title">Two-Step Verification</h3><p>Add a second verification step when signing in.</p></div></div></div>
            <div class="two-factor-state"><span>Not available yet</span></div>
            <p class="settings-note"><i data-lucide="info"></i>Two-step verification is not connected to a backend yet, so it cannot be enabled from this page.</p>
        </section>

        <section class="settings-panel" aria-labelledby="activity-title">
            <div class="settings-panel-heading compact"><div><span class="settings-icon"><i data-lucide="history"></i></span><div><h3 id="activity-title">Recent Security Activity</h3><p>Recorded account events.</p></div></div></div>
            <div class="security-activity-list">
                @forelse ($security['activity'] as $item)
                    <article><i class="activity-dot is-{{ $item['tone'] }}"></i><div><strong>{{ $item['event'] }}</strong><small>{{ $item['detail'] }}<br>{{ $item['time'] }}</small></div></article>
                @empty
                    <article><i class="activity-dot is-neutral"></i><div><strong>No activity recorded</strong><small>Account events will appear here as they are recorded.</small></div></article>
                @endforelse
            </div>
        </section>
    </aside>
</div>
@endsection
