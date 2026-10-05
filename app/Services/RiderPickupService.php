<?php

namespace App\Services;

use App\Models\PickupAssignment;
use App\Models\User;
use App\Models\Shipment;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderPickupService
{
    public function acceptPickup(
        string $pickupNo,
        User $rider
    ): PickupAssignment {
        return DB::transaction(
            function () use (
                $pickupNo,
                $rider
            ): PickupAssignment {
                $profile = $rider
                    ->riderProfile()
                    ->firstOrFail();

                $assignment =
                    PickupAssignment::query()
                        ->where(
                            'rider_profile_id',
                            $profile->id
                        )
                        ->whereHas(
                            'pickupRequest',
                            fn ($query) =>
                                $query->where(
                                    'pickup_no',
                                    $pickupNo
                                )
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $assignment->status
                    !== 'assigned'
                ) {
                    throw new ConflictHttpException(
                        'This pickup assignment can no longer be accepted.'
                    );
                }

                $assignment->update([
                    'status' =>
                        'accepted',

                    'accepted_at' =>
                        now(),
                ]);

                return $assignment
                    ->fresh([
                        'pickupRequest',
                    ]);
            },
            3
        );
    }

    public function confirmPickup(
        string $pickupNo,
        User $rider
    ): PickupAssignment {
        return DB::transaction(
            function () use (
                $pickupNo,
                $rider
            ): PickupAssignment {
                $profile = $rider
                    ->riderProfile()
                    ->firstOrFail();

                $assignment =
                    PickupAssignment::query()
                        ->where(
                            'rider_profile_id',
                            $profile->id
                        )
                        ->whereHas(
                            'pickupRequest',
                            fn ($query) =>
                                $query->where(
                                    'pickup_no',
                                    $pickupNo
                                )
                        )
                        ->with(
                            'pickupRequest'
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $assignment->status
                    !== 'accepted'
                ) {
                    throw new ConflictHttpException(
                        'This pickup assignment cannot be confirmed in its current state.'
                    );
                }

                $pickup =
                    $assignment->pickupRequest;

                if (
                    ! $pickup
                    || $pickup->status
                        !== 'scheduled'
                ) {
                    throw new ConflictHttpException(
                        'This pickup request is no longer scheduled for collection.'
                    );
                }

                $parcels =
                    $pickup
                        ->parcels()
                        ->with('shipment')
                        ->orderBy(
                            'parcels.id'
                        )
                        ->lockForUpdate()
                        ->get();

                if ($parcels->isEmpty()) {
                    throw new ConflictHttpException(
                        'This pickup request has no parcels to collect.'
                    );
                }

                /*
                * Validate the entire pickup before mutating
                * any parcel so the operation stays atomic.
                */
                foreach ($parcels as $parcel) {
                    if (
                        ! in_array(
                            $parcel->status,
                            [
                                'ready_for_pickup',
                                ParcelStatus::Created->value,
                            ],
                            true
                        )
                    ) {
                        throw new ConflictHttpException(
                            "Parcel {$parcel->parcel_no} cannot be picked up from its current state."
                        );
                    }

                    if (! $parcel->shipment) {
                        throw new ConflictHttpException(
                            "Parcel {$parcel->parcel_no} has no shipment."
                        );
                    }
                }

                $shipmentIds =
                    $parcels
                        ->pluck('shipment_id')
                        ->filter()
                        ->unique()
                        ->values();

                $shipments =
                    Shipment::query()
                        ->whereIn(
                            'id',
                            $shipmentIds
                        )
                        ->lockForUpdate()
                        ->get();

                if (
                    $shipments->count()
                    !== $shipmentIds->count()
                ) {
                    throw new ConflictHttpException(
                        'One or more pickup shipments could not be loaded.'
                    );
                }

                foreach ($shipments as $shipment) {
                    if (
                        $shipment->status
                        !== ShipmentStatus::PickupAssigned->value
                    ) {
                        throw new ConflictHttpException(
                            "Shipment {$shipment->shipment_no} cannot be picked up from its current state."
                        );
                    }
                }

                $pickedUpAt = now();

                foreach ($parcels as $parcel) {
                    $fromStatus =
                        $parcel->status;

                    $parcel->update([
                        'status' =>
                            ParcelStatus::PickedUp->value,

                        'last_event_at' =>
                            $pickedUpAt,
                    ]);

                    $parcel
                        ->shipment
                        ->events()
                        ->create([
                            'parcel_id' =>
                                $parcel->id,

                            'event_type' =>
                                'parcel_picked_up',

                            'from_status' =>
                                $fromStatus,

                            'to_status' =>
                                ParcelStatus::PickedUp->value,

                            'actor_user_id' =>
                                $rider->id,

                            'source' =>
                                'rider',

                            'metadata' => [
                                'pickup_request_id' =>
                                    $pickup->id,

                                'pickup_assignment_id' =>
                                    $assignment->id,
                            ],

                            'occurred_at' =>
                                $pickedUpAt,
                        ]);
                }

                foreach ($shipments as $shipment) {
                    /*
                    * A Shipment only becomes picked up once
                    * every parcel belonging to it has reached
                    * the picked_up state.
                    */
                    $hasOutstandingParcel =
                        $shipment
                            ->parcels()
                            ->where(
                                'status',
                                '!=',
                                ParcelStatus::PickedUp->value
                            )
                            ->exists();

                    if ($hasOutstandingParcel) {
                        continue;
                    }

                    $fromStatus =
                        $shipment->status;

                    $shipment->update([
                        'status' =>
                            ShipmentStatus::PickedUp->value,
                    ]);

                    $shipment
                        ->events()
                        ->create([
                            'event_type' =>
                                'shipment_picked_up',

                            'from_status' =>
                                $fromStatus,

                            'to_status' =>
                                ShipmentStatus::PickedUp->value,

                            'actor_user_id' =>
                                $rider->id,

                            'source' =>
                                'rider',

                            'metadata' => [
                                'pickup_request_id' =>
                                    $pickup->id,

                                'pickup_assignment_id' =>
                                    $assignment->id,
                            ],

                            'occurred_at' =>
                                $pickedUpAt,
                        ]);
                }

                $assignment->update([
                    'status' =>
                        'picked_up',

                    'picked_up_at' =>
                        $pickedUpAt,
                ]);

                return $assignment
                    ->fresh([
                        'pickupRequest',
                    ]);
            },
            3
        );
    }
}