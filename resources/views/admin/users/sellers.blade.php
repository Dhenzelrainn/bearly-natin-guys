@extends('layouts.admin')

@section('title', 'Sellers')
@section('page-title', 'Sellers')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">User Management</span>
        <h1>Seller accounts</h1>

        <p>
            View approved Seller accounts, registered business information,
            marketplace listings, and current platform status.
        </p>
    </div>

    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="store"></i>
        </span>

        <div>
            <strong>{{ count($users) }}</strong>
            <span>Seller accounts</span>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Seller directory</span>
            <h2>Registered Sellers</h2>
        </div>

        <div class="table-toolbar">
            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search seller..."
                    data-table-search="seller-users-table"
                >
            </label>

            <select
                class="select-field"
                data-table-filter="seller-users-table"
                data-filter-key="category"
            >
                <option value="">All categories</option>

                @foreach (collect($users)->pluck('category')->filter()->unique()->sort()->values() as $category)
                    <option value="{{ $category }}">
                        {{ $category }}
                    </option>
                @endforeach
            </select>

            <select
                class="select-field"
                data-table-filter="seller-users-table"
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
        <table class="admin-table admin-user-directory-table" id="seller-users-table">
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Owner</th>
                    <th>Category</th>
                    <th>Products</th>
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
                        data-user-name="{{ $user['owner'] }}"
                        data-status-url="{{ route('admin.users.status', $user['database_id']) }}"
                        data-current-status="{{ strtolower($user['status']) }}"
                        data-allowed-statuses="{{ implode(',', $user['allowed_statuses']) }}"
                        data-category="{{ $user['category'] }}"
                        data-status="{{ $user['status'] }}"
                        data-search="{{ strtolower(
                            $user['name'].' '.
                            $user['owner'].' '.
                            $user['email'].' '.
                            $user['category'].' '.
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

                        <td>{{ $user['owner'] }}</td>
                        <td>{{ $user['category'] }}</td>

                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $user['products'] }}</strong>
                                <small>Listings</small>
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
                                    data-open-modal="seller-user-{{ $user['database_id'] }}"
                                    aria-label="View {{ $user['owner'] }} profile"
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
                                <i data-lucide="store"></i>
                                <strong>No Seller accounts found</strong>
                                <span>Approved Seller accounts will appear here automatically.</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if (count($users) > 0)
            <div
                class="table-empty"
                data-table-empty="seller-users-table"
                hidden
            >
                <i data-lucide="search-x"></i>
                <strong>No Sellers found</strong>
                <span>Try another search or filter.</span>
            </div>
        @endif
    </div>
</section>

@foreach ($users as $user)
    @include('admin.users._profile-modal', [
        'modalId' => 'seller-user-'.$user['database_id'],
        'userId' => $user['database_id'],
        'profileCode' => $user['id'],
        'profileName' => $user['owner'],
        'profileEmail' => $user['email'],
        'profileRole' => 'Seller',
        'roleClass' => 'role-seller',
        'roleIcon' => 'store',
        'profileTitle' => 'Seller profile',
        'profileDescription' => 'Review the seller’s account, store, and listing activity.',
        'status' => $user['status'],
        'statusUrl' => route('admin.users.status', $user['database_id']),
        'allowedStatuses' => $user['allowed_statuses'],
        'summaryStats' => [
            ['label' => 'Product listings', 'value' => $user['products'], 'icon' => 'package'],
        ],
        'details' => [
            ['label' => 'Email address', 'value' => $user['email'], 'icon' => 'mail'],
            ['label' => 'Contact number', 'value' => $user['contact'], 'icon' => 'phone'],
            ['label' => 'Location', 'value' => $user['location'], 'icon' => 'map-pin'],
            ['label' => 'Business name', 'value' => $user['name'], 'icon' => 'store'],
            ['label' => 'Registered category', 'value' => $user['category'], 'icon' => 'tag'],
            ['label' => 'Product listings', 'value' => $user['products'], 'icon' => 'package'],
            ['label' => 'Date joined', 'value' => $user['joined'], 'icon' => 'calendar-days'],
        ],
    ])
@endforeach

@include('admin.users._status-change-dialog')

@endsection
