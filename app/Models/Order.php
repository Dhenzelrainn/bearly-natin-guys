<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function promotionRedemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }
}
