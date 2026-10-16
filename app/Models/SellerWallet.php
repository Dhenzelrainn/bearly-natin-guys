<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerWallet extends Model
{
    protected $guarded = [];

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SellerWalletTransaction::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SellerBankAccount::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(SellerWithdrawal::class)->latest('requested_at');
    }
}
