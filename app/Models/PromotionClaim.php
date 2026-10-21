<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionClaim extends Model
{
    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'used_at' => 'datetime',
        ];
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

    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class);
    }
}
