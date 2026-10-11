<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'external_paid_on' => 'date',
        ];
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed')->whereNotNull('completed_at');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' && $this->completed_at !== null;
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function commissions()
    {
        return $this->hasMany(PlatformCommission::class);
    }

    public function sellerTransactions()
    {
        return $this->hasMany(SellerTransaction::class);
    }

    public function sellerRecovery()
    {
        return $this->hasOne(SellerRefundRecovery::class);
    }

    public function cashPayout()
    {
        return $this->hasOne(RefundCashPayout::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
