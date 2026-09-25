<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
