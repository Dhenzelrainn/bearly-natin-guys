<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
