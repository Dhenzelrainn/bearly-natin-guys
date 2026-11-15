<?php

namespace App\Http\Controllers;

use App\Services\AddressGeocoderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MapLocationController extends Controller
{
    public function __construct(private AddressGeocoderService $geocoder)
    {
    }

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

        $result = $this->geocoder->forwardGeocode($data);

        if ($result['status'] === 'matched') {
            return response()->json([
                'data' => [
                    'latitude' => $result['latitude'],
                    'longitude' => $result['longitude'],
                    'display_name' => $result['display_name'],
                    'approximate' => $result['approximate'],
                ],
                'message' => $result['message'],
            ]);
        }

        return response()->json([
            'message' => $result['message'],
        ], $result['status'] === 'unmatched' ? 422 : 502);
    }
}
