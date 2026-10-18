<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
}
