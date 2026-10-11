<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\PickupAssignment;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\Shipment;
use App\Models\User;
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
        User $rider,
        ?ReturnRefundService $returns = null,
    ): PickupAssignment {
        return DB::transaction(
            function () use (
                $pickupNo,
                $rider,
                $returns,
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

                    if ($shipment->purpose === 'return' && $returns) {
                        $returns->recordReturnPickedUp(
                            (int) $pickup->return_request_id,
                            $rider,
                            (int) $shipment->id,
                        );
                    }
                }

                $this->recordSellerHandoffs(
                    $shipments
                        ->where('purpose', 'outbound')
                        ->pluck('seller_order_id')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    $rider,
                    $pickedUpAt,
                );

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

    /**
     * Move the Seller Order out of its pickup-ready state when the Rider
     * confirms custody of the shipment, preserving the transition audit.
     *
     * @param array<int, int|string> $sellerOrderIds
     */
    private function recordSellerHandoffs(
        array $sellerOrderIds,
        User $rider,
        mixed $occurredAt,
    ): void {
        if ($sellerOrderIds === []) {
            return;
        }

        $sellerOrderIds = array_map('intval', $sellerOrderIds);
        $orders = SellerOrder::query()
            ->whereIn('id', $sellerOrderIds)
            ->get(['id', 'order_id']);
        $orderIds = $orders
            ->pluck('order_id')
            ->unique()
            ->sort()
            ->values();

        Order::query()
            ->whereIn('id', $orderIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $lockedSellerOrders = SellerOrder::query()
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lockedSellerOrders->whereIn('id', $sellerOrderIds) as $sellerOrder) {
            if ($sellerOrder->status === SellerOrderStatus::HandedToLogistics->value) {
                continue;
            }

            if ($sellerOrder->status !== SellerOrderStatus::ReadyForPickup->value) {
                throw new ConflictHttpException(
                    "Seller Order {$sellerOrder->seller_order_no} is not ready to be handed to Logistics."
                );
            }

            $sellerOrder->update([
                'status' => SellerOrderStatus::HandedToLogistics->value,
                'handed_over_at' => $occurredAt,
            ]);

            SellerOrderStatusHistory::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'from_status' => SellerOrderStatus::ReadyForPickup->value,
                'to_status' => SellerOrderStatus::HandedToLogistics->value,
                'actor_user_id' => $rider->id,
                'reason' => 'Rider confirmed pickup and took custody of the parcel.',
                'occurred_at' => $occurredAt,
            ]);
        }
    }
}
