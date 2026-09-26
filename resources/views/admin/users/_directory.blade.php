<section class="page-hero">
    <div><span class="eyebrow">User Management</span><h1>{{ $role ? $roleLabel.' accounts' : 'Manage user accounts' }}</h1><p>Search approved accounts, inspect their profile details, and review their current platform access.</p></div>
</section>

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="users"></i></span><div><strong>{{ $userStats['total'] }}</strong><small>Total users</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-check-big"></i></span><div><strong>{{ $userStats['active'] }}</strong><small>Active</small></div></article>
    <article class="mini-stat"><span><i data-lucide="shield-ban"></i></span><div><strong>{{ $userStats['suspended'] }}</strong><small>Suspended</small></div></article>
    <article class="mini-stat"><span><i data-lucide="user-x"></i></span><div><strong>{{ $userStats['deactivated'] }}</strong><small>Deactivated</small></div></article>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Central directory</span><h2>{{ $role ? $roleLabel.' profiles' : 'User profiles' }}</h2></div>
        <form method="GET" action="{{ route($directoryRoute) }}" class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search users..."></label>
            @if (! $role)
                <select class="select-field" name="role">
                    <option value="">All roles</option>
                    @foreach (['buyer', 'seller', 'logistics', 'rider'] as $roleOption)
                        <option value="{{ $roleOption }}" @selected($filters['role'] === $roleOption)>{{ ucfirst($roleOption) }}</option>
                    @endforeach
                </select>
            @endif
            <select class="select-field" name="status">
                <option value="">All statuses</option>
                @foreach (['active', 'suspended', 'deactivated'] as $statusOption)
                    <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
            <button class="button button-secondary button-small" type="submit">Apply filters</button>
            @if (array_filter($filters))<a class="button button-ghost button-small" href="{{ route($directoryRoute) }}">Clear</a>@endif
        </form>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>User</th><th>Role</th><th>Business / Profile</th><th>Joined</th><th>Status</th><th class="align-right">Actions</th></tr></thead>
            <tbody>
            @forelse ($users as $account)
                @php
                    $profileName = $account->sellerProfile?->store?->name
                        ?? $account->logisticsProfile?->display_name
                        ?? $account->business_name
                        ?? '—';
                @endphp
                <tr>
                    <td><div class="identity-cell"><span class="avatar avatar-soft">{{ collect(explode(' ', $account->name))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') }}</span><div><strong>{{ $account->name }}</strong><small>USR-{{ str_pad((string) $account->id, 6, '0', STR_PAD_LEFT) }} • {{ $account->email }}</small></div></div></td>
                    <td><span class="role-badge role-{{ $account->role }}">{{ ucfirst($account->role) }}</span></td>
                    <td><strong>{{ $profileName }}</strong><small>{{ $account->contact_number ?: 'No contact number' }}</small></td>
                    <td>{{ $account->created_at?->format('M j, Y') ?? '—' }}</td>
                    <td><span class="status-badge {{ $account->status === 'active' ? 'badge-success' : ($account->status === 'suspended' ? 'badge-danger' : 'badge-neutral') }}">{{ ucfirst($account->status) }}</span></td>
                    <td class="align-right"><a class="button button-ghost button-small" href="{{ route('admin.users.show', $account) }}"><i data-lucide="eye"></i> View details</a></td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="table-empty"><i data-lucide="user-search"></i><strong>No matching users</strong><span>Adjust the selected filters.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($users->hasPages())<div class="panel-footer">{{ $users->links() }}</div>@endif
</section>
