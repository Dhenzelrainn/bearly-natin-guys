<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderVehicleChangeRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
