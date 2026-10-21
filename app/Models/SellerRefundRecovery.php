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
        if ($this->status === 'voided') {
            return 0;
        }

        return max(0, (int) $this->amount_minor - (int) $this->recovered_amount_minor);
    }
}
