<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionRedemptionAllocation extends Model
{
    protected $guarded = [];

    public function redemption()
    {
        return $this->belongsTo(PromotionRedemption::class, 'promotion_redemption_id');
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
