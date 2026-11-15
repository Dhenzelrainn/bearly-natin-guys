<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class SellerIdentityService
{
    public function current(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            return [
                'name' => 'Bearly Seller',
                'first_name' => 'Seller',
                'initials' => 'BS',
                'email' => '',
                'store' => 'Bearly Store',
                'business_category' => 'Marketplace Seller',
            ];
        }

        $user->loadMissing('sellerProfile.store');

        $name = trim((string) (
            $user->name
            ?: trim(implode(' ', array_filter([
                $user->first_name,
                $user->middle_initial,
                $user->last_name,
            ])))
        ));

        if ($name === '') {
            $name = 'Bearly Seller';
        }

        $parts = preg_split('/\\s+/', $name) ?: [];
        $firstName = trim((string) (
            $user->first_name
            ?: ($parts[0] ?? 'Seller')
        ));

        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(
                fn (string $part) =>
                    strtoupper(mb_substr($part, 0, 1))
            )
            ->implode('');

        $profile = $user->sellerProfile;
        $store = $profile?->store;

        $storeName = trim((string) (
            $store?->name
            ?: $user->business_name
            ?: $profile?->legal_business_name
            ?: $name
        ));

        $businessCategory = trim((string) (
            $user->business_category
            ?: 'Marketplace Seller'
        ));

        return [
            'name' => $name,
            'first_name' => $firstName ?: 'Seller',
            'initials' => $initials ?: 'BS',
            'email' => (string) $user->email,
            'store' => $storeName ?: $name,
            'business_category' =>
                $businessCategory ?: 'Marketplace Seller',
        ];
    }
}
