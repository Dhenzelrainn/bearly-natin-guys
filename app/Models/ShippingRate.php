<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_kg_fee_minor' => 'integer',
            'additional_kg_surcharge_minor' => 'integer',
        ];
    }
}
