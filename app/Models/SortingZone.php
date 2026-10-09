<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SortingZone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'destination_rules' => 'array',
        ];
    }

    public function sortingCenter()
    {
        return $this->belongsTo(SortingCenter::class);
    }

    public function parcels()
    {
        return $this->hasMany(
            Parcel::class,
            'current_zone_id'
        );
    }

    public function dispatchBatches()
    {
        return $this->hasMany(DispatchBatch::class);
    }
}
