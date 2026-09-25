<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformCommission extends Model { protected $guarded=[]; protected function casts():array{return ['calculated_at'=>'datetime','finalized_at'=>'datetime','reversed_at'=>'datetime'];} public function sellerOrder(){return $this->belongsTo(SellerOrder::class);} }
