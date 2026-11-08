<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\LogisticsLocation;
use App\Models\LogisticsProfile;
use App\Models\User;
use App\Services\LogisticsLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogisticsLocationController extends Controller
{
    public function show(Request $request, LogisticsLocationService $locations): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        $location = $locations->freshLocation($profile);

        return $this->privateResponse([
            'location' => $location ? $this->locationPayload($location) : null,
            'shared' => $location !== null,
            'stale_after_seconds' => LogisticsLocationService::FRESHNESS_SECONDS,
        ], 'Logistics live location status retrieved.');
    }

    public function update(Request $request, LogisticsLocationService $locations): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'between:0,100000'],
        ]);

        $location = $locations->record(
            $profile,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['accuracy_meters']) ? (float) $validated['accuracy_meters'] : null,
        );

        return $this->privateResponse([
            'location' => $this->locationPayload($location),
            'shared' => true,
            'stale_after_seconds' => LogisticsLocationService::FRESHNESS_SECONDS,
        ], 'Logistics live location updated.');
    }

    public function destroy(Request $request, LogisticsLocationService $locations): JsonResponse
    {
        $locations->clear($this->authenticatedProfile($request));

        return $this->privateResponse([
            'location' => null,
            'shared' => false,
            'stale_after_seconds' => LogisticsLocationService::FRESHNESS_SECONDS,
        ], 'Logistics live location sharing stopped.');
    }

    private function authenticatedProfile(Request $request): LogisticsProfile
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $user->status === AccountStatus::Active->value
            && $user->hasRole(UserRole::Logistics->value),
            403,
        );

        $profile = $user->logisticsProfile()->first();

        abort_unless($profile, 403);

        return $profile;
    }

    /** @return array{latitude: float, longitude: float, accuracy_meters: float|null, last_seen_at: string|null} */
    private function locationPayload(LogisticsLocation $location): array
    {
        return [
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'accuracy_meters' => $location->accuracy_meters,
            'last_seen_at' => $location->last_seen_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function privateResponse(array $data, string $message): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'message' => $message,
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
