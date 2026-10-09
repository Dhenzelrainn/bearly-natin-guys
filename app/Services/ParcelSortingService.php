<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Parcel;
use App\Models\SortingZone;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ParcelSortingService
{
    public function sort(
        Parcel $parcel,
        SortingZone $zone,
        User $actor
    ): Parcel {
        return DB::transaction(function () use (
            $parcel,
            $zone,
            $actor
        ): Parcel {
            $logisticsProfileId = $actor
                ->logisticsProfile
                ?->id;

            if (! $logisticsProfileId) {
                throw new AuthorizationException(
                    'The authenticated user has no Logistics profile.'
                );
            }

            $parcel = Parcel::query()
                ->with([
                    'shipment.parcels',
                    'currentSortingCenter',
                ])
                ->lockForUpdate()
                ->findOrFail($parcel->id);

            $zone = SortingZone::query()
                ->with('sortingCenter')
                ->lockForUpdate()
                ->findOrFail($zone->id);

            if (
                ! $parcel->shipment
                || (int) $parcel->shipment
                    ->logistics_profile_id
                    !== (int) $logisticsProfileId
                || ! $parcel->currentSortingCenter
                || (int) $parcel
                    ->currentSortingCenter
                    ->logistics_profile_id
                    !== (int) $logisticsProfileId
                || ! $zone->sortingCenter
                || (int) $zone
                    ->sortingCenter
                    ->logistics_profile_id
                    !== (int) $logisticsProfileId
                || (int) $zone->sorting_center_id
                    !== (int) $parcel
                        ->current_sorting_center_id
            ) {
                throw new AuthorizationException(
                    'The parcel and Sorting Zone must belong to the active Logistics provider and center.'
                );
            }

            if (
                $zone->status !== 'active'
                || $parcel->currentSortingCenter
                    ->status !== 'active'
            ) {
                throw new ConflictHttpException(
                    'Only active Sorting Centers and Zones can receive sorted parcels.'
                );
            }

            if (! in_array(
                $parcel->status,
                [
                    ParcelStatus::Received->value,
                    ParcelStatus::Sorted->value,
                ],
                true
            )) {
                throw new ConflictHttpException(
                    "Parcel {$parcel->parcel_no} cannot be sorted from its current status."
                );
            }

            $fromStatus = $parcel->status;
            $eventType =
                $fromStatus === ParcelStatus::Sorted->value
                    ? 'parcel_resorted'
                    : 'parcel_sorted';

            $parcel->update([
                'status' => ParcelStatus::Sorted->value,
                'current_zone_id' => $zone->id,
                'last_event_at' => now(),
            ]);

            $parcel->shipment->events()->create([
                'parcel_id' => $parcel->id,
                'event_type' => $eventType,
                'from_status' => $fromStatus,
                'to_status' => ParcelStatus::Sorted->value,
                'sorting_center_id' =>
                    $parcel->current_sorting_center_id,
                'sorting_zone_id' => $zone->id,
                'actor_user_id' => $actor->id,
                'source' => 'logistics',
                'occurred_at' => now(),
            ]);

            $hasUnsortedParcels = $parcel
                ->shipment
                ->parcels()
                ->where(
                    'status',
                    '!=',
                    ParcelStatus::Sorted->value
                )
                ->exists();

            if (! $hasUnsortedParcels) {
                $parcel->shipment->update([
                    'status' => ShipmentStatus::Sorted->value,
                ]);
            }

            return $parcel->fresh([
                'shipment',
                'currentSortingCenter',
                'currentZone',
            ]);
        }, 3);
    }
}
