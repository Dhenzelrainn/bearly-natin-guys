<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellerProfile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->hasOne(Store::class);
    }

    public function pickupAddress()
    {
        return $this->belongsTo(Address::class, 'pickup_address_id');
    }

    public function approvedCategory()
    {
        return $this->belongsTo(Category::class, 'approved_category_id');
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(SellerWarning::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(SellerWallet::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SellerBankAccount::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(SellerWithdrawal::class);
    }
}
