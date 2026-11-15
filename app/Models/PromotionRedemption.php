<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionRedemption extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_RELEASED = 'released';

    public const STATUS_REFUNDED = 'refunded';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function platformPromotion()
    {
        return $this->belongsTo(PlatformPromotion::class);
    }

    public function storePromotion()
    {
        return $this->belongsTo(StorePromotion::class);
    }

    public function claim()
    {
        return $this->belongsTo(PromotionClaim::class, 'promotion_claim_id');
    }

    public function allocations()
    {
        return $this->hasMany(PromotionRedemptionAllocation::class);
    }

    public function countsTowardLimit(): bool
    {
        return in_array($this->status, [self::STATUS_RESERVED, self::STATUS_REDEEMED], true);
    }
}
