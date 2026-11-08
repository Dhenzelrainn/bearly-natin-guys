@extends('logistics.layouts.app')

@section('title', 'Rider Attendance')
@section('page-title', 'Rider Attendance')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Rider and Courier time records</p>
        <h2>Rider Attendance</h2>
        <p>Review today’s provider-scoped Rider and Courier check-ins, shift status, and absence records.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="{{ route('logistics.dashboard') }}"><i data-lucide="layout-dashboard"></i>Dashboard overview</a>
    </div>
</div>

<div class="rider-attendance-page">
    <section class="panel attendance-roster-panel" data-attendance-roster data-work-date="{{ $attendance['date'] }}" data-cutoff-at="{{ $attendance['cutoff_at'] }}" aria-labelledby="attendance-roster-heading">
        <div class="panel-header">
            <div>
                <p class="page-kicker">Daily attendance · PHT</p>
                <h3 id="attendance-roster-heading">Today’s roster</h3>
                <p>{{ $attendance['date_label'] }} · Active, approved Local Riders and Couriers under this provider.</p>
            </div>
        </div>
        <div class="attendance-roster-summary" aria-label="Today’s attendance totals">
            <span><strong>{{ $attendance['checked_in_count'] }}</strong> checked in</span>
            <span><strong>{{ count($attendance['riders']) }}</strong> on roster</span>
            <span><strong>{{ $attendance['absent_count'] }}</strong> marked absent</span>
        </div>
        <div class="table-wrap">
            <table class="data-table attendance-roster-table" aria-label="Rider and Courier attendance for today">
                <thead>
                    <tr>
                        <th scope="col">Rider</th>
                        <th scope="col">Work type</th>
                        <th scope="col">Time in</th>
                        <th scope="col">Attendance</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($attendance['riders'] as $rider)
                        <tr>
                            <td><strong>{{ $rider['name'] }}</strong></td>
                            <td>{{ $rider['work_type'] }}</td>
                            <td>
                                @if($rider['check_in_at'])
                                    <time datetime="{{ $rider['check_in_at']->toIso8601String() }}">{{ $rider['check_in_at']->format('g:i A') }} PHT</time>
                                @else
                                    <span class="attendance-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($rider['absence'])
                                    <span class="attendance-state is-absent">Absent</span>
                                    <small class="attendance-state-detail">Marked by {{ $rider['absence']['marked_by'] }} at {{ $rider['absence']['marked_at']?->format('g:i A') }} PHT</small>
                                    @if($rider['late_check_in_at'])
                                        <span class="attendance-state is-late">Late check-in</span>
                                        <small class="attendance-state-detail">Scanned at {{ $rider['late_check_in_at']->format('g:i A') }} PHT</small>
                                    @endif
                                @elseif($rider['status'] === 'checked_in')
                                    <span class="attendance-state is-present">Checked in</span>
                                @elseif($rider['status'] === 'checked_out')
                                    <span class="attendance-state is-finished">Checked out</span>
                                    @if($rider['check_out_at'])
                                        <small class="attendance-state-detail">{{ $rider['check_out_at']->format('g:i A') }} PHT</small>
                                    @endif
                                @else
                                    <span class="attendance-state is-pending">Not checked in</span>
                                @endif
                            </td>
                            <td>
                                @if(! $rider['check_in_at'] && ! $rider['absence'])
                                    <form method="POST" action="{{ route('logistics.rider-attendance.absent', $rider['id']) }}" class="attendance-absence-form" data-attendance-absence-action @unless($attendance['can_mark_absent']) hidden @endunless onsubmit="return confirm('Mark this Rider absent for today? The action will be audited.')">
                                        @csrf
                                        <button class="button button-small button-danger-soft" type="submit">Mark absent</button>
                                    </form>
                                    <small class="attendance-action-hint" data-attendance-cutoff-hint @if($attendance['can_mark_absent']) hidden @endif>Available at {{ $attendance['cutoff_label'] }}</small>
                                @elseif($rider['absence'])
                                    <span class="attendance-muted">Recorded</span>
                                @else
                                    <span class="attendance-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <strong>No eligible Riders on today’s roster</strong>
                                    <p>Active, approved Local Riders and Couriers assigned to this provider will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="attendance-roster-footnote">Manual absence marking opens at {{ $attendance['cutoff_label'] }} only when no check-in is recorded. A later QR check-in remains visible as late; the absence audit is retained.</p>
    </section>
</div>
@push('scripts')
    @vite('resources/js/logistics-attendance-qr.js')
@endpush
@endsection
