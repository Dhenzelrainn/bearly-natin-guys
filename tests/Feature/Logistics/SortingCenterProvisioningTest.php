<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\SortingCenter;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SortingCenterProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_approved_logistics_gets_one_sorting_center_using_normalized_address(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'status' => AccountStatus::Active->value,
        ]);

        $logistics = User::factory()->create([
            'name' => 'Laguna Logistics',
            'first_name' => 'Lara',
            'last_name' => 'Santos',
            'email' => 'laguna-logistics@example.test',
            'contact_number' => '09181234567',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Pending->value,
            'business_name' => 'Laguna Logistics',
        ]);

        $service = app(RegistrationLifecycleService::class);

        $application = $service->recordPendingApplication(
            $logistics,
            UserRole::Logistics->value,
            [
                'house_number' => '8',
                'street' => 'Guevara Avenue',
                'barangay' => 'Poblacion',
                'municipality' => 'Santa Cruz',
                'province' => 'Laguna',
                'postal_code' => '4009',
            ],
            [
                'business_name' => 'Laguna Logistics',
            ]
        );

        foreach ([
            'government_id',
            'business_permit',
        ] as $documentType) {
            $application->documents()->create([
                'document_type' => $documentType,
                'file_path' => "test/{$documentType}.pdf",
                'original_name' => "{$documentType}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'verification_status' => 'verified',
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);
        }

        $service->approve(
            $logistics,
            $admin
        );

        $logistics->refresh();

        $profile = $logistics->logisticsProfile;

        $this->assertNotNull($profile);

        $this->assertDatabaseCount(
            'sorting_centers',
            1
        );

        $center = SortingCenter::query()
            ->where(
                'logistics_profile_id',
                $profile->id
            )
            ->sole();

        $address = Address::query()
            ->where('user_id', $logistics->id)
            ->where('label', 'Sorting center')
            ->sole();

        $this->assertSame(
            $profile->id,
            $center->logistics_profile_id
        );

        $this->assertSame(
            $address->id,
            $center->address_id
        );

        $this->assertSame(
            'Laguna Logistics',
            $center->name
        );

        $this->assertSame(
            'SC-' . $profile->id,
            $center->code
        );

        $this->assertSame(
            '09181234567',
            $center->contact_phone
        );

        $this->assertSame(
            'active',
            $center->status
        );

        $this->assertNull(
            $center->operating_hours
        );

        $this->assertNull(
            $center->daily_capacity
        );

        try {
            SortingCenter::query()->create([
                'logistics_profile_id' => $profile->id,
                'address_id' => $address->id,
                'name' => 'Second Logistics Center',
                'code' => 'SC-DUPLICATE',
                'status' => 'active',
            ]);

            $this->fail('A Logistics account must not have a second Sorting Center.');
        } catch (QueryException) {
            $this->assertDatabaseCount('sorting_centers', 1);
        }
    }

    public function test_reprovisioning_does_not_duplicate_center_or_erase_facility_settings(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'status' => AccountStatus::Active->value,
        ]);

        $logistics = User::factory()->create([
            'name' => 'South Hub Logistics',
            'first_name' => 'Marco',
            'last_name' => 'Reyes',
            'email' => 'south-hub@example.test',
            'contact_number' => '09190000001',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Pending->value,
            'business_name' => 'South Hub Logistics',
        ]);

        $service = app(RegistrationLifecycleService::class);

        $application = $service->recordPendingApplication(
            $logistics,
            UserRole::Logistics->value,
            [
                'street' => 'South Hub Road',
                'barangay' => 'San Rafael',
                'municipality' => 'San Pablo City',
                'province' => 'Laguna',
            ],
            [
                'business_name' => 'South Hub Logistics',
            ]
        );

        foreach ([
            'government_id',
            'business_permit',
        ] as $documentType) {
            $application->documents()->create([
                'document_type' => $documentType,
                'file_path' => "test/{$documentType}.pdf",
                'original_name' => "{$documentType}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'verification_status' => 'verified',
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);
        }

        $service->approve(
            $logistics,
            $admin
        );

        $logistics->refresh();

        $profile = $logistics->logisticsProfile;

        $center = $profile
            ->sortingCenters()
            ->sole();

        $originalCenterId = $center->id;

        $center->update([
            'operating_hours' => '08:00-18:00',
            'daily_capacity' => 500,
        ]);

        /*
         * Registering a Rider under the same Logistics provider invokes
         * ensureLogisticsProfile() again for the sponsor. This gives us
         * a real public workflow for verifying that provisioning is
         * idempotent without calling private service methods directly.
         */
        $rider = User::factory()->create([
            'name' => 'Test Rider',
            'first_name' => 'Test',
            'last_name' => 'Rider',
            'email' => 'rider-reprovision@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'logistics_id' => $logistics->id,
        ]);

        $service->recordPendingApplication(
            $rider,
            UserRole::Rider->value,
            [
                'street' => 'Rider Road',
                'barangay' => 'San Rafael',
                'municipality' => 'San Pablo City',
                'province' => 'Laguna',
            ],
            [],
            [],
            $logistics->id
        );

        $this->assertSame(
            1,
            SortingCenter::query()
                ->where(
                    'logistics_profile_id',
                    $profile->id
                )
                ->count()
        );

        $center->refresh();

        $this->assertSame(
            $originalCenterId,
            $center->id
        );

        $this->assertSame(
            '08:00-18:00',
            $center->operating_hours
        );

        $this->assertSame(
            500,
            $center->daily_capacity
        );
    }

    public function test_logistics_without_a_verified_service_city_cannot_sponsor_a_rider(): void
    {
        $logistics = User::factory()->create([
            'name' => 'Legacy Logistics',
            'first_name' => 'Legacy',
            'last_name' => 'Operator',
            'email' => 'legacy-logistics@example.test',
            'contact_number' => '09195550000',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => 'Legacy Logistics',
            'street_address' => 'Legacy Street',
            'barangay' => 'Poblacion',
            'city' => 'Calamba City',
            'province' => 'Laguna',
        ]);

        $rider = User::factory()->create([
            'name' => 'Legacy Rider',
            'first_name' => 'Legacy',
            'last_name' => 'Rider',
            'email' => 'legacy-rider@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'logistics_id' => $logistics->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The selected Logistics partner is not configured for a service city.');

        app(RegistrationLifecycleService::class)
            ->recordPendingApplication(
                $rider,
                UserRole::Rider->value,
                [
                    'street' => 'Rider Street',
                    'barangay' => 'Poblacion',
                    'municipality' => 'Calamba City',
                    'province' => 'Laguna',
                ],
                [],
                [],
                $logistics->id
            );
    }
}
