<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerWithdrawalStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function withdrawal()
    {
        return $this->belongsTo(SellerWithdrawal::class, 'seller_withdrawal_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
