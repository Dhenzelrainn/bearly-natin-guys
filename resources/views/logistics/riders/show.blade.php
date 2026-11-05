@extends('logistics.layouts.app')

@section('title', 'Rider Application '.$application['id'])
@section('page-title', 'Rider Application Review')

@section('content')
@php
    $documents = collect($application['documents']);

    $requiredDocumentTypes = [
        'driver_license',
        'or_cr',
    ];

    $allRequiredDocumentsVerified = collect($requiredDocumentTypes)
        ->every(function ($type) use ($documents) {
            return $documents->contains(function ($document) use ($type) {
                return $document['type'] === $type
                    && strtolower($document['status']) === 'verified';
            });
        });

    $applicationStatus = strtolower($application['status']);

    $isReviewable = in_array(
        $applicationStatus,
        [
            'pending',
            'needs review',
            'needs revision',
        ],
        true
    );

    $canManageAssignment = (bool) (
        $application['can_manage_assignment']
        ?? false
    );

    $canManageStatus = (bool) (
        $application['can_manage_status']
        ?? false
    );

    $canManageCapacity = (bool) (
        $application['can_manage_capacity']
        ?? false
    );

    $canManageSpecialization = (bool) (
        $application['can_manage_specialization']
        ?? false
    );

    $specializationOptions = [
        'both' => 'Pickup and delivery',
        'pickup_only' => 'Pickup only',
        'delivery_only' => 'Delivery only',
    ];

    $statusLabels = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'deactivated' => 'Deactivated',
    ];

    $operationalSummary = $application['operational_summary'] ?? [
        'unpicked_pickup_assignment_ids' => [],
        'picked_up_pickup_assignment_ids' => [],
        'dispatch_batch_ids' => [],
        'refund_cash_payout_ids' => [],
        'linehaul_run_ids' => [],
    ];
@endphp

<div class="page-header">
    <div>
        <p class="page-kicker">
            Rider {{ $application['id'] }}
        </p>

        <h2>{{ $application['name'] }}</h2>

        <p>
            Inspect the Rider profile, requested work type, vehicle, and submitted credentials.
        </p>
    </div>

    <div class="page-actions">
        <a
            class="button"
            href="{{ route('logistics.riders.index') }}"
        >
            <i data-lucide="arrow-left"></i>
            Back to riders
        </a>

        @if($isReviewable)
            <form
                method="POST"
                action="{{ route('logistics.riders.reject', $application['user_id']) }}"
            >
                @csrf

                <input
                    type="hidden"
                    name="reason"
                    value="Rider credentials were not approved by the selected Logistics Center."
                >

                <button
                    class="button button-danger"
                    type="submit"
                >
                    <i data-lucide="x-circle"></i>
                    Disapprove
                </button>
            </form>

            <form
                method="POST"
                action="{{ route('logistics.riders.approve', $application['user_id']) }}"
            >
                @csrf

                @if($application['requested_work_type'] === 'courier')
                    <label class="field">
                        <span>Logistics-verified line-haul parcel capacity</span>
                        <input type="number" name="linehaul_capacity" min="1" max="1000" required value="{{ old('linehaul_capacity', $application['linehaul_parcel_capacity']) }}">
                        <small>Approve only after checking the submitted vehicle and both verified documents.</small>
                    </label>
                @endif

                <button
                    class="button button-primary"
                    type="submit"
                    @disabled(! $allRequiredDocumentsVerified)
                    @if(! $allRequiredDocumentsVerified)
                        title="Verify both the Driver's License and Vehicle OR/CR before approving this Rider."
                    @endif
                >
                    <i data-lucide="badge-check"></i>
                    Approve rider
                </button>
            </form>
        @endif
    </div>
</div>

@if($canManageStatus)
    <section class="panel" aria-labelledby="rider-account-status-heading" style="margin-bottom:16px">
        <div class="panel-header">
            <div>
                <p class="page-kicker">Account controls</p>
                <h3 id="rider-account-status-heading">Manage Rider account status</h3>
                <p>
                    Status changes are owned by this Logistics provider. The Rider is currently
                    <strong>{{ $statusLabels[$application['status_key']] ?? $application['status'] }}</strong>
                    and availability will remain offline until the Rider chooses to go online again.
                </p>
            </div>
            <span class="status-badge" data-status="{{ $application['status'] }}">
                {{ $application['status'] }}
            </span>
        </div>

        <div class="panel-body">
            <div class="detail-list" aria-label="Rider operational work summary">
                <div class="detail-item">
                    <small>Unpicked pickup assignments</small>
                    <strong>{{ count($operationalSummary['unpicked_pickup_assignment_ids']) }}</strong>
                    <span>These are the only pickup assignments that will be cancelled when blocking the account.</span>
                </div>
                <div class="detail-item">
                    <small>Picked-up assignments retained</small>
                    <strong>{{ count($operationalSummary['picked_up_pickup_assignment_ids']) }}</strong>
                    <span>Logistics can receive these parcels; the Rider ID and custody history stay attached.</span>
                </div>
                <div class="detail-item">
                    <small>Other active work retained</small>
                    <strong>{{ count($operationalSummary['dispatch_batch_ids']) + count($operationalSummary['refund_cash_payout_ids']) + count($operationalSummary['linehaul_run_ids']) }}</strong>
                    <span>Dispatch, refund-cash, and Courier line-haul records are not auto-reassigned.</span>
                </div>
            </div>

            <form
                method="POST"
                action="{{ route('logistics.riders.status.update', $application['user_id']) }}"
                class="field-grid"
                style="margin-top:20px"
            >
                @csrf
                @method('PATCH')

                <div class="field">
                    <label for="rider-account-status">New account status <span aria-hidden="true">*</span></label>
                    <select id="rider-account-status" name="status" required>
                        <option value="">Choose a status</option>
                        @foreach($application['allowed_statuses'] as $targetStatus)
                            <option value="{{ $targetStatus }}" @selected(old('status') === $targetStatus)>
                                {{ $statusLabels[$targetStatus] ?? ucwords(str_replace('_', ' ', $targetStatus)) }}
                            </option>
                        @endforeach
                    </select>
                    @error('status')<small role="alert">{{ $message }}</small>@enderror
                </div>

                <div class="field span-2">
                    <label for="rider-account-status-reason">Reason <span aria-hidden="true">*</span></label>
                    <textarea
                        id="rider-account-status-reason"
                        name="reason"
                        rows="4"
                        minlength="5"
                        maxlength="1000"
                        required
                        aria-describedby="rider-account-status-help"
                    >{{ old('reason') }}</textarea>
                    <small id="rider-account-status-help">
                        Explain the operational or compliance reason. This is saved with the actor,
                        old/new status, and affected assignment/job IDs.
                    </small>
                    @error('reason')<small role="alert">{{ $message }}</small>@enderror
                </div>

                <div class="form-actions span-2">
                    <button class="button button-primary" type="submit">
                        <i data-lucide="shield-check"></i>
                        Confirm status change
                    </button>
                </div>
            </form>
        </div>
    </section>
@elseif(($application['status_key'] ?? null) === 'active')
    <section class="panel" aria-labelledby="rider-status-readiness-heading" style="margin-bottom:16px">
        <div class="panel-body">
            <strong id="rider-status-readiness-heading">Rider status controls are unavailable.</strong>
            <p>This Rider must have an approved Rider profile before Logistics can manage account status.</p>
        </div>
    </section>
@endif

<div class="content-grid">
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Rider profile</h3>
                <p>
                    Personal and contact information from the database.
                </p>
            </div>

            <span
                class="status-badge"
                data-status="{{ $application['status'] }}"
            >
                {{ $application['status'] }}
            </span>
        </div>

        <div class="panel-body detail-list">
            <div class="detail-item">
                <small>Full name</small>
                <strong>{{ $application['name'] }}</strong>
            </div>

            <div class="detail-item">
                <small>Sex</small>
                <strong>{{ $application['sex'] }}</strong>
            </div>

            <div class="detail-item">
                <small>Birthday</small>
                <strong>{{ $application['birthday'] }}</strong>
            </div>

            <div class="detail-item">
                <small>Email</small>
                <strong>{{ $application['email'] }}</strong>
            </div>

            <div class="detail-item">
                <small>Contact number</small>
                <strong>{{ $application['contact'] }}</strong>
            </div>

            <div class="detail-item">
                <small>Home address</small>
                <strong>{{ $application['address'] }}</strong>
            </div>
        </div>
    </section>

    <div class="section-stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>Vehicle and assignment</h3>
                    <p>
                        The sorting center and delivery zone are matched from the Rider’s registered home municipality and barangay.
                    </p>
                </div>
            </div>

            @if($canManageAssignment)
                <form
                    method="POST"
                    action="{{ route(
                        'logistics.riders.assignment.update',
                        $application['user_id']
                    ) }}"
                >
                    @csrf
                    @method('PATCH')

                    <div class="panel-body detail-list">
                        <div class="detail-item">
                            <small>Vehicle type</small>
                            <strong>{{ $application['vehicle'] }}</strong>
                        </div>

                        <div class="detail-item">
                            <small>Requested work type / model</small>
                            <strong>{{ ucfirst($application['requested_work_type']) }} · {{ $application['vehicle_model'] }}</strong>
                        </div>

                        @if($application['is_local_work'] ?? true)
                            <div class="detail-item">
                                <small>Pickup / delivery specialization</small>
                                <strong>{{ $application['specialization_label'] }}</strong>
                            </div>
                        @endif

                        <div class="detail-item">
                            <small>{{ ($application['is_local_work'] ?? true) ? 'Local parcel capacity' : 'Verified line-haul capacity' }}</small>
                            <strong>
                                @if(($application['is_local_work'] ?? true))
                                    {{ $application['parcel_capacity'] ? $application['parcel_capacity'].' parcels' : 'Not configured' }}
                                @else
                                    {{ $application['linehaul_parcel_capacity'] ? $application['linehaul_parcel_capacity'].' parcels' : 'Not verified' }}
                                @endif
                            </strong>
                        </div>

                        <div class="detail-item">
                            <small>Plate number</small>
                            <strong>{{ $application['plate'] }}</strong>
                        </div>

                        <div class="detail-item">
                            <small>Current assignment</small>
                            <strong>{{ $application['home_sorting_center'] }}</strong>
                            <span>{{ $application['current_zone'] }}</span>
                        </div>

                        <div class="detail-item">
                            <small>Submitted</small>
                            <strong>{{ $application['submitted'] }}</strong>
                        </div>

                        <div class="detail-item">
                            <button
                                class="button button-primary"
                                type="submit"
                            >
                                <i data-lucide="refresh-cw"></i>
                                Refresh home-area match
                            </button>
                        </div>
                    </div>
                </form>
            @else
                <div class="panel-body detail-list">
                    <div class="detail-item">
                        <small>Vehicle type</small>
                        <strong>{{ $application['vehicle'] }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>Requested work type / model</small>
                        <strong>{{ ucfirst($application['requested_work_type']) }} · {{ $application['vehicle_model'] }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>{{ ($application['is_local_work'] ?? true) ? 'Local parcel capacity' : 'Verified line-haul capacity' }}</small>
                        <strong>
                            @if(($application['is_local_work'] ?? true))
                                {{ $application['parcel_capacity'] ? $application['parcel_capacity'].' parcels' : 'Not configured' }}
                            @else
                                {{ $application['linehaul_parcel_capacity'] ? $application['linehaul_parcel_capacity'].' parcels' : 'Not verified' }}
                            @endif
                        </strong>
                    </div>

                    <div class="detail-item">
                        <small>Plate number</small>
                        <strong>{{ $application['plate'] }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>Automatic assignment</small>
                        <strong>{{ $application['home_sorting_center'] }} / {{ $application['current_zone'] }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>Submitted</small>
                        <strong>{{ $application['submitted'] }}</strong>
                    </div>
                </div>
            @endif
        </section>

        @if($canManageCapacity)
            <section class="panel" aria-labelledby="rider-parcel-capacity-heading">
                <div class="panel-header">
                    <div>
                        <p class="page-kicker">Dispatch control</p>
                        <h3 id="rider-parcel-capacity-heading">Local parcel capacity</h3>
                        <p>
                            Set the maximum number of active parcels this Rider may receive during delivery assignment.
                            Capacity is managed by this Logistics provider.
                        </p>
                    </div>
                    <span class="status-badge {{ $application['parcel_capacity'] ? 'is-success' : 'is-warning' }}">
                        {{ $application['parcel_capacity'] ? 'Configured' : 'Needs setup' }}
                    </span>
                </div>

                <form
                    class="panel-body"
                    method="POST"
                    action="{{ route('logistics.riders.capacity.update', $application['user_id']) }}"
                >
                    @csrf
                    @method('PATCH')

                    <div class="field-grid">
                        <div class="field">
                            <label for="rider-parcel-capacity">
                                Maximum active parcels <span aria-hidden="true">*</span>
                            </label>
                            <input
                                id="rider-parcel-capacity"
                                type="number"
                                name="parcel_capacity"
                                min="1"
                                max="1000"
                                step="1"
                                required
                                value="{{ old('parcel_capacity', $application['parcel_capacity']) }}"
                                aria-describedby="rider-parcel-capacity-help"
                            >
                            <small id="rider-parcel-capacity-help">
                                Use a verified whole-parcel limit from 1 to 1000. The limit cannot be lowered below the Rider’s current active load.
                            </small>
                            @error('parcel_capacity')
                                <small role="alert">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="button button-primary" type="submit">
                            <i data-lucide="save"></i>
                            Save capacity
                        </button>
                    </div>
                </form>
            </section>
        @endif

        @if($canManageSpecialization)
            <section class="panel" aria-labelledby="rider-specialization-heading">
                <div class="panel-header">
                    <div>
                        <p class="page-kicker">Local Rider assignment</p>
                        <h3 id="rider-specialization-heading">Pickup and delivery work</h3>
                        <p>Choose which new local assignments this Rider may receive. Existing work remains attached and Courier line-haul stays separate.</p>
                    </div>
                </div>

                <form
                    class="panel-body"
                    method="POST"
                    action="{{ route('logistics.riders.specialization.update', $application['user_id']) }}"
                >
                    @csrf
                    @method('PATCH')

                    <div class="field-grid">
                        <div class="field">
                            <label for="rider-specialization">Specialization <span aria-hidden="true">*</span></label>
                            <select id="rider-specialization" name="specialization" required>
                                @foreach($specializationOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('specialization', $application['specialization']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('specialization')<small role="alert">{{ $message }}</small>@enderror
                        </div>

                        <div class="field span-2">
                            <label for="rider-specialization-reason">Reason <span aria-hidden="true">*</span></label>
                            <textarea id="rider-specialization-reason" name="reason" rows="3" minlength="5" maxlength="1000" required>{{ old('reason') }}</textarea>
                            <small>Saved with the Logistics actor and previous/new specialization.</small>
                            @error('reason')<small role="alert">{{ $message }}</small>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="button button-primary" type="submit">
                            <i data-lucide="save" aria-hidden="true"></i>
                            Save specialization
                        </button>
                    </div>
                </form>
            </section>
        @endif

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>Submitted documents</h3>
                    <p>
                        Review the credentials submitted during Rider registration.
                    </p>
                </div>
            </div>

            <div class="panel-body attention-list">
                @forelse($application['documents'] as $document)
                    @php
                        $documentStatus = strtolower(
                            $document['status']
                        );
                    @endphp

                    <div class="attention-item">
                        <i data-lucide="file-check-2"></i>

                        <span>
                            <strong>
                                {{ $document['label'] }}
                            </strong>

                            <small>
                                {{ $document['filename'] }}
                            </small>

                            @if(
                                $documentStatus === 'rejected'
                                && ! empty($document['rejection_reason'])
                            )
                                <small>
                                    Reason:
                                    {{ $document['rejection_reason'] }}
                                </small>
                            @endif
                        </span>

                        <span
                            class="status-badge"
                            data-status="{{ $document['status'] }}"
                        >
                            {{ $document['status'] }}
                        </span>

                        <a
                            class="button"
                            href="{{ route(
                                'logistics.rider-documents.show',
                                [
                                    'application' => $document['application_id'],
                                    'document' => $document['id'],
                                ]
                            ) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <i data-lucide="eye"></i>
                            View
                        </a>

                        @if($isReviewable)
                            @if($documentStatus !== 'verified')
                                <form
                                    method="POST"
                                    action="{{ route(
                                        'logistics.rider-documents.update',
                                        [
                                            'application' => $document['application_id'],
                                            'document' => $document['id'],
                                        ]
                                    ) }}"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <input
                                        type="hidden"
                                        name="verification_status"
                                        value="verified"
                                    >

                                    <button
                                        class="button button-primary"
                                        type="submit"
                                    >
                                        <i data-lucide="check-circle-2"></i>
                                        Verify
                                    </button>
                                </form>
                            @endif

                            @if($documentStatus !== 'rejected')
                                <form
                                    method="POST"
                                    action="{{ route(
                                        'logistics.rider-documents.update',
                                        [
                                            'application' => $document['application_id'],
                                            'document' => $document['id'],
                                        ]
                                    ) }}"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <input
                                        type="hidden"
                                        name="verification_status"
                                        value="rejected"
                                    >

                                    <input
                                        type="hidden"
                                        name="rejection_reason"
                                        value=""
                                    >

                                    <button
                                        class="button button-danger"
                                        type="submit"
                                        onclick="
                                            const reason = window.prompt(
                                                'Enter the reason for rejecting this document:'
                                            );

                                            if (!reason || !reason.trim()) {
                                                return false;
                                            }

                                            this.form.querySelector(
                                                '[name=rejection_reason]'
                                            ).value = reason.trim();
                                        "
                                    >
                                        <i data-lucide="x-circle"></i>
                                        Reject
                                    </button>
                                </form>
                            @endif
                        @endif
                    </div>
                @empty
                    <p class="empty-copy">
                        No registration documents were recorded.
                    </p>
                @endforelse
            </div>
        </section>
    </div>
</div>

@if($application['work_type'] === \App\Enums\RiderWorkType::Courier->value && $courierDailySummary)
    <section class="panel" style="margin-bottom:20px" aria-labelledby="courier-attendance-heading">
        <div class="panel-header">
            <div>
                <p class="page-kicker">Courier monitoring</p>
                <h3 id="courier-attendance-heading">Attendance and daily earnings</h3>
                <p>₱650 is earned once per qualifying work date after check-in/out and full destination acceptance of at least one run assigned during the shift.</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="metric-strip" aria-label="Courier daily pay summary">
                <article class="metric-item"><span class="metric-copy"><small>Qualifying days</small><strong>{{ number_format($courierDailySummary['qualifying_days']) }}</strong><span>{{ $courierDailySummary['total'] }} recorded</span></span></article>
                <article class="metric-item"><span class="metric-copy"><small>Ready for payout</small><strong>{{ $courierDailySummary['eligible'] }}</strong><span>Awaiting external payment</span></span></article>
                <article class="metric-item"><span class="metric-copy"><small>Recorded as paid</small><strong>{{ $courierDailySummary['paid'] }}</strong><span>Actual payout logged by Logistics</span></span></article>
            </div>

            <div class="table-wrap" style="margin-top:18px">
                <table class="data-table">
                    <thead><tr><th>Work date (PHT)</th><th>Check-in (PHT)</th><th>Check-out (PHT)</th><th>Daily earning / payout</th></tr></thead>
                    <tbody>
                        @forelse($courierAttendance as $shift)
                            @php($earning = $shift->earnings->first())
                            <tr>
                                <td>{{ $shift->checkedInAtPht()?->format('M j, Y') ?? '—' }}</td>
                                <td>{{ $shift->checkedInAtPht()?->format('g:i A') ?? '—' }}</td>
                                <td>{{ $shift->checked_out_at ? $shift->checkedOutAtPht()?->format('g:i A') : 'Still checked in' }}</td>
                                <td>
                                    @if($earning)
                                        <strong>₱{{ number_format((int) $earning->amount_minor / 100, 2) }}</strong>
                                        <small class="table-subline">{{ $earning->linehaulRun?->run_no ?? 'Qualifying line-haul run' }} · {{ str($earning->status)->replace('_', ' ')->title() }}</small>
                                        @if($earning->payout)
                                            <small class="table-subline">Paid {{ $earning->payout->paid_on?->format('M j, Y') ?? 'date unavailable' }} · {{ str($earning->payout->method)->replace('_', ' ')->title() }} · {{ $earning->payout->reference }}</small>
                                        @elseif($earning->payoutBatchItem?->batch)
                                            <small class="table-subline">Batch {{ $earning->payoutBatchItem->batch->batch_no }} · {{ str($earning->payoutBatchItem->batch->status)->replace('_', ' ')->title() }}</small>
                                        @else
                                            <small class="table-subline">Not yet included in a payout batch</small>
                                        @endif
                                    @else
                                        <span class="empty-copy">No daily earning posted for this shift</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><p class="empty-copy">No Courier attendance shifts have been recorded for this provider-owned profile.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    const centerSelect = document.getElementById('rider-home-sorting-center');
    const zoneSelect = document.getElementById('rider-current-zone');

    if (!centerSelect || !zoneSelect) {
        return;
    }

    const synchronizeZones = () => {
        const selectedCenterId = centerSelect.value;
        let selectedZoneStillValid = false;

        Array.from(zoneSelect.options).forEach((option) => {
            if (!option.value) {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            const belongsToCenter =
                option.dataset.centerId === selectedCenterId;

            option.hidden = !belongsToCenter;
            option.disabled = !belongsToCenter;

            if (option.selected && belongsToCenter) {
                selectedZoneStillValid = true;
            }
        });

        if (!selectedZoneStillValid) {
            zoneSelect.value = '';
        }
    };

    centerSelect.addEventListener('change', synchronizeZones);
    synchronizeZones();
});
</script>
@endsection
