<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LogisticsProfile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function riders()
    {
        return $this->hasMany(RiderProfile::class);
    }

    public function sortingCenters()
    {
        return $this->hasMany(SortingCenter::class);
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    public function shippingSettlements()
    {
        return $this->hasMany(ShippingSettlement::class);
    }

    public function riderEarningPayouts()
    {
        return $this->hasMany(RiderEarningPayout::class);
    }

    public function serviceAddress()
    {
        return $this->hasOne(Address::class, 'user_id', 'user_id')
            ->whereRaw('LOWER(label) = ?', ['sorting center'])
            ->orderByDesc('id');
    }

    public function outgoingTransfers()
    {
        return $this->hasMany(
            ShipmentTransfer::class,
            'from_logistics_profile_id'
        );
    }

    public function incomingTransfers()
    {
        return $this->hasMany(
            ShipmentTransfer::class,
            'to_logistics_profile_id'
        );
    }

    public function pickupRequests()
    {
        return $this->hasMany(PickupRequest::class);
    }
}
