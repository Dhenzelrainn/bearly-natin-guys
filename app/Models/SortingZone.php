<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SortingZone extends Model
{
    use SoftDeletes;

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
