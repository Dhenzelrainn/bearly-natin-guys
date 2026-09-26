<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function sellerOrders()
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function returnRequests()
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}


