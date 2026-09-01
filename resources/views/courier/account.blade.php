@extends('layouts.courier')

@section('title', 'Account Management')
@section('page-title', 'Account Management')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 10</span><h1>Courier account management</h1><p>Edit personal and vehicle details, manage delivery base locations, and preview account security settings.</p></div>
    <div class="hero-summary-card"><span class="avatar avatar-large avatar-warm">{{ $courier['initials'] }}</span><div><strong>{{ $courier['name'] }}</strong><small>★ {{ $courier['rating'] }} courier rating</small></div></div>
</section>

<section class="account-grid">
    <article class="panel profile-edit-panel">
        <div class="panel-heading"><div><span class="eyebrow">Courier profile</span><h2>Personal details</h2></div><button class="button button-secondary button-small" type="button" data-mock-action="Profile edit mode enabled."><i data-lucide="pencil"></i> Edit</button></div>
        <div class="profile-banner"><span class="avatar avatar-xxl avatar-warm">{{ $courier['initials'] }}</span><div><h3>{{ $courier['name'] }}</h3><p>{{ $courier['email'] }}</p><span class="status-badge badge-success">Approved courier</span></div></div>
        <div class="form-grid two-column-form">
            <label class="form-field"><span>First name</span><input type="text" value="{{ $profile['first_name'] }}"></label>
            <label class="form-field"><span>Last name</span><input type="text" value="{{ $profile['last_name'] }}"></label>
            <label class="form-field"><span>Email address</span><input type="email" value="{{ $profile['email'] }}"></label>
            <label class="form-field"><span>Contact number</span><input type="text" value="{{ $profile['phone'] }}"></label>
            <label class="form-field"><span>Birthday</span><input type="date" value="{{ $profile['birthday'] }}"></label>
            <label class="form-field"><span>Sex</span><select><option>{{ $profile['sex'] }}</option><option>Female</option><option>Prefer not to say</option></select></label>
        </div>
        <div class="panel-footer-actions"><button class="button button-primary" type="button" data-mock-action="Courier profile changes saved in preview."><i data-lucide="save"></i> Save profile</button></div>
    </article>

    <aside class="panel security-panel">
        <div class="panel-heading"><div><span class="eyebrow">Vehicle details</span><h2>Registered vehicle</h2></div></div>
        <div class="vehicle-card"><span><i data-lucide="bike"></i></span><div><strong>{{ $vehicle['model'] }}</strong><small>{{ $vehicle['type'] }} • Plate {{ $vehicle['plate'] }}</small></div></div>
        <div class="detail-grid" style="margin-top:10px"><div><span>OR / CR</span><strong>{{ $vehicle['orcr'] }}</strong></div><div><span>License / ID</span><strong>{{ $vehicle['license'] }}</strong></div></div>
        <div class="section-subtitle">Update vehicle</div>
        <label class="form-field"><span>Vehicle type</span><select><option>{{ $vehicle['type'] }}</option><option>Bicycle</option><option>Sedan / Car</option><option>Van</option></select></label>
        <label class="form-field"><span>Plate number</span><input type="text" value="{{ $vehicle['plate'] }}"></label>
        <button class="button button-secondary full-button" type="button" data-mock-action="Vehicle update request saved in preview."><i data-lucide="car-front"></i> Save vehicle details</button>
    </aside>
</section>

<section class="dashboard-grid dashboard-grid-secondary" style="margin-top:16px">
    <article class="panel">
        <div class="panel-heading panel-heading-wrap"><div><span class="eyebrow">Manage addresses</span><h2>Delivery base locations</h2><p>Saved areas used as courier starting points.</p></div><button class="button button-primary button-small" type="button" data-add-address><i data-lucide="map-pin-plus"></i> Add address</button></div>
        <div class="address-list">@foreach($addresses as $address)<div class="address-card"><span class="avatar avatar-soft"><i data-lucide="map-pin"></i></span><div><strong>{{ $address['label'] }}</strong><small>{{ $address['address'] }} • {{ $address['note'] }}</small></div><button class="icon-button" type="button" data-mock-action="Address editor opened."><i data-lucide="pencil"></i></button></div>@endforeach</div>
    </article>

    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Security</span><h2>Change password</h2></div></div>
        <label class="form-field"><span>Current password</span><input type="password" placeholder="••••••••"></label>
        <label class="form-field"><span>New password</span><input type="password" placeholder="At least 8 characters"></label>
        <label class="form-field"><span>Confirm new password</span><input type="password" placeholder="Repeat new password"></label>
        <div class="password-rules"><span><i data-lucide="check"></i> 8+ characters</span><span><i data-lucide="check"></i> Mix letters and numbers</span></div>
        <button class="button button-primary full-button" type="button" data-mock-action="Password update simulated successfully.">Update password</button>
        <div class="security-callout"><i data-lucide="shield-check"></i><div><strong>Other settings</strong><p>Notification preferences, privacy settings, and real account security can be connected during backend development.</p></div></div>
    </article>
</section>
@endsection
