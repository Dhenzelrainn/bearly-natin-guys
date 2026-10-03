<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; class PickupAssignment extends Model {protected $guarded=[];public function request(){return $this->belongsTo(PickupRequest::class,'pickup_request_id');}public function riderProfile(){return $this->belongsTo(RiderProfile::class);}}
