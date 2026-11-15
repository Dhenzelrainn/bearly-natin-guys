@extends('rider.layouts.app')

@section('title', 'Attendance history')
@section('page-title', 'Attendance history')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Local Rider records</p>
        <h2>Your attendance shifts</h2>
        <p>Review your QR check-in and check-out records. Times are shown in Philippine Time (PHT / UTC+8). Attendance does not control assignments or Local Rider per-job earnings.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="{{ route('rider.dashboard.pickups') }}">
            <i data-lucide="package-plus" aria-hidden="true"></i>
            Pickup dashboard
        </a>
    </div>
</div>

<section class="panel" aria-label="Attendance history records">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">Work date (PHT)</th>
                    <th scope="col">Check-in (PHT)</th>
                    <th scope="col">Check-out (PHT)</th>
                    <th scope="col">Shift</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shifts as $shift)
                    <tr>
                        <td>{{ $shift->checkedInAtPht()?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ $shift->checkedInAtPht()?->format('g:i A') ?? '—' }}</td>
                        <td>{{ $shift->checked_out_at ? $shift->checkedOutAtPht()?->format('g:i A') : 'Still checked in' }}</td>
                        <td>
                            @if($shift->checked_out_at)
                                <span class="status-badge is-success">Closed</span>
                            @else
                                <span class="status-badge">Open shift</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty-row" colspan="4">No attendance records yet. Your shifts will appear here after you check in.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($shifts->total() > 0)
        <div class="history-pagination" role="status" aria-live="polite">
            <span>Showing {{ $shifts->firstItem() ?? 0 }}–{{ $shifts->lastItem() ?? 0 }} of {{ number_format($shifts->total()) }} shifts</span>
            <div class="page-actions">
                @if($shifts->previousPageUrl())
                    <a class="button button-small" href="{{ $shifts->previousPageUrl() }}" rel="prev">Previous</a>
                @endif
                @if($shifts->nextPageUrl())
                    <a class="button button-small" href="{{ $shifts->nextPageUrl() }}" rel="next">Next</a>
                @endif
            </div>
        </div>
    @endif
</section>
@endsection
