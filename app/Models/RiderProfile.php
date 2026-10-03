<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiderProfile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function homeSortingCenter()
    {
        return $this->belongsTo(
            SortingCenter::class,
            'home_sorting_center_id'
        );
    }

    public function currentZone()
    {
        return $this->belongsTo(
            SortingZone::class,
            'current_zone_id'
        );
    }

    public function pickupAssignments()
    {
        return $this->hasMany(PickupAssignment::class);
    }
}
