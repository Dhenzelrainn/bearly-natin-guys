<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodCashCollection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'logistics_received_at' => 'datetime',
            'remitted_to_finance_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function events()
    {
        return $this->hasMany(CodCashCollectionEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function logisticsReceiver()
    {
        return $this->belongsTo(User::class, 'logistics_received_by');
    }

    public function financeReconciler()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
