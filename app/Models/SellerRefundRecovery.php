<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerRefundRecovery extends Model
{
    protected $guarded = [];

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function events()
    {
        return $this->hasMany(SellerRefundRecoveryEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function outstandingMinor(): int
    {
        return $this->refundOutstandingMinor() + $this->returnShippingOutstandingMinor();
    }

    public function refundOutstandingMinor(): int
    {
        return max(
            0,
            (int) $this->refund_recovery_minor
                - (int) $this->refund_recovered_minor
                - (int) $this->refund_recovery_voided_minor,
        );
    }

    public function returnShippingOutstandingMinor(): int
    {
        return max(0, (int) $this->return_shipping_fee_minor - (int) $this->return_shipping_fee_recovered_minor);
    }
}
