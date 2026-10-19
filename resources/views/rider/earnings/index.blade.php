@extends('rider.layouts.app')

@section('title', 'Earnings')
@section('page-title', 'Earnings')

@section('content')
@php
    $exportFilters = collect($filters)->filter(static fn ($value) => filled($value))->all();
    $hasFilters = collect($filters)->filter(static fn ($value) => filled($value))->isNotEmpty();
@endphp

<div class="page-header">
    <div>
        <p class="page-kicker">Rider ledger</p>
        <h2>Recorded earnings</h2>
        <p>This page shows only earning entries recorded for your Rider profile. No payout schedule or account details are assumed.</p>
    </div>
    <div class="page-actions">
        <a class="button button-primary" href="{{ route('rider.earnings.export', $exportFilters) }}">
            <i data-lucide="download" aria-hidden="true"></i>
            Export filtered CSV
        </a>
    </div>
</div>

<section class="metric-strip" aria-label="Earnings ledger summary">
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="sun" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Today</small>
            <strong>{{ $earnings['today']['amount'] }}</strong>
            <span>{{ number_format($earnings['today']['entries']) }} active ledger {{ $earnings['today']['entries'] === 1 ? 'entry' : 'entries' }}</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="calendar-days" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>This week</small>
            <strong>{{ $earnings['week']['amount'] }}</strong>
            <span>{{ number_format($earnings['week']['entries']) }} active ledger {{ $earnings['week']['entries'] === 1 ? 'entry' : 'entries' }}</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="calendar-range" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>This month</small>
            <strong>{{ $earnings['month']['amount'] }}</strong>
            <span>{{ number_format($earnings['month']['entries']) }} active ledger {{ $earnings['month']['entries'] === 1 ? 'entry' : 'entries' }}</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="book-open-check" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Active ledger total</small>
            <strong>{{ $earnings['active_total'] }}</strong>
            <span>{{ number_format($earnings['active_entries']) }} active entries · {{ number_format($earnings['excluded_entries']) }} reversed/cancelled excluded</span>
        </span>
    </article>
</section>

<section class="panel" style="margin-bottom:20px" aria-label="Earnings ledger information">
    <div class="panel-body">
        <div class="attention-item">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>
                <strong>Ledger-only reporting</strong>
                <small>Pending, posted, and paid are ledger statuses, not payout promises. Reversed and cancelled rows remain visible below for audit context but are excluded from the totals above. Rider payout details are not configured.</small>
            </span>
        </div>
    </div>
</section>

<section class="panel" aria-label="Earnings activity">
    <div class="panel-header">
        <div>
            <h3>Earnings activity</h3>
            <p>Filter entries by their recorded earned date, linked work, type, or ledger status.</p>
        </div>
    </div>

    <form class="table-toolbar history-toolbar earnings-toolbar" method="GET" action="{{ route('rider.earnings.index') }}">
        <label class="search-field">
            <i data-lucide="search" aria-hidden="true"></i>
            <input type="search" name="search" value="{{ $filters['search'] }}" maxlength="120" placeholder="Search job, type, status, or description" aria-label="Search earnings">
        </label>

        <label>
            <span class="sr-only">Status</span>
            <select class="filter-select" name="status" aria-label="Filter by ledger status">
                <option value="">All statuses</option>
                @foreach($statusOptions as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="sr-only">Type</span>
            <select class="filter-select" name="type" aria-label="Filter by earning type">
                <option value="">All types</option>
                @foreach($typeOptions as $type)
                    <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
        </label>

        <label class="history-date-field">
            <span>Earned from</span>
            <input class="filter-select" type="date" name="date_from" value="{{ $filters['date_from'] }}" aria-label="Earned from" aria-invalid="{{ $errors->has('date_from') ? 'true' : 'false' }}">
            @error('date_from')<small class="delivery-field-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <label class="history-date-field">
            <span>Earned to</span>
            <input class="filter-select" type="date" name="date_to" value="{{ $filters['date_to'] }}" aria-label="Earned to" aria-invalid="{{ $errors->has('date_to') ? 'true' : 'false' }}">
            @error('date_to')<small class="delivery-field-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <button class="button button-small" type="submit"><i data-lucide="filter" aria-hidden="true"></i>Apply</button>

        @if($hasFilters)
            <a class="button button-small" href="{{ route('rider.earnings.index') }}">Clear</a>
        @endif
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Earned at</th>
                    <th>Linked job</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Posted at</th>
                    <th>Paid at</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td><time datetime="{{ $log['earned_at_iso'] }}">{{ $log['earned_at'] }}</time></td>
                        <td>
                            <div class="earnings-job-cell">
                                <strong>{{ $log['job'] }}</strong>
                                <small>{{ $log['source'] }}</small>
                                @if(filled($log['description']))
                                    <small>{{ $log['description'] }}</small>
                                @endif
                            </div>
                        </td>
                        <td>{{ $log['type_label'] }}</td>
                        <td><strong>{{ $log['amount'] }}</strong></td>
                        <td><span class="status-badge" data-status="{{ $log['status'] }}">{{ $log['status_label'] }}</span></td>
                        <td>{{ $log['posted_at'] }}</td>
                        <td>{{ $log['paid_at'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty-row" colspan="7">
                            {{ $hasFilters ? 'No earning ledger entries match these filters.' : 'No earning ledger entries are currently recorded for your Rider profile.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="history-pagination" role="status" aria-live="polite">
        <span>Showing {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ number_format($logs->total()) }} records</span>
        <div class="page-actions">
            @if($logs->previousPageUrl())
                <a class="button button-small" href="{{ $logs->previousPageUrl() }}" rel="prev">Previous</a>
            @endif
            @if($logs->nextPageUrl())
                <a class="button button-small" href="{{ $logs->nextPageUrl() }}" rel="next">Next</a>
            @endif
        </div>
    </div>
</section>
@endsection
