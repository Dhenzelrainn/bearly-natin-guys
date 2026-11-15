<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderEarningPayout extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_on' => 'date',
        ];
    }

    public function riderEarning()
    {
        return $this->belongsTo(RiderEarning::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
