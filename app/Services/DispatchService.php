<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\DispatchBatch;
use App\Models\Parcel;
use App\Models\RiderProfile;
use App\Models\SortingZone;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DispatchService
{
    /**
     * Assign and immediately dispatch every ready parcel in a zone
     * to one eligible rider.
     */
    public function dispatchZone(
        SortingZone $zone,
        RiderProfile $rider,
        User $actor
    ): DispatchBatch {
        return DB::transaction(function () use (
            $zone,
            $rider,
            $actor
        ): DispatchBatch {
            $logisticsProfileId = $actor->logisticsProfile?->id;

            if (! $logisticsProfileId) {
                throw new AuthorizationException(
                    'The authenticated user has no Logistics profile.'
                );
            }

            /*
             * Re-load and lock the zone so the service does not blindly
             * trust the model instance supplied by the controller.
             */
            $zone = SortingZone::query()
                ->with('sortingCenter')
                ->lockForUpdate()
                ->findOrFail($zone->id);

            $center = $zone->sortingCenter;

            if (
                ! $center
                || (int) $center->logistics_profile_id
                    !== (int) $logisticsProfileId
            ) {
                throw new AuthorizationException(
                    'The Sorting Zone does not belong to the active Logistics provider.'
                );
            }

            if (
                $center->status !== 'active'
                || $zone->status !== 'active'
            ) {
                throw new ConflictHttpException(
                    'Only active Sorting Centers and Zones can dispatch parcels.'
                );
            }

            /*
             * Re-load and lock the rider for the same reason.
             */
            $rider = RiderProfile::query()
                ->with('user')
                ->lockForUpdate()
                ->findOrFail($rider->id);

            if (
                (int) $rider->logistics_profile_id
                    !== (int) $logisticsProfileId
            ) {
                throw new AuthorizationException(
                    'The rider does not belong to the active Logistics provider.'
                );
            }

            if (
                $rider->verification_status !== 'approved'
                || $rider->availability_status !== 'available'
                || ! $rider->user
                || $rider->user->status
                    !== AccountStatus::Active->value
            ) {
                throw new ConflictHttpException(
                    'Only approved, available, active riders can receive a dispatch.'
                );
            }

            if (
                (int) $rider->home_sorting_center_id
                    !== (int) $center->id
            ) {
                throw new ConflictHttpException(
                    'The rider is not assigned to this Sorting Center.'
                );
            }

            if (
                (int) $rider->current_zone_id
                    !== (int) $zone->id
            ) {
                throw new ConflictHttpException(
                    'The rider is not assigned to this Sorting Zone.'
                );
            }

            /*
             * A nullable capacity means dispatch capacity has not been
             * configured yet, so it is not safe to assign parcels.
             */
            $capacity = (int) ($rider->parcel_capacity ?? 0);

            if ($capacity < 1) {
                throw new ConflictHttpException(
                    'The rider has no parcel capacity configured.'
                );
            }

            /*
             * Count parcels already assigned to this rider through
             * non-finalized dispatch batches.
             */
            $activeLoad = DB::table('dispatch_batch_parcels')
                ->join(
                    'dispatch_batches',
                    'dispatch_batches.id',
                    '=',
                    'dispatch_batch_parcels.dispatch_batch_id'
                )
                ->where(
                    'dispatch_batches.rider_profile_id',
                    $rider->id
                )
                ->whereNotIn(
                    'dispatch_batches.status',
                    [
                        'completed',
                        'cancelled',
                    ]
                )
                ->count();

            $remainingCapacity = $capacity - $activeLoad;

            if ($remainingCapacity < 1) {
                throw new ConflictHttpException(
                    'The rider has reached the configured parcel capacity.'
                );
            }

            /*
             * Dispatch only:
             * - parcels belonging to this provider
             * - parcels in this exact active center + zone
             * - parcels already sorted
             * - parcels belonging to shipments still in sorted state
             * - parcels not already attached to an active dispatch batch
             */
            $parcels = Parcel::query()
                ->with('shipment')
                ->where(
                    'current_sorting_center_id',
                    $center->id
                )
                ->where(
                    'current_zone_id',
                    $zone->id
                )
                ->where(
                    'status',
                    ParcelStatus::Sorted->value
                )
                ->whereHas(
                    'shipment',
                    fn ($query) => $query
                        ->where(
                            'logistics_profile_id',
                            $logisticsProfileId
                        )
                        ->where(
                            'status',
                            ShipmentStatus::Sorted->value
                        )
                )
                ->whereDoesntHave(
                    'dispatchBatches',
                    fn ($query) => $query->whereNotIn(
                        'dispatch_batches.status',
                        [
                            'completed',
                            'cancelled',
                        ]
                    )
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($parcels->isEmpty()) {
                throw new ConflictHttpException(
                    'There are no ready parcels available for dispatch in this zone.'
                );
            }

            if ($parcels->count() > $remainingCapacity) {
                throw new ConflictHttpException(
                    'The ready parcel count exceeds the rider\'s remaining capacity.'
                );
            }

            $now = now();

            $batch = DispatchBatch::query()->create([
                'batch_no' => $this->generateBatchNumber(),
                'sorting_center_id' => $center->id,
                'sorting_zone_id' => $zone->id,
                'rider_profile_id' => $rider->id,
                'status' => 'dispatched',
                'prepared_by' => $actor->id,
                'prepared_at' => $now,
                'assigned_at' => $now,
                'dispatched_at' => $now,
            ]);

            $pivotData = [];

            foreach ($parcels->values() as $index => $parcel) {
                $pivotData[$parcel->id] = [
                    'sequence' => $index + 1,
                    'loaded_at' => $now,
                ];
            }

            $batch->parcels()->attach($pivotData);

            $shipmentIds = [];

            foreach ($parcels as $parcel) {
                $fromStatus = $parcel->status;

                $parcel->update([
                    'status' => ParcelStatus::Dispatched->value,
                    'last_event_at' => $now,
                ]);

                $parcel->shipment->events()->create([
                    'parcel_id' => $parcel->id,
                    'event_type' => 'parcel_dispatched',
                    'from_status' => $fromStatus,
                    'to_status' =>
                        ParcelStatus::Dispatched->value,
                    'sorting_center_id' => $center->id,
                    'sorting_zone_id' => $zone->id,
                    'actor_user_id' => $actor->id,
                    'source' => 'logistics',
                    'metadata' => [
                        'dispatch_batch_id' => $batch->id,
                        'rider_profile_id' => $rider->id,
                    ],
                    'occurred_at' => $now,
                ]);

                $shipmentIds[$parcel->shipment_id] = true;
            }

            /*
             * A shipment may contain parcels routed through more than one
             * zone. Only move the shipment to dispatched after none of its
             * parcels remain in the sorted state.
             */
            foreach (array_keys($shipmentIds) as $shipmentId) {
                $shipment = $parcels
                    ->firstWhere('shipment_id', $shipmentId)
                    ?->shipment;

                if (! $shipment) {
                    continue;
                }

                $hasSortedParcels = $shipment
                    ->parcels()
                    ->where(
                        'status',
                        ParcelStatus::Sorted->value
                    )
                    ->exists();

                if ($hasSortedParcels) {
                    continue;
                }

                $fromStatus = $shipment->status;

                $shipment->update([
                    'status' =>
                        ShipmentStatus::Dispatched->value,
                ]);

                $shipment->events()->create([
                    'event_type' => 'shipment_dispatched',
                    'from_status' => $fromStatus,
                    'to_status' =>
                        ShipmentStatus::Dispatched->value,
                    'sorting_center_id' => $center->id,
                    'actor_user_id' => $actor->id,
                    'source' => 'logistics',
                    'metadata' => [
                        'dispatch_batch_id' => $batch->id,
                        'rider_profile_id' => $rider->id,
                    ],
                    'occurred_at' => $now,
                ]);
            }

            return $batch->fresh([
                'sortingCenter',
                'sortingZone',
                'riderProfile.user',
                'parcels.shipment',
            ]);
        }, 3);
    }

    private function generateBatchNumber(): string
    {
        do {
            $number =
                'DSP-'
                . now()->format('Ymd')
                . '-'
                . Str::upper(Str::random(8));
        } while (
            DispatchBatch::query()
                ->where('batch_no', $number)
                ->exists()
        );

        return $number;
    }
}