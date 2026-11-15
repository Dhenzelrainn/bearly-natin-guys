<?php

namespace App\Enums;

enum ParcelStatus: string
{
    case Created = 'created';
    case PickedUp = 'picked_up';
    case Received = 'received';
    case InTransit = 'in_transit';
    case Sorted = 'sorted';
    case Dispatched = 'dispatched';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';
    case Lost = 'lost';
    case Damaged = 'damaged';
}
