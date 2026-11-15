<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\LogisticsProfile;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PickupAssignmentReplacementService
{
    /** @var list<string> */
    private const REPLACEABLE_ASSIGNMENT_STATUSES = [
        'assigned',
        'accepted',
        'arrived',
    ];

    public function __construct(
        private readonly RiderAssignmentEligibilityService $eligibility,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function replace(
        PickupRequest $pickupRequest,
        LogisticsProfile $provider,
        User $operator,
        int $replacementRiderProfileId,
        string $reason,
    ): PickupAssignment {
        $assignmentSnapshot = PickupAssignment::query()
            ->where('pickup_request_id', $pickupRequest->id)
            ->active()
            ->orderByDesc('id')
            ->first();

        if (! $assignmentSnapshot) {
            throw new ConflictHttpException(
                'This pickup has no active Rider assignment to replace.'
            );
        }

        $oldRiderSnapshot = RiderProfile::withTrashed()
            ->whereKey($assignmentSnapshot->rider_profile_id)
            ->firstOrFail();
        $replacementSnapshot = RiderProfile::query()
            ->whereKey($replacementRiderProfileId)
            ->where('logistics_profile_id', $provider->id)
            ->firstOrFail();

        if ((int) $oldRiderSnapshot->id === (int) $replacementSnapshot->id) {
            throw new ConflictHttpException(
                'Choose a different Rider from the one already assigned.'
            );
        }

        return DB::transaction(function () use (
            $pickupRequest,
            $provider,
            $operator,
            $replacementRiderProfileId,
            $reason,
            $assignmentSnapshot,
            $oldRiderSnapshot,
            $replacementSnapshot,
        ): PickupAssignment {
            $lockedProvider = LogisticsProfile::query()
                ->whereKey($provider->id)
                ->where('user_id', $operator->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedProvider->status !== AccountStatus::Active->value
                || $operator->status !== AccountStatus::Active->value
                || ! $operator->hasRole(UserRole::Logistics->value)
            ) {
                throw new AuthorizationException(
                    'An active Logistics account is required to replace a pickup Rider.'
                );
            }

            $userIds = collect([
                (int) $oldRiderSnapshot->user_id,
                (int) $replacementSnapshot->user_id,
            ])->unique()->sort()->values();
            $lockedUsers = User::withTrashed()
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $riderIds = collect([
                (int) $oldRiderSnapshot->id,
                (int) $replacementSnapshot->id,
            ])->unique()->sort()->values();
            $lockedRiders = RiderProfile::withTrashed()
                ->whereIn('id', $riderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var RiderProfile|null $oldRider */
            $oldRider = $lockedRiders->get((int) $oldRiderSnapshot->id);
            /** @var RiderProfile|null $replacementRider */
            $replacementRider = $lockedRiders->get((int) $replacementSnapshot->id);
            $oldRiderUser = $lockedUsers->get((int) $oldRiderSnapshot->user_id);
            $replacementRiderUser = $lockedUsers->get((int) $replacementSnapshot->user_id);

            if (
                ! $oldRider
                || ! $replacementRider
                || ! $replacementRiderUser
                || $replacementRider->trashed()
                || $replacementRiderUser->trashed()
                || (int) $oldRider->logistics_profile_id !== (int) $lockedProvider->id
                || (int) $replacementRider->logistics_profile_id !== (int) $lockedProvider->id
            ) {
                throw new ConflictHttpException(
                    'The current or replacement Rider is no longer available under this Logistics provider.'
                );
            }

            if ($oldRiderUser) {
                $oldRider->setRelation('user', $oldRiderUser);
            }
            $replacementRider->setRelation('user', $replacementRiderUser);

            $activeAssignments = PickupAssignment::query()
                ->where('pickup_request_id', $pickupRequest->id)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (
                $activeAssignments->count() !== 1
                || (int) $activeAssignments->first()->id !== (int) $assignmentSnapshot->id
                || (int) $activeAssignments->first()->rider_profile_id !== (int) $oldRider->id
                || ! in_array(
                    $activeAssignments->first()->status,
                    self::REPLACEABLE_ASSIGNMENT_STATUSES,
                    true,
                )
            ) {
                throw new ConflictHttpException(
                    'This Rider assignment has changed or pickup custody has already begun.'
                );
            }

            $lockedPickup = PickupRequest::query()
                ->whereKey($pickupRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $lockedPickup->logistics_profile_id !== (int) $lockedProvider->id
                || $lockedPickup->status !== 'scheduled'
            ) {
                throw new ConflictHttpException(
                    'Only a scheduled pickup can have its Rider replaced.'
                );
            }

            $parcels = Parcel::query()
                ->whereHas(
                    'pickupRequests',
                    fn ($query) => $query->whereKey($lockedPickup->id),
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (
                $parcels->isEmpty()
                || $parcels->contains(fn (Parcel $parcel): bool => ! in_array(
                    $parcel->status,
                    [ParcelStatus::Created->value, 'ready_for_pickup'],
                    true,
                ))
            ) {
                throw new ConflictHttpException(
                    'This pickup contains a parcel that has already moved beyond the unpicked state.'
                );
            }

            $shipmentIds = $parcels->pluck('shipment_id')->filter()->unique()->values();
            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->where('logistics_profile_id', $lockedProvider->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (
                $shipmentIds->isEmpty()
                || $shipments->count() !== $shipmentIds->count()
                || $shipments->contains(fn (Shipment $shipment): bool => $shipment->status !== ShipmentStatus::PickupAssigned->value)
            ) {
                throw new ConflictHttpException(
                    'The pickup shipments are no longer assigned for Rider collection.'
                );
            }

            $replacementRider->setRelation('liveLocation', $replacementRider->liveLocation()->first());
            $this->eligibility->assertPickupEligible($replacementRider, $lockedPickup);

            $now = now();
            $replacementReason = trim($reason);
            $existingNotes = trim((string) $assignmentSnapshot->notes);

            $assignment = PickupAssignment::query()
                ->whereKey($assignmentSnapshot->id)
                ->lockForUpdate()
                ->firstOrFail();
            $assignment->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'notes' => trim(implode("\n", array_filter([
                    $existingNotes,
                    'Reassigned by Logistics to '
                        .($replacementRiderUser->name ?: "Rider #{$replacementRider->id}")
                        .". Reason: {$replacementReason}",
                ]))),
            ]);

            $replacementAssignment = PickupAssignment::query()->create([
                'pickup_request_id' => $lockedPickup->id,
                'rider_profile_id' => $replacementRider->id,
                'status' => 'assigned',
                'assigned_by' => $operator->id,
                'assigned_at' => $now,
            ]);

            foreach ($shipments as $shipment) {
                ShipmentEvent::query()->create([
                    'shipment_id' => $shipment->id,
                    'event_type' => 'pickup_rider_reassigned',
                    'from_status' => ShipmentStatus::PickupAssigned->value,
                    'to_status' => ShipmentStatus::PickupAssigned->value,
                    'actor_user_id' => $operator->id,
                    'source' => 'logistics',
                    'notes' => 'Pickup Rider changed from '
                        .($oldRiderUser?->name ?: "Rider #{$oldRider->id}")
                        .' to '.($replacementRiderUser->name ?: "Rider #{$replacementRider->id}")
                        .". Reason: {$replacementReason}",
                    'metadata' => [
                        'pickup_request_id' => (int) $lockedPickup->id,
                        'previous_pickup_assignment_id' => (int) $assignment->id,
                        'pickup_assignment_id' => (int) $replacementAssignment->id,
                        'previous_rider_profile_id' => (int) $oldRider->id,
                        'rider_profile_id' => (int) $replacementRider->id,
                        'reason' => $replacementReason,
                    ],
                    'occurred_at' => $now,
                ]);
            }

            $this->audit->record(
                action: 'pickup_assignment.replaced',
                module: 'Logistics',
                subject: $lockedPickup,
                description: 'Pickup '.$lockedPickup->pickup_no.' Rider reassigned from '
                    .($oldRiderUser?->name ?: "Rider #{$oldRider->id}")
                    .' to '.($replacementRiderUser->name ?: "Rider #{$replacementRider->id}")
                    .". Reason: {$replacementReason}",
                oldValues: [
                    'pickup_assignment_id' => (int) $assignment->id,
                    'rider_profile_id' => (int) $oldRider->id,
                    'rider_name' => $oldRiderUser?->name ?: "Rider #{$oldRider->id}",
                    'assignment_status' => (string) $activeAssignments->first()->status,
                ],
                newValues: [
                    'pickup_assignment_id' => (int) $replacementAssignment->id,
                    'rider_profile_id' => (int) $replacementRider->id,
                    'rider_name' => $replacementRiderUser->name,
                    'reason' => $replacementReason,
                ],
                actor: $operator,
            );

            $this->notifications->sendAfterCommit($replacementRiderUser, new WorkflowNotification(
                eventKey: 'rider_pickup_assigned',
                title: 'Pickup reassigned to you',
                body: "Pickup {$lockedPickup->pickup_no} was assigned to you by Logistics.",
                actionUrl: route('rider.dashboard.pickups'),
                type: 'info',
                meta: [
                    'pickup_request_id' => (int) $lockedPickup->id,
                    'pickup_assignment_id' => (int) $replacementAssignment->id,
                    'rider_profile_id' => (int) $replacementRider->id,
                ],
            ));

            if ($oldRiderUser && ! $oldRiderUser->trashed()) {
                $this->notifications->sendAfterCommit($oldRiderUser, new WorkflowNotification(
                    eventKey: 'rider_pickup_assignment_cancelled',
                    title: 'Pickup assignment reassigned',
                    body: "Pickup {$lockedPickup->pickup_no} was reassigned by Logistics. You are no longer expected to collect it.",
                    actionUrl: route('rider.dashboard.pickups'),
                    type: 'warning',
                    meta: [
                        'pickup_request_id' => (int) $lockedPickup->id,
                        'pickup_assignment_id' => (int) $assignment->id,
                        'replacement_pickup_assignment_id' => (int) $replacementAssignment->id,
                    ],
                ));
            }

            $lockedPickup->loadMissing([
                'store.sellerProfile.user',
                'returnRequest.buyer',
            ]);
            $isReturnPickup = $lockedPickup->return_request_id !== null
                && is_array($lockedPickup->pickup_address_snapshot);
            $recipient = $isReturnPickup
                ? $lockedPickup->returnRequest?->buyer
                : $lockedPickup->store?->sellerProfile?->user;

            if ($recipient) {
                $this->notifications->sendAfterCommit($recipient, new WorkflowNotification(
                    eventKey: $isReturnPickup ? 'buyer_return_updates' : 'seller_pickup_updates',
                    title: 'Pickup Rider changed',
                    body: 'Logistics assigned '.($replacementRiderUser->name ?: "Rider #{$replacementRider->id}")
                        ." to pickup {$lockedPickup->pickup_no}.",
                    actionUrl: $isReturnPickup
                        ? route('buyer.profile', ['tab' => 'returns'])
                        : route('seller.fulfillment.pickups'),
                    type: 'info',
                    meta: [
                        'pickup_request_id' => (int) $lockedPickup->id,
                        'pickup_assignment_id' => (int) $replacementAssignment->id,
                    ],
                ));
            }

            return $replacementAssignment->fresh([
                'pickupRequest',
                'riderProfile.user',
            ]);
        }, attempts: 3);
    }
}
