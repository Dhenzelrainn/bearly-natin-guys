<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['destination_address_snapshot' => 'array'];
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function originLogisticsProfile()
    {
        return $this->belongsTo(
            LogisticsProfile::class,
            'origin_logistics_profile_id'
        );
    }

    public function transfer()
    {
        return $this->hasOne(ShipmentTransfer::class);
    }

    public function originAddress()
    {
        return $this->belongsTo(Address::class, 'origin_address_id');
    }

    public function destinationAddress()
    {
        return $this->belongsTo(Address::class, 'destination_address_id');
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

    public function codCashCollection()
    {
        return $this->hasOne(CodCashCollection::class);
    }
}
