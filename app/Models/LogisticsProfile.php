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

    public function shipments(){ return $this->hasMany(Shipment::class); }
}
