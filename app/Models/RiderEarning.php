<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderEarning extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'earned_at' => 'datetime',
            'posted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function pickupAssignment()
    {
        return $this->belongsTo(PickupAssignment::class);
    }

    public function deliveryAttempt()
    {
        return $this->belongsTo(DeliveryAttempt::class);
    }
}
