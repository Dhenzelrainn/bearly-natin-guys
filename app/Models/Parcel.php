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

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class);
    }
}
