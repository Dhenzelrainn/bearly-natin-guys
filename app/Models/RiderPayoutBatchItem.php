<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderPayoutBatchItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(RiderPayoutBatch::class, 'rider_payout_batch_id');
    }

    public function riderEarning()
    {
        return $this->belongsTo(RiderEarning::class);
    }
}
