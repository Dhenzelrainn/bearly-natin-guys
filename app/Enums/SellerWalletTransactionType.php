<?php

namespace App\Enums;

enum SellerWalletTransactionType: string
{
    case SaleEarningPending = 'sale_earning_pending';
    case SaleEarningAvailable = 'sale_earning_available';
    case WithdrawalReservation = 'withdrawal_reservation';
    case WithdrawalCompletion = 'withdrawal_completion';
    case WithdrawalRelease = 'withdrawal_release';
    case RefundAdjustment = 'refund_adjustment';
    case ManualAdjustment = 'manual_adjustment';
    case LegacyWithdrawalCompletion = 'legacy_withdrawal_completion';
}
