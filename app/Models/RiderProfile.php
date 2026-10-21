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

    public function serviceLocationLabel(): string
    {
        $zone = $this->currentZone;
        $center = $zone?->sortingCenter ?? $this->homeSortingCenter;

        return collect([
            $zone?->name,
            $center?->name,
        ])->filter()->unique()->implode(' · ') ?: 'Location unavailable';
    }

    public function earnings()
    {
        return $this->hasMany(RiderEarning::class);
    }

    public function pickupAssignments()
    {
        return $this->hasMany(PickupAssignment::class);
    }

    public function deliveryAttempts()
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    public function dispatchBatches()
    {
        return $this->hasMany(DispatchBatch::class);
    }
}
