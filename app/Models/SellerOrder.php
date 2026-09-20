<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SellerOrder extends Model
{
    protected $fillable = [
        'order_id',
        'seller_id',
        'logistics_provider_id',
        'subtotal_minor',
        'shipping_fee_minor',
        'commission_minor',
        'status',
        'delivered_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'shipping_fee_minor' => 'integer',
            'commission_minor' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            LogisticsProvider::class,
            'logistics_provider_id'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }
}