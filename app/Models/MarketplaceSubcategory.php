<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceSubcategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function marketplaceCategory(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'marketplace_subcategory_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MarketplaceSubcategoryRequest::class);
    }
}
