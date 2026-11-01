<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingSettlement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'zone_level' => 'integer',
            'original_shipping_fee_minor' => 'integer',
            'return_shipping_fee_minor' => 'integer',
            'buyer_paid_shipping_fee_minor' => 'integer',
            'platform_shipping_subsidy_minor' => 'integer',
            'logistics_share_minor' => 'integer',
            'rider_share_minor' => 'integer',
            'remitted_amount_minor' => 'integer',
            'eligible_at' => 'datetime',
            'remitted_on' => 'date',
            'remitted_at' => 'datetime',
        ];
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function feeAmountMinor(): int
    {
        return $this->settlement_type === 'return'
            ? (int) $this->return_shipping_fee_minor
            : (int) $this->original_shipping_fee_minor;
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function logisticsPayoutAccount()
    {
        return $this->belongsTo(LogisticsPayoutAccount::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function deliveryAttempt()
    {
        return $this->belongsTo(DeliveryAttempt::class);
    }

    public function riderEarning()
    {
        return $this->hasOne(RiderEarning::class);
    }

    public function remitter()
    {
        return $this->belongsTo(User::class, 'remitted_by');
    }
}
