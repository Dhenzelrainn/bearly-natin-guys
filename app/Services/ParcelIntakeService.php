<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\PickupRequest;
use App\Models\SortingCenter;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ParcelIntakeService
{
    public function receive(
        Waybill $waybill,
        SortingCenter $center,
        User $actor,
        string $method = 'camera'
    ): Waybill {
        return DB::transaction(function () use (
            $waybill,
            $center,
            $actor,
            $method
        ) {
            $logisticsProfileId = $actor->logisticsProfile?->id;

            if (! $logisticsProfileId) {
                throw new AuthorizationException(
                    'The authenticated user has no Logistics profile.'
                );
            }

            /*
             * Re-load and lock the sorting center so this service does not
             * blindly trust a model instance supplied by the controller.
             */
            $center = SortingCenter::query()
                ->whereKey($center->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $center->logistics_profile_id
                !== (int) $logisticsProfileId
            ) {
                throw new AuthorizationException(
                    'You cannot receive parcels into another Logistics provider\'s sorting center.'
                );
            }

            if ($center->status !== 'active') {
                throw new ConflictHttpException(
                    'Parcels can only be received into an active Sorting Center.'
                );
            }

            /*
             * Lock the waybill so concurrent scans of the same waybill
             * cannot both process its parcels.
             */
            $waybill = Waybill::query()
                ->lockForUpdate()
                ->with([
                    'shipment.sellerOrder.store',
                    'parcels',
                ])
                ->findOrFail($waybill->id);

            $shipment = $waybill->shipment;

            if (
                ! $shipment
                || (int) $shipment->logistics_profile_id
                    !== (int) $logisticsProfileId
                || (int) $shipment->logistics_profile_id
                    !== (int) $center->logistics_profile_id
            ) {
                throw new AuthorizationException(
                    'This shipment does not belong to the active Logistics provider.'
                );
            }

            foreach ($waybill->parcels as $parcel) {
                /*
                 * A parcel may enter a sorting center only from one of the
                 * legitimate pre-intake states.
                 *
                 * Everything else is considered already received or an
                 * invalid lifecycle transition.
                 */
                if (! in_array($parcel->status, [
                    ParcelStatus::Created->value,
                    ParcelStatus::PickedUp->value,
                ], true)) {
                    throw new ConflictHttpException(
                        "Parcel {$parcel->parcel_no} has already been received or cannot be received in its current state."
                    );
                }

                $fromStatus = $parcel->status;

                $parcel->update([
                    'status' => ParcelStatus::Received->value,
                    'current_sorting_center_id' => $center->id,
                    'last_event_at' => now(),
                ]);

                $shipment->events()->create([
                    'parcel_id' => $parcel->id,
                    'event_type' => 'received_at_center',
                    'from_status' => $fromStatus,
                    'to_status' => ParcelStatus::Received->value,
                    'sorting_center_id' => $center->id,
                    'actor_user_id' => $actor->id,
                    'source' => 'scan',
                    'scan_method' => $method,
                    'occurred_at' => now(),
                ]);
            }

            $shipment->update([
                'status' => ShipmentStatus::AtSortingCenter->value,
            ]);

            $this->completeReceivedPickups(
                $waybill
                    ->parcels
                    ->pluck('id')
                    ->all(),
                $logisticsProfileId
            );

            return $waybill->fresh([
                'shipment.sellerOrder.store',
                'parcels',
            ]);
        });
    }

    private function completeReceivedPickups(
        array $parcelIds,
        int $logisticsProfileId
    ): void {
        if ($parcelIds === []) {
            return;
        }

        /*
        * Only inspect active pickup requests touched
        * by parcels processed in this intake.
        *
        * We deliberately do not scan every pickup
        * request owned by the Logistics provider.
        */
        $pickupIds = PickupRequest::query()
            ->forLogisticsProfile(
                $logisticsProfileId
            )
            ->where(
                'status',
                'scheduled'
            )
            ->whereHas(
                'parcels',
                fn ($query) =>
                    $query->whereIn(
                        'parcels.id',
                        $parcelIds
                    )
            )
            ->orderBy('pickup_requests.id')
            ->pluck('pickup_requests.id');

        foreach ($pickupIds as $pickupId) {
            /*
            * Lock the Pickup Request itself so two
            * different waybills from the same pickup
            * cannot complete it concurrently.
            */
            $pickup = PickupRequest::query()
                ->whereKey($pickupId)
                ->where(
                    'logistics_profile_id',
                    $logisticsProfileId
                )
                ->lockForUpdate()
                ->first();

            if (
                ! $pickup
                || $pickup->status !== 'scheduled'
            ) {
                continue;
            }

            /*
            * A request can have assignment history.
            * Only the latest/current assignment may
            * close the pickup lifecycle.
            */
            $assignment = $pickup
                ->assignments()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (
                ! $assignment
                || $assignment->status !== 'picked_up'
            ) {
                continue;
            }

            /*
            * An empty pickup must never become
            * completed accidentally.
            */
            if (! $pickup->parcels()->exists()) {
                continue;
            }

            /*
            * Current parcel status is not enough here:
            * after intake, a parcel can later become
            * sorted/dispatched/etc.
            *
            * received_at_center is the durable proof
            * that the parcel actually reached a
            * Sorting Center.
            */
            $hasParcelNotReceivedAtCenter =
                $pickup
                    ->parcels()
                    ->whereDoesntHave(
                        'events',
                        fn ($query) =>
                            $query->where(
                                'event_type',
                                'received_at_center'
                            )
                    )
                    ->exists();

            if ($hasParcelNotReceivedAtCenter) {
                continue;
            }

            $completedAt = now();

            $assignment->update([
                'status' =>
                    'completed',

                'completed_at' =>
                    $completedAt,
            ]);

            $pickup->update([
                'status' =>
                    'completed',

                'completed_at' =>
                    $completedAt,
            ]);
        }
    }
}
