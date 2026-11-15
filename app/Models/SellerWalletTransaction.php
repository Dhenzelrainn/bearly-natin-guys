<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SellerWalletTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Seller wallet ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Seller wallet ledger entries are immutable.'));
    }

    public function wallet()
    {
        return $this->belongsTo(SellerWallet::class, 'seller_wallet_id');
    }

    public function sellerTransaction()
    {
        return $this->belongsTo(SellerTransaction::class);
    }

    public function withdrawal()
    {
        return $this->belongsTo(SellerWithdrawal::class, 'seller_withdrawal_id');
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function legacyPayout()
    {
        return $this->belongsTo(SellerPayout::class, 'seller_payout_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
