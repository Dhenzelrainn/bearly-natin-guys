@extends('layouts.admin')

@section('title', 'Logistics Centers')
@section('page-title', 'Logistics Centers')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">User Management</span>
        <h1>Logistics Centers</h1>

        <p>
            View approved Logistics accounts, assigned riders,
            location information, and current platform status.
        </p>
    </div>

    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="warehouse"></i>
        </span>

        <div>
            <strong>{{ count($users) }}</strong>
            <span>Logistics Centers</span>
        </div>
    </div>
</section>

<section class="panel" aria-labelledby="shipping-policy-owner-title">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Bearly Logistics policy governance</span>
            <h2 id="shipping-policy-owner-title">Appointed policy manager</h2>
        </div>
    </div>

    <p class="detail-note">
        One active Logistics account manages shared Bearly Logistics shipping and Rider policies. Other Logistics accounts may view published policies. Changes are audit-logged with the reason provided.
    </p>

    @if ($hasPolicyManagerAssignment)
        <p class="detail-note">
            Current assignment: <strong>{{ $policyManagerProfile?->display_name ?? 'Unresolved Logistics profile' }}</strong>
            @if ($policyManagerProfile?->user)
                · {{ $policyManagerProfile->user->name }} ({{ $policyManagerProfile->user->status }})
            @endif
            @if ($policyManagerProfile?->trashed() || $policyManagerProfile?->user?->trashed())
                · This account/profile has been deleted and must be replaced or explicitly removed.
            @elseif ($policyManagerProfile && ($policyManagerProfile->status !== 'active' || $policyManagerProfile->user?->status !== 'active'))
                · This manager is inactive and cannot edit policies.
            @endif
        </p>
    @else
        <p class="detail-note">No Logistics policy manager is currently assigned.</p>
    @endif

    <form method="POST" action="{{ route('admin.users.logistics.shipping-policy-manager') }}" class="announcement-form">
        @csrf

        <div class="form-grid two-column-form">
            <label class="form-field" for="shipping-policy-manager-profile">
                <span>Active Logistics account</span>
                <select class="select-field" id="shipping-policy-manager-profile" name="logistics_profile_id" required>
                    <option value="">Choose an active account</option>
                    @foreach ($eligiblePolicyManagers as $profile)
                        <option value="{{ $profile->id }}" @selected((string) old('logistics_profile_id', $policyManagerProfile?->id) === (string) $profile->id)>
                            {{ $profile->display_name }} — {{ $profile->user?->name }}
                        </option>
                    @endforeach
                </select>
                @error('logistics_profile_id')<small class="field-error">{{ $message }}</small>@enderror
            </label>

            <label class="form-field" for="shipping-policy-manager-reason">
                <span>Reason for this manager change</span>
                <input
                    class="text-field"
                    id="shipping-policy-manager-reason"
                    name="reason"
                    type="text"
                    value="{{ old('reason') }}"
                    minlength="5"
                    maxlength="1000"
                    required
                >
                @error('reason')<small class="field-error">{{ $message }}</small>@enderror
            </label>
        </div>

        <div class="modal-footer decision-footer">
            <button type="submit" name="action" value="assign" class="button button-primary">
                <i data-lucide="shield-check" aria-hidden="true"></i>
                Assign / replace manager
            </button>
            @if ($hasPolicyManagerAssignment)
                <button type="submit" name="action" value="remove" class="button button-danger-soft" formnovalidate>
                    <i data-lucide="shield-off" aria-hidden="true"></i>
                    Remove manager
                </button>
            @endif
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Logistics directory</span>
            <h2>Registered Logistics Centers</h2>
        </div>

        <div class="table-toolbar">
            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search logistics center..."
                    data-table-search="logistics-users-table"
                >
            </label>

            <select
                class="select-field"
                data-table-filter="logistics-users-table"
                data-filter-key="status"
            >
                <option value="">All statuses</option>
                <option value="Active">Active</option>
                <option value="Suspended">Suspended</option>
                <option value="Deactivated">Deactivated</option>
            </select>
        </div>
    </div>

    <div class="table-wrap">
        <table class="admin-table admin-user-directory-table" id="logistics-users-table">
            <thead>
                <tr>
                    <th>Logistics Center</th>
                    <th>Manager</th>
                    <th>Location</th>
                    <th>Riders</th>
                    <th>Joined</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($users as $user)
                    <tr
                        data-table-row
                        data-user-status-context
                        data-user-id="{{ $user['database_id'] }}"
                        data-user-name="{{ $user['manager'] }}"
                        data-status-url="{{ route('admin.users.status', $user['database_id']) }}"
                        data-current-status="{{ strtolower($user['status']) }}"
                        data-allowed-statuses="{{ implode(',', $user['allowed_statuses']) }}"
                        data-status="{{ $user['status'] }}"
                        data-search="{{ strtolower(
                            $user['name'].' '.
                            $user['manager'].' '.
                            $user['email'].' '.
                            $user['location'].' '.
                            $user['id']
                        ) }}"
                    >
                        <td>
                            <div class="identity-cell">
                                <span class="avatar avatar-soft">
                                    {{
                                        collect(explode(' ', $user['name']))
                                            ->filter()
                                            ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                                            ->take(2)
                                            ->implode('')
                                    }}
                                </span>

                                <div>
                                    <strong>{{ $user['name'] }}</strong>
                                    <small>{{ $user['id'] }} • {{ $user['email'] }}</small>
                                </div>
                            </div>
                        </td>

                        <td>{{ $user['manager'] }}</td>
                        <td>{{ $user['location'] }}</td>

                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $user['riders'] }}</strong>
                                <small>Assigned riders</small>
                            </div>
                        </td>

                        <td>{{ $user['joined'] }}</td>

                        <td>
                            <span
                                class="status-badge js-status-badge
                                {{
                                    $user['status'] === 'Active'
                                        ? 'badge-success'
                                        : ($user['status'] === 'Suspended'
                                            ? 'badge-warning'
                                            : 'badge-danger')
                                }}"
                            >
                                {{ $user['status'] }}
                            </span>
                        </td>

                        <td class="align-right">
                            <div class="admin-user-list-actions">
                                @include('admin.users._row-status-actions', ['user' => $user])
                                <button
                                    type="button"
                                    class="button button-ghost button-small"
                                    data-open-modal="logistics-user-{{ $user['database_id'] }}"
                                    aria-label="View {{ $user['manager'] }} profile"
                                >
                                    <i data-lucide="eye"></i><span>View</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="table-empty">
                                <i data-lucide="warehouse"></i>
                                <strong>No Logistics Centers found</strong>
                                <span>Approved Logistics accounts will appear here automatically.</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if (count($users) > 0)
            <div
                class="table-empty"
                data-table-empty="logistics-users-table"
                hidden
            >
                <i data-lucide="search-x"></i>
                <strong>No Logistics Centers found</strong>
                <span>Try another search or filter.</span>
            </div>
        @endif
    </div>
</section>

@foreach ($users as $user)
    @include('admin.users._profile-modal', [
        'modalId' => 'logistics-user-'.$user['database_id'],
        'userId' => $user['database_id'],
        'profileCode' => $user['id'],
        'profileName' => $user['manager'],
        'profileEmail' => $user['email'],
        'profileRole' => 'Logistics Center',
        'roleClass' => 'role-logistics',
        'roleIcon' => 'warehouse',
        'profileTitle' => 'Logistics Center profile',
        'profileDescription' => 'Review the center’s account, assigned riders, and delivery activity.',
        'status' => $user['status'],
        'statusUrl' => route('admin.users.status', $user['database_id']),
        'allowedStatuses' => $user['allowed_statuses'],
        'summaryStats' => [
            ['label' => 'Assigned riders', 'value' => $user['riders'], 'icon' => 'bike'],
            ['label' => 'Delivered shipments', 'value' => $user['deliveries'], 'icon' => 'package-check'],
        ],
        'details' => [
            ['label' => 'Email address', 'value' => $user['email'], 'icon' => 'mail'],
            ['label' => 'Contact number', 'value' => $user['contact'], 'icon' => 'phone'],
            ['label' => 'Location', 'value' => $user['location'], 'icon' => 'map-pin'],
            ['label' => 'Center name', 'value' => $user['name'], 'icon' => 'warehouse'],
            ['label' => 'Assigned riders', 'value' => $user['riders'], 'icon' => 'bike'],
            ['label' => 'Delivered shipments', 'value' => $user['deliveries'], 'icon' => 'package-check'],
            ['label' => 'Date joined', 'value' => $user['joined'], 'icon' => 'calendar-days'],
        ],
    ])
@endforeach

@include('admin.users._status-change-dialog')

@endsection
