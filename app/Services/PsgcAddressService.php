<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PsgcAddressService
{
    /** @var array<string, mixed>|null */
    private ?array $mapping = null;

    public function __construct(
        private readonly PostalCodeLookup $postalCodes,
    ) {
    }

    /**
     * Normalize and cross-check the selected Region against the canonical
     * PSGC municipality/city. The browser selectors are only a convenience;
     * the submitted labels and codes remain untrusted HTTP input.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data, bool $requireCityCode = false): array
    {
        $province = trim((string) ($data['province'] ?? ''));
        $city = trim((string) ($data['city'] ?? $data['municipality'] ?? ''));
        $region = trim((string) ($data['region'] ?? ''));
        $submittedRegionCode = trim((string) ($data['region_code'] ?? ''));
        $cityCode = $this->postalCodes->canonicalCityCode(
            filled($data['city_code'] ?? null)
                ? (string) $data['city_code']
                : null,
            $province,
            $city,
        );

        if ($requireCityCode && ! $cityCode) {
            throw ValidationException::withMessages([
                'city' => 'Select your municipality/city again from the PSGC list.',
            ]);
        }

        if ($region === '') {
            throw ValidationException::withMessages([
                'region' => 'Select your Philippine region.',
            ]);
        }

        $expectedRegion = null;

        if ($cityCode) {
            $regionCode = $this->regionCodeForCity($cityCode);
            $expectedRegion = $this->mapping()['regions'][$regionCode] ?? null;

            if (! is_array($expectedRegion) || blank($expectedRegion['name'] ?? null)) {
                throw ValidationException::withMessages([
                    'region' => 'The selected municipality/city is not linked to a valid PSGC region.',
                ]);
            }

            if ($this->normalizeLabel($region) !== $this->normalizeLabel((string) $expectedRegion['name'])) {
                throw ValidationException::withMessages([
                    'region' => 'The selected region does not match the municipality/city.',
                ]);
            }

            if ($submittedRegionCode !== '' && $submittedRegionCode !== $regionCode) {
                throw ValidationException::withMessages([
                    'region' => 'Select the region again from the PSGC list.',
                ]);
            }

            $data['region'] = (string) $expectedRegion['name'];
            $data['region_code'] = $regionCode;

            return $data;
        }

        $regionCode = $this->canonicalRegionCode($submittedRegionCode, $region);

        if ($regionCode === null) {
            throw ValidationException::withMessages([
                'region' => 'Select a valid PSGC region.',
            ]);
        }

        $data['region'] = (string) $this->mapping()['regions'][$regionCode]['name'];
        $data['region_code'] = $regionCode;

        return $data;
    }

    public function regionCodeForCity(string $cityCode): string
    {
        $cityCode = trim($cityCode);

        return substr($cityCode, 0, 2).'00000000';
    }

    /** @return array<string, mixed>|null */
    private function region(string $regionCode): ?array
    {
        $region = $this->mapping()['regions'][$regionCode] ?? null;

        return is_array($region) ? $region : null;
    }

    private function canonicalRegionCode(string $submittedCode, string $regionName): ?string
    {
        if ($submittedCode !== '') {
            if (! preg_match('/^\d{10}$/D', $submittedCode)) {
                return null;
            }

            $region = $this->region($submittedCode);

            return $region
                && $this->normalizeLabel((string) ($region['name'] ?? ''))
                    === $this->normalizeLabel($regionName)
                ? $submittedCode
                : null;
        }

        foreach ($this->mapping()['regions'] ?? [] as $code => $region) {
            if (
                is_array($region)
                && $this->normalizeLabel((string) ($region['name'] ?? ''))
                    === $this->normalizeLabel($regionName)
            ) {
                return (string) $code;
            }
        }

        return null;
    }

    private function normalizeLabel(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return preg_replace('/[^a-z0-9]/', '', strtolower($ascii ?: $value)) ?: '';
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        return $this->mapping ??= json_decode(
            file_get_contents(resource_path('data/psgc-shipping-zones.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
