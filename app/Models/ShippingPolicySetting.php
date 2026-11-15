<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingPolicySetting extends Model
{
    protected $guarded = [];

    public function managerLogisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class, 'manager_logistics_profile_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
