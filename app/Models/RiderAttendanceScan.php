<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderAttendanceScan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    public function station()
    {
        return $this->belongsTo(RiderAttendanceQrStation::class, 'station_id');
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function shift()
    {
        return $this->belongsTo(RiderShift::class, 'rider_shift_id');
    }
}
