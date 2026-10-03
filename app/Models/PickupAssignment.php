<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickupAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'arrived_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function pickupRequest()
    {
        return $this->belongsTo(PickupRequest::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
