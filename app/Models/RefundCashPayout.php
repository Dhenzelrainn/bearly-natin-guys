<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundCashPayout extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_to_logistics_at' => 'datetime',
            'received_by_logistics_at' => 'datetime',
            'assigned_at' => 'datetime',
            'received_by_rider_at' => 'datetime',
            'started_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'cash_returned_to_logistics_at' => 'datetime',
            'logistics_cash_return_received_at' => 'datetime',
            'delivered_at' => 'datetime',
            'logistics_verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function events()
    {
        return $this->hasMany(RefundCashPayoutEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }
}
