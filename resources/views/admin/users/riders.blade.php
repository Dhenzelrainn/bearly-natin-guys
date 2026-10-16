@extends('layouts.admin')

@section('title', 'Riders')
@section('page-title', 'Riders')

@section('content')

<section class="page-hero">

    <div>
        <span class="eyebrow">User Management</span>
        <h1>Riders</h1>

        <p>
            View Rider accounts, assigned Logistics Centers,
            vehicle information, delivery activity, and account status.
        </p>
    </div>


    <div class="hero-context-stat">
        <span class="hero-context-icon">
            <i data-lucide="bike"></i>
        </span>

        <div>
            <strong>{{ count($users) }}</strong>
            <span>Rider accounts</span>
        </div>
    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Rider directory</span>
            <h2>Registered Riders</h2>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search rider..."
                    data-table-search="rider-users-table"
                >
            </label>


            <select
                class="select-field"
                data-table-filter="rider-users-table"
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

        <table class="admin-table admin-user-directory-table" id="rider-users-table">

            <thead>
                <tr>
                    <th>Rider</th>
                    <th>Vehicle</th>
                    <th>Plate</th>
                    <th>Logistics Center</th>
                    <th>Deliveries</th>
                    <th>Status</th>
                    <th class="align-right">Actions</th>
                </tr>
            </thead>


            <tbody>

                @foreach ($users as $user)

                    <tr
                        data-table-row
                        data-user-status-context
                        data-user-id="{{ $user['database_id'] }}"
                        data-user-name="{{ $user['name'] }}"
                        data-status-url="{{ route('admin.users.status', $user['database_id']) }}"
                        data-current-status="{{ strtolower($user['status']) }}"
                        data-allowed-statuses="{{ implode(',', $user['allowed_statuses']) }}"
                        data-status="{{ $user['status'] }}"
                        data-search="{{
                            strtolower(
                                $user['name']
                                .' '
                                .$user['email']
                                .' '
                                .$user['vehicle']
                                .' '
                                .$user['plate']
                                .' '
                                .$user['center']
                                .' '
                                .$user['id']
                            )
                        }}"
                    >

                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    {{
                                        collect(explode(' ', $user['name']))
                                            ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                                            ->take(2)
                                            ->implode('')
                                    }}
                                </span>


                                <div>
                                    <strong>{{ $user['name'] }}</strong>

                                    <small>
                                        {{ $user['id'] }} • {{ $user['email'] }}
                                    </small>
                                </div>

                            </div>

                        </td>


                        <td>
                            {{ $user['vehicle'] }}
                        </td>


                        <td>
                            <strong>{{ $user['plate'] }}</strong>
                        </td>


                        <td>
                            {{ $user['center'] }}
                        </td>


                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $user['deliveries'] }}</strong>
                                <small>Completed</small>
                            </div>
                        </td>


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
                                    data-open-modal="rider-user-{{ $user['database_id'] }}"
                                    aria-label="View {{ $user['name'] }} profile"
                                >
                                    <i data-lucide="eye"></i><span>View</span>
                                </button>
                            </div>

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="table-empty"
            data-table-empty="rider-users-table"
            hidden
        >
            <i data-lucide="search-x"></i>
            <strong>No Riders found</strong>
            <span>Try another search or status filter.</span>
        </div>

    </div>

</section>


@foreach ($users as $user)
    @include('admin.users._profile-modal', [
        'modalId' => 'rider-user-'.$user['database_id'],
        'userId' => $user['database_id'],
        'profileCode' => $user['id'],
        'profileName' => $user['name'],
        'profileEmail' => $user['email'],
        'profileRole' => 'Rider',
        'roleClass' => 'role-rider',
        'roleIcon' => 'bike',
        'profileTitle' => 'Rider profile',
        'profileDescription' => 'Review the rider’s account, vehicle, and delivery activity.',
        'status' => $user['status'],
        'statusUrl' => route('admin.users.status', $user['database_id']),
        'allowedStatuses' => $user['allowed_statuses'],
        'summaryStats' => [
            ['label' => 'Completed deliveries', 'value' => $user['deliveries'], 'icon' => 'package-check'],
        ],
        'details' => [
            ['label' => 'Email address', 'value' => $user['email'], 'icon' => 'mail'],
            ['label' => 'Contact number', 'value' => $user['contact'], 'icon' => 'phone'],
            ['label' => 'Location', 'value' => $user['location'], 'icon' => 'map-pin'],
            ['label' => 'Vehicle', 'value' => $user['vehicle'], 'icon' => 'bike'],
            ['label' => 'Plate number', 'value' => $user['plate'], 'icon' => 'hash'],
            ['label' => 'Assigned Logistics Center', 'value' => $user['center'], 'icon' => 'warehouse'],
            ['label' => 'Completed deliveries', 'value' => $user['deliveries'], 'icon' => 'package-check'],
            ['label' => 'Date joined', 'value' => $user['joined'], 'icon' => 'calendar-days'],
        ],
    ])
@endforeach

@include('admin.users._status-change-dialog')

@endsection
