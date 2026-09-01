@extends('layouts.admin')

@section('title', 'Account Management')
@section('page-title', 'Account Management')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 10</span><h1>Account management</h1><p>Manage the administrator profile, preview sub-admin access, and update account security settings.</p></div>
    <div class="hero-summary-card"><span class="avatar avatar-large avatar-warm">{{ $admin['initials'] }}</span><div><strong>{{ $admin['name'] }}</strong><small>{{ $admin['role'] }}</small></div></div>
</section>

<section class="account-grid">
    <article class="panel profile-edit-panel">
        <div class="panel-heading"><div><span class="eyebrow">Admin profile</span><h2>View / edit profile</h2></div><button class="button button-secondary button-small" type="button" data-mock-action="Profile edit mode enabled."><i data-lucide="pencil"></i> Edit</button></div>
        <div class="profile-banner"><span class="avatar avatar-xxl avatar-warm">{{ $admin['initials'] }}</span><div><h3>{{ $admin['name'] }}</h3><p>{{ $admin['email'] }}</p><span class="status-badge badge-success">Active administrator</span></div></div>
        <div class="form-grid two-column-form">
            <label class="form-field"><span>First name</span><input type="text" value="{{ $profile['first_name'] }}"></label>
            <label class="form-field"><span>Last name</span><input type="text" value="{{ $profile['last_name'] }}"></label>
            <label class="form-field"><span>Email address</span><input type="email" value="{{ $profile['email'] }}"></label>
            <label class="form-field"><span>Contact number</span><input type="text" value="{{ $profile['phone'] }}"></label>
        </div>
        <label class="form-field"><span>Role</span><input type="text" value="{{ $profile['role'] }}" readonly></label>
        <div class="panel-footer-actions"><button class="button button-primary" type="button" data-mock-action="Admin profile changes saved in preview."><i data-lucide="save"></i> Save changes</button></div>
    </article>

    <aside class="panel security-panel">
        <div class="panel-heading"><div><span class="eyebrow">Security</span><h2>Change password</h2></div></div>
        <label class="form-field"><span>Current password</span><input type="password" placeholder="••••••••"></label>
        <label class="form-field"><span>New password</span><input type="password" placeholder="At least 8 characters"></label>
        <label class="form-field"><span>Confirm new password</span><input type="password" placeholder="Repeat new password"></label>
        <div class="password-rules"><span><i data-lucide="check"></i> 8+ characters</span><span><i data-lucide="check"></i> Mix letters and numbers</span></div>
        <button class="button button-primary full-button" type="button" data-mock-action="Password update simulated successfully.">Update password</button>
        <div class="security-callout"><i data-lucide="shield-check"></i><div><strong>Account security</strong><p>Two-factor authentication and real password validation can be wired in during backend development.</p></div></div>
    </aside>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap"><div><span class="eyebrow">Admin access</span><h2>Manage admin accounts</h2><p>Create or remove sub-admin accounts in this front-end preview.</p></div><button class="button button-primary button-small" type="button" data-open-modal="new-admin"><i data-lucide="user-plus"></i> Add admin</button></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Administrator</th><th>Role</th><th>Status</th><th class="align-right">Actions</th></tr></thead><tbody>
        @foreach($admins as $index => $member)
            <tr><td><div class="identity-cell"><span class="avatar avatar-soft">{{ collect(explode(' ', $member['name']))->map(fn($part) => strtoupper(substr($part,0,1)))->take(2)->implode('') }}</span><div><strong>{{ $member['name'] }}</strong><small>{{ $member['email'] }}</small></div></div></td><td>{{ $member['role'] }}</td><td><span class="status-badge badge-success">{{ $member['status'] }}</span></td><td class="align-right"><div class="row-actions"><button class="button button-ghost button-small" type="button" data-mock-action="Permissions editor opened for {{ $member['name'] }}."><i data-lucide="key-round"></i> Permissions</button>@if($index !== 0)<button class="icon-button danger-icon" type="button" data-mock-action="Sub-admin removal simulated."><i data-lucide="trash-2"></i></button>@endif</div></td></tr>
        @endforeach
    </tbody></table></div>
</section>

<div class="modal-shell" data-modal="new-admin" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-label="Create admin account">
        <div class="modal-heading"><div><span class="eyebrow">Sub-admin access</span><h2>Create admin account</h2></div><button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <div class="form-grid two-column-form"><label class="form-field"><span>First name</span><input type="text" placeholder="First name"></label><label class="form-field"><span>Last name</span><input type="text" placeholder="Last name"></label></div>
        <label class="form-field"><span>Email</span><input type="email" placeholder="admin@example.com"></label>
        <label class="form-field"><span>Admin role</span><select><option>Operations Admin</option><option>Support Admin</option><option>Compliance Admin</option></select></label>
        <div class="modal-footer"><button class="button button-secondary" type="button" data-close-modal>Cancel</button><button class="button button-primary" type="button" data-close-modal data-mock-action="New sub-admin account created in preview."><i data-lucide="user-plus"></i> Create admin</button></div>
    </section>
</div>
@endsection
