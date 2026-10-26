<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderEarning extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
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

    public function shippingSettlement()
    {
        return $this->belongsTo(ShippingSettlement::class);
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function payout()
    {
        return $this->hasOne(RiderEarningPayout::class);
    }
}
