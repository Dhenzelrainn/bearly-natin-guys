<?php

namespace App\Http\Controllers;

use App\Services\WeatherApiService;
use Illuminate\Http\JsonResponse;

class DashboardWeatherController extends Controller
{
    public function __invoke(WeatherApiService $weather): JsonResponse
    {
        $data = $weather->currentConditions();

        return response()->json([
            'data' => $data,
            'message' => $data === null
                ? 'Weather is temporarily unavailable.'
                : 'Current weather loaded.',
        ]);
    }
}
