<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_osm_reverse_geocoding_suggestions(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => '123 Maple Street, Balibago, Santa Rosa, Laguna, Philippines',
                'lat' => '14.3121000',
                'lon' => '121.1112000',
                'address' => [
                    'house_number' => '123',
                    'road' => 'Maple Street',
                    'barangay' => 'Balibago',
                    'city' => 'Santa Rosa',
                    'province' => 'Laguna',
                    'postcode' => '4026',
                ],
            ]),
        ]);

        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('maps.reverse-geocode', [
                'latitude' => 14.3121,
                'longitude' => 121.1112,
            ]))
            ->assertOk()
            ->assertJsonPath('data.street', 'Maple Street')
            ->assertJsonPath('data.barangay', 'Balibago')
            ->assertJsonPath('data.city', 'Santa Rosa')
            ->assertJsonPath('data.province', 'Laguna')
            ->assertJsonPath('data.postal', '4026');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), config('maps.geocoding_url'))
            && (string) $request->data()['lat'] === '14.3121'
            && (string) $request->data()['lon'] === '121.1112'
            && isset($request->headers()['User-Agent']));
    }

    public function test_reverse_geocoding_rejects_invalid_coordinates(): void
    {
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('maps.reverse-geocode', [
                'latitude' => 91,
                'longitude' => 181,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }
}
