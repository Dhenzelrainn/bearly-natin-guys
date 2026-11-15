<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerPayoutItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function payout()
    {
        return $this->belongsTo(SellerPayout::class, 'seller_payout_id');
    }

    public function transaction()
    {
        return $this->belongsTo(SellerTransaction::class, 'seller_transaction_id');
    }
}
