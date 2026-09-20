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

    /** @return list<string> */
    public static function adminApproved(): array
    {
        return [self::Buyer->value, self::Seller->value, self::Logistics->value];
    }
}
