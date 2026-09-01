@extends('layouts.admin')

@section('title', 'User Accounts')
@section('page-title', 'Manage User Accounts')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 03</span><h1>Manage user accounts</h1><p>Search user profiles and simulate account activation, suspension, or deactivation without touching a database.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-mock-action="User list exported as a preview."><i data-lucide="download"></i> Export list</button></div>
</section>

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="users"></i></span><div><strong>24,860</strong><small>Total users</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-check-big"></i></span><div><strong>23,914</strong><small>Active</small></div></article>
    <article class="mini-stat"><span><i data-lucide="shield-ban"></i></span><div><strong>128</strong><small>Suspended</small></div></article>
    <article class="mini-stat"><span><i data-lucide="user-x"></i></span><div><strong>818</strong><small>Deactivated</small></div></article>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Central directory</span><h2>User profiles</h2></div>
        <div class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Search users..." data-table-search="users-table"></label>
            <select class="select-field" data-table-filter="users-table" data-filter-key="role"><option value="">All roles</option><option>Buyer</option><option>Seller</option><option>Courier</option></select>
            <select class="select-field" data-table-filter="users-table" data-filter-key="status"><option value="">All statuses</option><option>Active</option><option>Suspended</option><option>Deactivated</option></select>
        </div>
    </div>
    <div class="table-wrap">
        <table class="admin-table" id="users-table">
            <thead><tr><th>User</th><th>Role</th><th>Joined</th><th>Status</th><th class="align-right">Account controls</th></tr></thead>
            <tbody>
            @foreach ($users as $user)
                <tr data-table-row data-role="{{ $user['role'] }}" data-status="{{ $user['status'] }}" data-search="{{ strtolower($user['name'].' '.$user['email'].' '.$user['id']) }}">
                    <td><div class="identity-cell"><span class="avatar avatar-soft">{{ collect(explode(' ', $user['name']))->map(fn($part) => strtoupper(substr($part,0,1)))->take(2)->implode('') }}</span><div><strong>{{ $user['name'] }}</strong><small>{{ $user['id'] }} • {{ $user['email'] }}</small></div></div></td>
                    <td><span class="role-badge role-{{ strtolower($user['role']) }}">{{ $user['role'] }}</span></td>
                    <td>{{ $user['joined'] }}</td>
                    <td><span class="status-badge js-status-badge {{ $user['status'] === 'Active' ? 'badge-success' : ($user['status'] === 'Suspended' ? 'badge-danger' : 'badge-neutral') }}">{{ $user['status'] }}</span></td>
                    <td class="align-right"><div class="row-actions account-action-group" data-account-actions>
                        <button type="button" class="action-chip action-activate" data-user-status="Active">Activate</button>
                        <button type="button" class="action-chip action-suspend" data-user-status="Suspended">Suspend</button>
                        <button type="button" class="action-chip action-deactivate" data-user-status="Deactivated">Deactivate</button>
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="table-empty" data-table-empty="users-table" hidden><i data-lucide="user-search"></i><strong>No matching users</strong><span>Adjust the selected filters.</span></div>
    </div>
</section>
@endsection
