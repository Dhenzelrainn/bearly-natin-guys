@extends('layouts.seller')

@section('title', 'Security')
@section('page-title', 'Security')

@section('content')
<div class="page-heading settings-page-heading">
    <div><span class="section-kicker">Account protection</span><h2>Security</h2><p>Manage your password, verification, and signed-in devices.</p></div>
    <span class="settings-security-status"><i data-lucide="shield-check"></i>Account protected</span>
</div>

<div class="settings-layout">
    <div class="settings-main-column">
        <section class="settings-panel" aria-labelledby="password-title">
            <div class="settings-panel-heading"><div><span class="settings-icon"><i data-lucide="key-round"></i></span><div><h3 id="password-title">Password</h3><p>Last changed {{ $security['last_password_change'] }}</p></div></div></div>
            <form class="settings-form" data-password-form>
                <div class="settings-form-grid">
                    <label class="seller-field"><span>Current password</span><input type="password" autocomplete="current-password" placeholder="Enter current password" required></label>
                    <label class="seller-field"><span>New password</span><input type="password" autocomplete="new-password" placeholder="At least 8 characters" minlength="8" required data-new-password><small>Use uppercase, lowercase, a number, and a symbol.</small></label>
                    <label class="seller-field"><span>Confirm new password</span><input type="password" autocomplete="new-password" placeholder="Repeat new password" minlength="8" required data-confirm-password></label>
                </div>
                <div class="settings-form-actions"><button class="seller-primary-button" type="submit"><i data-lucide="save"></i>Update password</button></div>
            </form>
        </section>

        <section class="settings-panel" aria-labelledby="sessions-title">
            <div class="settings-panel-heading"><div><span class="settings-icon"><i data-lucide="monitor-smartphone"></i></span><div><h3 id="sessions-title">Active Sessions</h3><p>Devices currently or recently signed in to your seller account.</p></div></div><button class="settings-text-button" type="button" data-security-demo="Other sessions signed out.">Sign out other sessions</button></div>
            <div class="session-list">
                @foreach ($security['sessions'] as $session)
                    <article class="session-row">
                        <span class="session-device-icon"><i data-lucide="{{ str_contains($session['device'], 'Windows') ? 'monitor' : 'smartphone' }}"></i></span>
                        <div><strong>{{ $session['device'] }}</strong><small>{{ $session['location'] }} · {{ $session['activity'] }}</small></div>
                        @if ($session['current'])<span class="session-current">Current device</span>@else<button type="button" data-security-demo="Session signed out.">Sign out</button>@endif
                    </article>
                @endforeach
            </div>
        </section>
    </div>

    <aside class="settings-side-column">
        <section class="settings-panel" aria-labelledby="two-factor-title">
            <div class="settings-panel-heading compact"><div><span class="settings-icon"><i data-lucide="smartphone-nfc"></i></span><div><h3 id="two-factor-title">Two-Step Verification</h3><p>Add a second verification step when signing in.</p></div></div></div>
            <div class="two-factor-state"><span data-two-factor-label>{{ $security['two_factor_enabled'] ? 'Enabled' : 'Not enabled' }}</span><label class="settings-switch"><input type="checkbox" data-two-factor-toggle @checked($security['two_factor_enabled'])><i></i><span class="sr-only">Toggle two-step verification</span></label></div>
            <p class="settings-note"><i data-lucide="info"></i>The backend will send and verify security codes later. This control is a frontend preview.</p>
        </section>

        <section class="settings-panel" aria-labelledby="activity-title">
            <div class="settings-panel-heading compact"><div><span class="settings-icon"><i data-lucide="history"></i></span><div><h3 id="activity-title">Recent Security Activity</h3><p>Review recent account access events.</p></div></div></div>
            <div class="security-activity-list">
                @foreach ($security['activity'] as $item)
                    <article><i class="activity-dot is-{{ $item['tone'] }}"></i><div><strong>{{ $item['event'] }}</strong><small>{{ $item['detail'] }}<br>{{ $item['time'] }}</small></div></article>
                @endforeach
            </div>
        </section>
    </aside>
</div>
@endsection
