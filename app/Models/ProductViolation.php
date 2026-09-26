<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductViolation extends Model
{
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function actions()
    {
        return $this->hasMany(ViolationAction::class, 'violation_id');
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }
}
