<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function businessCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'marketplace_category_business_categories')
            ->withTimestamps();
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(MarketplaceSubcategory::class)->orderBy('position')->orderBy('name');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MarketplaceSubcategoryRequest::class);
    }
}
