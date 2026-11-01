<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Logistics = 'logistics';
    case Rider = 'rider';
    case Guest = 'guest';

    /**
     * Roles with reviewable applications. Buyer remains in this list only so
     * existing pre-policy pending applications can still be reviewed.
     *
     * @return list<string>
     */
    public static function adminApproved(): array
    {
        return [self::Buyer->value, self::Seller->value, self::Logistics->value];
    }

    /** @return list<string> */
    public static function adminReviewQueueRoles(): array
    {
        return [self::Seller->value, self::Logistics->value];
    }
}
