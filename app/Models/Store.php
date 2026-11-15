<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function subcategories()
    {
        return $this->hasMany(StoreSubcategory::class);
    }

    public function promotionalBanners()
    {
        return $this->hasMany(StorePromotionalBanner::class)->orderBy('position');
    }

    public function promotions()
    {
        return $this->hasMany(StorePromotion::class);
    }

    /**
     * Stores that are currently eligible for Buyer discovery.
     *
     * Seller online/offline presence is deliberately not part of this scope.
     * Account status and Seller standing are, because either can suspend a
     * previously published Store from Buyer-facing surfaces.
     */
    public function scopeVisibleToBuyers(Builder $query): Builder
    {
        return $query
            ->where('publication_status', 'published')
            ->whereHas('sellerProfile', fn (Builder $profiles) => $profiles
                ->where('standing_status', 'good_standing')
                ->whereHas('user', fn (Builder $users) => $users->where('status', 'active')));
    }

    public function pickupRequests()
    {
        return $this->hasMany(PickupRequest::class);
    }
}
