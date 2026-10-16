<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerBankAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'is_default' => 'boolean'];
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function wallet()
    {
        return $this->hasOne(SellerWallet::class, 'seller_profile_id', 'seller_profile_id');
    }

    public function withdrawals()
    {
        return $this->hasMany(SellerWithdrawal::class);
    }

    public function maskedAccountNumber(): string
    {
        return '•••• '.$this->account_number_last4;
    }
}
