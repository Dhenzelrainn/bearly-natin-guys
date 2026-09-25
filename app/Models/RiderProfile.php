<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiderProfile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function earnings(){ return $this->hasMany(RiderEarning::class); }
    public function pickupAssignments(){ return $this->hasMany(PickupAssignment::class); }
    public function deliveryAttempts(){ return $this->hasMany(DeliveryAttempt::class); }
}
