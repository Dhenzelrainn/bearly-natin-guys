<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case Pending = 'pending';
    case ReadyForPickup = 'ready_for_pickup';
    case PickupAssigned = 'pickup_assigned';
    case PickedUp = 'picked_up';
    case AtSortingCenter = 'at_sorting_center';
    case Sorted = 'sorted';
    case Dispatched = 'dispatched';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case ReturnToSender = 'return_to_sender';
    case Cancelled = 'cancelled';
}