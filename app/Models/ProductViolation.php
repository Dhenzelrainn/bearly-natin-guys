<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductViolation extends Model
{
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class)->withTrashed();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'rule_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ProductComplianceCheck::class, 'check_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ViolationAction::class, 'violation_id')->latest('created_at');
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(SellerWarning::class, 'violation_id')->latest('issued_at');
    }
}
