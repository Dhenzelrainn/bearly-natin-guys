<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'shop_id',
        'name',
        'description',
        'category',
        'price',
        'original_price',
        'discount_percentage',
        'image',
        'images',
        'rating',
        'sold_count',
        'stock',
        'free_shipping',
        'location',
        'badge',
        'is_featured',
    ];

    protected $casts = [
        'images' => 'array',
        'is_featured' => 'boolean',
        'free_shipping' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function wishlistItems()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function getDiscountedPriceAttribute()
    {
        if ($this->discount_percentage) {
            return $this->price - ($this->price * $this->discount_percentage / 100);
        }
        return $this->price;
    }
}
