@extends('layouts.admin')

@section('title', 'User Accounts')
@section('page-title', 'Manage User Accounts')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">User Management</span>
        <h1>Manage user accounts</h1>
        <p>Search approved user profiles and review their current account status.</p>
    </div>
    <div class="hero-actions">
        <button class="button button-secondary" type="button" data-export-users="users-table">
            <i data-lucide="download"></i> Export list
        </button>
    </div>
</section>

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="users"></i></span><div><strong data-user-kpi="total">{{ $userStats['total'] }}</strong><small>Total users</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-check-big"></i></span><div><strong data-user-kpi="active">{{ $userStats['active'] }}</strong><small>Active</small></div></article>
    <article class="mini-stat"><span><i data-lucide="shield-ban"></i></span><div><strong data-user-kpi="suspended">{{ $userStats['suspended'] }}</strong><small>Suspended</small></div></article>
    <article class="mini-stat"><span><i data-lucide="user-x"></i></span><div><strong data-user-kpi="deactivated">{{ $userStats['deactivated'] }}</strong><small>Deactivated</small></div></article>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Central directory</span><h2>User profiles</h2></div>
        <div class="table-toolbar">
            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>
                <input type="search" placeholder="Search users..." data-table-search="users-table">
            </label>
            <select class="select-field" data-table-filter="users-table" data-filter-key="role">
                <option value="">All roles</option><option>Buyer</option><option>Seller</option><option>Logistics</option><option>Rider</option>
            </select>
            <select class="select-field" data-table-filter="users-table" data-filter-key="status">
                <option value="">All statuses</option><option>Active</option><option>Suspended</option><option>Deactivated</option>
            </select>
        </div>
    </div>

    <div class="table-wrap">
        <table class="admin-table admin-user-directory-table" id="users-table">
            <thead>
                <tr><th>User</th><th>Role</th><th>Joined</th><th>Status</th><th class="align-right">Account controls</th></tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr
                        data-table-row
                        data-role="{{ $user['role'] }}"
                        data-status="{{ $user['status'] }}"
                        data-search="{{ strtolower($user['name'].' '.$user['owner'].' '.$user['email'].' '.$user['id']) }}"
                        data-user-status-context
                        data-user-id="{{ $user['database_id'] }}"
                        data-user-name="{{ $user['owner'] }}"
                        data-status-url="{{ route('admin.users.status', $user['database_id']) }}"
                        data-current-status="{{ strtolower($user['status']) }}"
                        data-allowed-statuses="{{ implode(',', $user['allowed_statuses']) }}"
                    >
                        <td>
                            <div class="identity-cell">
                                <span class="avatar avatar-soft" aria-hidden="true">{{ collect(explode(' ', $user['name']))->filter()->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') }}</span>
                                <div>
                                    <strong>{{ $user['name'] }}</strong>
                                    <small>{{ $user['id'] }} • {{ $user['email'] }}</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="role-badge role-{{ strtolower($user['role']) }}">{{ $user['role'] }}</span></td>
                        <td>{{ $user['joined'] }}</td>
                        <td>
                            <span class="status-badge js-status-badge {{ $user['status'] === 'Active' ? 'badge-success' : ($user['status'] === 'Suspended' ? 'badge-warning' : 'badge-danger') }}">
                                {{ $user['status'] }}
                            </span>
                        </td>
                        <td class="align-right">
                            <div class="admin-user-list-actions">
                                @include('admin.users._row-status-actions', ['user' => $user])
                                <button
                                    type="button"
                                    class="button button-ghost button-small"
                                    data-open-modal="all-user-{{ $user['database_id'] }}"
                                    aria-label="View {{ $user['owner'] }} profile"
                                >
                                    <i data-lucide="eye"></i><span>View</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="table-empty" data-table-empty="users-table" hidden>
            <i data-lucide="user-search"></i><strong>No matching users</strong><span>Adjust the selected filters.</span>
        </div>
    </div>
</section>

@foreach ($users as $user)
    @php
        $roleDetails = match ($user['role']) {
            'Buyer' => [
                ['label' => 'Total orders', 'value' => $user['orders'], 'icon' => 'package'],
            ],
            'Seller' => [
                ['label' => 'Business name', 'value' => $user['name'], 'icon' => 'store'],
                ['label' => 'Registered category', 'value' => $user['category'], 'icon' => 'tag'],
                ['label' => 'Product listings', 'value' => $user['products'], 'icon' => 'package'],
            ],
            'Logistics' => [
                ['label' => 'Center name', 'value' => $user['name'], 'icon' => 'warehouse'],
                ['label' => 'Assigned riders', 'value' => $user['riders'], 'icon' => 'bike'],
                ['label' => 'Delivered shipments', 'value' => $user['deliveries'], 'icon' => 'package-check'],
            ],
            'Rider' => [
                ['label' => 'Vehicle', 'value' => $user['vehicle'], 'icon' => 'bike'],
                ['label' => 'Plate number', 'value' => $user['plate'], 'icon' => 'hash'],
                ['label' => 'Assigned Logistics Center', 'value' => $user['center'], 'icon' => 'warehouse'],
                ['label' => 'Completed deliveries', 'value' => $user['deliveries'], 'icon' => 'package-check'],
            ],
            default => [],
        };
        $summaryStats = match ($user['role']) {
            'Buyer' => [['label' => 'Total orders', 'value' => $user['orders'], 'icon' => 'package']],
            'Seller' => [['label' => 'Product listings', 'value' => $user['products'], 'icon' => 'store']],
            'Logistics' => [
                ['label' => 'Assigned riders', 'value' => $user['riders'], 'icon' => 'bike'],
                ['label' => 'Delivered shipments', 'value' => $user['deliveries'], 'icon' => 'package-check'],
            ],
            'Rider' => [['label' => 'Completed deliveries', 'value' => $user['deliveries'], 'icon' => 'package-check']],
            default => [],
        };
        $roleIcon = match ($user['role']) {
            'Buyer' => 'shopping-bag',
            'Seller' => 'store',
            'Logistics' => 'warehouse',
            'Rider' => 'bike',
            default => 'user-round',
        };
    @endphp

    @include('admin.users._profile-modal', [
        'modalId' => 'all-user-'.$user['database_id'],
        'userId' => $user['database_id'],
        'profileCode' => $user['id'],
        'profileName' => $user['owner'],
        'profileEmail' => $user['email'],
        'profileRole' => $user['role'],
        'roleClass' => 'role-'.strtolower($user['role']),
        'roleIcon' => $roleIcon,
        'profileTitle' => $user['role'].' profile',
        'profileDescription' => 'Review this account’s contact details and platform activity.',
        'status' => $user['status'],
        'statusUrl' => route('admin.users.status', $user['database_id']),
        'allowedStatuses' => $user['allowed_statuses'],
        'summaryStats' => $summaryStats,
        'details' => array_merge([
            ['label' => 'Email address', 'value' => $user['email'], 'icon' => 'mail'],
            ['label' => 'Contact number', 'value' => $user['contact'], 'icon' => 'phone'],
            ['label' => 'Location', 'value' => $user['location'], 'icon' => 'map-pin'],
            ['label' => 'Date joined', 'value' => $user['joined'], 'icon' => 'calendar-days'],
        ], $roleDetails),
    ])
@endforeach

@include('admin.users._status-change-dialog')
@endsection
