<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PostalCodeLookup
{
    private function data(): array
    {
        static $data;
        return $data ??= json_decode(file_get_contents(resource_path('data/philippine-postal-codes.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)));
    }

    public function find(?string $code, string $province = '', string $city = ''): array
    {
        $cities = $this->data()['cities'];
        if ($code && isset($cities[$code])) {
            return $cities[$code];
        }
        // Manual entry or an upstream code change: require BOTH names, never city alone.
        foreach ($cities as $item) {
            if ($this->normalize($item['province']) === $this->normalize($province) &&
                $this->normalize($item['city']) === $this->normalize($city)) {
                return $item;
            }
        }
        return ['province' => $province, 'city' => $city, 'options' => []];
    }

    public function canonicalCityCode(
        ?string $code,
        string $province,
        string $city
    ): ?string {
        $cities = $this->data()['cities'];

        if (filled($code)) {
            $code = trim($code);
            $place = $cities[$code] ?? null;

            if (
                ! $place
                || $this->normalize($place['province']) !== $this->normalize($province)
                || $this->normalizeCity($place['city']) !== $this->normalizeCity($city)
            ) {
                throw ValidationException::withMessages([
                    'city' => 'Please select your city again; the PSGC city code does not match the address.',
                ]);
            }

            return $code;
        }

        $matches = [];

        foreach ($cities as $cityCode => $place) {
            if (
                $this->normalize($place['province']) === $this->normalize($province)
                && $this->normalizeCity($place['city']) === $this->normalizeCity($city)
            ) {
                $matches[] = (string) $cityCode;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Resolve a city only by its canonical PSGC identifier. Shipping-zone
     * classification must not fall back to municipality or province names.
     *
     * @return array{province: string, city: string, options: array}|null
     */
    public function findByCanonicalCityCode(?string $code): ?array
    {
        $code = trim((string) $code);
        if (! preg_match('/^\d{10}$/D', $code)) {
            return null;
        }

        $place = $this->data()['cities'][$code] ?? null;

        return is_array($place) ? $place : null;
    }

    private function normalizeCity(string $value): string
    {
        $normalized = $this->normalize($value);

        if (str_starts_with($normalized, 'cityof')) {
            $normalized = substr($normalized, strlen('cityof'));
        }

        if (str_ends_with($normalized, 'city')) {
            $normalized = substr($normalized, 0, -strlen('city'));
        }

        return $normalized;
    }

    public function validate(array $data): void
    {
        $place = $this->find($data['city_code'] ?? null, $data['province'], $data['city']);
        if ($this->normalize($place['province']) !== $this->normalize($data['province']) ||
            $this->normalizeCity($place['city']) !== $this->normalizeCity($data['city'])) {
            throw ValidationException::withMessages(['city' => 'Please select your city again; the address does not match.']);
        }
        if ($place['options'] && ! in_array($data['postal_code'], array_column($place['options'], 'code'), true)) {
            // A legitimate district may be absent from the snapshot. Explicit manual
            // correction is supported, never silently substitute the city-center ZIP.
            if (empty($data['postal_manual'])) {
                throw ValidationException::withMessages(['postal_code' => 'Choose a listed postal code or select the manual postal-code option for your area.']);
            }
        }
    }
}
