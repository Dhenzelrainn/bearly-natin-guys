<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Server-side forward geocoding for Philippine addresses: MapTiler when an
 * API key is configured, otherwise OpenStreetMap Nominatim.
 *
 * The map location HTTP endpoint uses `forwardGeocode()` (status + message +
 * coordinate payload), while services such as the buyer route map use
 * `pinForAddress()` for silent cached lookups without user-facing copy.
 */
class AddressGeocoderService
{
    /**
     * Geocodes a locality and returns coordinates when a Philippine result
     * matches the barangay, city, and province tokens. No messages are
     * produced: callers decide what an unmatched/unavailable provider means
     * for their surface.
     *
     * @param  array{barangay: string, city?: string|null, province?: string|null}  $address
     * @return array{latitude: float, longitude: float}|null
     */
    public function pinForAddress(array $address): ?array
    {
        $barangay = trim((string) ($address['barangay'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        $province = trim((string) ($address['province'] ?? ''));

        if ($barangay === '' || $city === '' || $province === '') {
            return null;
        }

        $result = $this->forwardGeocode([
            'street' => null,
            'barangay' => $barangay,
            'city' => $city,
            'province' => $province,
        ]);

        if ($result['status'] !== 'matched') {
            return null;
        }

        return [
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
        ];
    }

    /**
     * Forward-geocodes a Philippine address: MapTiler when configured, with
     * an OpenStreetMap Nominatim fallback otherwise. Callers map `status`
     * onto their own surface (the HTTP endpoint uses 200/422/502).
     *
     * @param  array{street?: string|null, barangay: string, city: string, province: string, postal?: string|null}  $address
     * @return array{
     *     status: 'matched'|'unmatched'|'unavailable',
     *     message: string,
     *     latitude?: float,
     *     longitude?: float,
     *     display_name?: string,
     *     approximate?: bool,
     * }
     */
    public function forwardGeocode(array $address): array
    {
        $apiKey = (string) config('maps.maptiler_api_key');

        if ($apiKey === '') {
            return $this->forwardGeocodeWithOpenStreetMap($address);
        }

        $query = collect([
            $address['street'] ?? null,
            $address['barangay'],
            $address['city'],
            $address['province'],
            'Philippines',
        ])->filter()->implode(', ');

        $response = Http::timeout(8)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Bearly Marketplace/1.0 ('.config('app.url').')',
                'Referer' => config('app.url'),
            ])
            ->get(rtrim((string) config('maps.forward_geocoding_url'), '/').'/'.rawurlencode($query).'.json', [
                'key' => $apiKey,
                'country' => 'ph',
                'language' => 'en',
                'limit' => 5,
            ]);

        if (! $response->successful()) {
            return [
                'status' => 'unavailable',
                'message' => 'MapTiler could not find this address. Place the pin manually.',
            ];
        }

        $features = collect((array) $response->json('features', []))
            ->filter(fn ($feature): bool => is_array($feature) && is_array($feature['center'] ?? null) && count($feature['center']) >= 2);
        $localityTokens = collect([$address['barangay'], $address['city'], $address['province']])
            ->map(fn (string $value): string => Str::lower(Str::ascii(trim($value))))
            ->filter()
            ->values();
        $feature = $features->first(function (array $candidate) use ($localityTokens): bool {
            $placeName = Str::lower(Str::ascii((string) ($candidate['place_name'] ?? '')));

            return $localityTokens->every(fn (string $token): bool => Str::contains($placeName, $token));
        });

        if (! $feature) {
            return [
                'status' => 'unmatched',
                'message' => 'No Philippine map result matched the selected barangay, city, and province. Refine the address or place the pin manually.',
            ];
        }

        $placeTypes = (array) ($feature['place_type'] ?? []);
        $approximate = ! in_array('address', $placeTypes, true)
            && ! in_array('poi', $placeTypes, true);

        return [
            'status' => 'matched',
            'message' => $approximate
                ? 'Map centered on an approximate locality result. Drag the pin to the exact location.'
                : 'Map centered on the matched address. Verify the pin before saving.',
            'latitude' => (float) $feature['center'][1],
            'longitude' => (float) $feature['center'][0],
            'display_name' => (string) ($feature['place_name'] ?? $query),
            'approximate' => $approximate,
        ];
    }

    /**
     * @param  array{street?: string|null, barangay: string, city: string, province: string, postal?: string|null}  $address
     * @return array{
     *     status: 'matched'|'unmatched'|'unavailable',
     *     message: string,
     *     latitude?: float,
     *     longitude?: float,
     *     display_name?: string,
     *     approximate?: bool,
     * }
     */
    private function forwardGeocodeWithOpenStreetMap(array $address): array
    {
        $localityQuery = collect([
            $address['barangay'],
            $address['city'],
            $address['province'],
            'Philippines',
        ])->filter()->implode(', ');

        $parameters = [
            'q' => $localityQuery,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'countrycodes' => 'ph',
            'limit' => 5,
            'accept-language' => 'en',
        ];

        if ($email = config('maps.geocoder_email')) {
            $parameters['email'] = (string) $email;
        }

        $response = Http::timeout(8)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Bearly Marketplace/1.0 ('.config('app.url').')',
                'Referer' => config('app.url'),
            ])
            ->get(config('maps.forward_geocoding_search_url'), $parameters);

        if (! $response->successful()) {
            return [
                'status' => 'unavailable',
                'message' => 'OpenStreetMap could not find this address. Open OpenStreetMap and place the pin manually.',
            ];
        }

        $normalizeLocality = function (string $value): string {
            $normalized = Str::lower(Str::ascii(trim($value)));
            $normalized = preg_replace('/\b(city|municipality)\b/', ' ', $normalized) ?? $normalized;
            $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

            return trim($normalized);
        };

        $localityTokens = collect([$address['barangay'], $address['city'], $address['province']])
            ->map($normalizeLocality)
            ->filter()
            ->values();

        $results = collect((array) $response->json())
            ->filter(fn ($result): bool => is_array($result)
                && is_numeric($result['lat'] ?? null)
                && is_numeric($result['lon'] ?? null)
                && strtolower((string) ($result['address']['country_code'] ?? '')) === 'ph');

        $result = $results->first(function (array $candidate) use ($localityTokens, $normalizeLocality): bool {
            $displayName = $normalizeLocality((string) ($candidate['display_name'] ?? ''));

            return $localityTokens->every(fn (string $token): bool => Str::contains($displayName, $token));
        });

        if (! $result) {
            return [
                'status' => 'unmatched',
                'message' => 'No Philippine map result matched the selected barangay, city, and province. Refine the address or open OpenStreetMap.',
            ];
        }

        $type = (string) ($result['type'] ?? '');
        $approximate = ! in_array($type, ['house', 'building', 'road', 'residential'], true);

        return [
            'status' => 'matched',
            'message' => $approximate
                ? 'Map centered on an approximate OpenStreetMap result. Open OpenStreetMap to verify the exact pickup location.'
                : 'Pickup location found on OpenStreetMap.',
            'latitude' => (float) $result['lat'],
            'longitude' => (float) $result['lon'],
            'display_name' => (string) ($result['display_name'] ?? $localityQuery),
            'approximate' => $approximate,
        ];
    }
}
