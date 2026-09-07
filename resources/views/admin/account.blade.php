@extends('layouts.admin')

@section('title', 'Account Management')
@section('page-title', 'Account Management')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 10</span><h1>Account management</h1><p>Manage the administrator profile, preview sub-admin access, and update account security settings.</p></div>
    <div class="hero-summary-card"><span class="avatar avatar-large avatar-warm" data-profile-avatar data-current-admin-avatar>{{ $admin['initials'] }}</span><div><strong data-profile-name data-current-admin-name>{{ $admin['name'] }}</strong><small>{{ $admin['role'] }}</small></div></div>
</section>

<section class="account-grid">
    <article class="panel profile-edit-panel">
        <div class="panel-heading"><div><span class="eyebrow">Admin profile</span><h2>View / edit profile</h2></div><button class="button button-secondary button-small" type="button" data-profile-edit><i data-lucide="pencil"></i> Edit</button></div>
        <div class="profile-banner"><span class="avatar avatar-xxl avatar-warm" data-profile-avatar data-current-admin-avatar>{{ $admin['initials'] }}</span><div><h3 data-profile-name data-current-admin-name>{{ $admin['name'] }}</h3><p data-profile-email data-current-admin-email>{{ $admin['email'] }}</p><span class="status-badge badge-success">Active administrator</span></div></div>
        <div class="form-grid two-column-form">
            <label class="form-field"><span>First name</span><input type="text" value="{{ $profile['first_name'] }}" data-profile-field="first_name" readonly></label>
            <label class="form-field"><span>Last name</span><input type="text" value="{{ $profile['last_name'] }}" data-profile-field="last_name" readonly></label>
            <label class="form-field"><span>Email address</span><input type="email" value="{{ $profile['email'] }}" data-profile-field="email" readonly></label>
            <label class="form-field"><span>Contact number</span><input type="text" value="{{ $profile['phone'] }}" data-profile-field="phone" readonly></label>
        </div>
        <label class="form-field"><span>Role</span><input type="text" value="{{ $profile['role'] }}" readonly></label>
        <div class="panel-footer-actions"><button class="button button-primary" type="button" data-profile-save disabled><i data-lucide="save"></i> Save changes</button></div>
    </article>

    <aside class="panel security-panel">
        <div class="panel-heading"><div><span class="eyebrow">Security</span><h2>Change password</h2></div></div>
        <label class="form-field"><span>Current password</span><input type="password" placeholder="••••••••" data-password-current></label>
        <label class="form-field"><span>New password</span><input type="password" placeholder="At least 8 characters" data-password-new></label>
        <label class="form-field"><span>Confirm new password</span><input type="password" placeholder="Repeat new password" data-password-confirm></label>
        <div class="password-rules"><span><i data-lucide="check"></i> 8+ characters</span><span><i data-lucide="check"></i> Mix letters and numbers</span></div>
        <button class="button button-primary full-button" type="button" data-password-update>Update password</button>
        <div class="security-callout"><i data-lucide="shield-check"></i><div><strong>Account security</strong><p>Two-factor authentication and real password validation can be wired in during backend development.</p></div></div>
    </aside>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap"><div><span class="eyebrow">Admin access</span><h2>Manage admin accounts</h2><p>Create or remove sub-admin accounts in this front-end preview.</p></div><button class="button button-primary button-small" type="button" data-open-modal="new-admin"><i data-lucide="user-plus"></i> Add admin</button></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Administrator</th><th>Role</th><th>Status</th><th class="align-right">Actions</th></tr></thead><tbody data-admin-accounts-body>
        @foreach($admins as $index => $member)
            <tr
                data-admin-account
                data-admin-name="{{ $member['name'] }}"
                data-admin-email="{{ $member['email'] }}"
                data-admin-role="{{ $member['role'] }}"
                data-admin-status="{{ $member['status'] }}"
                @if($index === 0) data-current-admin-row @endif
            >
                <td>
                    <div class="identity-cell">
                        <span class="avatar avatar-soft" @if($index === 0) data-current-admin-avatar @endif>{{ collect(explode(' ', $member['name']))->map(fn($part) => strtoupper(substr($part,0,1)))->take(2)->implode('') }}</span>
                        <div>
                            <strong @if($index === 0) data-current-admin-name @endif>{{ $member['name'] }}</strong>
                            <small @if($index === 0) data-current-admin-email @endif>{{ $member['email'] }}</small>
                        </div>
                    </div>
                </td>
                <td data-admin-role-cell>{{ $member['role'] }}</td>
                <td><span class="status-badge badge-success">{{ $member['status'] }}</span></td>
                <td class="align-right">
                    <div class="row-actions">
                        <button
                            class="button button-ghost button-small"
                            type="button"
                            data-admin-permissions
                            data-admin-email="{{ $member['email'] }}"
                            data-admin-name="{{ $member['name'] }}"
                        >
                            <i data-lucide="key-round"></i> Permissions
                        </button>
                        @if($index !== 0)
                            <button
                                class="icon-button danger-icon"
                                type="button"
                                data-admin-remove
                                data-admin-email="{{ $member['email'] }}"
                                data-admin-name="{{ $member['name'] }}"
                                aria-label="Remove {{ $member['name'] }}"
                            >
                                <i data-lucide="trash-2"></i>
                            </button>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </tbody></table></div>
</section>

<div class="modal-shell" data-modal="new-admin" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-label="Create admin account">
        <div class="modal-heading"><div><span class="eyebrow">Sub-admin access</span><h2>Create admin account</h2></div><button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button></div>
        <div class="form-grid two-column-form"><label class="form-field"><span>First name</span><input type="text" placeholder="First name" data-new-admin-first></label><label class="form-field"><span>Last name</span><input type="text" placeholder="Last name" data-new-admin-last></label></div>
        <label class="form-field"><span>Email</span><input type="email" placeholder="admin@example.com" data-new-admin-email></label>
        <label class="form-field"><span>Admin role</span><select data-new-admin-role><option>Operations Admin</option><option>Support Admin</option><option>Compliance Admin</option></select></label>
        <div class="modal-footer"><button class="button button-secondary" type="button" data-close-modal>Cancel</button><button class="button button-primary" type="button" data-create-admin><i data-lucide="user-plus"></i> Create admin</button></div>
    </section>
</div>
<div class="modal-shell" data-modal="admin-permissions" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-label="Edit admin permissions">
        <div class="modal-heading">
            <div>
                <span class="eyebrow">Admin access</span>
                <h2>Manage permissions</h2>
                <p data-permissions-admin-label></p>
            </div>
            <button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button>
        </div>

        <div class="form-grid">
            <label class="form-field">
                <span>Admin role</span>
                <select data-permissions-role>
                    <option>Operations Admin</option>
                    <option>Support Admin</option>
                    <option>Compliance Admin</option>
                    <option>Super Admin</option>
                </select>
            </label>

            <div class="permission-list">
                <label><input type="checkbox" value="users" data-permission-option> User management</label>
                <label><input type="checkbox" value="registrations" data-permission-option> Registration management</label>
                <label><input type="checkbox" value="compliance" data-permission-option> Compliance & disputes</label>
                <label><input type="checkbox" value="reports" data-permission-option> Finance & reports</label>
                <label><input type="checkbox" value="messages" data-permission-option> Messages & announcements</label>
                <label><input type="checkbox" value="settings" data-permission-option> Platform settings</label>
            </div>
        </div>

        <div class="modal-footer">
            <button class="button button-secondary" type="button" data-close-modal>Cancel</button>
            <button class="button button-primary" type="button" data-save-admin-permissions><i data-lucide="save"></i> Save permissions</button>
        </div>
    </section>
</div>

<div class="modal-shell" data-modal="remove-admin" hidden>
    <button class="modal-backdrop" type="button" data-close-modal></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-label="Remove admin account">
        <div class="modal-heading">
            <div>
                <span class="eyebrow">Admin access</span>
                <h2>Remove admin account?</h2>
            </div>
            <button class="icon-button" type="button" data-close-modal><i data-lucide="x"></i></button>
        </div>

        <p>This will remove <strong data-remove-admin-name>this sub-admin</strong> from the front-end preview.</p>

        <div class="modal-footer">
            <button class="button button-secondary" type="button" data-close-modal>Cancel</button>
            <button class="button button-danger" type="button" data-confirm-admin-remove><i data-lucide="trash-2"></i> Remove admin</button>
        </div>
    </section>
</div>

@endsection
