<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class RiderShift extends Model
{
    public const TIMEZONE = 'Asia/Manila';

    public const TIMEZONE_LABEL = 'PHT';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function checkedInAtPht(): ?Carbon
    {
        return $this->checked_in_at?->copy()->timezone(self::TIMEZONE);
    }

    public function checkedOutAtPht(): ?Carbon
    {
        return $this->checked_out_at?->copy()->timezone(self::TIMEZONE);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function earnings()
    {
        return $this->hasMany(RiderEarning::class);
    }

    public function attendanceScans()
    {
        return $this->hasMany(RiderAttendanceScan::class);
    }
}
