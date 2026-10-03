<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public function scopeMatchingIdentifier(
        Builder $query,
        string $identifier
    ): Builder {
        return $query->where(function (Builder $query) use ($identifier) {
            $query->where('scan_token', $identifier)
                ->orWhere('waybill_no', $identifier)
                ->orWhere('barcode_value', $identifier);
        });
    }

    public function scopeForLogisticsProfile(
        Builder $query,
        int $logisticsProfileId
    ): Builder {
        return $query->whereHas(
            'shipment',
            fn (Builder $shipmentQuery) => $shipmentQuery
                ->where('logistics_profile_id', $logisticsProfileId)
        );
    }
}