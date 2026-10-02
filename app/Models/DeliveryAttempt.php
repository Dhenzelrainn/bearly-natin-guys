<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; class DeliveryAttempt extends Model {protected $guarded=[];public function proofs(){return $this->hasMany(DeliveryProof::class);}public function parcel(){return $this->belongsTo(Parcel::class);}}
