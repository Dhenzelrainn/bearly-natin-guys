<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderAttendanceAbsence extends Model
{
    protected $fillable = [
        'logistics_profile_id',
        'rider_profile_id',
        'work_date',
        'marked_by_user_id',
        'marked_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'marked_at' => 'datetime',
        ];
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
