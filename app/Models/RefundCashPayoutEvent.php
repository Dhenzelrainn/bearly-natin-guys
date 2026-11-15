<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundCashPayoutEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function payout()
    {
        return $this->belongsTo(RefundCashPayout::class, 'refund_cash_payout_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
