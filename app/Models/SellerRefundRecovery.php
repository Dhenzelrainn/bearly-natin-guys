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
}
