<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function commission()
    {
        return $this->belongsTo(PlatformCommission::class, 'commission_id');
    }
}
