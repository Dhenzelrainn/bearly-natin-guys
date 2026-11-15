<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\ReturnDeliveryMethod;
use App\Enums\ReturnRequestStatus;
use App\Enums\ShipmentStatus;
use App\Models\Parcel;
use App\Models\PickupRequest;
use App\Models\RiderEarning;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ParcelIntakeService
{
    public function __construct(
        private readonly ReturnRefundService $returns,
        private readonly RiderPickupEarningService $pickupEarnings,
    ) {}

    public function receive(
        Waybill $waybill,
        SortingCenter $center,
        User $actor,
        string $method = 'camera',
        ?array $parcelSizeClasses = null,
        ?int $parcelId = null,
    ): Waybill {
        return DB::transaction(function () use (
            $waybill,
            $center,
            $actor,
            $method,
            $parcelSizeClasses,
            $parcelId,
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
                    'shipment.returnRequest',
                ])
                ->findOrFail($waybill->id);

            $parcels = Parcel::query()
                ->where('waybill_id', $waybill->id)
                ->orderBy('piece_sequence')
                ->lockForUpdate()
                ->get();
            $waybill->setRelation('parcels', $parcels);
            $selectedParcels = $parcelId === null
                ? $parcels->filter(fn (Parcel $parcel): bool => in_array(
                    $parcel->status,
                    [ParcelStatus::Created->value, ParcelStatus::PickedUp->value],
                    true,
                ))->values()
                : $parcels->where('id', $parcelId)->values();

            if ($selectedParcels->isEmpty()) {
                throw new ConflictHttpException($parcelId === null
                    ? 'This Waybill has no parcels awaiting intake.'
                    : 'The selected parcel does not belong to this Waybill.');
            }

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

            $returnRequest = null;
            $isBuyerReturnDropoff = false;
            if ($shipment->purpose === 'return') {
                $returnRequest = ReturnRequest::query()
                    ->where('return_shipment_id', $shipment->id)
                    ->lockForUpdate()
                    ->first();

                if (! $returnRequest) {
                    throw new ConflictHttpException('This return shipment is not linked to an active return case.');
                }

                $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
                $waybill->setRelation('shipment', $shipment);
                $isBuyerReturnDropoff = $returnRequest->return_delivery_method === ReturnDeliveryMethod::BuyerDropoff->value;

                if ($isBuyerReturnDropoff) {
                    if ($returnRequest->status !== ReturnRequestStatus::ReturnDropoffPending->value
                        || (int) $returnRequest->dropoff_sorting_center_id !== (int) $center->id
                        || ! $returnRequest->dropoff_due_at
                        || now()->greaterThan($returnRequest->dropoff_due_at)
                        || $shipment->status !== ShipmentStatus::AwaitingBuyerDropoff->value) {
                        throw new ConflictHttpException('This return is expired or is no longer awaiting Buyer drop-off.');
                    }
                } elseif ($returnRequest->return_delivery_method === ReturnDeliveryMethod::RiderPickup->value
                    && $selectedParcels->contains(fn ($parcel): bool => $parcel->status === ParcelStatus::Created->value)) {
                    throw new ConflictHttpException('The Rider must collect this return parcel before Logistics can receive it.');
                }
            }

            foreach ($selectedParcels as $parcel) {
                if (! in_array($parcel->status, [
                    ParcelStatus::Created->value,
                    ParcelStatus::PickedUp->value,
                ], true)) {
                    throw new ConflictHttpException(
                        "Parcel {$parcel->parcel_no} has already been received or cannot be received in its current state."
                    );
                }
            }

            $parcelSizeClasses = $this->validateParcelSizeClasses(
                $parcelSizeClasses,
                $selectedParcels,
            );

            foreach ($selectedParcels as $parcel) {
                $fromStatus = $parcel->status;
                $previousSizeClass = $parcel->size_class;
                $sizeClass = $parcelSizeClasses[(int) $parcel->id] ?? null;

                if ($sizeClass !== null) {
                    $this->saveParcelSizeClass(
                        $parcel,
                        $sizeClass,
                        $actor,
                        (int) $logisticsProfileId,
                    );
                }

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
                    'metadata' => $sizeClass === null ? null : [
                        'parcel_size_class' => $sizeClass,
                        'previous_parcel_size_class' => $previousSizeClass,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            $parcelIds = $parcels->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $arrivedParcelCount = DB::table('shipment_events')
                ->where('shipment_id', $shipment->id)
                ->whereIn('parcel_id', $parcelIds)
                ->where('event_type', 'received_at_center')
                ->distinct()
                ->count('parcel_id');
            $allParcelsReceived = $parcels->isNotEmpty()
                && $arrivedParcelCount === $parcels->count();

            if ($allParcelsReceived) {
                $shipment->update([
                    'status' => ShipmentStatus::AtSortingCenter->value,
                ]);
            }

            if ($isBuyerReturnDropoff && $returnRequest && $allParcelsReceived) {
                $shipment->events()->create([
                    'event_type' => 'buyer_return_dropoff_received',
                    'from_status' => ShipmentStatus::AwaitingBuyerDropoff->value,
                    'to_status' => ShipmentStatus::AtSortingCenter->value,
                    'sorting_center_id' => $center->id,
                    'actor_user_id' => $actor->id,
                    'source' => 'scan',
                    'scan_method' => $method,
                    'metadata' => [
                        'return_request_id' => (int) $returnRequest->id,
                        'sorting_center_id' => (int) $center->id,
                    ],
                    'occurred_at' => now(),
                ]);
                $this->returns->recordReturnDropoffReceived(
                    (int) $returnRequest->id,
                    $actor,
                    (int) $shipment->id,
                );
            }

            $this->completeReceivedPickups(
                $selectedParcels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                (int) $logisticsProfileId,
            );

            return $waybill->fresh([
                'shipment.sellerOrder.store',
                'parcels',
            ]);
        });
    }

    /**
     * @param  array<int|string, mixed>|null  $submitted
     * @param  Collection<int, Parcel>  $parcels
     * @return array<int, string>
     */
    private function validateParcelSizeClasses(?array $submitted, Collection $parcels): array
    {
        if ($submitted === null) {
            return [];
        }

        $expectedIds = $parcels
            ->map(fn (Parcel $parcel): string => (string) $parcel->id)
            ->sort()
            ->values()
            ->all();
        $submittedIds = collect(array_keys($submitted))
            ->map(fn ($id): string => (string) $id)
            ->sort()
            ->values()
            ->all();

        if ($expectedIds !== $submittedIds) {
            throw ValidationException::withMessages([
                'parcel_size_classes' => 'Choose a size class for every parcel on this waybill.',
            ]);
        }

        $normalized = [];
        $errors = [];
        $allowedSizeClasses = $this->pickupEarnings->sizeClasses();

        foreach ($submitted as $parcelId => $sizeClass) {
            $parcelId = (int) $parcelId;

            if (
                ! is_string($sizeClass)
                || ! in_array($sizeClass, $allowedSizeClasses, true)
            ) {
                $errors["parcel_size_classes.{$parcelId}"] = 'Choose a recognized parcel size class.';

                continue;
            }

            $normalized[$parcelId] = $sizeClass;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function saveParcelSizeClass(
        Parcel $parcel,
        string $sizeClass,
        User $actor,
        int $logisticsProfileId,
    ): void {
        $pickupEarning = RiderEarning::query()
            ->where('parcel_id', $parcel->id)
            ->where('type', config('bearly_rider_compensation.pickup_earning_type', 'pickup_parcel'))
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($pickupEarning?->status === RiderPickupEarningService::STATUS_HELD) {
            if ((int) $pickupEarning->logistics_profile_id !== $logisticsProfileId) {
                throw new ConflictHttpException(
                    'The held Rider pickup earning belongs to a different Logistics provider and cannot be classified here.'
                );
            }

            $this->pickupEarnings->classifyHeldEarning(
                $pickupEarning,
                $sizeClass,
                $actor,
            );
        } elseif (
            $pickupEarning
            && $pickupEarning->status !== RiderPickupEarningService::STATUS_HELD
            && (string) $pickupEarning->size_class_snapshot !== $sizeClass
        ) {
            throw new ConflictHttpException(
                'This parcel size cannot be changed because its Rider pickup earning has already been classified.'
            );
        }

        $parcel->update(['size_class' => $sizeClass]);
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
