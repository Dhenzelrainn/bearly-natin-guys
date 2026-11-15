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

    public function parcel()
    {
        return $this->belongsTo(Parcel::class);
    }

    public function sortingCenter()
    {
        return $this->belongsTo(SortingCenter::class);
    }

    public function sortingZone()
    {
        return $this->belongsTo(
            SortingZone::class,
            'sorting_zone_id'
        );
    }
}
