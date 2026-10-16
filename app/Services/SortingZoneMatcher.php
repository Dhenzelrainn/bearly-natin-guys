<?php

namespace App\Services;

use App\Models\Parcel;
use App\Models\SortingZone;
use Illuminate\Support\Str;

class SortingZoneMatcher
{
    /**
     * Resolve the immutable checkout destination for outbound shipments and
     * the seller destination address for return shipments.
     *
     * @return array{province: string, city_municipality: string, barangay: string, psgc_city_code: string}
     */
    public function destinationFor(Parcel $parcel): array
    {
        $parcel->loadMissing([
            'shipment.destinationAddress',
            'shipment.sellerOrder.order',
        ]);

        $shipment = $parcel->shipment;

        if (! $shipment) {
            return $this->emptyDestination();
        }

        if ($shipment->purpose === 'return') {
            $snapshot = $shipment->destination_address_snapshot;

            if (is_array($snapshot)) {
                return [
                    'province' => (string) ($snapshot['province'] ?? ''),
                    'city_municipality' => (string) ($snapshot['city_municipality'] ?? ''),
                    'barangay' => (string) ($snapshot['barangay'] ?? ''),
                    'psgc_city_code' => $this->cityCodeFor(
                        $snapshot['psgc_city_code'] ?? null,
                        (string) ($snapshot['province'] ?? ''),
                        (string) ($snapshot['city_municipality'] ?? '')
                    ),
                ];
            }

            $address = $shipment->destinationAddress;

            return $address
                ? $this->destinationFrom($address)
                : $this->emptyDestination();
        }

        $order = $shipment->sellerOrder?->order;

        if ($order) {
            return [
                'province' => (string) $order->province,
                'city_municipality' => (string) $order->city_municipality,
                'barangay' => (string) $order->barangay,
                'psgc_city_code' => $this->cityCodeFor(
                    $order->psgc_city_code,
                    (string) $order->province,
                    (string) $order->city_municipality
                ),
            ];
        }

        return $shipment->destinationAddress
            ? $this->destinationFrom($shipment->destinationAddress)
            : $this->emptyDestination();
    }

    public function matches(SortingZone $zone, Parcel $parcel): bool
    {
        $zone->loadMissing('sortingCenter.logisticsProfile');
        $destination = $this->destinationFor($parcel);
        $serviceCityCode = (string) ($zone->sortingCenter?->logisticsProfile?->psgc_city_code ?? '');

        if (
            $serviceCityCode !== ''
            && (string) ($destination['psgc_city_code'] ?? '') !== $serviceCityCode
        ) {
            return false;
        }

        return $this->coversDestination(
            $zone->destination_rules,
            $destination
        );
    }

    /**
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $destination
     */
    public function coversDestination(?array $rules, array $destination): bool
    {
        $province = $this->normalize((string) ($rules['province'] ?? ''));
        $city = $this->normalize((string) ($rules['city_municipality'] ?? ''));
        $barangay = $this->normalize((string) ($destination['barangay'] ?? ''));
        $destinationProvince = $this->normalize((string) ($destination['province'] ?? ''));
        $destinationCity = $this->normalize((string) ($destination['city_municipality'] ?? ''));
        $coverageCityCode = trim((string) ($rules['psgc_city_code'] ?? ''));
        $destinationCityCode = trim((string) ($destination['psgc_city_code'] ?? ''));
        $cityIdentityMatches = $coverageCityCode !== '' && $destinationCityCode !== ''
            ? $coverageCityCode === $destinationCityCode
            : $province === $destinationProvince && $city === $destinationCity;

        if (
            $province === ''
            || $city === ''
            || $barangay === ''
            || $destinationProvince === ''
            || $destinationCity === ''
            || ! $cityIdentityMatches
        ) {
            return false;
        }

        $coveredBarangays = $rules['barangays'] ?? [];

        if (! is_array($coveredBarangays)) {
            return false;
        }

        foreach ($coveredBarangays as $coveredBarangay) {
            $name = is_array($coveredBarangay)
                ? (string) ($coveredBarangay['name'] ?? '')
                : (string) $coveredBarangay;

            if ($this->normalize($name) === $barangay) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>|null  $rules */
    public function coverageLabel(?array $rules): string
    {
        $province = trim((string) ($rules['province'] ?? ''));
        $city = trim((string) ($rules['city_municipality'] ?? ''));
        $barangays = collect($rules['barangays'] ?? [])
            ->map(fn (mixed $barangay): string => is_array($barangay)
                ? trim((string) ($barangay['name'] ?? ''))
                : trim((string) $barangay))
            ->filter()
            ->values();

        if ($province === '' || $city === '' || $barangays->isEmpty()) {
            return 'Coverage not configured';
        }

        return $province.' / '.$city.' · '.$barangays->implode(', ');
    }

    public function normalizeLabel(string $value): string
    {
        return $this->normalize($value);
    }

    private function destinationFrom(object $address): array
    {
        return [
            'province' => (string) ($address->province ?? ''),
            'city_municipality' => (string) ($address->city_municipality ?? ''),
            'barangay' => (string) ($address->barangay ?? ''),
            'psgc_city_code' => $this->cityCodeFor(
                $address->psgc_city_code ?? null,
                (string) ($address->province ?? ''),
                (string) ($address->city_municipality ?? '')
            ),
        ];
    }

    private function cityCodeFor(?string $code, string $province, string $city): string
    {
        if (filled($code)) {
            return trim($code);
        }

        try {
            return app(PostalCodeLookup::class)->canonicalCityCode(
                null,
                $province,
                $city
            ) ?? '';
        } catch (\Illuminate\Validation\ValidationException) {
            return '';
        }
    }

    /** @return array{province: string, city_municipality: string, barangay: string, psgc_city_code: string} */
    private function emptyDestination(): array
    {
        return [
            'province' => '',
            'city_municipality' => '',
            'barangay' => '',
            'psgc_city_code' => '',
        ];
    }

    private function normalize(string $value): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($value)));

        return trim(preg_replace('/[^a-z0-9]+/i', ' ', $ascii) ?? '');
    }
}
