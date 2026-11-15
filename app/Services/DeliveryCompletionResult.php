<?php

namespace App\Services;

use App\Models\Shipment;

final readonly class DeliveryCompletionResult
{
    public function __construct(
        public Shipment $shipment,
        public bool $alreadyCompleted,
    ) {}
}
