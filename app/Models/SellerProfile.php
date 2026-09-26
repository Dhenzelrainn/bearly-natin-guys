<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellerProfile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->hasOne(Store::class);
    }

    public function approvedCategory()
    {
        return $this->belongsTo(Category::class, 'approved_category_id');
    }

    public function warnings()
    {
        return $this->hasMany(SellerWarning::class);
    }

    public function transactions()
    {
        return $this->hasMany(SellerTransaction::class);
    }

    public function payouts()
    {
        return $this->hasMany(SellerPayout::class);
    }
}
