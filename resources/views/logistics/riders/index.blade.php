@extends('logistics.layouts.app')
@section('title','Rider Management')
@section('page-title','Rider Management')
@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Workforce</p>
        <h2>Riders and courier applications</h2>
        <p>Review real Rider applications assigned to this Logistics account and view approved Rider accounts.</p>
    </div>
</div>

<div data-tab-scope>
    <div class="tabs" data-tabs>
        <button class="tab-button is-active" type="button" data-tab="applications">Applications ({{ count($applications) }})</button>
        <button class="tab-button" type="button" data-tab="accounts">Rider accounts ({{ count($riders) }})</button>
    </div>

    <section class="panel" data-tab-panel="applications" style="margin-top:16px" data-table-scope>
        <div class="table-toolbar">
            <div class="search-field">
                <i data-lucide="search"></i>
                <input type="search" data-table-search placeholder="Search applicant, ID, vehicle, or area">
            </div>
            <select class="filter-select" data-filter-status>
                <option value="">All statuses</option>
                <option>Pending</option>
                <option>Needs Review</option>
            </select>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th data-sort>Application</th>
                        <th data-sort>Applicant</th>
                        <th>Vehicle</th>
                        <th>Preferred area</th>
                        <th data-sort>Submitted</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($applications as $application)
                    <tr
                        data-row
                        data-record-row
                        data-record-type="riderApplications"
                        data-record-id="{{ $application['id'] }}"
                        data-status="{{ $application['status'] }}"
                    >
                        <td><strong>{{ $application['id'] }}</strong></td>
                        <td>
                            <span class="cell-title">
                                <strong>{{ $application['name'] }}</strong>
                                <small>Registration documents submitted</small>
                            </span>
                        </td>
                        <td>
                            <span class="cell-title">
                                <strong>{{ $application['vehicle'] }}</strong>
                                <small>{{ $application['plate'] }}</small>
                            </span>
                        </td>
                        <td>{{ $application['area'] }}</td>
                        <td>{{ $application['submitted'] }}</td>
                        <td>
                            <span class="status-badge" data-status-badge data-status="{{ $application['status'] }}">
                                {{ $application['status'] }}
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="button button-small" href="{{ route('logistics.riders.show', $application['user_id']) }}">
                                    Review
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty-row" colspan="7">No pending Rider applications for this Logistics account.</td>
                    </tr>
                @endforelse
                <tr><td class="empty-row" colspan="7" data-empty-row>No rider applications match these filters.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel" data-tab-panel="accounts" hidden style="margin-top:16px" data-table-scope>
        <div class="table-toolbar">
            <div class="search-field">
                <i data-lucide="search"></i>
                <input type="search" data-table-search placeholder="Search rider, vehicle, or area">
            </div>
            <select class="filter-select" data-filter-status>
                <option value="">All account states</option>
                <option>Active</option>
                <option>Suspended</option>
                <option>Deactivated</option>
            </select>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Rider</th>
                        <th>Vehicle</th>
                        <th>Area</th>
                        <th>Completed jobs</th>
                        <th>Rating</th>
                        <th>Account</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($riders as $rider)
                    <tr
                        data-row
                        data-record-row
                        data-record-type="riderAccounts"
                        data-record-id="{{ $rider['id'] }}"
                        data-status="{{ $rider['status'] }}"
                    >
                        <td><strong>{{ $rider['name'] }}</strong></td>
                        <td>{{ $rider['vehicle'] }}</td>
                        <td>{{ $rider['zone'] }}</td>
                        <td>{{ $rider['jobs'] }}</td>
                        <td>{{ $rider['rating'] !== null ? '★ '.$rider['rating'] : '—' }}</td>
                        <td>
                            <span class="status-badge" data-status-badge data-status="{{ $rider['status'] }}">
                                {{ $rider['status'] }}
                            </span>
                        </td>
                        <td>
                            <a class="button button-small" href="{{ route('logistics.riders.show', $rider['id']) }}">
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty-row" colspan="7">No approved Rider accounts are assigned yet.</td>
                    </tr>
                @endforelse
                <tr><td class="empty-row" colspan="7" data-empty-row>No rider accounts match these filters.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
