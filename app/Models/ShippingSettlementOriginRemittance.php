<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingSettlementOriginRemittance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'remitted_on' => 'date',
            'remitted_at' => 'datetime',
        ];
    }

    public function settlement()
    {
        return $this->belongsTo(ShippingSettlement::class, 'shipping_settlement_id');
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function logisticsPayoutAccount()
    {
        return $this->belongsTo(LogisticsPayoutAccount::class);
    }

    public function remitter()
    {
        return $this->belongsTo(User::class, 'remitted_by');
    }
}
