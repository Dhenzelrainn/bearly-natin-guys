<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
            'package_weight_kg' => 'decimal:3',
            'package_length_cm' => 'decimal:3',
            'package_width_cm' => 'decimal:3',
            'package_height_cm' => 'decimal:3',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id');
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, $this->stock_on_hand - $this->stock_reserved);
    }
}
