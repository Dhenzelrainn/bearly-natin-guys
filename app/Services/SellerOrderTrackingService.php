<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\SellerOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SellerOrderTrackingService
{
    /**
     * Build the Seller-facing status from the persisted Seller Order and its
     * authoritative shipment/event records. Delivery is not completion until
     * the Buyer confirms receipt.
     *
     * @return array{
     *     seller_order_status: string,
     *     status_label: string,
     *     status_detail: string,
     *     canonical_status: string,
     *     status_key: string,
     *     tone: string,
     *     responsibility: string,
     *     next_step: string,
     *     timeline_history: array<int, array<string, mixed>>,
     *     updated_at: ?string
     * }
     */
    public function snapshot(SellerOrder $sellerOrder): array
    {
        $sellerOrder->loadMissing([
            'statusHistory.actor',
            'shipments.events',
        ]);

        $shipmentStatus = $this->aggregateShipmentStatus($sellerOrder->shipments);
        $state = $this->resolveState((string) $sellerOrder->status, $shipmentStatus);

        return [
            'seller_order_status' => (string) $sellerOrder->status,
            ...$state,
            'timeline_history' => $this->timelineHistory($sellerOrder),
            'updated_at' => $this->latestUpdate($sellerOrder),
        ];
    }

    private function resolveState(string $sellerStatus, ?string $shipmentStatus): array
    {
        if ($sellerStatus === SellerOrderStatus::Completed->value) {
            return $this->state('Completed', '', 'COMPLETED', 'completed', 'completed', 'System', 'The Buyer confirmed receipt; this Seller Order is complete.');
        }

        if ($sellerStatus === SellerOrderStatus::Cancelled->value) {
            return $this->state('Cancelled', '', 'CANCELLED', 'cancelled-returned', 'cancelled', 'System', 'This Seller Order was cancelled.');
        }

        if ($shipmentStatus !== null && ! in_array($shipmentStatus, ['pending', 'pending_waybill', 'ready_for_pickup'], true)) {
            return match ($shipmentStatus) {
                'pickup_assigned' => $this->state('Rider Assigned', '', 'ASSIGNED_TO_RIDER', 'shipped', 'transit', 'Logistics', 'A pickup Rider has been assigned and will collect the parcel from your Store.'),
                'picked_up' => $this->state('Picked Up', '', 'PICKED_UP', 'shipped', 'transit', 'Logistics', 'The Rider has collected the parcel. Logistics will receive and sort it.'),
                'at_sorting_center' => $this->state('At Sorting Center', '', 'AT_SORTING_CENTER', 'shipped', 'transit', 'Logistics', 'The parcel has reached the Sorting Center and is awaiting sorting.'),
                'sorted' => $this->state('Sorted', '', 'SORTED', 'shipped', 'transit', 'Logistics', 'The parcel has been sorted for its delivery area.'),
                'dispatched' => $this->state('Rider Assigned', '', 'ASSIGNED_TO_RIDER', 'shipped', 'transit', 'Logistics', 'The parcel has been dispatched to its delivery Rider.'),
                'out_for_delivery' => $this->state('Out for Delivery', '', 'OUT_FOR_DELIVERY', 'shipped', 'transit', 'Rider', 'The Rider is taking the parcel to the Buyer.'),
                'delivered' => $this->state('Delivered', 'Awaiting Buyer confirmation', 'DELIVERED', 'shipped', 'transit', 'Buyer', 'The Rider recorded delivery. The Seller Order remains open until the Buyer confirms receipt.'),
                'delivery_failed' => $this->state('Delivery Failed', 'Needs another delivery attempt', 'DELIVERY_FAILED', 'shipped', 'cancelled', 'Logistics', 'A delivery attempt failed. Logistics or the Rider must resolve or retry the delivery.'),
                'return_to_sender' => $this->state('Returned', '', 'RETURNED', 'cancelled-returned', 'cancelled', 'Logistics', 'The parcel is returning to the Seller.'),
                'cancelled' => $this->state('Shipment Cancelled', '', 'CANCELLED', 'cancelled-returned', 'cancelled', 'Logistics', 'The shipment was cancelled. Review its history for the latest details.'),
                default => $this->sellerState($sellerStatus),
            };
        }

        return $this->sellerState($sellerStatus);
    }

    private function sellerState(string $status): array
    {
        return match ($status) {
            'pending', 'placed' => $this->state('Placed', '', 'PLACED', 'new', 'new', 'Seller', 'Review the order details and confirm that your Store can fulfill it, or cancel with a reason.'),
            SellerOrderStatus::Accepted->value => $this->state('Confirmed', '', 'CONFIRMED', 'to-prepare', 'prepare', 'Seller', 'Verify the items and quantities, then start preparing the order.'),
            SellerOrderStatus::Packing->value => $this->state('Preparing', '', 'PREPARING', 'to-prepare', 'prepare', 'Seller', 'Complete the packing checklist before marking the order ready for pickup.'),
            SellerOrderStatus::ReadyForPickup->value => $this->state('Ready for Pickup', '', 'READY_FOR_PICKUP', 'ready-pickup', 'pickup', 'Seller', 'Schedule collection from Fulfillment → Pickup Requests; Logistics will verify it and assign a Rider.'),
            SellerOrderStatus::HandedToLogistics->value => $this->state('Handed to Logistics', '', 'PICKED_UP', 'shipped', 'transit', 'Logistics', 'The parcel has been handed to Logistics and is moving through fulfillment.'),
            default => $this->state(Str::headline($status), '', strtoupper($status), 'all', 'new', 'Seller', 'Review the saved Seller Order status and tracking activity.'),
        };
    }

    private function state(
        string $label,
        string $detail,
        string $canonical,
        string $key,
        string $tone,
        string $responsibility,
        string $nextStep,
    ): array {
        return [
            'status_label' => $label,
            'status_detail' => $detail,
            'canonical_status' => $canonical,
            'status_key' => $key,
            'tone' => $tone,
            'responsibility' => $responsibility,
            'next_step' => $nextStep,
        ];
    }

    private function aggregateShipmentStatus(Collection $shipments): ?string
    {
        $statuses = $shipments
            ->pluck('status')
            ->filter()
            ->unique()
            ->values();

        if ($statuses->isEmpty()) {
            return null;
        }

        if ($statuses->contains('return_to_sender')) {
            return 'return_to_sender';
        }

        if ($statuses->contains('delivery_failed')) {
            return 'delivery_failed';
        }

        $activeStatuses = $statuses
            ->reject(fn (string $status): bool => in_array($status, ['delivered', 'cancelled'], true));

        if ($activeStatuses->isNotEmpty()) {
            $rank = [
                'pending' => 0,
                'pending_waybill' => 0,
                'ready_for_pickup' => 1,
                'pickup_assigned' => 2,
                'picked_up' => 3,
                'at_sorting_center' => 4,
                'sorted' => 5,
                'dispatched' => 6,
                'out_for_delivery' => 7,
            ];

            // A Seller Order can own more than one shipment. Surface the most
            // advanced active stage so the display never regresses while a
            // lagging shipment is still moving.
            return $activeStatuses
                ->sortByDesc(fn (string $status): int => $rank[$status] ?? -1)
                ->first();
        }

        if ($statuses->contains('delivered')) {
            return 'delivered';
        }

        return 'cancelled';
    }

    private function timelineHistory(SellerOrder $sellerOrder): array
    {
        $sellerHistory = $sellerOrder->statusHistory->map(fn ($entry): array => [
            'status' => $this->canonicalSellerStatus((string) $entry->to_status),
            'label' => $this->sellerStatusLabel((string) $entry->to_status),
            'actor' => $entry->actor?->name ?? 'Former user',
            'source' => 'Seller Order',
            'reason' => $entry->reason,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
        ]);

        $shipmentHistory = $sellerOrder->shipments
            ->flatMap(fn ($shipment) => $shipment->events)
            ->map(fn ($event): array => [
                'status' => $this->canonicalShipmentStatus((string) $event->to_status),
                'label' => $this->shipmentEventLabel((string) $event->event_type),
                'actor' => $this->eventActor((string) $event->source),
                'source' => $this->eventActor((string) $event->source),
                'reason' => $event->notes,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ]);

        return $sellerHistory
            ->concat($shipmentHistory)
            ->filter(fn (array $entry): bool => filled($entry['occurred_at']))
            ->unique(fn (array $entry): string => implode('|', [
                $entry['status'],
                $entry['source'],
                $entry['occurred_at'],
            ]))
            ->sortByDesc('occurred_at')
            ->take(12)
            ->values()
            ->all();
    }

    private function canonicalSellerStatus(string $status): string
    {
        return match ($status) {
            'pending', 'placed' => 'PLACED',
            'accepted' => 'CONFIRMED',
            'packing' => 'PREPARING',
            'ready_for_pickup' => 'READY_FOR_PICKUP',
            'handed_to_logistics' => 'PICKED_UP',
            'completed' => 'COMPLETED',
            'cancelled' => 'CANCELLED',
            default => strtoupper($status),
        };
    }

    private function sellerStatusLabel(string $status): string
    {
        return match ($this->canonicalSellerStatus($status)) {
            'PLACED' => 'Placed',
            'CONFIRMED' => 'Confirmed',
            'PREPARING' => 'Preparing',
            'READY_FOR_PICKUP' => 'Ready for Pickup',
            'PICKED_UP' => 'Handed to Logistics',
            'COMPLETED' => 'Completed',
            'CANCELLED' => 'Cancelled',
            default => Str::headline($status),
        };
    }

    private function canonicalShipmentStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'PLACED',
            'ready_for_pickup' => 'READY_FOR_PICKUP',
            'pickup_assigned', 'dispatched' => 'ASSIGNED_TO_RIDER',
            'picked_up' => 'PICKED_UP',
            'received' => 'AT_SORTING_CENTER',
            'sorted' => 'SORTED',
            'out_for_delivery' => 'OUT_FOR_DELIVERY',
            'delivered' => 'DELIVERED',
            'failed', 'delivery_failed' => 'DELIVERY_FAILED',
            'returned', 'return_to_sender' => 'RETURNED',
            'cancelled' => 'CANCELLED',
            default => strtoupper($status),
        };
    }

    private function shipmentEventLabel(string $eventType): string
    {
        return match ($eventType) {
            'shipment_ready_for_pickup' => 'Ready for pickup',
            'parcel_picked_up' => 'Parcel picked up from Seller',
            'shipment_picked_up' => 'Pickup confirmed',
            'received_at_center' => 'Received at Sorting Center',
            'parcel_sorted', 'parcel_resorted' => 'Sorted for destination',
            'parcel_dispatched', 'shipment_dispatched' => 'Dispatched to delivery Rider',
            'parcel_out_for_delivery', 'shipment_out_for_delivery' => 'Out for Delivery',
            'parcel_delivered', 'shipment_delivered' => 'Delivered to recipient',
            'parcel_delivery_failed', 'shipment_delivery_failed' => 'Delivery attempt failed',
            default => Str::headline($eventType),
        };
    }

    private function eventActor(string $source): string
    {
        return match ($source) {
            'rider' => 'Rider',
            'logistics', 'scan' => 'Logistics',
            'seller' => 'Seller',
            default => 'System',
        };
    }

    private function latestUpdate(SellerOrder $sellerOrder): ?string
    {
        $latestShipmentEvent = $sellerOrder->shipments
            ->flatMap(fn ($shipment) => $shipment->events)
            ->pluck('occurred_at')
            ->filter()
            ->sortDesc()
            ->first();

        $latestSellerUpdate = $sellerOrder->statusHistory
            ->pluck('occurred_at')
            ->filter()
            ->sortDesc()
            ->first();

        $latest = collect([$latestShipmentEvent, $latestSellerUpdate, $sellerOrder->updated_at])
            ->filter()
            ->sortDesc()
            ->first();

        return $latest?->toIso8601String();
    }
}
