<?php

namespace App\Services;

use App\Models\Address;
use Illuminate\Validation\ValidationException;

class ShippingZoneClassifier
{
    public function __construct(private readonly PostalCodeLookup $postalCodes) {}

    /**
     * @return array{
     *     level: int,
     *     classification: string,
     *     label: string,
     *     origin: array<string, string|null>,
     *     destination: array<string, string|null>
     * }
     */
    public function classify(Address $origin, Address $destination): array
    {
        $originLocation = $this->resolve($origin);
        $destinationLocation = $this->resolve($destination);

        if ($originLocation['city_code'] === $destinationLocation['city_code']) {
            return $this->result(0, 'same_municipality', 'Same municipality', $originLocation, $destinationLocation);
        }

        if ($originLocation['region_code'] === $destinationLocation['region_code']) {
            if ($originLocation['province_code'] === null || $destinationLocation['province_code'] === null) {
                throw ValidationException::withMessages([
                    'shipping' => 'These PSGC locations do not have a reliable province parent for same-region pricing. Verify both addresses before checkout.',
                ]);
            }

            if ($originLocation['province_code'] === $destinationLocation['province_code']) {
                return $this->result(1, 'same_province', 'Different municipality, same province', $originLocation, $destinationLocation);
            }

            return $this->result(2, 'same_region', 'Different province, same region', $originLocation, $destinationLocation);
        }

        if ($originLocation['island_group'] === $destinationLocation['island_group']) {
            return $this->result(3, 'same_island_group', 'Different region, same island group', $originLocation, $destinationLocation);
        }

        return $this->result(4, 'different_island_group', 'Different island groups', $originLocation, $destinationLocation);
    }

    /** @return array{city_code: string, province_code: string|null, region_code: string, island_group: string} */
    private function resolve(Address $address): array
    {
        $cityCode = trim((string) $address->psgc_city_code);
        if (! $this->postalCodes->findByCanonicalCityCode($cityCode)) {
            throw ValidationException::withMessages([
                'shipping' => 'Both the Seller pickup and Buyer delivery addresses need a verified PSGC city code before shipping can be priced.',
            ]);
        }

        $mapping = $this->mapping();
        $regionCode = substr($cityCode, 0, 2).'00000000';
        $region = $mapping['regions'][$regionCode] ?? null;
        if (! is_array($region) || ! in_array($region['island_group'] ?? null, ['luzon', 'visayas', 'mindanao'], true)) {
            throw ValidationException::withMessages([
                'shipping' => 'The PSGC region for one of these addresses is not in Bearly’s validated shipping map. Verify the address before checkout.',
            ]);
        }

        // PSGC municipality codes retain the parent province in their first
        // five digits. Independent cities and locations with a non-province
        // parent remain unclassified at province level rather than being
        // inferred from a display name.
        $provinceCode = substr($cityCode, 0, 5).'00000';
        $province = $mapping['provinces'][$provinceCode] ?? null;
        if (is_array($province)) {
            if (($province['region_code'] ?? null) !== $regionCode) {
                throw ValidationException::withMessages([
                    'shipping' => 'The PSGC city and province identifiers do not resolve to the same region. Verify both addresses before checkout.',
                ]);
            }
        } else {
            $provinceCode = null;
        }

        $storedProvinceCode = trim((string) $address->psgc_province_code);
        if (preg_match('/^\d{10}$/D', $storedProvinceCode)) {
            $storedProvince = $mapping['provinces'][$storedProvinceCode] ?? null;
            if (! is_array($storedProvince)
                || ($storedProvince['region_code'] ?? null) !== $regionCode
                || $provinceCode === null
                || $storedProvinceCode !== $provinceCode) {
                throw ValidationException::withMessages([
                    'shipping' => 'The stored PSGC province identifier does not match the city and region. Update the address before checkout.',
                ]);
            }
        }

        return [
            'city_code' => $cityCode,
            'province_code' => $provinceCode,
            'region_code' => $regionCode,
            'island_group' => (string) $region['island_group'],
        ];
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        static $mapping;

        if ($mapping === null) {
            $mapping = json_decode(
                file_get_contents(resource_path('data/psgc-shipping-zones.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        return $mapping;
    }

    /** @return array<string, mixed> */
    private function result(
        int $level,
        string $classification,
        string $label,
        array $origin,
        array $destination,
    ): array {
        return [
            'level' => $level,
            'classification' => $classification,
            'label' => $label,
            'origin' => $origin,
            'destination' => $destination,
        ];
    }
}
