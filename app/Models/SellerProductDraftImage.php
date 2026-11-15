<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerProductDraftImage extends Model
{
    protected $fillable = [
        'seller_product_draft_id',
        'purpose',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'position',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'position' => 'integer',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(SellerProductDraft::class, 'seller_product_draft_id');
    }
}
