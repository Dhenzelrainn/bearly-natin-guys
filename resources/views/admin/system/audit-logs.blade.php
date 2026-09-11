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


    <div class="hero-summary-card">
        <span class="metric-icon">
            <i data-lucide="scroll-text"></i>
        </span>

        <div>
            <strong>{{ count($logs) }}</strong>
            <small>Recorded activities</small>
        </div>
    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">Administrative activity</span>
            <h2>System activity history</h2>
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
                <option value="">All modules</option>
                <option value="Registration Management">Registration Management</option>
                <option value="User Management">User Management</option>
                <option value="Compliance & Disputes">Compliance & Disputes</option>
                <option value="Communication">Communication</option>
                <option value="System Management">System Management</option>
            </select>


            <select
                class="select-field"
                data-table-filter="audit-logs-table"
                data-filter-key="severity"
            >
                <option value="">All severities</option>
                <option value="Info">Info</option>
                <option value="Warning">Warning</option>
                <option value="Critical">Critical</option>
            </select>

        </div>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="audit-logs-table">

            <thead>
                <tr>
                    <th>Activity</th>
                    <th>Administrator</th>
                    <th>Module</th>
                    <th>Target</th>
                    <th>Date & Time</th>
                    <th>Severity</th>
                    <th class="align-right">Details</th>
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

                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    <i data-lucide="activity"></i>
                                </span>

                                <div>
                                    <strong>{{ $log['action'] }}</strong>
                                    <small>{{ $log['id'] }}</small>
                                </div>

                            </div>

                        </td>


                        <td>
                            <strong>{{ $log['admin'] }}</strong>
                            <small>{{ $log['role'] }}</small>
                        </td>


                        <td>
                            {{ $log['module'] }}
                        </td>


                        <td>
                            {{ $log['target'] }}
                        </td>


                        <td>
                            <strong>{{ $log['date'] }}</strong>
                            <small>{{ $log['time'] }}</small>
                        </td>


                        <td>

                            <span
                                class="status-badge
                                {{
                                    $log['severity'] === 'Critical'
                                        ? 'badge-danger'
                                        : ($log['severity'] === 'Warning'
                                            ? 'badge-warning'
                                            : 'badge-success')
                                }}"
                            >
                                {{ $log['severity'] }}
                            </span>

                        </td>


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
            <strong>No audit records found</strong>
            <span>Try another search or filter.</span>
        </div>

    </div>

</section>


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
    ></button>


    <section
        class="modal-card modal-wide"
        role="dialog"
        aria-modal="true"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">{{ $log['id'] }}</span>
                <h2>Audit log details</h2>
            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-details">

            <h3 class="section-subtitle">
                Activity information
            </h3>


            <div class="detail-grid">

                <div>
                    <span>Action</span>
                    <strong>{{ $log['action'] }}</strong>
                </div>

                <div>
                    <span>Administrator</span>
                    <strong>{{ $log['admin'] }}</strong>
                </div>

                <div>
                    <span>Administrator role</span>
                    <strong>{{ $log['role'] }}</strong>
                </div>

                <div>
                    <span>Module</span>
                    <strong>{{ $log['module'] }}</strong>
                </div>

                <div>
                    <span>Target</span>
                    <strong>{{ $log['target'] }}</strong>
                </div>

                <div>
                    <span>Severity</span>
                    <strong>{{ $log['severity'] }}</strong>
                </div>

                <div>
                    <span>Date</span>
                    <strong>{{ $log['date'] }}</strong>
                </div>

                <div>
                    <span>Time</span>
                    <strong>{{ $log['time'] }}</strong>
                </div>

                <div>
                    <span>IP Address</span>
                    <strong>{{ $log['ip'] }}</strong>
                </div>

            </div>


            <div class="detail-note">
                <span>Activity description</span>

                <p>
                    {{ $log['description'] }}
                </p>
            </div>


            <div class="detail-note">
                <span>Audit record</span>

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