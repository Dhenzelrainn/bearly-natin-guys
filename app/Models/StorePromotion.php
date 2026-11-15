<?php

namespace App\Models;

use App\Models\Concerns\HasPromotionSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class StorePromotion extends Model
{
    use HasPromotionSchedule;

    public const TYPE_PRODUCT_DISCOUNT = 'product_discount';

    public const TYPE_STORE_VOUCHER = 'store_voucher';

    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_PERCENT = 'percent';

    public const FUNDING_SELLER = 'seller';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_claimable' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'store_promotion_products')
            ->withTimestamps();
    }

    public function claims()
    {
        return $this->hasMany(PromotionClaim::class);
    }

    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function isProductDiscount(): bool
    {
        return $this->type === self::TYPE_PRODUCT_DISCOUNT;
    }

    public function isStoreVoucher(): bool
    {
        return $this->type === self::TYPE_STORE_VOUCHER;
    }

    public function statusKey(?Carbon $at = null): string
    {
        if ($this->status === self::STATUS_PAUSED) {
            return self::STATUS_PAUSED;
        }

        if ($this->status === self::STATUS_ENDED) {
            return self::STATUS_ENDED;
        }

        if ($this->status === self::STATUS_DRAFT) {
            return self::STATUS_DRAFT;
        }

        $at = ($at ?? now('Asia/Manila'))->copy()->timezone('Asia/Manila');

        if ($this->startsAt()?->greaterThan($at)) {
            return self::STATUS_SCHEDULED;
        }

        if ($this->endsAt()?->lessThan($at)) {
            return self::STATUS_ENDED;
        }

        return self::STATUS_ACTIVE;
    }

    public function isActiveAt(?Carbon $at = null): bool
    {
        return $this->statusKey($at) === self::STATUS_ACTIVE;
    }

    public function isPercentDiscount(): bool
    {
        return $this->discount_type === self::DISCOUNT_PERCENT;
    }

    public function discountLabel(): string
    {
        if ($this->isPercentDiscount()) {
            return ((int) $this->discount_percent).'% off'.($this->max_discount_minor
                ? ' up to ₱'.number_format(((int) $this->max_discount_minor) / 100, 2)
                : '');
        }

        return '₱'.number_format(((int) $this->voucher_amount_minor) / 100, 2).' off';
    }
}
