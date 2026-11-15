<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerWarning extends Model
{
    protected $guarded = [];

    public function violation(): BelongsTo
    {
        return $this->belongsTo(ProductViolation::class, 'violation_id');
    }
}
