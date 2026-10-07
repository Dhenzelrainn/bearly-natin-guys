<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function parcel()
    {
        return $this->belongsTo(Parcel::class);
    }

    public function dispatchBatch()
    {
        return $this->belongsTo(DispatchBatch::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function proofs()
    {
        return $this->hasMany(DeliveryProof::class);
    }
}