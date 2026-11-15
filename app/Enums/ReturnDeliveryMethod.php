<?php

namespace App\Enums;

enum ReturnDeliveryMethod: string
{
    case RiderPickup = 'rider_pickup';
    case BuyerDropoff = 'buyer_dropoff';
}
