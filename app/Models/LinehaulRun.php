<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinehaulRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'loaded_at' => 'datetime',
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function originLogisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class, 'origin_logistics_profile_id');
    }

    public function destinationLogisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class, 'destination_logistics_profile_id');
    }

    public function originSortingCenter()
    {
        return $this->belongsTo(SortingCenter::class, 'origin_sorting_center_id');
    }

    public function courierRiderProfile()
    {
        return $this->belongsTo(RiderProfile::class, 'courier_rider_profile_id');
    }

    public function shipments()
    {
        return $this->belongsToMany(Shipment::class, 'linehaul_run_shipments')
            ->withPivot(['id', 'loaded_at'])
            ->withTimestamps();
    }

    public function events()
    {
        return $this->hasMany(LinehaulRunEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function earnings()
    {
        return $this->hasMany(RiderEarning::class);
    }
}
