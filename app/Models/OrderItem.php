<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
