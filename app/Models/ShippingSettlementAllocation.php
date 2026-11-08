<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingSettlementAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'original_shipping_fee_minor' => 'integer',
            'buyer_paid_shipping_fee_minor' => 'integer',
            'platform_shipping_subsidy_minor' => 'integer',
            'zone_level' => 'integer',
            'origin_logistics_share_minor' => 'integer',
            'logistics_share_minor' => 'integer',
            'rider_share_minor' => 'integer',
        ];
    }

    public function settlement()
    {
        return $this->belongsTo(ShippingSettlement::class, 'shipping_settlement_id');
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
