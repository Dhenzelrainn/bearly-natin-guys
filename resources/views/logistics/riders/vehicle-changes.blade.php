@extends('logistics.layouts.app')

@section('title', 'Vehicle and Courier Reviews')
@section('page-title', 'Vehicle / Courier Reviews')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Sponsoring Logistics review</p>
        <h2>Vehicle and work-type proposals</h2>
        <p>Approval changes the Rider’s active vehicle and work permissions. Courier approval requires checked OR/CR and license documents plus a capacity set by Logistics.</p>
    </div>
    <a class="button" href="{{ route('logistics.riders.index') }}">Back to Rider management</a>
</div>

<section class="panel" data-table-scope>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Rider</th><th>Proposal</th><th>Documents</th><th>Submitted</th><th>Status / review</th><th>Decision</th></tr></thead>
            <tbody>
                @forelse($changes as $change)
                    <tr>
                        <td><span class="cell-title"><strong>{{ $change->riderProfile?->user?->name ?? 'Rider' }}</strong><small>{{ $change->riderProfile?->user?->email ?? 'Account unavailable' }}</small></span></td>
                        <td><span class="cell-title"><strong>{{ ucfirst($change->requested_work_type) }} · {{ $change->vehicle_type }}</strong><small>{{ $change->vehicle_model ?: 'Model not provided' }} · {{ $change->plate_number }}</small></span></td>
                        <td>
                            <span class="cell-title">
                                <a href="{{ route('logistics.riders.vehicle-changes.documents.show', [$change, 'or-cr']) }}" target="_blank" rel="noopener">{{ $change->or_cr_original_name }}</a>
                                <a href="{{ route('logistics.riders.vehicle-changes.documents.show', [$change, 'driver-license']) }}" target="_blank" rel="noopener">{{ $change->driver_license_original_name }}</a>
                            </span>
                        </td>
                        <td>{{ $change->created_at?->format('M j, Y · g:i A') }}</td>
                        <td><span class="status-badge" data-status="{{ $change->status }}">{{ str($change->status)->replace('_', ' ')->title() }}</span>@if($change->review_notes)<small>{{ $change->review_notes }}</small>@endif</td>
                        <td>
                            @if($change->status === 'pending')
                                <div class="form-actions">
                                    <form method="POST" action="{{ route('logistics.riders.vehicle-changes.approve', $change) }}">
                                        @csrf
                                        @if($change->requested_work_type === 'courier')
                                            <label class="field">Verified parcel capacity<input type="number" name="linehaul_capacity" min="1" max="1000" required></label>
                                        @endif
                                        <button class="button button-primary" type="submit">Verify and approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('logistics.riders.vehicle-changes.reject', $change) }}">
                                        @csrf
                                        <label class="field">Reason<textarea name="review_notes" minlength="5" maxlength="1000" required></textarea></label>
                                        <button class="button button-danger" type="submit">Reject proposal</button>
                                    </form>
                                </div>
                            @else
                                {{ $change->reviewer?->name ?? 'Reviewer unavailable' }}<small>{{ $change->reviewed_at?->format('M j, Y · g:i A') }}</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty-row" colspan="6">No vehicle or Courier work-type proposals have been submitted.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
