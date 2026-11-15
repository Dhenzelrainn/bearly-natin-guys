<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WeatherApiService
{
    private const ENDPOINT = 'https://api.weatherapi.com/v1/current.json';

    private const CACHE_MINUTES = 15;

    /**
     * Fetch current conditions for the configured fixed location.
     *
     * Only the small display payload is returned; provider credentials and
     * the raw provider response never leave the server.
     *
     * @return array{location: string, temperature_c: float, condition: string, feels_like_c: float|null, wind_kph: float|null, humidity_percent: int|null, pressure_mb: float|null, updated_at: string|null}|null
     */
    public function currentConditions(): ?array
    {
        $apiKey = trim((string) config('services.weatherapi.key', ''));
        $location = trim((string) config('services.weatherapi.location', 'Manila, Philippines'));

        if ($apiKey === '' || $location === '') {
            return null;
        }

        $cacheKey = 'admin.weatherapi.current.'.hash('sha256', mb_strtolower($location));
        $result = Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($apiKey, $location): array {
            try {
                $response = Http::acceptJson()
                    ->connectTimeout(3)
                    ->timeout(6)
                    ->get(self::ENDPOINT, [
                        'key' => $apiKey,
                        'q' => $location,
                        'aqi' => 'no',
                    ]);

                if (! $response->successful()) {
                    return ['available' => false];
                }

                $payload = $response->json();
                $providerLocation = is_array($payload) ? ($payload['location'] ?? null) : null;
                $current = is_array($payload) ? ($payload['current'] ?? null) : null;
                $condition = is_array($current) ? ($current['condition'] ?? null) : null;
                $name = is_array($providerLocation) ? trim((string) ($providerLocation['name'] ?? '')) : '';
                $region = is_array($providerLocation) ? trim((string) ($providerLocation['region'] ?? '')) : '';
                $conditionText = is_array($condition) ? trim((string) ($condition['text'] ?? '')) : '';
                $temperature = is_array($current) ? ($current['temp_c'] ?? null) : null;

                if ($name === '' || $conditionText === '' || ! is_numeric($temperature)) {
                    return ['available' => false];
                }

                $locationLabel = $name;
                if ($region !== '' && mb_strtolower($region) !== mb_strtolower($name)) {
                    $locationLabel .= ', '.$region;
                }

                $timezone = is_array($providerLocation) ? (string) ($providerLocation['tz_id'] ?? '') : '';
                if (! in_array($timezone, timezone_identifiers_list(), true)) {
                    $timezone = 'Asia/Manila';
                }

                $updatedAtEpoch = is_array($providerLocation) ? ($providerLocation['localtime_epoch'] ?? null) : null;
                $updatedAt = is_numeric($updatedAtEpoch)
                    ? (new DateTimeImmutable('@'.(int) $updatedAtEpoch))
                        ->setTimezone(new DateTimeZone($timezone))
                        ->format('g:i A')
                    : null;

                return [
                    'available' => true,
                    'data' => [
                        'location' => Str::limit($locationLabel, 100, ''),
                        'temperature_c' => round((float) $temperature, 1),
                        'condition' => Str::limit($conditionText, 100, ''),
                        'feels_like_c' => is_numeric($current['feelslike_c'] ?? null)
                            ? round((float) $current['feelslike_c'], 1)
                            : null,
                        'wind_kph' => is_numeric($current['wind_kph'] ?? null)
                            ? round((float) $current['wind_kph'], 1)
                            : null,
                        'humidity_percent' => is_numeric($current['humidity'] ?? null)
                            ? (int) round((float) $current['humidity'])
                            : null,
                        'pressure_mb' => is_numeric($current['pressure_mb'] ?? null)
                            ? round((float) $current['pressure_mb'], 1)
                            : null,
                        'updated_at' => $updatedAt,
                    ],
                ];
            } catch (ConnectionException) {
                return ['available' => false];
            }
        });

        if (! is_array($result) || ! ($result['available'] ?? false) || ! is_array($result['data'] ?? null)) {
            return null;
        }

        return $result['data'];
    }
}
