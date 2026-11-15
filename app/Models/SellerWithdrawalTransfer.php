<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerWithdrawalTransfer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'recorded_at' => 'datetime',
            'verified_at' => 'datetime',
            'failure_confirmed_at' => 'datetime',
        ];
    }

    public function withdrawal()
    {
        return $this->belongsTo(SellerWithdrawal::class, 'seller_withdrawal_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
