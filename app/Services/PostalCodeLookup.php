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

    public function validate(array $data): void
    {
        $place = $this->find($data['city_code'] ?? null, $data['province'], $data['city']);
        if ($this->normalize($place['province']) !== $this->normalize($data['province']) ||
            $this->normalize($place['city']) !== $this->normalize($data['city'])) {
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
