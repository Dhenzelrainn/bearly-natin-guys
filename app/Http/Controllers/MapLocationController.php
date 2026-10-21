<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MapLocationController extends Controller
{
    public function reverseGeocode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $response = Http::timeout(8)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Bearly Marketplace/1.0 ('.config('app.url').')',
                'Referer' => config('app.url'),
            ])
            ->get(config('maps.geocoding_url'), [
                'lat' => $data['latitude'],
                'lon' => $data['longitude'],
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'zoom' => 18,
                'accept-language' => 'en',
            ]);

        if (! $response->successful()) {
            return response()->json([
                'message' => 'OpenStreetMap could not resolve this pin. You can keep the pin and enter the address manually.',
            ], 502);
        }

        $address = (array) $response->json('address', []);
        $city = $address['city']
            ?? $address['municipality']
            ?? $address['town']
            ?? $address['village']
            ?? null;
        $barangay = $address['barangay']
            ?? $address['suburb']
            ?? $address['neighbourhood']
            ?? $address['quarter']
            ?? null;

        return response()->json([
            'data' => [
                'display_name' => $response->json('display_name'),
                'name' => $address['house_number'] ?? null,
                'street' => $address['road'] ?? $address['street'] ?? null,
                'barangay' => $barangay,
                'city' => $city,
                'province' => $address['province'] ?? $address['state'] ?? null,
                'postal' => $address['postcode'] ?? null,
                'latitude' => (float) ($response->json('lat') ?? $data['latitude']),
                'longitude' => (float) ($response->json('lon') ?? $data['longitude']),
            ],
            'message' => 'OpenStreetMap suggested address details for this pin.',
        ]);
    }

    public function forwardGeocode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'street' => ['nullable', 'string', 'max:180'],
            'barangay' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
            'postal' => ['nullable', 'digits:4'],
        ]);

        $query = collect([
            $data['street'] ?? null,
            $data['barangay'],
            $data['city'],
            $data['province'],
            'Philippines',
        ])->filter()->implode(', ');

        $apiKey = (string) config('maps.maptiler_api_key');

        if ($apiKey === '') {
            return $this->forwardGeocodeWithOpenStreetMap($data);
        }

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
            return response()->json([
                'message' => 'MapTiler could not find this address. Place the pin manually.',
            ], 502);
        }

        $features = collect((array) $response->json('features', []))
            ->filter(fn ($feature): bool => is_array($feature) && is_array($feature['center'] ?? null) && count($feature['center']) >= 2);
        $localityTokens = collect([$data['barangay'], $data['city'], $data['province']])
            ->map(fn (string $value): string => Str::lower(Str::ascii(trim($value))))
            ->filter()
            ->values();
        $feature = $features->first(function (array $candidate) use ($localityTokens): bool {
            $placeName = Str::lower(Str::ascii((string) ($candidate['place_name'] ?? '')));

            return $localityTokens->every(fn (string $token): bool => Str::contains($placeName, $token));
        });

        if (! $feature) {
            return response()->json([
                'message' => 'No Philippine map result matched the selected barangay, city, and province. Refine the address or place the pin manually.',
            ], 422);
        }

        $placeTypes = (array) ($feature['place_type'] ?? []);
        $approximate = ! in_array('address', $placeTypes, true)
            && ! in_array('poi', $placeTypes, true);

        return response()->json([
            'data' => [
                'latitude' => (float) $feature['center'][1],
                'longitude' => (float) $feature['center'][0],
                'display_name' => (string) ($feature['place_name'] ?? $query),
                'approximate' => $approximate,
            ],
            'message' => $approximate
                ? 'Map centered on an approximate locality result. Drag the pin to the exact location.'
                : 'Map centered on the matched address. Verify the pin before saving.',
        ]);
    }

    /**
     * @param  array{street?: string|null, barangay: string, city: string, province: string, postal?: string|null}  $data
     */
    private function forwardGeocodeWithOpenStreetMap(array $data): JsonResponse
    {
        $localityQuery = collect([
            $data['barangay'],
            $data['city'],
            $data['province'],
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
            return response()->json([
                'message' => 'OpenStreetMap could not find this address. Open OpenStreetMap and place the pin manually.',
            ], 502);
        }

        $normalizeLocality = function (string $value): string {
            $normalized = Str::lower(Str::ascii(trim($value)));
            $normalized = preg_replace('/\b(city|municipality)\b/', ' ', $normalized) ?? $normalized;
            $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

            return trim($normalized);
        };

        $localityTokens = collect([$data['barangay'], $data['city'], $data['province']])
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
            return response()->json([
                'message' => 'No Philippine map result matched the selected barangay, city, and province. Refine the address or open OpenStreetMap.',
            ], 422);
        }

        $type = (string) ($result['type'] ?? '');
        $approximate = ! in_array($type, ['house', 'building', 'road', 'residential'], true);

        return response()->json([
            'data' => [
                'latitude' => (float) $result['lat'],
                'longitude' => (float) $result['lon'],
                'display_name' => (string) ($result['display_name'] ?? $localityQuery),
                'approximate' => $approximate,
            ],
            'message' => $approximate
                ? 'Map centered on an approximate OpenStreetMap result. Open OpenStreetMap to verify the exact pickup location.'
                : 'Pickup location found on OpenStreetMap.',
        ]);
    }
}
