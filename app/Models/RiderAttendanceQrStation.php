<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderAttendanceQrStation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function scans()
    {
        return $this->hasMany(RiderAttendanceScan::class, 'station_id');
    }
}
