<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $fillable = [
        'seller_order_id',
        'logistics_provider_id',
        'rider_id',
        'tracking_code',
        'status',
        'fee_minor',
        'cod_amount_minor',
        'cod_collected',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'fee_minor' => 'integer',
            'cod_amount_minor' => 'integer',
            'cod_collected' => 'boolean',
            'attempts' => 'integer',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            LogisticsProvider::class,
            'logistics_provider_id'
        );
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class)
            ->orderBy('occurred_at');
    }
}