<?php

namespace Tests\Feature;

use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationIdentityConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_duplicate_account_contact_values_across_roles(): void
    {
        User::factory()->create([
            'email' => 'first-buyer@example.test',
            'role' => 'buyer',
            'phone' => '+639171234567',
            'contact_number' => '+639171234567',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create([
            'email' => 'second-seller@example.test',
            'role' => 'seller',
            'phone' => '+639171234567',
            'contact_number' => '+639171234567',
        ]);
    }

    public function test_database_allows_multiple_login_only_users_without_phone_values(): void
    {
        User::factory()->create([
            'email' => 'login-only-one@example.test',
            'phone' => null,
            'contact_number' => null,
        ]);

        User::factory()->create([
            'email' => 'login-only-two@example.test',
            'phone' => null,
            'contact_number' => null,
        ]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_database_rejects_duplicate_rider_plates_on_users(): void
    {
        User::factory()->create([
            'email' => 'first-rider@example.test',
            'role' => 'rider',
            'plate_number' => 'DEMO-PLATE-1',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create([
            'email' => 'second-rider@example.test',
            'role' => 'rider',
            'plate_number' => 'DEMO-PLATE-1',
        ]);
    }

    public function test_database_rejects_duplicate_rider_profile_plates(): void
    {
        $logisticsUser = User::factory()->create([
            'email' => 'plate-logistics@example.test',
            'role' => 'logistics',
        ]);

        $logisticsProfile = LogisticsProfile::query()->create([
            'user_id' => $logisticsUser->id,
            'legal_name' => 'Plate Test Logistics',
            'display_name' => 'Plate Test Logistics',
            'contact_phone' => '+639171111111',
        ]);

        $firstRider = User::factory()->create([
            'email' => 'profile-rider-one@example.test',
            'role' => 'rider',
        ]);
        $secondRider = User::factory()->create([
            'email' => 'profile-rider-two@example.test',
            'role' => 'rider',
        ]);

        RiderProfile::query()->create([
            'user_id' => $firstRider->id,
            'logistics_profile_id' => $logisticsProfile->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'PROFILE-PLATE-1',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        RiderProfile::query()->create([
            'user_id' => $secondRider->id,
            'logistics_profile_id' => $logisticsProfile->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'PROFILE-PLATE-1',
        ]);
    }
}
