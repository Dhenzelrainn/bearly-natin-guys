<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImageCategoryCheck extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_MATCH = 'match';

    public const STATUS_POSSIBLE_MISMATCH = 'possible_mismatch';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'listing_snapshot' => 'array',
            'is_current' => 'boolean',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productImage(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class);
    }
}
