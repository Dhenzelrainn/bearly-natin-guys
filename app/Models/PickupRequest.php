<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PickupRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function pickupAddress()
    {
        return $this->belongsTo(Address::class, 'pickup_address_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function parcels()
    {
        return $this->belongsToMany(
            Parcel::class,
            'pickup_request_parcels'
        )->withPivot('added_at');
    }

    public function assignments()
    {
        return $this->hasMany(PickupAssignment::class);
    }

    public function latestAssignment()
    {
        return $this->hasOne(PickupAssignment::class)
            ->latestOfMany();
    }

    public function scopeForLogisticsProfile(
        Builder $query,
        int $logisticsProfileId
    ): Builder {
        return $query->where(
            'logistics_profile_id',
            $logisticsProfileId
        );
    }
}
