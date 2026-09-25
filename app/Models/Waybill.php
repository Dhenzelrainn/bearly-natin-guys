<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Waybill extends Model
{
    protected $guarded = [];

    protected $hidden = ['scan_token'];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function parcels()
    {
        return $this->hasMany(Parcel::class);
    }
}
