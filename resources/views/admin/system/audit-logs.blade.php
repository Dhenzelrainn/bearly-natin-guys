@extends('layouts.admin')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')

@section('content')

<section class="page-hero">

    <div>
        <span class="eyebrow">System Management</span>

        <h1>Audit logs</h1>

        <p>
            Review administrative activity across the Bearly platform,
            including account actions, compliance decisions, policy changes,
            and other important system events.
        </p>
    </div>


    <div class="hero-context-card audit-hero-stat">

        <span class="hero-context-icon">
            <i data-lucide="scroll-text"></i>
        </span>


        <div class="hero-context-copy">

            <small>
                Recorded activities
            </small>

            <strong>
                {{ $logs->total() }}
            </strong>

            <span>
                Administrative events logged
            </span>

        </div>

    </div>

</section>



<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">
                Administrative activity
            </span>

            <h2>
                System activity history
            </h2>
        </div>


        <form method="GET" action="{{ route('admin.audit-logs') }}" class="table-toolbar">

            <label class="field-with-icon compact-field">

                <i data-lucide="search"></i>

                <input
                    type="search"
                    name="search"
                    value="{{ $filters['search'] }}"
                    placeholder="Search logs..."
                >

            </label>


            <select
                class="select-field"
                name="module"
            >

                <option value="">
                    All modules
                </option>

                <option value="registration_management" @selected($filters['module'] === 'registration_management')>
                    Registration Management
                </option>

                <option value="user_management" @selected($filters['module'] === 'user_management')>
                    User Management
                </option>

                <option value="compliance" @selected($filters['module'] === 'compliance')>
                    Compliance & Disputes
                </option>

                <option value="communication" @selected($filters['module'] === 'communication')>
                    Communication
                </option>

                <option value="system_management" @selected($filters['module'] === 'system_management')>
                    System Management
                </option>

            </select>


            <select
                class="select-field"
                name="severity"
            >

                <option value="">
                    All severities
                </option>

                <option value="info" @selected($filters['severity'] === 'info')>
                    Info
                </option>

                <option value="warning" @selected($filters['severity'] === 'warning')>
                    Warning
                </option>

                <option value="critical" @selected($filters['severity'] === 'critical')>
                    Critical
                </option>

            </select>

            <input class="select-field" type="date" name="date_from" value="{{ $filters['dateFrom'] }}" aria-label="From date">
            <input class="select-field" type="date" name="date_to" value="{{ $filters['dateTo'] }}" aria-label="To date">
            <button class="button button-secondary button-small" type="submit">Apply filters</button>

        </form>

    </div>



    <div class="table-wrap">

        <table
            class="admin-table"
            id="audit-logs-table"
        >

            <thead>

                <tr>
                    <th>Activity</th>
                    <th>Administrator</th>
                    <th>Module</th>
                    <th>Target</th>
                    <th>Date & Time</th>
                    <th>Severity</th>
                    <th class="align-right">
                        Details
                    </th>
                </tr>

            </thead>


            <tbody>

                @foreach ($logs as $log)

                    <tr
                        data-table-row
                        data-module="{{ $log['module'] }}"
                        data-severity="{{ $log['severity'] }}"
                        data-search="{{
                            strtolower(
                                $log['id'].' '.
                                $log['admin'].' '.
                                $log['role'].' '.
                                $log['action'].' '.
                                $log['module'].' '.
                                $log['target'].' '.
                                $log['description'].' '.
                                $log['ip']
                            )
                        }}"
                    >

                        {{-- Activity --}}
                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    <i data-lucide="activity"></i>
                                </span>


                                <div class="table-primary-secondary">

                                    <strong>
                                        {{ $log['action'] }}
                                    </strong>

                                    <small>
                                        {{ $log['id'] }}
                                    </small>

                                </div>

                            </div>

                        </td>


                        {{-- Administrator --}}
                        <td>

                            <div class="table-primary-secondary">

                                <strong>
                                    {{ $log['admin'] }}
                                </strong>

                                <small>
                                    {{ $log['role'] }}
                                </small>

                            </div>

                        </td>


                        {{-- Module --}}
                        <td>
                            {{ $log['module'] }}
                        </td>


                        {{-- Target --}}
                        <td>
                            {{ $log['target'] }}
                        </td>


                        {{-- Date & Time --}}
                        <td>

                            <div class="table-primary-secondary">

                                <strong>
                                    {{ $log['date'] }}
                                </strong>

                                <small>
                                    {{ $log['time'] }}
                                </small>

                            </div>

                        </td>


                        {{-- Severity --}}
                        <td>

                            <span
                                class="status-badge
                                {{
                                    $log['severity'] === 'Critical'
                                        ? 'badge-danger'
                                        : ($log['severity'] === 'Warning'
                                            ? 'badge-warning'
                                            : 'badge-info')
                                }}"
                            >
                                {{ $log['severity'] }}
                            </span>

                        </td>


                        {{-- Details --}}
                        <td class="align-right">

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="audit-log-{{ $loop->index }}"
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
            data-table-empty="audit-logs-table"
            hidden
        >

            <i data-lucide="search-x"></i>

            <strong>
                No audit records found
            </strong>

            <span>
                Try another search or filter.
            </span>

        </div>

    </div>

</section>

{{ $logs->links() }}



{{-- =========================================================
     Audit Log Detail Modals
     ========================================================= --}}

@foreach ($logs as $log)

<div
    class="modal-shell"
    data-modal="audit-log-{{ $loop->index }}"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
        aria-label="Close audit log"
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
    >

        <div class="modal-heading">

            <div>

                <span class="eyebrow">
                    {{ $log['id'] }}
                </span>

                <h2>
                    Audit log details
                </h2>

            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
                aria-label="Close"
            >
                <i data-lucide="x"></i>
            </button>

        </div>



        <div class="review-details">

            <h3 class="section-subtitle">
                Activity information
            </h3>


            <div class="detail-grid">

                {{-- Action --}}
                <div>

                    <span>
                        Action
                    </span>

                    <strong>
                        {{ $log['action'] }}
                    </strong>

                </div>


                {{-- Administrator --}}
                <div>

                    <span>
                        Administrator
                    </span>

                    <strong>
                        {{ $log['admin'] }}
                    </strong>

                </div>


                {{-- Administrator Role --}}
                <div>

                    <span>
                        Administrator role
                    </span>

                    <strong>
                        {{ $log['role'] }}
                    </strong>

                </div>


                {{-- Module --}}
                <div>

                    <span>
                        Module
                    </span>

                    <strong>
                        {{ $log['module'] }}
                    </strong>

                </div>


                {{-- Target --}}
                <div>

                    <span>
                        Target
                    </span>

                    <strong>
                        {{ $log['target'] }}
                    </strong>

                </div>


                {{-- Severity --}}
                <div>

                    <span>
                        Severity
                    </span>


                    <strong>

                        <span
                            class="status-badge
                            {{
                                $log['severity'] === 'Critical'
                                    ? 'badge-danger'
                                    : ($log['severity'] === 'Warning'
                                        ? 'badge-warning'
                                        : 'badge-info')
                            }}"
                        >
                            {{ $log['severity'] }}
                        </span>

                    </strong>

                </div>


                {{-- Date --}}
                <div>

                    <span>
                        Date
                    </span>

                    <strong>
                        {{ $log['date'] }}
                    </strong>

                </div>


                {{-- Time --}}
                <div>

                    <span>
                        Time
                    </span>

                    <strong>
                        {{ $log['time'] }}
                    </strong>

                </div>


                {{-- IP Address --}}
                <div>

                    <span>
                        IP Address
                    </span>

                    <strong>
                        {{ $log['ip'] }}
                    </strong>

                </div>

            </div>



            <div class="detail-note">

                <span>
                    Activity description
                </span>

                <p>
                    {{ $log['description'] }}
                </p>

            </div>


            <div class="detail-note">

                <span>
                    Audit record
                </span>

                <p>
                    This record is read-only and represents an administrative
                    action recorded by the platform for accountability and
                    system monitoring.
                </p>

            </div>

        </div>

    </section>

</div>

@endforeach


@endsection
