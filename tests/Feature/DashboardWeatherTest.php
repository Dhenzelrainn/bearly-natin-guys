<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardWeatherTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
        $this->buyer = User::factory()->create(['name' => 'Weather Buyer', 'role' => 'buyer', 'status' => 'active']);
        config([
            'services.weatherapi.key' => 'test-weather-key',
            'services.weatherapi.location' => 'Manila, Philippines',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_current_weather_is_proxied_as_a_whitelisted_dashboard_json_response(): void
    {
        $payload = $this->weatherPayload();
        Http::fake([
            'https://api.weatherapi.com/v1/current.json*' => Http::response($payload, 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('dashboard.weather.current'))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'location' => 'Manila, Metro Manila',
                    'temperature_c' => 29.4,
                    'condition' => 'Light rain',
                    'feels_like_c' => 31.2,
                    'wind_kph' => 12.6,
                    'humidity_percent' => 74,
                    'pressure_mb' => 1009.8,
                    'updated_at' => '3:30 PM',
                ],
                'message' => 'Current weather loaded.',
            ]);

        $response->assertDontSee('test-weather-key');

        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://api.weatherapi.com/v1/current.json')
            && $request['key'] === 'test-weather-key'
            && $request['q'] === 'Manila, Philippines'
            && $request['aqi'] === 'no');
    }

    public function test_weather_endpoint_allows_operational_roles_but_not_buyers_or_guests(): void
    {
        Http::fake([
            'https://api.weatherapi.com/v1/current.json*' => Http::response($this->weatherPayload(), 200),
        ]);

        $this->getJson(route('dashboard.weather.current'))->assertUnauthorized();

        foreach (['admin', 'seller', 'logistics', 'rider'] as $role) {
            $user = $role === 'admin'
                ? $this->admin
                : User::factory()->create(['name' => "{$role} weather user", 'role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->getJson(route('dashboard.weather.current'))
                ->assertOk()
                ->assertJsonPath('data.condition', 'Light rain');
        }

        $this->actingAs($this->buyer)->getJson(route('dashboard.weather.current'))->assertForbidden();

        Http::assertSentCount(1);
    }

    public function test_missing_key_or_provider_failure_returns_a_safe_unavailable_state(): void
    {
        Http::fake([
            'https://api.weatherapi.com/v1/current.json*' => Http::response(['error' => ['message' => 'provider detail']], 503),
        ]);

        config(['services.weatherapi.key' => '']);
        $this->actingAs($this->admin)
            ->getJson(route('dashboard.weather.current'))
            ->assertOk()
            ->assertExactJson([
                'data' => null,
                'message' => 'Weather is temporarily unavailable.',
            ]);
        Http::assertNothingSent();

        config(['services.weatherapi.key' => 'test-weather-key']);
        $this->actingAs($this->admin)
            ->getJson(route('dashboard.weather.current'))
            ->assertOk()
            ->assertExactJson([
                'data' => null,
                'message' => 'Weather is temporarily unavailable.',
            ])
            ->assertDontSee('provider detail')
            ->assertDontSee('test-weather-key');
    }

    public function test_current_weather_is_cached_for_repeated_dashboard_requests(): void
    {
        $calls = 0;
        $payload = $this->weatherPayload();

        Http::fake(function (ClientRequest $request) use (&$calls, $payload) {
            $calls++;

            return Http::response($payload, 200);
        });

        $this->actingAs($this->admin)->getJson(route('dashboard.weather.current'))->assertOk();
        $this->getJson(route('dashboard.weather.current'))->assertOk();

        $this->assertSame(1, $calls);
    }

    /** @return array<string, mixed> */
    private function weatherPayload(): array
    {
        return [
            'location' => [
                'name' => 'Manila',
                'region' => 'Metro Manila',
                'country' => 'Philippines',
                'tz_id' => 'Asia/Manila',
                'localtime_epoch' => (new DateTimeImmutable('2026-10-17 07:30:00 UTC'))->getTimestamp(),
            ],
            'current' => [
                'temp_c' => 29.4,
                'feelslike_c' => 31.2,
                'wind_kph' => 12.6,
                'humidity' => 74,
                'pressure_mb' => 1009.8,
                'condition' => ['text' => 'Light rain'],
            ],
        ];
    }
}
