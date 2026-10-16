<?php

namespace App\Services;

use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\SortingZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiderZoneAssignmentService
{
    public function __construct(
        private readonly SortingZoneMatcher $zoneMatcher,
        private readonly PostalCodeLookup $postalCodes,
    ) {}

    public function sync(RiderProfile $rider): RiderProfile
    {
        return DB::transaction(function () use ($rider): RiderProfile {
            $rider = RiderProfile::query()
                ->with('user')
                ->lockForUpdate()
                ->findOrFail($rider->id);

            $logisticsProfile = LogisticsProfile::query()
                ->find($rider->logistics_profile_id);

            $address = Address::query()
                ->where('user_id', $rider->user_id)
                ->where('label', 'Home')
                ->latest('id')
                ->first();

            $cityCode = $this->cityCodeFor($address);
            $zone = null;

            if (
                $address
                && $logisticsProfile
                && filled($logisticsProfile->psgc_city_code)
                && (string) $cityCode === (string) $logisticsProfile->psgc_city_code
            ) {
                $destination = [
                    'province' => (string) $address->province,
                    'city_municipality' => (string) $address->city_municipality,
                    'psgc_city_code' => (string) $cityCode,
                    'barangay' => (string) $address->barangay,
                ];

                $zone = SortingZone::query()
                    ->where('status', 'active')
                    ->whereHas('sortingCenter', fn ($query) => $query
                        ->where('logistics_profile_id', $logisticsProfile->id)
                        ->where('status', 'active'))
                    ->with('sortingCenter')
                    ->orderBy('sorting_center_id')
                    ->orderBy('id')
                    ->get()
                    ->first(fn (SortingZone $candidate): bool =>
                        $this->zoneMatcher->coversDestination(
                            $candidate->destination_rules,
                            $destination
                        )
                    );
            }

            $rider->forceFill([
                'home_sorting_center_id' => $zone?->sorting_center_id,
                'current_zone_id' => $zone?->id,
            ])->save();

            return $rider->refresh();
        }, attempts: 3);
    }

    public function syncForProfile(LogisticsProfile $profile): void
    {
        RiderProfile::query()
            ->where('logistics_profile_id', $profile->id)
            ->orderBy('id')
            ->chunkById(100, function ($riders): void {
                foreach ($riders as $rider) {
                    $this->sync($rider);
                }
            });
    }

    private function cityCodeFor(?Address $address): ?string
    {
        if (! $address) {
            return null;
        }

        try {
            return $this->postalCodes->canonicalCityCode(
                $address->psgc_city_code,
                (string) $address->province,
                (string) $address->city_municipality,
            );
        } catch (ValidationException) {
            return null;
        }
    }
}
