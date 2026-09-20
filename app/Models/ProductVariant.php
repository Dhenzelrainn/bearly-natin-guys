<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'options',
        'price_minor',
        'stock',
        'weight_grams',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price_minor' => 'integer',
            'stock' => 'integer',
            'weight_grams' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}