<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SortingCenter extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function zones()
    {
        return $this->hasMany(SortingZone::class);
    }
}
