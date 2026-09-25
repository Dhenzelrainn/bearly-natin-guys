    @extends('layouts.admin')

    @section('title', 'User Accounts')
    @section('page-title', 'Manage User Accounts')

    @section('content')
    <section class="page-hero">
        <div><span class="eyebrow">User Management</span><h1>Manage user accounts</h1><p>Search approved user profiles and review their current account status.</p></div>
        <div class="hero-actions"><button class="button button-secondary" type="button" data-export-users="users-table"><i data-lucide="download"></i> Export list</button></div>
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
                <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Search users..." data-table-search="users-table"></label>
                <select class="select-field" data-table-filter="users-table" data-filter-key="role"><option value="">All roles</option><option>Buyer</option><option>Seller</option><option>Logistics</option><option>Rider</option></select>
                <select class="select-field" data-table-filter="users-table" data-filter-key="status"><option value="">All statuses</option><option>Active</option><option>Suspended</option><option>Deactivated</option></select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="admin-table" id="users-table">
                <thead><tr><th>User</th><th>Role</th><th>Joined</th><th>Status</th><th class="align-right">Account controls</th></tr></thead>
                <tbody>
                @foreach ($users as $user)
                    <tr
                        data-table-row
                        data-role="{{ $user['role'] }}"
                        data-status="{{ $user['status'] }}"
                        data-search="{{ strtolower($user['name'].' '.$user['email'].' '.$user['id']) }}"
                        data-user-id="{{ $user['id'] }}"
                        data-user-name="{{ $user['name'] }}"
                        data-user-email="{{ $user['email'] }}"
                        data-user-joined="{{ $user['joined'] }}"
                    >
                        <td><div class="identity-cell"><span class="avatar avatar-soft">{{ collect(explode(' ', $user['name']))->map(fn($part) => strtoupper(substr($part,0,1)))->take(2)->implode('') }}</span><div><strong>{{ $user['name'] }}</strong><small>{{ $user['id'] }} • {{ $user['email'] }}</small></div></div></td>
                        <td><span class="role-badge role-{{ strtolower($user['role']) }}">{{ $user['role'] }}</span></td>
                        <td>{{ $user['joined'] }}</td>
                        <td><span class="status-badge js-status-badge {{ $user['status'] === 'Active' ? 'badge-success' : ($user['status'] === 'Suspended' ? 'badge-danger' : 'badge-neutral') }}">{{ $user['status'] }}</span></td>
                        <td class="align-right">

                            <div class="row-actions account-action-group" data-account-actions>

                                {{-- Dynamic primary action --}}
                                <button
                                    type="button"
                                    class="button button-ghost button-small"
                                    data-user-primary-action
                                >
                                    <i data-lucide="pause-circle"></i>
                                    <span>Suspend</span>
                                </button>


                                {{-- More actions --}}
                                <button
                                    type="button"
                                    class="icon-button table-more"
                                    data-registration-menu-toggle="user-menu-{{ $loop->index }}"
                                    aria-label="More account actions for {{ $user['name'] }}"
                                    aria-expanded="false"
                                >
                                    <i data-lucide="ellipsis"></i>
                                </button>


                                <div
                                    class="registration-action-menu user-account-menu"
                                    data-registration-menu="user-menu-{{ $loop->index }}"
                                    hidden
                                >

                                    <button
                                        type="button"
                                        data-user-status="Active"
                                        data-user-menu-action="Active"
                                    >
                                        <i data-lucide="circle-check-big"></i>
                                        <span>Activate account</span>
                                    </button>

                                    <button
                                        type="button"
                                        data-user-status="Suspended"
                                        data-user-menu-action="Suspended"
                                    >
                                        <i data-lucide="pause-circle"></i>
                                        <span>Suspend account</span>
                                    </button>

                                    <button
                                        type="button"
                                        data-user-status="Deactivated"
                                        data-user-menu-action="Deactivated"
                                    >
                                        <i data-lucide="user-x"></i>
                                        <span>Deactivate account</span>
                                    </button>

                                </div>

                            </div>

                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="table-empty" data-table-empty="users-table" hidden><i data-lucide="user-search"></i><strong>No matching users</strong><span>Adjust the selected filters.</span></div>
        </div>
    </section>
    @endsection
