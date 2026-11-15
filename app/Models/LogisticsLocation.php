<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogisticsLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
            'last_seen_at' => 'datetime',
        ];
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }
}
