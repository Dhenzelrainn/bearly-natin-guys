<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderPayoutBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_on' => 'date',
        ];
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function riderProfile()
    {
        return $this->belongsTo(RiderProfile::class);
    }

    public function items()
    {
        return $this->hasMany(RiderPayoutBatchItem::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
