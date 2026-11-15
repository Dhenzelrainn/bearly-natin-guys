<?php

namespace App\Enums;

enum DeliveryAttemptOutcome: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
}