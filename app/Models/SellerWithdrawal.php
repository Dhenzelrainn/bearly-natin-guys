<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerWithdrawal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function wallet()
    {
        return $this->belongsTo(SellerWallet::class, 'seller_wallet_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(SellerBankAccount::class, 'seller_bank_account_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SellerWithdrawalStatusHistory::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function transfer()
    {
        return $this->hasOne(SellerWithdrawalTransfer::class);
    }
}
