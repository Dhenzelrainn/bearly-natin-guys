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
@endphp

<div class="page-header">
    <div>
        <p class="page-kicker">
            Rider {{ $application['id'] }}
        </p>

        <h2>{{ $application['name'] }}</h2>

        <p>
            Inspect the Rider profile, vehicle, and submitted credentials.
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
                        Rider vehicle and operational assignment.
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
                            <small>Plate number</small>
                            <strong>{{ $application['plate'] }}</strong>
                        </div>

                        <div class="detail-item">
                            <label for="rider-home-sorting-center">
                                <small>Home sorting center</small>
                            </label>

                            <select
                                id="rider-home-sorting-center"
                                name="home_sorting_center_id"
                                required
                            >
                                <option value="">Select sorting center</option>

                                @foreach($assignmentCenters as $center)
                                    <option
                                        value="{{ $center['id'] }}"
                                        @selected(
                                            (int) ($application['home_sorting_center_id'] ?? 0)
                                            === (int) $center['id']
                                        )
                                    >
                                        {{ $center['name'] }}
                                        @if(! empty($center['code']))
                                            ({{ $center['code'] }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="detail-item">
                            <label for="rider-current-zone">
                                <small>Current zone</small>
                            </label>

                            <select
                                id="rider-current-zone"
                                name="current_zone_id"
                            >
                                <option value="">No current zone</option>

                                @foreach($assignmentCenters as $center)
                                    @foreach($center['zones'] as $zone)
                                        <option
                                            value="{{ $zone['id'] }}"
                                            data-center-id="{{ $center['id'] }}"
                                            @selected(
                                                (int) ($application['current_zone_id'] ?? 0)
                                                === (int) $zone['id']
                                            )
                                        >
                                            {{ $zone['name'] }}
                                            @if(! empty($zone['code']))
                                                ({{ $zone['code'] }})
                                            @endif
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
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
                                <i data-lucide="save"></i>
                                Save assignment
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
                        <small>Plate number</small>
                        <strong>{{ $application['plate'] }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>Area</small>
                        <strong>{{ $application['area'] }}</strong>
                    </div>

                    <div class="detail-item">
                        <small>Submitted</small>
                        <strong>{{ $application['submitted'] }}</strong>
                    </div>
                </div>
            @endif
        </section>

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
