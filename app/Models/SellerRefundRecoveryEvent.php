<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerRefundRecoveryEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recovered_on' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function recovery()
    {
        return $this->belongsTo(SellerRefundRecovery::class, 'seller_refund_recovery_id');
    }

    public function sellerPayout()
    {
        return $this->belongsTo(SellerPayout::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
