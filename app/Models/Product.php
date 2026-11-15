<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_methods' => 'array',
            'voucher_eligible' => 'boolean',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'archived_previous_published_at' => 'datetime',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(StoreSubcategory::class, 'subcategory_id');
    }

    public function marketplaceSubcategory()
    {
        return $this->belongsTo(MarketplaceSubcategory::class, 'marketplace_subcategory_id');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function storePromotions()
    {
        return $this->belongsToMany(StorePromotion::class, 'store_promotion_products')
            ->withTimestamps();
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function complianceChecks()
    {
        return $this->hasMany(ProductComplianceCheck::class);
    }

    public function latestComplianceCheck(): HasOne
    {
        return $this->hasOne(ProductComplianceCheck::class)->latestOfMany();
    }

    public function currentImageCategoryCheck(): HasOne
    {
        return $this->hasOne(ProductImageCategoryCheck::class)
            ->where('is_current', true)
            ->latestOfMany();
    }

    public function violations()
    {
        return $this->hasMany(ProductViolation::class);
    }

    /**
     * Products that can satisfy the first Store publication requirement.
     *
     * A product needs an active, compliance-clear state and at least one
     * active variant with available stock. Buyer visibility intentionally uses
     * a separate scope so an already-published product may remain listed as
     * Out of Stock when its balance reaches zero.
     */
    public function scopeEligibleForStorePublication(Builder $query): Builder
    {
        return $query
            ->where('product_status', 'active')
            ->where('compliance_status', 'clear')
            ->whereNull('removed_at')
            ->whereHas('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->whereColumn('stock_on_hand', '>', 'stock_reserved'));
    }

    /**
     * Bearly checkout currently supports Cash on Delivery only. Legacy
     * products without an explicit payment-method list remain orderable.
     */
    public function acceptsCashOnDelivery(): bool
    {
        $methods = (array) ($this->payment_methods ?? []);

        return $methods === [] || in_array('cod', $methods, true);
    }

    /**
     * Products that buyers may discover or open directly.
     */
    public function scopeVisibleToBuyers(Builder $query): Builder
    {
        return $query
            ->where('product_status', 'active')
            ->where('compliance_status', 'clear')
            ->whereNull('removed_at')
            ->whereHas('store', fn (Builder $stores) => $stores->visibleToBuyers())
            ->whereHas('variants', fn (Builder $variants) => $variants->where('is_active', true));
    }

    /**
     * Keep discovery and orderability aligned with the supported COD checkout.
     * Empty/null legacy method lists remain COD-compatible, matching checkout.
     */
    public function scopeAcceptsCashOnDelivery(Builder $query): Builder
    {
        return $query->where(function (Builder $methods): void {
            $methods->whereNull('payment_methods')
                ->orWhereJsonLength('payment_methods', 0)
                ->orWhereJsonContains('payment_methods', 'cod');
        });
    }
}
