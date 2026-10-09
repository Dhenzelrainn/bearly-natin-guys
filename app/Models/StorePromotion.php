<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePromotion extends Model
{
    public const TYPE_PRODUCT_DISCOUNT = 'product_discount';

    public const TYPE_STORE_VOUCHER = 'store_voucher';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
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

    public function statusKey(): string
    {
        $today = today()->toDateString();

        if ($this->starts_on->toDateString() > $today) {
            return self::STATUS_SCHEDULED;
        }

        if ($this->ends_on->toDateString() < $today) {
            return self::STATUS_ENDED;
        }

        return self::STATUS_ACTIVE;
    }
}
