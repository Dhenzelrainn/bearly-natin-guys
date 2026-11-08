<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\SortingCenter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Buyer-facing route map data for the order tracking panel: approximate pins
 * for the Buyer delivery target, Seller pickup addresses, and Logistics
 * sorting centers, plus optional cached road geometry between them.
 */
class BuyerTrackingRouteService
{
    private const ROUTING_ENDPOINT = 'https://api.geoapify.com/v1/routing';
    private const ROUTING_LEGS_CACHE_SECONDS = 21600;
    private const ROUTING_FAILURE_CACHE_SECONDS = 300;
    private const GEOCODE_SUCCESS_CACHE_SECONDS = 86400;
    private const GEOCODE_FAILURE_CACHE_SECONDS = 3600;
    private const MAX_PINS_PER_GROUP = 3;

    public function __construct(private AddressGeocoderService $geocoder)
    {
    }

    /**
     * Pins are buyer-scoped (the caller verifies order ownership) and rounded
     * to 3 decimals — the same approximation level used for live Rider
     * locations — so exact address coordinates are never exposed.
     *
     * @return array{
     *     buyer: array{latitude: float, longitude: float}|null,
     *     sellers: list<array{latitude: float, longitude: float, label: string}>,
     *     hubs: list<array{latitude: float, longitude: float, label: string}>,
     * }
     */
    public function pinsForOrder(Order $order): array
    {
        if ($order->status === 'cancelled') {
            return [
                'buyer' => null,
                'sellers' => [],
                'hubs' => [],
            ];
        }

        $sellers = collect();
        $hubs = collect();
        $shipments = $this->outboundShipments($order);

        // A hub pin is shown only for the sorting center recorded on a parcel
        // from this shipment. The current-center field is set by parcel intake
        // and updated on accepted center transfers. Do not infer or show a
        // provider's default/origin hub before a parcel has actually been
        // received and sorted there.
        $shipments->each(function (Shipment $shipment) use ($sellers, $hubs): void {
            $pickup = $shipment->originAddress;
            $sellerLabel = (string) ($shipment->sellerOrder?->store?->name ?? 'Seller pickup');

            $pickupPin = $this->savedPin($pickup) ?? $this->geocodedPin($pickup);

            if ($pickupPin !== null) {
                $this->addPin($sellers, [
                    'latitude' => $pickupPin['latitude'],
                    'longitude' => $pickupPin['longitude'],
                    'label' => $sellerLabel,
                ]);
            }

            $center = $this->sortedCenterForShipment($shipment);

            if (! $center?->address) {
                return;
            }

            $hubLabel = (string) ($center->name ?? 'Sorting center');
            $hubPin = $this->savedPin($center->address) ?? $this->geocodedPin($center->address);

            if ($hubPin !== null) {
                $this->addPin($hubs, [
                    'latitude' => $hubPin['latitude'],
                    'longitude' => $hubPin['longitude'],
                    'label' => $hubLabel,
                ]);
            }
        });

        return [
            'buyer' => $this->buyerPin($order),
            'sellers' => $sellers->take(self::MAX_PINS_PER_GROUP)->values()->all(),
            'hubs' => $hubs->take(self::MAX_PINS_PER_GROUP)->values()->all(),
        ];
    }

    /**
     * Cached Geoapify road geometry for Seller → Sorting center → Buyer.
     * Returns [] when routing is unconfigured or unavailable; the UI falls
     * back to straight guideline segments between the revealed pins.
     *
     * @param  list<array{latitude?: float|int|string|null, longitude?: float|int|string|null, label?: string}|null> $waypoints
     * @return list<array{lines: list<list<array{0: float, 1: float}>>}>
     */
    public function routeLegs(array $waypoints): array
    {
        $points = collect($waypoints)
            ->filter(fn ($waypoint): bool => is_array($waypoint)
                && $this->isValidCoordinate($waypoint['latitude'] ?? null, $waypoint['longitude'] ?? null))
            ->map(fn (array $waypoint): array => [
                'latitude' => (float) $waypoint['latitude'],
                'longitude' => (float) $waypoint['longitude'],
            ])
            ->unique(fn (array $point): string => sprintf('%.3F,%.3F', $point['latitude'], $point['longitude']))
            ->values();

        if ($points->count() < 2) {
            return [];
        }

        $apiKey = trim((string) config('maps.geoapify_api_key'));

        if ($apiKey === '') {
            return [];
        }

        $cacheKey = 'buyer-route-map:'.sha1($points
            ->map(fn (array $point): string => sprintf('%.3F,%.3F', $point['latitude'], $point['longitude']))
            ->implode('|'));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $legs = $this->fetchRoadLegs($points, $apiKey);

        Cache::put(
            $cacheKey,
            $legs,
            $legs === [] ? self::ROUTING_FAILURE_CACHE_SECONDS : self::ROUTING_LEGS_CACHE_SECONDS,
        );

        return $legs;
    }

    /**
     * @return Collection<int, Shipment>
     */
    private function outboundShipments(Order $order): Collection
    {
        return $order->sellerOrders
            ->reject(fn ($sellerOrder): bool => (string) $sellerOrder->status === SellerOrderStatus::Cancelled->value)
            ->flatMap(fn ($sellerOrder) => $sellerOrder->shipments->where('purpose', 'outbound'))
            ->unique('id')
            ->values();
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function buyerPin(Order $order): ?array
    {
        if ($this->isValidCoordinate($order->delivery_latitude, $order->delivery_longitude)) {
            return [
                'latitude' => round((float) $order->delivery_latitude, 3),
                'longitude' => round((float) $order->delivery_longitude, 3),
            ];
        }

        $shippingAddress = $order->shippingAddress;
        $pin = $this->savedPin($shippingAddress) ?? $this->geocodedPin($shippingAddress);

        if ($pin !== null) {
            return [
                'latitude' => $pin['latitude'],
                'longitude' => $pin['longitude'],
            ];
        }

        return null;
    }

    /**
     * The most recently updated parcel's recorded current sorting center.
     * This is actual parcel custody, not a provider's origin/default center.
     */
    private function sortedCenterForShipment(Shipment $shipment): ?SortingCenter
    {
        $parcel = $shipment->parcels
            ->filter(fn ($parcel) => filled($parcel->current_sorting_center_id))
            ->sortByDesc('updated_at')
            ->first();

        return $parcel?->currentSortingCenter;
    }

    /**
     * Rounded pin from coordinates already saved on the address row.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function savedPin(?Address $address): ?array
    {
        if ($address === null || ! $this->isValidCoordinate($address->latitude, $address->longitude)) {
            return null;
        }

        return [
            'latitude' => round((float) $address->latitude, 3),
            'longitude' => round((float) $address->longitude, 3),
        ];
    }

    /**
     * Approximate pin for an address without saved coordinates, resolved by
     * server-side geocoding and cached per address row (the updated_at token
     * invalidates the entry when the address is edited). Failures are cached
     * briefly so provider outages do not slow every request.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function geocodedPin(?Address $address): ?array
    {
        if ($address === null
            || trim((string) $address->city_municipality) === ''
            || trim((string) $address->province) === ''
            || trim((string) $address->barangay) === '') {
            return null;
        }

        $cacheKey = 'buyer-route-map:geocode:'.$address->getKey().':'.($address->updated_at?->getTimestamp() ?? 0);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        if ($cached === false) {
            return null;
        }

        $pin = $this->geocoder->pinForAddress([
            'barangay' => (string) $address->barangay,
            'city' => (string) $address->city_municipality,
            'province' => (string) $address->province,
        ]);

        if ($pin === null) {
            Cache::put($cacheKey, false, self::GEOCODE_FAILURE_CACHE_SECONDS);

            return null;
        }

        $pin = [
            'latitude' => round($pin['latitude'], 3),
            'longitude' => round($pin['longitude'], 3),
        ];

        Cache::put($cacheKey, $pin, self::GEOCODE_SUCCESS_CACHE_SECONDS);

        return $pin;
    }

    private function isValidCoordinate(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        return is_finite($latitude) && is_finite($longitude)
            && $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180;
    }

    /**
     * @param  Collection<int, array{latitude: float, longitude: float, label: string}> $pins
     * @param  array{latitude: float, longitude: float, label: string} $pin
     */
    private function addPin(Collection $pins, array $pin): void
    {
        $key = sprintf('%.3F,%.3F', $pin['latitude'], $pin['longitude']);

        $alreadyShown = $pins->first(
            fn (array $existing): bool => sprintf('%.3F,%.3F', $existing['latitude'], $existing['longitude']) === $key,
        );

        if ($alreadyShown === null) {
            $pins->push($pin);
        }
    }

    /**
     * @param  Collection<int, array{latitude: float, longitude: float}> $points
     * @return list<array{lines: list<list<array{0: float, 1: float}>>}>
     */
    private function fetchRoadLegs(Collection $points, string $apiKey): array
    {
        $expectedLegs = $points->count() - 1;

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->get(self::ROUTING_ENDPOINT, [
                    'waypoints' => $points
                        ->map(fn (array $point): string => sprintf('%.7F,%.7F', $point['latitude'], $point['longitude']))
                        ->implode('|'),
                    'mode' => 'drive',
                    'format' => 'geojson',
                    'apiKey' => $apiKey,
                ]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $features = $response->json('features');

        if (! is_array($features) || count($features) !== $expectedLegs) {
            return [];
        }

        $legs = [];

        foreach ($features as $feature) {
            $lines = $this->validatedLegLines($feature['geometry'] ?? null);

            if ($lines === null) {
                return [];
            }

            $legs[] = ['lines' => $lines];
        }

        return $legs;
    }

    /**
     * Validates LineString/MultiLineString GeoJSON geometry and normalizes it
     * into lines of [lng, lat] float pairs.
     *
     * @return list<list<array{0: float, 1: float}>>|null
     */
    private function validatedLegLines(mixed $geometry): ?array
    {
        if (! is_array($geometry)) {
            return null;
        }

        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if (! in_array($type, ['LineString', 'MultiLineString'], true) || ! is_array($coordinates)) {
            return null;
        }

        $lines = $type === 'LineString' ? [$coordinates] : $coordinates;

        if ($lines === [] || count($lines) > 25) {
            return null;
        }

        $pointCount = 0;

        foreach ($lines as $line) {
            if (! is_array($line) || count($line) < 2) {
                return null;
            }

            foreach ($line as $point) {
                if (! is_array($point) || count($point) < 2
                    || ! is_numeric($point[0] ?? null)
                    || ! is_numeric($point[1] ?? null)) {
                    return null;
                }

                $longitude = (float) $point[0];
                $latitude = (float) $point[1];

                if (! is_finite($longitude) || ! is_finite($latitude)
                    || $longitude < -180 || $longitude > 180
                    || $latitude < -90 || $latitude > 90) {
                    return null;
                }

                $pointCount++;

                if ($pointCount > 12_000) {
                    return null;
                }
            }
        }

        return $lines;
    }
}
