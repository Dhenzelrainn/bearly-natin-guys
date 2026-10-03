<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Parcel extends Model
{
    protected $guarded = [];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function waybill()
    {
        return $this->belongsTo(Waybill::class);
    }

    public function currentSortingCenter()
    {
        return $this->belongsTo(
            SortingCenter::class,
            'current_sorting_center_id'
        );
    }

    public function currentZone()
    {
        return $this->belongsTo(
            SortingZone::class,
            'current_zone_id'
        );
    }

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class)
            ->orderBy('occurred_at');
    }

    public function pickupRequests()
    {
        return $this->belongsToMany(
            PickupRequest::class,
            'pickup_request_parcels'
        )->withPivot('added_at');
    }
}
