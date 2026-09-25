<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; class PickupRequest extends Model {protected $guarded=[];public function parcels(){return $this->belongsToMany(Parcel::class,'pickup_request_parcels')->withPivot('added_at');}}
