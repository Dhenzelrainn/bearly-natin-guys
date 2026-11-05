<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchBatch extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'assignment_reason',
    ];

    protected function casts(): array
    {
        return [
            'planned_window_start' => 'datetime',
            'planned_window_end' => 'datetime',
            'prepared_at' => 'datetime',
            'assigned_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function sortingCenter()
    {
        return $this->belongsTo(SortingCenter::class);
    }

    public function sortingZone()
    {
        return $this->belongsTo(SortingZone::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function riderZone()
    {
        return $this->belongsTo(SortingZone::class, 'rider_zone_id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function parcels()
    {
        return $this->belongsToMany(
            Parcel::class,
            'dispatch_batch_parcels'
        )->withPivot([
            'sequence',
            'loaded_at',
        ]);
    }

    public function deliveryAttempts()
    {
        return $this->hasMany(DeliveryAttempt::class);
    }
}
