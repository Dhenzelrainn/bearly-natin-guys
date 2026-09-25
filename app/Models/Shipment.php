<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $guarded = [];

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function waybill()
    {
        return $this->hasOne(Waybill::class);
    }

    public function parcels()
    {
        return $this->hasMany(Parcel::class);
    }

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at');
    }
}
