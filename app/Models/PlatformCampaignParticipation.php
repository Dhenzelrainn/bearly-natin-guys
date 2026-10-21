<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformCampaignParticipation extends Model
{
    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'terms_accepted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(PlatformPromotion::class, 'platform_promotion_id');
    }

    public function platformPromotion()
    {
        return $this->belongsTo(PlatformPromotion::class, 'platform_promotion_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items()
    {
        return $this->hasMany(PlatformCampaignParticipationItem::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_REVIEW => 'Pending review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_WITHDRAWN => 'Withdrawn',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }
}
