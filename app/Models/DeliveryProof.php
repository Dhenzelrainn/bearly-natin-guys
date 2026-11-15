<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryProof extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'otp_verified_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    public function deliveryAttempt()
    {
        return $this->belongsTo(DeliveryAttempt::class);
    }
}