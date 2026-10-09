<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SortingCenter extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function zones()
    {
        return $this->hasMany(SortingZone::class);
    }

    public function parcels()
    {
        return $this->hasMany(
            Parcel::class,
            'current_sorting_center_id'
        );
    }

    public function dispatchBatches()
    {
        return $this->hasMany(DispatchBatch::class);
    }
}
