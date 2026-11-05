<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class CourierDemoSeeder extends Seeder
{
    private const EMAIL = 'courier.demo@bearly.test';

    private const APPLICATION_NO = 'DEMO-COURIER-0001';

    private const PASSWORD = 'Password123';

    private const PHONE = '+639170000001';

    private const PLATE_NUMBER = 'DEMO-COURIER-001';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'CourierDemoSeeder may only run in local or testing environments.'
            );
        }

        [$sponsorProfile, $sponsorAddress] = $this->sponsoringLogisticsProvider();
        $sponsor = $sponsorProfile->user;

        if (! $sponsor) {
            throw new RuntimeException(
                'The active Logistics sponsor has no user account.'
            );
        }

        DB::transaction(function () use ($sponsorProfile, $sponsorAddress, $sponsor): void {
            $riderRole = Role::query()->firstOrCreate(
                ['name' => UserRole::Rider->value],
                ['display_name' => 'Rider'],
            );

            $user = User::withTrashed()
                ->where('email', self::EMAIL)
                ->first();

            if ($user?->trashed()) {
                throw new RuntimeException(
                    'The reserved Courier demo account is soft-deleted. Restore or replace it before reseeding.'
                );
            }

            if ($user && $user->role !== UserRole::Rider->value) {
                throw new RuntimeException(
                    'The reserved Courier demo email is already used by a non-Rider account.'
                );
            }

            $now = now();
            $name = 'Demo Courier';
            $cityCode = (string) $sponsorProfile->psgc_city_code;
            $streetAddress = '1 Demo Courier Road';

            $userAttributes = [
                'name' => $name,
                'first_name' => 'Demo',
                'last_name' => 'Courier',
                'middle_initial' => null,
                'sex' => 'prefer_not_to_say',
                'birthday' => '1995-01-01',
                'email' => self::EMAIL,
                'email_verified_at' => $now,
                'phone' => self::PHONE,
                'contact_number' => self::PHONE,
                'role' => UserRole::Rider->value,
                'status' => AccountStatus::Active->value,
                'province' => $sponsorAddress->province,
                'city' => $sponsorAddress->city_municipality,
                'barangay' => $sponsorAddress->barangay,
                'street_address' => $streetAddress,
                'vehicle_type' => RiderWorkType::vehicleTypes(RiderWorkType::Courier)[0],
                'plate_number' => self::PLATE_NUMBER,
                'logistics_id' => $sponsor->id,
                'approved_by' => $sponsor->id,
                'approved_at' => $now,
                'terms_accepted_at' => $now,
                'terms_version' => config('bearly-policies.version'),
                'privacy_version' => config('bearly-policies.version'),
                'password' => Hash::make(self::PASSWORD),
            ];

            if ($user) {
                $user->forceFill($userAttributes)->save();
            } else {
                $user = User::query()->create($userAttributes);
            }

            $user->roles()->syncWithoutDetaching([
                $riderRole->id => [
                    'assigned_by' => $sponsor->id,
                    'assigned_at' => $now,
                ],
            ]);

            Address::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'label' => 'Home',
                ],
                [
                    'recipient_name' => $name,
                    'phone' => self::PHONE,
                    'house_number' => '1',
                    'street' => 'Demo Courier Road',
                    'barangay' => $sponsorAddress->barangay,
                    'city_municipality' => $sponsorAddress->city_municipality,
                    'province' => $sponsorAddress->province,
                    'postal_code' => $sponsorAddress->postal_code,
                    'psgc_province_code' => $sponsorAddress->psgc_province_code,
                    'psgc_city_code' => $cityCode,
                    'psgc_barangay_code' => $sponsorAddress->psgc_barangay_code,
                    'is_default_shipping' => false,
                    'is_default_pickup' => false,
                ],
            );

            $application = AccountApplication::query()
                ->where('application_no', self::APPLICATION_NO)
                ->first();

            if ($application && (int) $application->user_id !== (int) $user->id) {
                throw new RuntimeException(
                    'The reserved Courier demo application number is already assigned to another account.'
                );
            }

            $application ??= new AccountApplication;
            $application->forceFill([
                'application_no' => self::APPLICATION_NO,
                'user_id' => $user->id,
                'requested_role_id' => $riderRole->id,
                'sponsor_logistics_profile_id' => $sponsorProfile->id,
                'requested_work_type' => RiderWorkType::Courier->value,
                'rider_vehicle_model' => 'Demo four-wheel van',
                'status' => 'approved',
                'submitted_at' => $now,
                'review_started_at' => $now,
                'decided_at' => $now,
                'reviewed_by' => $sponsor->id,
                'decision_reason' => null,
                'revision_notes' => 'Local/testing demo fixture only; no identity documents were reviewed.',
            ])->save();

            $profile = RiderProfile::withTrashed()
                ->where('user_id', $user->id)
                ->first();

            if ($profile?->trashed()) {
                $profile->restore();
            }

            $profile ??= new RiderProfile;
            $profile->forceFill([
                'user_id' => $user->id,
                'logistics_profile_id' => $sponsorProfile->id,
                'home_sorting_center_id' => $sponsorProfile->sortingCenters()
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->value('id'),
                'vehicle_type' => RiderWorkType::vehicleTypes(RiderWorkType::Courier)[0],
                'vehicle_model' => 'Demo four-wheel van',
                'plate_number' => self::PLATE_NUMBER,
                'parcel_capacity' => null,
                'availability_status' => 'available',
                'verification_status' => 'approved',
                'work_type' => RiderWorkType::Courier->value,
                'linehaul_parcel_capacity' => 10,
                'courier_vehicle_verified_by' => $sponsor->id,
                'courier_vehicle_verified_at' => $now,
                'specialization' => 'both',
            ])->save();
        });

        $this->command?->info('Local demo Courier account seeded.');
        $this->command?->line('Email: '.self::EMAIL);
        $this->command?->line('Password: '.self::PASSWORD);
        $this->command?->line('Sponsor: '.$sponsorProfile->display_name.' ('.$sponsor->email.')');
        $this->command?->warn('Demo-only approval metadata; no real identity documents were reviewed.');
    }

    /** @return array{0: LogisticsProfile, 1: Address} */
    private function sponsoringLogisticsProvider(): array
    {
        $profiles = LogisticsProfile::query()
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('psgc_city_code')
            ->whereHas('user', fn ($query) => $query
                ->where('role', UserRole::Logistics->value)
                ->where('status', AccountStatus::Active->value))
            ->with(['user.addresses', 'sortingCenters'])
            ->orderBy('id')
            ->get();

        foreach ($profiles as $profile) {
            $cityCode = (string) $profile->psgc_city_code;
            $address = $profile->user?->addresses
                ?->first(fn (Address $candidate): bool => (string) $candidate->psgc_city_code === $cityCode
                );

            if ($address) {
                return [$profile, $address];
            }
        }

        throw new RuntimeException(
            'CourierDemoSeeder needs an active Logistics account with an active Logistics profile and a service address matching its PSGC city code. Create or approve that Logistics account first.'
        );
    }
}
