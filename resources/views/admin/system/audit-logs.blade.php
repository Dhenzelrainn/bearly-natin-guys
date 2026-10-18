@extends('layouts.admin')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')

@section('content')

<section class="page-hero">

    <div>
        <span class="eyebrow">System Management</span>

        <h1>Audit logs</h1>

        <p>
            Review recorded activity across the Bearly platform,
            including sign-ins and sign-outs, account actions,
            compliance decisions, policy changes, and other
            important system events.
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
                {{ count($logs) }}
            </strong>

            <span>
                Platform events logged
            </span>

        </div>

    </div>

</section>



<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">
                Recorded activity
            </span>

            <h2>
                System activity history
            </h2>
        </div>


        <div class="table-toolbar">

            <label class="field-with-icon compact-field">

                <i data-lucide="search"></i>

                <input
                    type="search"
                    placeholder="Search logs..."
                    data-table-search="audit-logs-table"
                >

            </label>


            <select
                class="select-field"
                data-table-filter="audit-logs-table"
                data-filter-key="module"
            >

                <option value="">
                    All modules
                </option>

                <option value="Authentication">
                    Authentication
                </option>

                <option value="Registration Management">
                    Registration Management
                </option>

                <option value="User Management">
                    User Management
                </option>

                <option value="Compliance & Disputes">
                    Compliance & Disputes
                </option>

                <option value="Communication">
                    Communication
                </option>

                <option value="System Management">
                    System Management
                </option>

            </select>


            <select
                class="select-field"
                data-table-filter="audit-logs-table"
                data-filter-key="severity"
            >

                <option value="">
                    All severities
                </option>

                <option value="Info">
                    Info
                </option>

                <option value="Warning">
                    Warning
                </option>

                <option value="Critical">
                    Critical
                </option>

            </select>

        </div>

    </div>



    <div class="table-wrap">

        <table
            class="admin-table"
            id="audit-logs-table"
        >

            <thead>

                <tr>
                    <th>Activity</th>
                    <th>Account</th>
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
                                $log['ip'].' '.
                                ($log['channel'] ?? '').' '.
                                ($log['device'] ?? '').' '.
                                ($log['browser'] ?? '').' '.
                                ($log['os'] ?? '').' '.
                                ($log['location'] ?? '')
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


                        {{-- Account --}}
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


                {{-- Account --}}
                <div>

                    <span>
                        Account
                    </span>

                    <strong>
                        {{ $log['admin'] }}
                    </strong>

                </div>


                {{-- Account Role --}}
                <div>

                    <span>
                        Account role
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

                @if ($log['is_authentication'])

                    {{-- Sign-in channel --}}
                    <div>

                        <span>
                            Sign-in channel
                        </span>

                        <strong>
                            {{ $log['channel'] ?? '—' }}
                        </strong>

                    </div>


                    {{-- Device --}}
                    <div>

                        <span>
                            Device
                        </span>

                        <strong>
                            {{ $log['device'] ?? '—' }}
                        </strong>

                    </div>


                    {{-- Browser --}}
                    <div>

                        <span>
                            Browser
                        </span>

                        <strong>
                            {{ $log['browser'] ?? '—' }}
                        </strong>

                    </div>


                    {{-- Operating system --}}
                    <div>

                        <span>
                            Operating system
                        </span>

                        <strong>
                            {{ $log['os'] ?? '—' }}
                        </strong>

                    </div>


                    {{-- Approximate location --}}
                    <div>

                        <span>
                            Approximate location
                        </span>

                        <strong>
                            {{ $log['location'] ?? 'Approximate location unavailable' }}
                        </strong>

                    </div>

                @endif

            </div>



            <div class="detail-note">

                <span>
                    Activity description
                </span>

                <p>
                    {{ $log['description'] }}
                </p>

            </div>


            @if ($log['is_authentication'])

                <div class="detail-note">

                    <span>
                        Approximate device and location
                    </span>

                    <p>
                        Device, browser, and operating system are estimated
                        from the request User-Agent and can be spoofed by the
                        client. Location is IP-based, not GPS location; when no
                        approved location provider is configured, it is
                        reported as unavailable.
                    </p>

                </div>

            @endif


            <div class="detail-note">

                <span>
                    Audit record
                </span>

                <p>
                    This record is read-only and represents platform activity
                    recorded for accountability and system monitoring.
                </p>

            </div>

        </div>

    </section>

</div>

@endforeach


@endsection