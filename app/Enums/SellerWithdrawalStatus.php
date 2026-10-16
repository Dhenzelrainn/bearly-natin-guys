<?php

namespace App\Enums;

enum SellerWithdrawalStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::PendingReview => [self::Approved, self::Rejected],
            self::Approved => [self::Processing, self::Rejected],
            self::Processing => [self::Completed, self::Failed],
            self::Completed, self::Rejected, self::Failed => [],
        }, true);
    }
}
