<?php

namespace App\Enums;

enum SellerOrderStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Packing = 'packing';
    case ReadyForPickup = 'ready_for_pickup';
    case HandedToLogistics = 'handed_to_logistics';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
