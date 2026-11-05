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
            'origin_logistics_share_minor' => 'integer',
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

    public function isInterHub(): bool
    {
        return $this->settlement_type === 'outbound'
            && $this->settlement_mode === 'inter_hub'
            && (int) $this->origin_logistics_profile_id > 0;
    }

    public function expectedFinalLogisticsRemittanceMinor(): int
    {
        return (int) $this->logistics_share_minor + (int) $this->rider_share_minor;
    }

    public function remittedAmountTotalMinor(): int
    {
        return (int) ($this->remitted_amount_minor ?? 0)
            + ($this->isInterHub() && $this->originRemittance?->status === 'remitted'
                ? (int) $this->originRemittance->amount_minor
                : 0);
    }

    public function pendingRemittanceMinor(): int
    {
        return max(0, $this->feeAmountMinor() - $this->remittedAmountTotalMinor());
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function originLogisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class, 'origin_logistics_profile_id');
    }

    public function originRemittance()
    {
        return $this->hasOne(
            ShippingSettlementOriginRemittance::class,
            'shipping_settlement_id',
            'id',
        );
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
