<?php

namespace App\Models;

use App\Models\Concerns\HasPromotionSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PlatformPromotion extends Model
{
    use HasPromotionSchedule;

    public const KIND_VOUCHER = 'voucher';

    public const KIND_AUTOMATIC_DISCOUNT = 'automatic_discount';

    public const KIND_FREE_SHIPPING = 'free_shipping';

    public const KIND_FLASH_DEAL = 'flash_deal';

    public const KIND_SELLER_CAMPAIGN = 'seller_campaign';

    public const FUNDING_PLATFORM = 'platform';

    public const FUNDING_SELLER = 'seller';

    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_PERCENT = 'percent';

    public const DISCOUNT_FREE_SHIPPING = 'free_shipping';

    public const DISCOUNT_FIXED_SHIPPING = 'fixed_shipping';

    public const DISCOUNT_PERCENT_SHIPPING = 'percent_shipping';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    /** Computed display states; these are not persisted by the regular-offer workflow. */
    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_EXPIRED = 'expired';

    public const CAMPAIGN_FLASH_DEAL = 'flash_deal';

    public const CAMPAIGN_PISO_SALE = 'piso_sale';

    public const CAMPAIGN_PAYDAY_SALE = 'payday_sale';

    public const CAMPAIGN_MONTHLY_DEAL = 'monthly_deal';

    public const CAMPAIGN_DOUBLE_DATE = 'double_date';

    public const CAMPAIGN_TYPES = [
        self::CAMPAIGN_FLASH_DEAL,
        self::CAMPAIGN_PISO_SALE,
        self::CAMPAIGN_PAYDAY_SALE,
        self::CAMPAIGN_MONTHLY_DEAL,
        self::CAMPAIGN_DOUBLE_DATE,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'join_deadline' => 'date',
            'is_claimable' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'requires_approval' => 'boolean',
            'minimum_discount_percent' => 'integer',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'platform_promotion_products')->withTimestamps();
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'platform_promotion_categories')->withTimestamps();
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class, 'platform_promotion_stores')->withTimestamps();
    }

    public function claims()
    {
        return $this->hasMany(PromotionClaim::class);
    }

    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function participations()
    {
        return $this->hasMany(PlatformCampaignParticipation::class);
    }

    public function approvedParticipations()
    {
        return $this->hasMany(PlatformCampaignParticipation::class)
            ->where('status', PlatformCampaignParticipation::STATUS_APPROVED);
    }

    public function scopeCampaigns($query)
    {
        return $query->where(function ($campaigns): void {
            $campaigns->whereIn('kind', [self::KIND_FLASH_DEAL, self::KIND_SELLER_CAMPAIGN])
                ->orWhereNotNull('campaign_type');
        });
    }

    public function isCampaign(): bool
    {
        return in_array($this->kind, [self::KIND_FLASH_DEAL, self::KIND_SELLER_CAMPAIGN], true)
            || filled($this->campaign_type);
    }

    public function isJoinable(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->join_deadline !== null
            && $this->join_deadline->toDateString() >= now('Asia/Manila')->toDateString()
            && $this->ends_on !== null
            && $this->endsAt()?->greaterThanOrEqualTo(now('Asia/Manila'));
    }

    public function statusKey(?Carbon $at = null): string
    {
        $at = ($at ?? now('Asia/Manila'))->copy()->timezone('Asia/Manila');

        if ($this->isCampaign()) {
            if ($this->status === self::STATUS_PAUSED) {
                return self::STATUS_PAUSED;
            }

            if ($this->status === self::STATUS_ENDED) {
                return self::STATUS_ENDED;
            }

            if ($this->status === self::STATUS_DRAFT) {
                return self::STATUS_DRAFT;
            }

            if ($this->status === self::STATUS_OPEN) {
                if ($this->endsAt()?->lessThan($at)) {
                    return self::STATUS_ENDED;
                }

                return $this->join_deadline !== null
                    && $this->join_deadline->toDateString() < $at->toDateString()
                    ? 'closed'
                    : self::STATUS_OPEN;
            }

            if ($this->startsAt()?->greaterThan($at)) {
                return self::STATUS_SCHEDULED;
            }

            if ($this->endsAt()?->lessThan($at)) {
                return self::STATUS_ENDED;
            }

            return self::STATUS_ACTIVE;
        }

        if ($this->status === self::STATUS_DRAFT) {
            return self::STATUS_DRAFT;
        }

        if ($this->status === self::STATUS_ENDED || $this->endsAt()?->lessThan($at)) {
            return self::STATUS_EXPIRED;
        }

        if ($this->status === self::STATUS_PAUSED) {
            return self::STATUS_PAUSED;
        }

        if ($this->startsAt()?->greaterThan($at)) {
            return self::STATUS_SCHEDULED;
        }

        if ($this->status !== self::STATUS_ACTIVE) {
            return $this->status ?: self::STATUS_DRAFT;
        }

        return self::STATUS_ACTIVE;
    }

    public function isExhausted(int $activeRedemptions, int $activeBudgetSpendMinor): bool
    {
        return ($this->redemption_limit !== null && $activeRedemptions >= (int) $this->redemption_limit)
            || ($this->budget_minor !== null && $activeBudgetSpendMinor >= (int) $this->budget_minor);
    }

    public function isActiveAt(?Carbon $at = null): bool
    {
        return $this->statusKey($at) === self::STATUS_ACTIVE;
    }

    public function isVoucher(): bool
    {
        return $this->kind === self::KIND_VOUCHER;
    }

    public function isShippingDiscount(): bool
    {
        return in_array($this->discount_type, [
            self::DISCOUNT_FREE_SHIPPING,
            self::DISCOUNT_FIXED_SHIPPING,
            self::DISCOUNT_PERCENT_SHIPPING,
        ], true);
    }

    public function scopeLabel(): string
    {
        if ($this->relationLoaded('stores') && $this->stores->isNotEmpty()) {
            return $this->stores->pluck('name')->take(2)->join(', ')
                .($this->stores->count() > 2 ? ' +'.($this->stores->count() - 2) : '');
        }

        if ($this->relationLoaded('categories') && $this->categories->isNotEmpty()) {
            return $this->categories->pluck('name')->take(2)->join(', ')
                .($this->categories->count() > 2 ? ' +'.($this->categories->count() - 2) : '');
        }

        return 'All eligible Stores';
    }

    public function campaignTypeLabel(): string
    {
        return match ($this->campaign_type) {
            self::CAMPAIGN_FLASH_DEAL => 'Flash Deal',
            self::CAMPAIGN_PISO_SALE => 'Piso Sale',
            self::CAMPAIGN_PAYDAY_SALE => 'Payday Sale',
            self::CAMPAIGN_MONTHLY_DEAL => 'Monthly Deal',
            self::CAMPAIGN_DOUBLE_DATE => 'Double Date Sale',
            default => 'Bearly Campaign',
        };
    }

    public function discountLabel(): string
    {
        if ($this->isCampaign() && $this->funding_type === self::FUNDING_SELLER) {
            return 'Seller-funded campaign';
        }

        if ($this->isCampaign() && $this->funding_type === self::FUNDING_PLATFORM) {
            return 'Bearly-funded campaign';
        }

        return match ($this->discount_type) {
            self::DISCOUNT_FIXED => '₱'.number_format(((int) $this->value_minor) / 100, 2).' off',
            self::DISCOUNT_PERCENT => ((int) $this->discount_percent).'% off'.($this->max_discount_minor ? ' up to ₱'.number_format(((int) $this->max_discount_minor) / 100, 2) : ''),
            self::DISCOUNT_FREE_SHIPPING => 'Free shipping',
            self::DISCOUNT_FIXED_SHIPPING => '₱'.number_format(((int) $this->value_minor) / 100, 2).' off shipping',
            self::DISCOUNT_PERCENT_SHIPPING => ((int) $this->discount_percent).'% off shipping',
            default => 'Promotion',
        };
    }
}
