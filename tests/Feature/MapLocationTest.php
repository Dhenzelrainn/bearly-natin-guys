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

    public function test_forward_geocoding_centers_only_on_a_matching_philippine_locality(): void
    {
        config(['maps.maptiler_api_key' => 'test-maptiler-key']);
        Http::fake([
            'api.maptiler.com/geocoding/*' => Http::response([
                'features' => [[
                    'place_name' => 'Maple Street, Balibago, Santa Rosa, Laguna, Philippines',
                    'place_type' => ['address'],
                    'center' => [121.1112, 14.3121],
                ]],
            ]),
        ]);
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('maps.forward-geocode', [
                'street' => 'Maple Street',
                'barangay' => 'Balibago',
                'city' => 'Santa Rosa',
                'province' => 'Laguna',
                'postal' => '4026',
            ]))
            ->assertOk()
            ->assertJsonPath('data.latitude', 14.3121)
            ->assertJsonPath('data.longitude', 121.1112)
            ->assertJsonPath('data.approximate', false);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'country=ph')
            && str_contains($request->url(), 'key=test-maptiler-key'));
    }

    public function test_forward_geocoding_rejects_invalid_or_non_philippine_results(): void
    {
        config(['maps.maptiler_api_key' => 'test-maptiler-key']);
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('maps.forward-geocode', [
                'city' => 'Santa Rosa',
                'province' => 'Laguna',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('barangay');

        Http::fake([
            'api.maptiler.com/geocoding/*' => Http::response([
                'features' => [[
                    'place_name' => 'Paris, France',
                    'place_type' => ['place'],
                    'center' => [2.3522, 48.8566],
                ]],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson(route('maps.forward-geocode', [
                'barangay' => 'Balibago',
                'city' => 'Santa Rosa',
                'province' => 'Laguna',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No Philippine map result matched the selected barangay, city, and province. Refine the address or place the pin manually.');
    }

    public function test_forward_geocoding_provider_failure_is_reported_without_changing_fields(): void
    {
        config(['maps.maptiler_api_key' => 'test-maptiler-key']);
        Http::fake([
            'api.maptiler.com/geocoding/*' => Http::response([], 503),
        ]);
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('maps.forward-geocode', [
                'barangay' => 'Balibago',
                'city' => 'Santa Rosa',
                'province' => 'Laguna',
            ]))
            ->assertStatus(502)
            ->assertJsonPath('message', 'MapTiler could not find this address. Place the pin manually.');
    }
}
