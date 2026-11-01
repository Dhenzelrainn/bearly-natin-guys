<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinehaulRunShipment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['loaded_at' => 'datetime'];
    }

    public function run()
    {
        return $this->belongsTo(LinehaulRun::class, 'linehaul_run_id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
