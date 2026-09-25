<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SellerTransaction extends Model { protected $guarded=[]; public function sellerOrder(){return $this->belongsTo(SellerOrder::class);} public function payment(){return $this->belongsTo(Payment::class);} }
