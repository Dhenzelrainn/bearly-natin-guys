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

    /**
     * Refunds whose external or cash payout has actually been recorded.
     * `processing` is reserved here for a sent external payout awaiting Buyer
     * receipt confirmation; it is not evidence that the case is resolved.
     */
    public function scopePayoutSent(Builder $query): Builder
    {
        return $query->where(function (Builder $payouts): void {
            $payouts
                ->where(function (Builder $processing): void {
                    $processing->where('status', 'processing')
                        ->whereNotNull('payout_amount_minor')
                        ->whereNotNull('processed_at')
                        ->whereColumn('payout_amount_minor', 'amount_minor')
                        ->whereIn('method', ['gcash', 'bank_transfer', 'other']);
                })
                ->orWhere(function (Builder $completed): void {
                    $completed->where('status', 'completed')
                        ->whereNotNull('completed_at');
                });
        });
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' && $this->completed_at !== null;
    }

    public function isPayoutSent(): bool
    {
        return ($this->status === 'processing'
                && $this->payout_amount_minor !== null
                && $this->processed_at !== null
                && (int) $this->payout_amount_minor === (int) $this->amount_minor
                && in_array($this->method, ['gcash', 'bank_transfer', 'other'], true))
            || $this->isCompleted();
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

    public function walletTransactions()
    {
        return $this->hasMany(SellerWalletTransaction::class);
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
