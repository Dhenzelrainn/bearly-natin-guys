<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['shipping_quote' => 'array'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(SellerOrderStatusHistory::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    public function returnRequests()
    {
        return $this->hasMany(ReturnRequest::class)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
    }
}
