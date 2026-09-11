@extends('layouts.admin')

@section('title', 'Riders / Couriers')
@section('page-title', 'Riders / Couriers')

@section('content')

<section class="page-hero">

    <div>
        <span class="eyebrow">User Management</span>
        <h1>Riders / Couriers</h1>

        <p>
            View Rider accounts, assigned Logistics Centers,
            vehicle information, delivery activity, and account status.
        </p>
    </div>


    <div class="hero-summary-card">
        <span class="metric-icon">
            <i data-lucide="bike"></i>
        </span>

        <div>
            <strong>{{ count($users) }}</strong>
            <small>Rider accounts</small>
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

        <table class="admin-table" id="rider-users-table">

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
                            <strong>{{ $user['deliveries'] }}</strong>
                            <small>Completed</small>
                        </td>


                        <td>

                            <span
                                class="status-badge
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

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="rider-user-{{ $loop->index }}"
                            >
                                <i data-lucide="eye"></i>
                                View
                            </button>

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

<div
    class="modal-shell"
    data-modal="rider-user-{{ $loop->index }}"
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
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">{{ $user['id'] }}</span>
                <h2>Rider profile</h2>
            </div>


            <button
                class="icon-button"
                type="button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-grid">

            <div class="review-profile">

                <span class="avatar avatar-large avatar-warm">
                    {{
                        collect(explode(' ', $user['name']))
                            ->map(fn($part) => strtoupper(substr($part, 0, 1)))
                            ->take(2)
                            ->implode('')
                    }}
                </span>

                <h3>{{ $user['name'] }}</h3>

                <p>{{ $user['email'] }}</p>

                <span class="role-badge">
                    Rider
                </span>

            </div>


            <div class="review-details">

                <h3 class="section-subtitle">
                    Rider information
                </h3>


                <div class="detail-grid">

                    <div>
                        <span>Email</span>
                        <strong>{{ $user['email'] }}</strong>
                    </div>

                    <div>
                        <span>Vehicle</span>
                        <strong>{{ $user['vehicle'] }}</strong>
                    </div>

                    <div>
                        <span>Plate number</span>
                        <strong>{{ $user['plate'] }}</strong>
                    </div>

                    <div>
                        <span>Assigned Logistics Center</span>
                        <strong>{{ $user['center'] }}</strong>
                    </div>

                    <div>
                        <span>Completed deliveries</span>
                        <strong>{{ $user['deliveries'] }}</strong>
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


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-secondary"
                data-mock-action="{{ $user['name'] }} account activated."
            >
                <i data-lucide="circle-check"></i>
                Activate
            </button>


            <button
                type="button"
                class="button button-danger-soft"
                data-mock-action="{{ $user['name'] }} account suspended."
            >
                <i data-lucide="pause-circle"></i>
                Suspend
            </button>


            <button
                type="button"
                class="button button-danger"
                data-mock-action="{{ $user['name'] }} account deactivated."
            >
                <i data-lucide="user-x"></i>
                Deactivate
            </button>

        </div>

    </section>

</div>

@endforeach

@endsection