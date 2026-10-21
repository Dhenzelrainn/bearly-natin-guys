<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformCampaignParticipationItem extends Model
{
    protected $guarded = [];

    public function participation()
    {
        return $this->belongsTo(PlatformCampaignParticipation::class, 'platform_campaign_participation_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function campaignPromotion()
    {
        return $this->participation?->campaign;
    }
}
