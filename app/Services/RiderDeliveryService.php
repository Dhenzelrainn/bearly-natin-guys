<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\DispatchBatch;
use App\Models\Parcel;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderDeliveryService
{
    public function startDelivery(
        string $shipmentNo,
        User $actor
    ): Shipment {
        return DB::transaction(
            function () use (
                $shipmentNo,
                $actor
            ) {
                if (
                    $actor->role
                        !== UserRole::Rider->value
                    || $actor->status
                        !== AccountStatus::Active->value
                ) {
                    throw new AuthorizationException(
                        'Only an active Rider can start delivery.'
                    );
                }

                $rider = $actor
                    ->riderProfile()
                    ->first();

                if (
                    ! $rider
                    || $rider->verification_status
                        !== 'approved'
                ) {
                    throw new AuthorizationException(
                        'An approved Rider profile is required.'
                    );
                }

                $shipment = Shipment::query()
                    ->where(
                        'shipment_no',
                        $shipmentNo
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $shipment
                        ->logistics_profile_id
                    !== (int) $rider
                        ->logistics_profile_id
                ) {
                    throw new AuthorizationException(
                        'This shipment does not belong to your Logistics provider.'
                    );
                }

                $batches = DispatchBatch::query()
                    ->where(
                        'rider_profile_id',
                        $rider->id
                    )
                    ->where(
                        'status',
                        'dispatched'
                    )
                    ->whereHas(
                        'parcels',
                        fn ($query) =>
                            $query->where(
                                'parcels.shipment_id',
                                $shipment->id
                            )
                    )
                    ->with([
                        'parcels' =>
                            fn ($query) =>
                                $query->where(
                                    'parcels.shipment_id',
                                    $shipment->id
                                ),
                    ])
                    ->lockForUpdate()
                    ->get();

                if ($batches->isEmpty()) {
                    throw new AuthorizationException(
                        'This shipment is not assigned to this Rider.'
                    );
                }

                $parcelBatchIds = [];

                foreach (
                    $batches as $batch
                ) {
                    foreach (
                        $batch->parcels
                        as $parcel
                    ) {
                        $parcelBatchIds[
                            $parcel->id
                        ] = $batch->id;
                    }
                }

                $parcelIds = collect(
                    array_keys(
                        $parcelBatchIds
                    )
                );

                if ($parcelIds->isEmpty()) {
                    throw new AuthorizationException(
                        'No assigned parcels were found.'
                    );
                }

                $parcels = Parcel::query()
                    ->whereIn(
                        'id',
                        $parcelIds
                    )
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->lockForUpdate()
                    ->get();

                if (
                    $parcels->contains(
                        fn (Parcel $parcel) =>
                            $parcel->status
                            !== ParcelStatus::Dispatched
                                ->value
                    )
                ) {
                    throw new ConflictHttpException(
                        'Only dispatched parcels can start delivery.'
                    );
                }

                if (
                    $shipment->status
                    !== ShipmentStatus::Dispatched
                        ->value
                ) {
                    throw new ConflictHttpException(
                        'This shipment is not ready to start delivery.'
                    );
                }

                $now = now();

                foreach (
                    $parcels as $parcel
                ) {
                    $fromStatus =
                        $parcel->status;

                    $parcel->update([
                        'status' =>
                            ParcelStatus::OutForDelivery
                                ->value,

                        'last_event_at' =>
                            $now,
                    ]);

                    $shipment
                        ->events()
                        ->create([
                            'parcel_id' =>
                                $parcel->id,

                            'event_type' =>
                                'parcel_out_for_delivery',

                            'from_status' =>
                                $fromStatus,

                            'to_status' =>
                                ParcelStatus::OutForDelivery
                                    ->value,

                            'sorting_center_id' =>
                                $parcel
                                    ->current_sorting_center_id,

                            'sorting_zone_id' =>
                                $parcel
                                    ->current_zone_id,

                            'actor_user_id' =>
                                $actor->id,

                            'source' =>
                                'rider',

                            'metadata' => [
                                'dispatch_batch_id' =>
                                    $parcelBatchIds[
                                        $parcel->id
                                    ],

                                'rider_profile_id' =>
                                    $rider->id,
                            ],

                            'occurred_at' =>
                                $now,
                        ]);
                }

                /*
                 * A shipment may contain parcels
                 * assigned across more than one
                 * dispatch batch / Rider.
                 *
                 * Only promote the shipment after
                 * every parcel has left the
                 * dispatched state.
                 */
                $hasDispatchedParcels =
                    $shipment
                        ->parcels()
                        ->where(
                            'status',
                            ParcelStatus::Dispatched
                                ->value
                        )
                        ->exists();

                if (! $hasDispatchedParcels) {
                    $fromStatus =
                        $shipment->status;

                    $shipment->update([
                        'status' =>
                            ShipmentStatus::OutForDelivery
                                ->value,
                    ]);

                    $centerIds = $parcels
                        ->pluck(
                            'current_sorting_center_id'
                        )
                        ->filter()
                        ->unique()
                        ->values();

                    $zoneIds = $parcels
                        ->pluck(
                            'current_zone_id'
                        )
                        ->filter()
                        ->unique()
                        ->values();

                    $shipment
                        ->events()
                        ->create([
                            'event_type' =>
                                'shipment_out_for_delivery',

                            'from_status' =>
                                $fromStatus,

                            'to_status' =>
                                ShipmentStatus::OutForDelivery
                                    ->value,

                            'sorting_center_id' =>
                                $centerIds->count() === 1
                                    ? $centerIds->first()
                                    : null,

                            'sorting_zone_id' =>
                                $zoneIds->count() === 1
                                    ? $zoneIds->first()
                                    : null,

                            'actor_user_id' =>
                                $actor->id,

                            'source' =>
                                'rider',

                            'metadata' => [
                                'dispatch_batch_ids' =>
                                    $batches
                                        ->pluck('id')
                                        ->unique()
                                        ->values()
                                        ->all(),

                                'rider_profile_id' =>
                                    $rider->id,
                            ],

                            'occurred_at' =>
                                $now,
                        ]);
                }

                return $shipment->fresh([
                    'parcels',
                    'events',
                ]);
            },
            3
        );
    }
}