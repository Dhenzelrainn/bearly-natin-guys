<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParcelItem extends Model
{
    protected $guarded = [];

    public function parcel()
    {
        return $this->belongsTo(Parcel::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
