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
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderPickupService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

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
                        ->whereIn('status', [
                            'assigned',
                            'accepted',
                            'arrived',
                            'picked_up',
                            'completed',
                        ])
                        ->whereHas(
                            'pickupRequest',
                            fn ($query) => $query->where(
                                'pickup_no',
                                $pickupNo
                            )
                        )
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->firstOrFail();

                /*
                * The accept form can be replayed by a double click, a stale
                * tab, or a retried request. Once accepted, return the existing
                * assignment instead of surfacing a 409 error page.
                */
                if (
                    $assignment->status
                    !== 'assigned'
                ) {
                    return $assignment
                        ->fresh([
                            'pickupRequest',
                        ]);
                }

                $assignment->update([
                    'status' => 'accepted',

                    'accepted_at' => now(),
                ]);

                $this->notifySellerOfPickupUpdate(
                    $assignment,
                    'Pickup accepted by Rider',
                    "A Rider accepted pickup {$assignment->pickupRequest?->pickup_no} and will collect it on the scheduled date.",
                );

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
        ?string $waybillIdentifier = null,
        string $scanMethod = 'manual',
    ): PickupAssignment {
        return DB::transaction(
            function () use (
                $pickupNo,
                $rider,
                $returns,
                $waybillIdentifier,
                $scanMethod,
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
                            fn ($query) => $query->where(
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
                        ->with(['shipment', 'waybill'])
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

                if (filled($waybillIdentifier)) {
                    $identifier = trim($waybillIdentifier);
                    $matched = $parcels->contains(function ($parcel) use ($identifier): bool {
                        $waybill = $parcel->waybill;

                        return $waybill
                            && in_array($identifier, [
                                (string) $waybill->waybill_no,
                                (string) $waybill->barcode_value,
                            ], true);
                    });

                    if (! $matched) {
                        throw new ConflictHttpException(
                            'The scanned waybill does not belong to this Rider pickup assignment.'
                        );
                    }
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
                        'status' => ParcelStatus::PickedUp->value,

                        'last_event_at' => $pickedUpAt,
                    ]);

                    $parcel
                        ->shipment
                        ->events()
                        ->create([
                            'parcel_id' => $parcel->id,

                            'event_type' => 'parcel_picked_up',

                            'from_status' => $fromStatus,

                            'to_status' => ParcelStatus::PickedUp->value,

                            'actor_user_id' => $rider->id,

                            'source' => 'rider',

                            'metadata' => [
                                'pickup_request_id' => $pickup->id,

                                'pickup_assignment_id' => $assignment->id,

                                'scan_method' => $scanMethod,
                            ],

                            'occurred_at' => $pickedUpAt,
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
                        'status' => ShipmentStatus::PickedUp->value,
                    ]);

                    $shipment
                        ->events()
                        ->create([
                            'event_type' => 'shipment_picked_up',

                            'from_status' => $fromStatus,

                            'to_status' => ShipmentStatus::PickedUp->value,

                            'actor_user_id' => $rider->id,

                            'source' => 'rider',

                            'metadata' => [
                                'pickup_request_id' => $pickup->id,

                                'pickup_assignment_id' => $assignment->id,

                                'scan_method' => $scanMethod,
                            ],

                            'occurred_at' => $pickedUpAt,
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
                    'status' => 'picked_up',

                    'picked_up_at' => $pickedUpAt,
                ]);

                $this->notifySellerOfPickupUpdate(
                    $assignment,
                    'Pickup collected',
                    "Pickup {$pickup->pickup_no} was collected by the Rider and handed to Logistics.",
                );
                $this->notifyBuyersOfConfirmedPickup($assignment, $shipments);

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
     * @param  array<int, int|string>  $sellerOrderIds
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

    private function notifySellerOfPickupUpdate(PickupAssignment $assignment, string $title, string $body): void
    {
        $pickup = $assignment->pickupRequest;
        $sellerUser = $pickup?->store?->sellerProfile?->user;

        if (! $pickup || ! $sellerUser) {
            return;
        }

        $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
            eventKey: 'seller_pickup_updates',
            title: $title,
            body: $body,
            actionUrl: route('seller.fulfillment.pickups'),
            type: 'info',
            meta: [
                'pickup_request_id' => (int) $pickup->id,
                'pickup_assignment_id' => (int) $assignment->id,
            ],
        ));
    }

    private function notifyBuyersOfConfirmedPickup(PickupAssignment $assignment, Collection $shipments): void
    {
        $sellerOrderIds = $shipments
            ->where('purpose', 'outbound')
            ->pluck('seller_order_id')
            ->filter()
            ->unique()
            ->values();

        if ($sellerOrderIds->isEmpty()) {
            return;
        }

        $sellerOrders = SellerOrder::query()
            ->with('order.buyer')
            ->whereIn('id', $sellerOrderIds)
            ->get();

        foreach ($sellerOrders as $sellerOrder) {
            $buyer = $sellerOrder->order?->buyer;

            if (! $buyer) {
                continue;
            }

            $this->notifications->sendAfterCommit($buyer, new WorkflowNotification(
                eventKey: 'buyer_shipment_updates',
                title: 'Parcel picked up',
                body: "Your parcel for {$sellerOrder->seller_order_no} was picked up by the Rider.",
                actionUrl: route('buyer.profile').'#tracking',
                type: 'info',
                meta: [
                    'seller_order_id' => (int) $sellerOrder->id,
                    'pickup_request_id' => (int) $assignment->pickup_request_id,
                ],
            ));
        }
    }
}
