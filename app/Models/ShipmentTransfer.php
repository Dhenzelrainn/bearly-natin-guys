<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentTransfer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function fromLogisticsProfile()
    {
        return $this->belongsTo(
            LogisticsProfile::class,
            'from_logistics_profile_id'
        );
    }

    public function toLogisticsProfile()
    {
        return $this->belongsTo(
            LogisticsProfile::class,
            'to_logistics_profile_id'
        );
    }

    public function fromSortingCenter()
    {
        return $this->belongsTo(
            SortingCenter::class,
            'from_sorting_center_id'
        );
    }

    public function toSortingCenter()
    {
        return $this->belongsTo(
            SortingCenter::class,
            'to_sorting_center_id'
        );
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function accepter()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
