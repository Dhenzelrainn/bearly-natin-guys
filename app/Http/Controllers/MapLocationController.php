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

        $apiKey = (string) config('maps.maptiler_api_key');
        if ($apiKey === '') {
            return response()->json([
                'message' => 'MapTiler forward geocoding is not configured. Place the pin manually.',
            ], 503);
        }

        $query = collect([
            $data['street'] ?? null,
            $data['barangay'],
            $data['city'],
            $data['province'],
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
}
