<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RiderSpecialization;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LogisticsProviderRecommendationService
{
    private const MAX_RECOMMENDATIONS = 8;

    private const MAX_RECOMMENDATION_DISTANCE_KM = 100.0;

    public function __construct(private readonly PostalCodeLookup $postalCodes) {}

    public function activeProviderForCity(string $cityCode, bool $lock = false): ?LogisticsProfile
    {
        $query = $this->activeProvidersQuery()
            ->where('psgc_city_code', $cityCode);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function activeProviderById(int $providerId, bool $lock = false): ?LogisticsProfile
    {
        $query = $this->activeProvidersQuery()
            ->whereKey($providerId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Recommend active Logistics providers using saved facility and Seller
     * coordinates when available. If coordinates are missing, only providers
     * with a matching canonical PSGC region (or city) are included and no
     * distance is claimed.
     *
     * @param  list<int>  $excludeProviderIds
     * @return Collection<int, array{
     *     id: int,
     *     name: string,
     *     location: string,
     *     distance_km: float|null,
     *     distance_label: string|null,
     *     available_rider_count: int
     * }>
     */
    public function recommendationsForAddress(Address $pickupAddress, array $excludeProviderIds = []): Collection
    {
        $pickupCoordinates = $this->coordinates($pickupAddress);
        $pickupCityCode = $this->canonicalCityCode($pickupAddress);
        $pickupRegionCode = $this->regionCode($pickupAddress->psgc_region_code, $pickupCityCode);
        $excluded = collect($excludeProviderIds)->map(fn ($id): int => (int) $id)->flip();

        $providers = $this->activeProvidersQuery()->get();

        if ($providers->isEmpty()) {
            return collect();
        }

        $riderCounts = $this->availablePickupRiderCounts($providers->pluck('id'));

        $options = $providers
            ->reject(fn (LogisticsProfile $provider): bool => $excluded->has((int) $provider->id))
            ->map(function (LogisticsProfile $provider) use ($pickupAddress, $pickupCoordinates, $pickupCityCode, $pickupRegionCode, $riderCounts): ?array {
                $location = $this->locationAddress($provider);
                $providerCoordinates = $location ? $this->coordinates($location) : null;
                $providerCityCode = $location
                    ? ($this->canonicalCityCode($location) ?: trim((string) $provider->psgc_city_code))
                    : trim((string) $provider->psgc_city_code);
                $providerRegionCode = $this->regionCode(
                    $location?->psgc_region_code ?: $provider->user?->region_code,
                    $providerCityCode,
                );
                $sameCity = $pickupCityCode !== '' && $providerCityCode === $pickupCityCode;
                $sameRegion = $pickupRegionCode !== null && $pickupRegionCode === $providerRegionCode;
                $distance = $pickupCoordinates && $providerCoordinates
                    ? $this->distanceKm($pickupCoordinates, $providerCoordinates)
                    : null;

                if (($distance === null && ! $sameCity && ! $sameRegion)
                    || ($distance !== null && $distance > self::MAX_RECOMMENDATION_DISTANCE_KM && ! $sameCity)) {
                    return null;
                }

                $roundedDistance = $distance === null ? null : round($distance, 1);
                $locationLabel = implode(', ', array_filter([
                    trim((string) $location?->city_municipality),
                    trim((string) $location?->province),
                ], fn (string $part): bool => $part !== ''));

                if ($locationLabel === '') {
                    $locationLabel = 'Service location not listed';
                }

                return [
                    'id' => (int) $provider->id,
                    'name' => (string) $provider->display_name,
                    'location' => $locationLabel,
                    'distance_km' => $roundedDistance,
                    'distance_label' => $roundedDistance === null
                        ? null
                        : 'About '.number_format($roundedDistance, 1).' km straight-line',
                    'available_rider_count' => (int) $riderCounts->get((int) $provider->id, 0),
                    '_same_city' => $sameCity,
                    '_same_region' => $sameRegion,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                $leftDistance = $left['distance_km'];
                $rightDistance = $right['distance_km'];

                if ($leftDistance !== null && $rightDistance !== null && $leftDistance !== $rightDistance) {
                    return $leftDistance <=> $rightDistance;
                }

                if (($leftDistance === null) !== ($rightDistance === null)) {
                    return $leftDistance === null ? 1 : -1;
                }

                if ($left['_same_city'] !== $right['_same_city']) {
                    return $left['_same_city'] ? -1 : 1;
                }

                if ($left['_same_region'] !== $right['_same_region']) {
                    return $left['_same_region'] ? -1 : 1;
                }

                return Str::lower($left['name']) <=> Str::lower($right['name']);
            })
            ->take(self::MAX_RECOMMENDATIONS)
            ->map(function (array $option): array {
                unset($option['_same_city'], $option['_same_region']);

                return $option;
            })
            ->values();

        return $options;
    }

    /** @return array{id: int, name: string, location: string, distance_km: float|null, distance_label: string|null, available_rider_count: int} */
    public function optionForProvider(Address $pickupAddress, LogisticsProfile $provider): array
    {
        $nearby = $this->recommendationsForAddress($pickupAddress);
        $option = $nearby->firstWhere('id', (int) $provider->id);

        if ($option) {
            return $option;
        }

        $location = $this->locationAddress($provider);
        $pickupCoordinates = $this->coordinates($pickupAddress);
        $providerCoordinates = $location ? $this->coordinates($location) : null;
        $distance = $pickupCoordinates && $providerCoordinates
            ? round($this->distanceKm($pickupCoordinates, $providerCoordinates), 1)
            : null;
        $riderCount = $this->availablePickupRiderCounts(collect([$provider->id]))
            ->get((int) $provider->id, 0);

        return [
            'id' => (int) $provider->id,
            'name' => (string) $provider->display_name,
            'location' => implode(', ', array_filter([
                trim((string) $location?->city_municipality),
                trim((string) $location?->province),
            ], fn (string $part): bool => $part !== '')) ?: 'Service location not listed',
            'distance_km' => $distance,
            'distance_label' => $distance === null ? null : 'About '.number_format($distance, 1).' km straight-line',
            'available_rider_count' => (int) $riderCount,
        ];
    }

    public function isNearby(Address $pickupAddress, LogisticsProfile $provider): bool
    {
        if ($this->recommendationsForAddress($pickupAddress)->contains(fn (array $option): bool => $option['id'] === (int) $provider->id)) {
            return true;
        }

        return false;
    }

    private function activeProvidersQuery(): Builder
    {
        return LogisticsProfile::query()
            ->where('status', 'active')
            ->whereHas('user', fn (Builder $query) => $query
                ->where('status', AccountStatus::Active->value)
                ->where(fn (Builder $roleQuery) => $roleQuery
                    ->where('role', UserRole::Logistics->value)
                    ->orWhereHas('roles', fn (Builder $roles) => $roles
                        ->where('roles.name', UserRole::Logistics->value))))
            ->with([
                'user',
                'serviceAddress',
                'sortingCenters' => fn ($query) => $query
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->with('address'),
            ])
            ->orderBy('display_name')
            ->orderBy('id');
    }

    /** @param Collection<int, int|string> $providerIds */
    private function availablePickupRiderCounts(Collection $providerIds): Collection
    {
        $ids = $providerIds->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return RiderProfile::query()
            ->selectRaw('logistics_profile_id, COUNT(*) as available_count')
            ->whereIn('logistics_profile_id', $ids)
            ->where('verification_status', 'approved')
            ->where(function ($query): void {
                $query->whereNull('work_type')
                    ->orWhere('work_type', RiderWorkType::Local->value);
            })
            ->whereIn('specialization', [
                RiderSpecialization::PickupOnly->value,
                RiderSpecialization::Both->value,
            ])
            ->where('availability_status', 'available')
            ->whereHas('user', fn (Builder $query) => $query
                ->where('status', AccountStatus::Active->value))
            ->groupBy('logistics_profile_id')
            ->pluck('available_count', 'logistics_profile_id');
    }

    private function locationAddress(LogisticsProfile $provider): ?Address
    {
        $facilityAddresses = $provider->sortingCenters
            ->map(fn ($center) => $center->address)
            ->filter();
        $addresses = $facilityAddresses->concat(collect([$provider->serviceAddress])->filter());

        return $addresses->first(fn (Address $address): bool => $this->coordinates($address) !== null)
            ?? $addresses->first();
    }

    /** @return array{0: float, 1: float}|null */
    private function coordinates(?Address $address): ?array
    {
        if (! $address || ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
            return null;
        }

        $latitude = (float) $address->latitude;
        $longitude = (float) $address->longitude;

        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return [$latitude, $longitude];
    }

    private function regionCode(?string $regionCode, string $cityCode): ?string
    {
        $regionCode = preg_replace('/\D/', '', (string) $regionCode) ?? '';

        if (preg_match('/^\d{10}$/', $regionCode) === 1 && substr($regionCode, 2) === '00000000') {
            return $regionCode;
        }

        $cityCode = preg_replace('/\D/', '', $cityCode) ?? '';

        return preg_match('/^\d{10}$/', $cityCode) === 1
            ? substr($cityCode, 0, 2).'00000000'
            : null;
    }

    private function canonicalCityCode(Address $address): string
    {
        try {
            return (string) ($this->postalCodes->canonicalCityCode(
                filled($address->psgc_city_code) ? trim((string) $address->psgc_city_code) : null,
                (string) $address->province,
                (string) $address->city_municipality,
            ) ?? '');
        } catch (ValidationException) {
            return '';
        }
    }

    /** @param array{0: float, 1: float} $from @param array{0: float, 1: float} $to */
    private function distanceKm(array $from, array $to): float
    {
        [$lat1, $lon1] = $from;
        [$lat2, $lon2] = $to;
        $earthRadiusKm = 6371.0088;
        $latitudeDelta = deg2rad($lat2 - $lat1);
        $longitudeDelta = deg2rad($lon2 - $lon1);
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($haversine), sqrt(max(0.0, 1 - $haversine)));
    }
}
