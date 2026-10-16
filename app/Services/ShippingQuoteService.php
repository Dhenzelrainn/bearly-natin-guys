<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Store;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShippingQuoteService
{
    public function quote(Store $store, Address $destination, Collection $lines, bool $lockConfiguration = false): array
    {
        $profile = $store->sellerProfile;
        $origin = $profile?->pickupAddress;

        if (! $profile || ! $origin || (int) $origin->user_id !== (int) $profile->user_id) {
            throw ValidationException::withMessages([
                'shipping' => "{$store->name} needs a valid pickup address before checkout can be quoted.",
            ]);
        }

        $routeTier = $this->routeTier($origin, $destination);
        $divisor = $this->volumetricDivisor($lockConfiguration);
        $sellerOrderFee = $this->sellerOrderFeeMinor($lockConfiguration);

        $package = $this->combinedPackage($lines);
        $volumetricWeight = ($package['length_cm'] * $package['width_cm'] * $package['height_cm']) / $divisor;
        $billableWeight = max(1, (int) ceil(max($package['actual_weight_kg'], $volumetricWeight) - 0.0000001));

        return [
            'provider_key' => (string) config('shipping.provider_key'),
            'provider_label' => (string) config('shipping.provider_label'),
            'route_tier' => $routeTier,
            'route_label' => (string) config("shipping.route_tiers.{$routeTier}.label", $routeTier),
            'pricing_rule' => 'flat_per_seller_order',
            'seller_order_fee_minor' => $sellerOrderFee,
            'volumetric_divisor' => $divisor,
            'actual_weight_kg' => round($package['actual_weight_kg'], 3),
            'volumetric_weight_kg' => round($volumetricWeight, 3),
            'billable_weight_kg' => $billableWeight,
            'parcel_dimensions_cm' => [
                'length' => round($package['length_cm'], 2),
                'width' => round($package['width_cm'], 2),
                'height' => round($package['height_cm'], 2),
            ],
            'package_estimator' => 'greedy_minimum_bounding_box_v1',
            'package_items' => $package['items'],
            'origin' => $this->addressSnapshot($origin),
            'destination' => $this->addressSnapshot($destination),
            'shipping_fee_minor' => $sellerOrderFee,
            'currency' => 'PHP',
        ];
    }

    public function routeTier(Address $origin, Address $destination): string
    {
        $originCityCode = trim((string) $origin->psgc_city_code);
        $destinationCityCode = trim((string) $destination->psgc_city_code);
        $sameCity = $originCityCode !== '' && $destinationCityCode !== ''
            ? $originCityCode === $destinationCityCode
            : $this->normalizeLocation((string) $origin->city_municipality) === $this->normalizeLocation((string) $destination->city_municipality)
                && $this->sameProvince($origin, $destination);

        if ($sameCity) {
            return 'nearby_delivery';
        }

        if ($this->sameProvince($origin, $destination)) {
            return 'same_province';
        }

        $originGroup = $this->islandGroup((string) $origin->province);
        $destinationGroup = $this->islandGroup((string) $destination->province);

        if ($originGroup === null || $destinationGroup === null) {
            throw ValidationException::withMessages([
                'shipping' => 'The delivery route cannot be classified from these addresses. Verify the Seller pickup and Buyer delivery provinces.',
            ]);
        }

        return $originGroup === $destinationGroup ? 'nearby_provinces' : 'inter_island';
    }

    /** Lock quote configuration in the same order used by Admin edits. */
    public function lockQuoteConfiguration(): void
    {
        $this->volumetricDivisor(true);
        $this->sellerOrderFeeMinor(true);
    }

    private function combinedPackage(Collection $lines): array
    {
        $boxes = [];
        $actualWeight = 0.0;
        $packageItems = [];

        foreach ($lines as $line) {
            $variant = $line['variant'];
            $quantity = (int) $line['quantity'];
            $dimensions = [
                (float) $variant->package_length_cm,
                (float) $variant->package_width_cm,
                (float) $variant->package_height_cm,
            ];
            rsort($dimensions, SORT_NUMERIC);

            $boxes[] = $this->packRepeatedBoxes($dimensions, $quantity);

            $unitWeight = (float) $variant->package_weight_kg;
            $actualWeight += $unitWeight * $quantity;
            $packageItems[] = [
                'sku' => (string) $variant->sku,
                'quantity' => $quantity,
                'package_weight_kg' => round($unitWeight, 3),
                'package_dimensions_cm' => [
                    'length' => round((float) $variant->package_length_cm, 2),
                    'width' => round((float) $variant->package_width_cm, 2),
                    'height' => round((float) $variant->package_height_cm, 2),
                ],
            ];
        }

        usort($boxes, static function (array $left, array $right): int {
            $volumeOrder = ($right[0] * $right[1] * $right[2]) <=> ($left[0] * $left[1] * $left[2]);

            return $volumeOrder !== 0 ? $volumeOrder : ($right[0] <=> $left[0]);
        });

        $parcel = array_shift($boxes);
        foreach ($boxes as $box) {
            $parcel = $this->combineBoundingBoxes($parcel, $box);
        }

        return [
            'length_cm' => (float) $parcel[0],
            'width_cm' => (float) $parcel[1],
            'height_cm' => (float) $parcel[2],
            'actual_weight_kg' => $actualWeight,
            'items' => $packageItems,
        ];
    }

    private function packRepeatedBoxes(array $unit, int $quantity): array
    {
        $packed = null;
        $block = $unit;
        $remaining = $quantity;

        while ($remaining > 0) {
            if (($remaining & 1) === 1) {
                $packed = $packed === null ? $block : $this->combineBoundingBoxes($packed, $block);
            }

            $remaining >>= 1;
            if ($remaining > 0) {
                $block = $this->combineBoundingBoxes($block, $block);
            }
        }

        return $packed ?? $unit;
    }

    /**
     * Combine two rectangular packages using the smallest valid axis-aligned
     * bounding box among the six possible orientations of the next package.
     */
    private function combineBoundingBoxes(array $parcel, array $box): array
    {
        $candidates = [];
        foreach ($this->permutations($box) as $orientation) {
            foreach ([0, 1, 2] as $axis) {
                $candidate = [];
                for ($dimension = 0; $dimension < 3; $dimension++) {
                    $candidate[$dimension] = $dimension === $axis
                        ? $parcel[$dimension] + $orientation[$dimension]
                        : max($parcel[$dimension], $orientation[$dimension]);
                }

                $candidates[] = [
                    'dimensions' => $candidate,
                    'volume' => $candidate[0] * $candidate[1] * $candidate[2],
                    'largest_side' => max($candidate),
                    'axis' => $axis,
                ];
            }
        }

        usort($candidates, static fn (array $left, array $right): int => ($left['volume'] <=> $right['volume'])
            ?: ($left['largest_side'] <=> $right['largest_side'])
            ?: ($left['axis'] <=> $right['axis'])
        );

        return $candidates[0]['dimensions'];
    }

    private function permutations(array $dimensions): array
    {
        $candidates = [
            [$dimensions[0], $dimensions[1], $dimensions[2]],
            [$dimensions[0], $dimensions[2], $dimensions[1]],
            [$dimensions[1], $dimensions[0], $dimensions[2]],
            [$dimensions[1], $dimensions[2], $dimensions[0]],
            [$dimensions[2], $dimensions[0], $dimensions[1]],
            [$dimensions[2], $dimensions[1], $dimensions[0]],
        ];
        $seen = [];

        return array_values(array_filter($candidates, static function (array $candidate) use (&$seen): bool {
            $key = implode(':', $candidate);
            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    private function volumetricDivisor(bool $lock = false): int
    {
        $setting = SystemSetting::query()->where('key', 'shipping.volumetric_divisor');
        if ($lock) {
            $setting->lockForUpdate();
        }
        $stored = $setting->value('value');

        return max(1, (int) ($stored ?? config('shipping.default_volumetric_divisor', 5000)));
    }

    private function sellerOrderFeeMinor(bool $lock = false): int
    {
        $setting = SystemSetting::query()->where('key', 'shipping.seller_order_fee_minor');
        if ($lock) {
            $setting->lockForUpdate();
        }
        $stored = $setting->value('value');

        return max(0, (int) ($stored ?? config('shipping.default_seller_order_fee_minor', 5000)));
    }

    private function sameProvince(Address $origin, Address $destination): bool
    {
        $originCode = trim((string) $origin->psgc_province_code);
        $destinationCode = trim((string) $destination->psgc_province_code);

        if ($originCode !== '' && $destinationCode !== '') {
            return $originCode === $destinationCode;
        }

        return $this->normalizeLocation((string) $origin->province) === $this->normalizeLocation((string) $destination->province);
    }

    private function islandGroup(string $province): ?string
    {
        $normalized = $this->normalizeLocation($province);

        foreach ((array) config('shipping.island_groups', []) as $group => $provinces) {
            foreach ($provinces as $candidate) {
                if ($normalized === $this->normalizeLocation((string) $candidate)) {
                    return (string) $group;
                }
            }
        }

        return null;
    }

    private function normalizeLocation(string $value): string
    {
        $value = Str::lower(Str::ascii(trim($value)));
        $value = preg_replace('/\b(province|provincia)\b/', '', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function addressSnapshot(Address $address): array
    {
        return [
            'city_municipality' => (string) $address->city_municipality,
            'province' => (string) $address->province,
            'psgc_city_code' => $address->psgc_city_code,
            'psgc_province_code' => $address->psgc_province_code,
        ];
    }
}
