<?php

namespace App\Services;

use App\Models\Address;

class AddressNormalizationService
{
    private ?array $cities = null;

    public function enrich(Address $address): bool
    {
        $changed = false;

        $requestPostal = $this->requestValue('postal_code');
        $requestCityCode = $this->requestValue('city_code');

        $match = $this->findCity(
            (string) $address->province,
            (string) $address->city_municipality
        );

        if (
            blank($address->psgc_city_code)
            && $requestCityCode
            && preg_match('/^[0-9]{6,10}$/', $requestCityCode)
        ) {
            $address->psgc_city_code = $requestCityCode;
            $changed = true;
        } elseif (
            blank($address->psgc_city_code)
            && $match
        ) {
            $address->psgc_city_code = $match['code'];
            $changed = true;
        }

        if (
            blank($address->postal_code)
            && $requestPostal
            && preg_match('/^[0-9]{4}$/', $requestPostal)
        ) {
            $address->postal_code = $requestPostal;
            $changed = true;
        }

        if (blank($address->postal_code)) {
            $legacyPostal = $this->postalFromLegacyUser($address)
                ?? $this->postalFromText((string) $address->street);

            if ($legacyPostal) {
                $address->postal_code = $legacyPostal;
                $changed = true;
            }
        }

        if (
            blank($address->postal_code)
            && $match
        ) {
            $options = $match['item']['options'] ?? [];

            if (count($options) === 1) {
                $postal = (string) ($options[0]['code'] ?? '');

                if (preg_match('/^[0-9]{4}$/', $postal)) {
                    $address->postal_code = $postal;
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    private function requestValue(string $key): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $value = request()->input($key);

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function postalFromLegacyUser(Address $address): ?string
    {
        if (! $address->user_id) {
            return null;
        }

        $user = $address->relationLoaded('user')
            ? $address->user
            : $address->user()->first();

        return $this->postalFromText(
            (string) ($user?->street_address ?? '')
        );
    }

    private function postalFromText(string $text): ?string
    {
        if (
            preg_match(
                '/(?:^|[\s,])(\d{4})\s*$/',
                trim($text),
                $matches
            )
        ) {
            return $matches[1];
        }

        return null;
    }

    private function findCity(
        string $province,
        string $city
    ): ?array {
        $provinceKey = $this->normalize($province);
        $cityKey = $this->normalize($city);

        if ($provinceKey === '' || $cityKey === '') {
            return null;
        }

        foreach ($this->cities() as $code => $item) {
            if (
                $this->normalize((string) ($item['province'] ?? ''))
                    === $provinceKey
                && $this->normalize((string) ($item['city'] ?? ''))
                    === $cityKey
            ) {
                return [
                    'code' => (string) $code,
                    'item' => $item,
                ];
            }
        }

        return null;
    }

    private function cities(): array
    {
        if ($this->cities !== null) {
            return $this->cities;
        }

        $payload = json_decode(
            file_get_contents(
                resource_path('data/philippine-postal-codes.json')
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $this->cities = $payload['cities'] ?? [];
    }

    private function normalize(string $value): string
    {
        $ascii = iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $value
        );

        return preg_replace(
            '/[^a-z0-9]/',
            '',
            strtolower($ascii ?: $value)
        );
    }
}
