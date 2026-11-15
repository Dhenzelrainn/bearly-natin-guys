<?php

namespace App\Observers;

use App\Models\Address;
use App\Services\AddressNormalizationService;

class AddressObserver
{
    public function saving(Address $address): void
    {
        app(AddressNormalizationService::class)
            ->enrich($address);
    }
}
