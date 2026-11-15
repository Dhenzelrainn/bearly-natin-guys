<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Events\RiderOperationalStatusUpdated;
use App\Models\AuditLog;
use App\Models\DispatchBatch;
use App\Models\LinehaulRun;
use App\Models\LogisticsProfile;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RefundCashPayout;
use App\Models\RiderProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Notifications\AccountStatusChangedNotification;
use App\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class RiderAccountStatusService
{
    private const UNPICKED_PICKUP_STATUSES = [
        'assigned',
        'accepted',
        'arrived',
    ];

    private const PICKED_UP_PICKUP_STATUSES = [
        'picked_up',
    ];

    private const ACTIVE_REFUND_CASH_STATUSES = [
        'assigned_to_rider',
        'cash_with_rider',
        'out_for_delivery',
        'cash_return_pending',
        'logistics_return_verification',
        'delivered_pending_verification',
    ];

    private const ACTIVE_LINEHAUL_STATUSES = [
        'assigned',
        'loading',
        'loaded',
        'departed',
        'arrived',
        'exception',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly RiderLocationService $locations,
    ) {}

    /**
     * Change an approved Rider's account status for its owning Logistics
     * provider. All operational cleanup is performed in the same transaction
     * as the account change.
     *
     * @return array<string, mixed>
     */
    public function change(
        User $rider,
        User $actor,
        string $targetStatus,
        string $reason,
    ): array {
        $targetStatus = strtolower(trim($targetStatus));
        $reason = trim($reason);

        $this->validateRequest($targetStatus, $reason);

        $result = DB::transaction(function () use (
            $rider,
            $actor,
            $targetStatus,
            $reason,
        ): array {
            $logisticsProfile = LogisticsProfile::query()
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if (
                $actor->status !== AccountStatus::Active->value
                || ! $actor->hasRole(UserRole::Logistics->value)
                || ! $logisticsProfile
                || $logisticsProfile->status !== AccountStatus::Active->value
            ) {
                throw new AuthorizationException(
                    'An active Logistics account is required.'
                );
            }

            $lockedRider = User::query()
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedRider->role !== UserRole::Rider->value
                && ! $lockedRider->hasRole(UserRole::Rider->value)
            ) {
                throw new AuthorizationException(
                    'Only Rider accounts can be managed through this workflow.'
                );
            }

            $riderProfile = RiderProfile::query()
                ->where('user_id', $lockedRider->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $riderProfile
                || (int) $riderProfile->logistics_profile_id
                    !== (int) $logisticsProfile->id
            ) {
                throw new AuthorizationException(
                    'This Rider does not belong to the authenticated Logistics provider.'
                );
            }

            if ($riderProfile->verification_status !== 'approved') {
                throw new ConflictHttpException(
                    'Only an approved Rider can have its account status managed.'
                );
            }

            $fromStatus = (string) $lockedRider->status;
            $allowedStatuses = $this->transitions()[$fromStatus] ?? null;

            if ($allowedStatuses === null) {
                throw ValidationException::withMessages([
                    'status' => 'The current Rider account status cannot be changed through this workflow.',
                ]);
            }

            if (! in_array($targetStatus, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => "A Rider account cannot change from {$fromStatus} to {$targetStatus}.",
                ]);
            }

            $operations = $this->lockedOperations($riderProfile);
            $now = now();
            $cancelled = [
                'assignment_ids' => [],
                'pickup_request_ids' => [],
                'shipment_ids' => [],
            ];

            if (in_array($targetStatus, [
                AccountStatus::Suspended->value,
                AccountStatus::Deactivated->value,
            ], true)) {
                $cancelled = $this->cancelUnpickedPickups(
                    $operations['pickup_assignments']
                        ->filter(fn (PickupAssignment $assignment): bool => in_array(
                            $assignment->status,
                            self::UNPICKED_PICKUP_STATUSES,
                            true
                        )),
                    $riderProfile,
                    $actor,
                    $reason,
                    $now,
                );
            }

            $oldAvailability = (string) ($riderProfile->availability_status ?: 'offline');

            $riderProfile->update([
                'availability_status' => 'offline',
            ]);
            $this->locations->clear($riderProfile);

            $lockedRider->forceFill([
                'status' => $targetStatus,
                'suspended_at' => $targetStatus === AccountStatus::Suspended->value
                    ? $now
                    : null,
                'deactivated_at' => $targetStatus === AccountStatus::Deactivated->value
                    ? $now
                    : null,
                'status_reason' => $reason,
            ])->save();

            $affected = [
                'cancelled_pickup_assignment_ids' => $cancelled['assignment_ids'],
                'cancelled_pickup_request_ids' => $cancelled['pickup_request_ids'],
                'restored_shipment_ids' => $cancelled['shipment_ids'],
                'retained_picked_up_assignment_ids' => $operations['pickup_assignments']
                    ->filter(fn (PickupAssignment $assignment): bool => in_array(
                        $assignment->status,
                        self::PICKED_UP_PICKUP_STATUSES,
                        true
                    ))
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
                'retained_dispatch_batch_ids' => $operations['dispatch_batches']
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
                'retained_refund_cash_payout_ids' => $operations['refund_cash_payouts']
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
                'retained_linehaul_run_ids' => $operations['linehaul_runs']
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ];

            AuditLog::query()->create([
                'actor_user_id' => $actor->id,
                'action' => 'rider.status_changed',
                'module' => 'rider_management',
                'auditable_type' => User::class,
                'auditable_id' => $lockedRider->id,
                'description' => "{$lockedRider->name}'s Rider account status changed from {$fromStatus} to {$targetStatus} by Logistics.",
                'old_values' => [
                    'status' => $fromStatus,
                    'availability_status' => $oldAvailability,
                ],
                'new_values' => [
                    'status' => $targetStatus,
                    'availability_status' => 'offline',
                    'reason' => $reason,
                    'affected' => $affected,
                ],
                'severity' => $targetStatus === AccountStatus::Active->value
                    ? 'info'
                    : 'warning',
                'created_at' => $now,
            ]);

            return [
                'user' => $lockedRider->fresh(['riderProfile']),
                'from_status' => $fromStatus,
                'to_status' => $targetStatus,
                'reason' => $reason,
                'affected' => $affected,
                'cancelled_pickup_count' => count($cancelled['assignment_ids']),
            ];
        }, 3);

        $this->broadcastOperationalStatus(
            $result['user'],
            $result['user']->riderProfile,
            (int) $actor->id,
        );

        $this->notifications->sendAfterCommit(
            $result['user'],
            new AccountStatusChangedNotification(
                $result['from_status'],
                $result['to_status'],
                $result['reason'],
            )
        );

        return $result;
    }

    public function broadcastOperationalStatus(
        User $rider,
        RiderProfile $riderProfile,
        int $logisticsUserId,
    ): void {
        if ($logisticsUserId < 1) {
            return;
        }

        $event = new RiderOperationalStatusUpdated(
            riderUserId: (int) $rider->id,
            logisticsUserId: $logisticsUserId,
            accountStatus: (string) $rider->status,
            availabilityStatus: (string) ($riderProfile->availability_status ?: 'offline'),
            updatedAt: now()->toIso8601String(),
        );

        DB::afterCommit(function () use ($event): void {
            try {
                Event::dispatch($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * @return array<string, list<string>>
     */
    public function transitions(): array
    {
        return [
            AccountStatus::Active->value => [
                AccountStatus::Suspended->value,
                AccountStatus::Deactivated->value,
            ],
            AccountStatus::Suspended->value => [
                AccountStatus::Active->value,
                AccountStatus::Deactivated->value,
            ],
            AccountStatus::Deactivated->value => [
                AccountStatus::Active->value,
            ],
        ];
    }

    /** @return list<string> */
    public function allowedStatuses(string $currentStatus): array
    {
        return $this->transitions()[$currentStatus] ?? [];
    }

    /**
     * Read-only operational inventory used by the Logistics Rider detail
     * page. These IDs are also captured in the status-change audit record.
     *
     * @return array<string, list<int>>
     */
    public function operationalSummary(RiderProfile $riderProfile): array
    {
        $operations = $this->lockedOperations($riderProfile, false);

        return [
            'unpicked_pickup_assignment_ids' => $operations['pickup_assignments']
                ->filter(fn (PickupAssignment $assignment): bool => in_array(
                    $assignment->status,
                    self::UNPICKED_PICKUP_STATUSES,
                    true
                ))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'picked_up_pickup_assignment_ids' => $operations['pickup_assignments']
                ->filter(fn (PickupAssignment $assignment): bool => in_array(
                    $assignment->status,
                    self::PICKED_UP_PICKUP_STATUSES,
                    true
                ))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'dispatch_batch_ids' => $operations['dispatch_batches']
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'refund_cash_payout_ids' => $operations['refund_cash_payouts']
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'linehaul_run_ids' => $operations['linehaul_runs']
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
        ];
    }

    private function validateRequest(string $targetStatus, string $reason): void
    {
        if (! in_array($targetStatus, array_keys($this->transitions()), true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose a valid Rider account status.',
            ]);
        }

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a reason between 5 and 1000 characters.',
            ]);
        }
    }

    /**
     * @return array{
     *     pickup_assignments: Collection<int, PickupAssignment>,
     *     dispatch_batches: Collection<int, DispatchBatch>,
     *     refund_cash_payouts: Collection<int, RefundCashPayout>,
     *     linehaul_runs: Collection<int, LinehaulRun>
     * }
     */
    private function lockedOperations(
        RiderProfile $riderProfile,
        bool $lock = true,
    ): array {
        $pickupAssignments = PickupAssignment::query()
            ->where('rider_profile_id', $riderProfile->id)
            ->whereNull('cancelled_at')
            ->whereIn('status', [
                ...self::UNPICKED_PICKUP_STATUSES,
                ...self::PICKED_UP_PICKUP_STATUSES,
            ])
            ->orderBy('id');

        $dispatchBatches = DispatchBatch::query()
            ->where('rider_profile_id', $riderProfile->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('id');

        $refundCashPayouts = RefundCashPayout::query()
            ->where('rider_profile_id', $riderProfile->id)
            ->whereIn('status', self::ACTIVE_REFUND_CASH_STATUSES)
            ->orderBy('id');

        $linehaulRuns = LinehaulRun::query()
            ->where('courier_rider_profile_id', $riderProfile->id)
            ->whereIn('status', self::ACTIVE_LINEHAUL_STATUSES)
            ->orderBy('id');

        if ($lock) {
            $pickupAssignments->lockForUpdate();
            $dispatchBatches->lockForUpdate();
            $refundCashPayouts->lockForUpdate();
            $linehaulRuns->lockForUpdate();
        }

        return [
            'pickup_assignments' => $pickupAssignments->get(),
            'dispatch_batches' => $dispatchBatches->get(),
            'refund_cash_payouts' => $refundCashPayouts->get(),
            'linehaul_runs' => $linehaulRuns->get(),
        ];
    }

    /**
     * @param  Collection<int, PickupAssignment>  $assignments
     * @return array{assignment_ids: list<int>, pickup_request_ids: list<int>, shipment_ids: list<int>}
     */
    private function cancelUnpickedPickups(
        Collection $assignments,
        RiderProfile $riderProfile,
        User $actor,
        string $reason,
        $now,
    ): array {
        if ($assignments->isEmpty()) {
            return [
                'assignment_ids' => [],
                'pickup_request_ids' => [],
                'shipment_ids' => [],
            ];
        }

        $pickupRequestIds = $assignments
            ->pluck('pickup_request_id')
            ->unique()
            ->values();
        $pickupRequests = PickupRequest::query()
            ->whereIn('id', $pickupRequestIds)
            ->with('store.sellerProfile.user')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $cancelledAssignmentIds = [];
        $cancelledPickupRequestIds = [];
        $restoredShipmentIds = [];

        foreach ($assignments->groupBy('pickup_request_id') as $pickupRequestId => $requestAssignments) {
            /** @var PickupRequest|null $pickupRequest */
            $pickupRequest = $pickupRequests->get($pickupRequestId);

            if (
                ! $pickupRequest
                || (int) $pickupRequest->logistics_profile_id
                    !== (int) $riderProfile->logistics_profile_id
            ) {
                throw new AuthorizationException(
                    'A Rider pickup assignment is outside the authenticated Logistics provider.'
                );
            }

            if ($pickupRequest->status !== 'scheduled') {
                throw new ConflictHttpException(
                    "Pickup {$pickupRequest->pickup_no} is no longer in a scheduled state and needs operational review."
                );
            }

            $parcels = Parcel::query()
                ->whereHas(
                    'pickupRequests',
                    fn ($query) => $query->whereKey($pickupRequest->id)
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($parcels->contains(fn (Parcel $parcel): bool => ! in_array(
                $parcel->status,
                ['created', 'ready_for_pickup'],
                true
            ))) {
                throw new ConflictHttpException(
                    "Pickup {$pickupRequest->pickup_no} contains a parcel that has already moved beyond the unpicked state."
                );
            }

            $shipmentIds = $parcels->pluck('shipment_id')->filter()->unique()->values();
            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($shipmentIds as $shipmentId) {
                /** @var Shipment|null $shipment */
                $shipment = $shipments->get($shipmentId);

                if (! $shipment) {
                    throw new ConflictHttpException(
                        "Pickup {$pickupRequest->pickup_no} references a missing shipment."
                    );
                }

                if ($shipment->status === 'pickup_assigned') {
                    $fromStatus = $shipment->status;
                    $shipment->update([
                        'status' => 'ready_for_pickup',
                    ]);

                    ShipmentEvent::query()->create([
                        'shipment_id' => $shipment->id,
                        'parcel_id' => $parcels
                            ->where('shipment_id', $shipment->id)
                            ->first()?->id,
                        'event_type' => 'pickup_assignment_cancelled',
                        'from_status' => $fromStatus,
                        'to_status' => 'ready_for_pickup',
                        'actor_user_id' => $actor->id,
                        'source' => 'logistics',
                        'notes' => "Pickup assignment cancelled because the Rider account was changed to a blocked status. Reason: {$reason}",
                        'metadata' => [
                            'pickup_request_id' => (int) $pickupRequest->id,
                            'pickup_assignment_ids' => $requestAssignments
                                ->pluck('id')
                                ->map(fn ($id): int => (int) $id)
                                ->values()
                                ->all(),
                            'rider_profile_id' => (int) $riderProfile->id,
                        ],
                        'occurred_at' => $now,
                    ]);

                    $restoredShipmentIds[] = (int) $shipment->id;
                } elseif ($shipment->status !== 'ready_for_pickup') {
                    throw new ConflictHttpException(
                        "Shipment {$shipment->shipment_no} is no longer in a state that can be returned to the pickup queue."
                    );
                }
            }

            foreach ($requestAssignments as $assignment) {
                $existingNotes = trim((string) $assignment->notes);
                $cancellationNote = "Cancelled by Logistics during Rider account status change. Reason: {$reason}";

                $assignment->update([
                    'status' => 'cancelled',
                    'cancelled_at' => $now,
                    'notes' => trim(implode("\n", array_filter([
                        $existingNotes,
                        $cancellationNote,
                    ]))),
                ]);

                $cancelledAssignmentIds[] = (int) $assignment->id;
            }

            $pickupRequest->update([
                'status' => 'verified',
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);
            $cancelledPickupRequestIds[] = (int) $pickupRequest->id;

            $sellerUser = $pickupRequest->store?->sellerProfile?->user;
            if ($sellerUser) {
                $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
                    eventKey: 'seller_pickup_updates',
                    title: 'Pickup returned to the Logistics queue',
                    body: "Pickup {$pickupRequest->pickup_no} was returned to Logistics' Verified queue for manual reassignment because its assigned Rider account changed status. No automatic reassignment was made.",
                    actionUrl: route('seller.fulfillment.pickups'),
                    type: 'warning',
                    meta: [
                        'pickup_request_id' => (int) $pickupRequest->id,
                        'rider_profile_id' => (int) $riderProfile->id,
                    ],
                ));
            }
        }

        return [
            'assignment_ids' => array_values(array_unique($cancelledAssignmentIds)),
            'pickup_request_ids' => array_values(array_unique($cancelledPickupRequestIds)),
            'shipment_ids' => array_values(array_unique($restoredShipmentIds)),
        ];
    }
}
