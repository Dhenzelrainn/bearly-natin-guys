<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Carbon;

class PisoSaleEligibilityService
{
    public const ACCOUNT_AGE_LIMIT_DAYS = 30;

    /**
     * These orders temporarily reserve an already-used Piso Sale until they
     * are cancelled/closed or the Buyer confirms receipt.
     *
     * @var list<string>
     */
    private const RESERVING_ORDER_STATUSES = [
        'pending_payment',
        'paid',
        'processing',
        'partially_fulfilled',
    ];

    public function canDisplay(User $buyer, ?Carbon $at = null): bool
    {
        if (! $this->hasEligibleAccount($buyer, $at)) {
            return false;
        }

        return ! $buyer->buyerOrders()->where('status', 'fulfilled')->exists();
    }

    public function isReserved(User $buyer): bool
    {
        return $buyer->buyerOrders()
            ->where('piso_sale_used', true)
            ->whereIn('status', self::RESERVING_ORDER_STATUSES)
            ->exists();
    }

    public function canApply(User $buyer, ?Carbon $at = null): bool
    {
        if (! $this->hasEligibleAccount($buyer, $at)) {
            return false;
        }

        return ! $buyer->buyerOrders()
            ->where(function ($orders): void {
                $orders->where('status', 'fulfilled')
                    ->orWhere(function ($reserved): void {
                        $reserved->where('piso_sale_used', true)
                            ->whereIn('status', self::RESERVING_ORDER_STATUSES);
                    });
            })
            ->exists();
    }

    private function hasEligibleAccount(User $buyer, ?Carbon $at): bool
    {
        $at = ($at ?? now('Asia/Manila'))->copy()->timezone('Asia/Manila');
        $createdAt = $buyer->created_at;

        return $buyer->role === UserRole::Buyer->value
            && $buyer->status === AccountStatus::Active->value
            && $createdAt !== null
            && $createdAt->lessThanOrEqualTo($at)
            && $createdAt->greaterThanOrEqualTo($at->copy()->subDays(self::ACCOUNT_AGE_LIMIT_DAYS));
    }
}
