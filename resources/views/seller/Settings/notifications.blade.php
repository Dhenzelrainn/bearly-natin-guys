@extends('layouts.seller')

@section('title', 'Notifications')
@section('page-title', 'Notifications')

@section('content')
<div class="page-heading settings-page-heading">
    <div><span class="section-kicker">Preferences</span><h2>Notifications</h2><p>Choose how you receive important seller and fulfillment updates.</p></div>
    <button class="seller-primary-button" type="button" data-save-notifications><i data-lucide="save"></i>Save preferences</button>
</div>

<section class="settings-panel notification-settings-panel" aria-labelledby="notification-preferences-title">
    <div class="notification-table-heading">
        <div><h3 id="notification-preferences-title">Notification Preferences</h3><p>Critical account notices remain enabled for your protection.</p></div>
        <div aria-hidden="true"><span>In-app</span><span>Email</span></div>
    </div>
    <div class="notification-preference-list">
        @foreach ($preferences as $preference)
            <article class="notification-preference-row">
                <span class="notification-type-icon"><i data-lucide="{{ $preference['icon'] }}"></i></span>
                <div class="notification-type-copy"><strong>{{ $preference['title'] }}</strong><small>{{ $preference['description'] }}</small></div>
                <label class="settings-switch">
                    <input type="checkbox" name="{{ $preference['key'] }}_app" @checked($preference['in_app']) @disabled($preference['required'] ?? false)>
                    <i></i><span class="sr-only">In-app {{ $preference['title'] }}</span>
                </label>
                <label class="settings-switch">
                    <input type="checkbox" name="{{ $preference['key'] }}_email" @checked($preference['email'])>
                    <i></i><span class="sr-only">Email {{ $preference['title'] }}</span>
                </label>
            </article>
        @endforeach
    </div>
</section>

<div class="notification-settings-bottom">
    <section class="settings-panel notification-delivery-card">
        <span class="settings-icon"><i data-lucide="mail-check"></i></span>
        <div><h3>Delivery email</h3><p>Notifications will be sent to your verified seller email.</p><strong>{{ $seller['email'] }}</strong></div>
        <a href="{{ route('seller.settings.account') }}">View account</a>
    </section>
    <section class="settings-panel notification-help-card">
        <span class="settings-icon"><i data-lucide="bell-ring"></i></span>
        <div><h3>Keep operational alerts enabled</h3><p>Order deadlines, pickup approvals, inventory shortages, and shipment exceptions directly affect fulfillment.</p></div>
    </section>
</div>
@endsection
