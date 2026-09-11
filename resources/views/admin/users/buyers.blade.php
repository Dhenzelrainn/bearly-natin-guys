@extends('layouts.admin')

@section('title', 'Buyers')
@section('page-title', 'Buyers')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">User Management</span>
        <h1>Buyer accounts</h1>
        <p>
            View registered Buyer accounts, review account details,
            and manage their current platform access status.
        </p>
    </div>

    <div class="hero-summary-card">
        <span class="metric-icon">
            <i data-lucide="users"></i>
        </span>

        <div>
            <strong>{{ count($users) }}</strong>
            <small>Buyer accounts</small>
        </div>
    </div>
</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Buyer directory</span>
            <h2>Registered Buyers</h2>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">
                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search buyer..."
                    data-table-search="buyer-users-table"
                >
            </label>


            <select
                class="select-field"
                data-table-filter="buyer-users-table"
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

        <table class="admin-table" id="buyer-users-table">

            <thead>
                <tr>
                    <th>Buyer</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th>Orders</th>
                    <th>Joined</th>
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
                                .$user['contact']
                                .' '
                                .$user['location']
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
                            {{ $user['contact'] }}
                        </td>


                        <td>
                            {{ $user['location'] }}
                        </td>


                        <td>
                            <strong>{{ $user['orders'] }}</strong>
                            <small>Total orders</small>
                        </td>


                        <td>
                            {{ $user['joined'] }}
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
                                data-open-modal="buyer-user-{{ $loop->index }}"
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
            data-table-empty="buyer-users-table"
            hidden
        >
            <i data-lucide="search-x"></i>
            <strong>No Buyers found</strong>
            <span>Try another search or status filter.</span>
        </div>

    </div>

</section>


@foreach ($users as $user)

<div
    class="modal-shell"
    data-modal="buyer-user-{{ $loop->index }}"
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
                <h2>Buyer profile</h2>
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

                <span class="role-badge role-buyer">
                    Buyer
                </span>

            </div>


            <div class="review-details">

                <h3 class="section-subtitle">
                    Account information
                </h3>


                <div class="detail-grid">

                    <div>
                        <span>Email</span>
                        <strong>{{ $user['email'] }}</strong>
                    </div>

                    <div>
                        <span>Contact number</span>
                        <strong>{{ $user['contact'] }}</strong>
                    </div>

                    <div>
                        <span>Location</span>
                        <strong>{{ $user['location'] }}</strong>
                    </div>

                    <div>
                        <span>Date joined</span>
                        <strong>{{ $user['joined'] }}</strong>
                    </div>

                    <div>
                        <span>Total orders</span>
                        <strong>{{ $user['orders'] }}</strong>
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