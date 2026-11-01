<?php

namespace App\Enums;

enum RiderWorkType: string
{
    case Local = 'local';
    case Courier = 'courier';

    /** @return array<string> */
    public static function vehicleTypes(self $workType): array
    {
        return match ($workType) {
            self::Local => ['Motorcycle', 'Tricycle', 'E-bike'],
            // Van is the only four-wheel vehicle currently offered in Bearly's Rider flow.
            self::Courier => ['Van'],
        };
    }

    public static function isLocal(?string $workType): bool
    {
        // Null is a compatibility state for existing profiles. It is never Courier authority.
        return $workType === null || $workType === self::Local->value;
    }
}
