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
        <table class="admin-table" id="logistics-users-table">
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
                        data-user-id="{{ $user['database_id'] }}"
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
                                class="status-badge
                                {{
                                    $user['status'] === 'Active'
                                        ? 'badge-success'
                                        : ($user['status'] === 'Suspended'
                                            ? 'badge-danger'
                                            : 'badge-neutral')
                                }}"
                            >
                                {{ $user['status'] }}
                            </span>
                        </td>

                        <td class="align-right">
                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="logistics-user-{{ $user['database_id'] }}"
                            >
                                <i data-lucide="eye"></i>
                                View
                            </button>
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
<div
    class="modal-shell"
    data-modal="logistics-user-{{ $user['database_id'] }}"
    hidden
>
    <button
        class="modal-backdrop"
        type="button"
        data-close-modal
    ></button>

    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
        aria-label="Logistics Center profile"
    >
        <div class="modal-heading">
            <div>
                <span class="eyebrow">{{ $user['id'] }}</span>
                <h2>Logistics Center profile</h2>
            </div>

            <button
                class="icon-button"
                type="button"
                data-close-modal
                aria-label="Close"
            >
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="review-grid">
            <div class="review-profile">
                <span class="avatar avatar-large avatar-warm">
                    {{
                        collect(explode(' ', $user['name']))
                            ->filter()
                            ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                            ->take(2)
                            ->implode('')
                    }}
                </span>

                <h3>{{ $user['name'] }}</h3>
                <p>{{ $user['email'] }}</p>

                <span class="role-badge">
                    Logistics Center
                </span>
            </div>

            <div class="review-details">
                <h3 class="section-subtitle">Center information</h3>

                <div class="detail-grid">
                    <div>
                        <span>Center name</span>
                        <strong>{{ $user['name'] }}</strong>
                    </div>

                    <div>
                        <span>Manager</span>
                        <strong>{{ $user['manager'] }}</strong>
                    </div>

                    <div>
                        <span>Email</span>
                        <strong>{{ $user['email'] }}</strong>
                    </div>

                    <div>
                        <span>Location</span>
                        <strong>{{ $user['location'] }}</strong>
                    </div>

                    <div>
                        <span>Assigned riders</span>
                        <strong>{{ $user['riders'] }}</strong>
                    </div>

                    <div>
                        <span>Date joined</span>
                        <strong>{{ $user['joined'] }}</strong>
                    </div>

                    <div>
                        <span>Current status</span>
                        <strong>{{ $user['status'] }}</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button
                type="button"
                class="button button-primary"
                data-close-modal
            >
                Close
            </button>
        </div>
    </section>
</div>
@endforeach

@endsection
