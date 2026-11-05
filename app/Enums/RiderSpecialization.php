<?php

namespace App\Enums;

enum RiderSpecialization: string
{
    case PickupOnly = 'pickup_only';
    case DeliveryOnly = 'delivery_only';
    case Both = 'both';

    /** @return array<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function normalize(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Both;
    }

    public static function allowsPickup(?string $value): bool
    {
        return self::normalize($value) !== self::DeliveryOnly;
    }

    public static function allowsDelivery(?string $value): bool
    {
        return self::normalize($value) !== self::PickupOnly;
    }

    public function label(): string
    {
        return match ($this) {
            self::PickupOnly => 'Pickup only',
            self::DeliveryOnly => 'Delivery only',
            self::Both => 'Pickup and delivery',
        };
    }
}
