<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePromotionalBanner extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
