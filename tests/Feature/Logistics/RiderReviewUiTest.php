<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderReviewUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_rider_review_page_shows_document_review_actions_and_disables_approval_until_all_documents_are_verified(): void
    {
        $provider = $this->makeLogisticsProvider();

        $rider = User::factory()->create([
            'name' => 'UI Test Rider',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'email' => 'ui-rider@example.test',
            'logistics_id' => $provider['user']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'UI-1234',
        ]);

        $application = AccountApplication::query()->create([
            'application_no' => 'APP-RIDER-UI',
            'user_id' => $rider->id,
            'requested_role_id' => Role::where(
                'name',
                UserRole::Rider->value
            )->value('id'),
            'sponsor_logistics_profile_id' => $provider['profile']->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $driverLicense = $application
            ->documents()
            ->create([
                'document_type' => 'driver_license',
                'file_path' => 'test/ui-driver-license.pdf',
                'original_name' => 'ui-driver-license.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'verification_status' => 'pending',
            ]);

        $orCr = $application
            ->documents()
            ->create([
                'document_type' => 'or_cr',
                'file_path' => 'test/ui-or-cr.pdf',
                'original_name' => 'ui-or-cr.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'verification_status' => 'pending',
            ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(
                route(
                    'logistics.riders.show',
                    $rider->id
                )
            );

        $response->assertOk();

        $response->assertSee(
            "Driver's License / ID"
        );

        $response->assertSee(
            'Vehicle OR/CR'
        );

        $response->assertSee(
            'ui-driver-license.pdf'
        );

        $response->assertSee(
            'ui-or-cr.pdf'
        );

        $response->assertSee(
            route(
                'logistics.rider-documents.show',
                [
                    'application' => $application->id,
                    'document' => $driverLicense->id,
                ]
            ),
            false
        );

        $response->assertSee(
            route(
                'logistics.rider-documents.show',
                [
                    'application' => $application->id,
                    'document' => $orCr->id,
                ]
            ),
            false
        );

        $response->assertSee(
            route(
                'logistics.rider-documents.update',
                [
                    'application' => $application->id,
                    'document' => $driverLicense->id,
                ]
            ),
            false
        );

        $response->assertSee(
            route(
                'logistics.rider-documents.update',
                [
                    'application' => $application->id,
                    'document' => $orCr->id,
                ]
            ),
            false
        );

        $response->assertSee('Verify');
        $response->assertSee('Reject');
        $response->assertSee('Approve rider');

        $approvalButton = $this->extractApprovalButton(
            $response->getContent()
        );

        $this->assertStringContainsString(
            'disabled',
            $approvalButton
        );

        $this->assertStringContainsString(
            'Verify both the Driver',
            $approvalButton
        );
    }

    public function test_rider_review_page_enables_approval_when_both_required_documents_are_verified(): void
    {
        $provider = $this->makeLogisticsProvider();

        $rider = User::factory()->create([
            'name' => 'Verified UI Rider',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'email' => 'verified-ui-rider@example.test',
            'logistics_id' => $provider['user']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'UI-5678',
        ]);

        $application = AccountApplication::query()->create([
            'application_no' => 'APP-RIDER-UI-VERIFIED',
            'user_id' => $rider->id,
            'requested_role_id' => Role::where(
                'name',
                UserRole::Rider->value
            )->value('id'),
            'sponsor_logistics_profile_id' => $provider['profile']->id,
            'status' => 'under_review',
            'submitted_at' => now(),
            'review_started_at' => now(),
            'reviewed_by' => $provider['user']->id,
        ]);

        foreach (
            [
                'driver_license',
                'or_cr',
            ] as $documentType
        ) {
            $application
                ->documents()
                ->create([
                    'document_type' => $documentType,
                    'file_path' =>
                        "test/{$documentType}.pdf",
                    'original_name' =>
                        "{$documentType}.pdf",
                    'mime_type' =>
                        'application/pdf',
                    'size_bytes' =>
                        100,
                    'verification_status' =>
                        'verified',
                    'verified_by' =>
                        $provider['user']->id,
                    'verified_at' =>
                        now(),
                ]);
        }

        $response = $this
            ->actingAs($provider['user'])
            ->get(
                route(
                    'logistics.riders.show',
                    $rider->id
                )
            );

        $response->assertOk();

        $response->assertSee(
            'Needs Review'
        );

        $response->assertSee(
            'Approve rider'
        );

        $approvalButton = $this->extractApprovalButton(
            $response->getContent()
        );

        $this->assertStringNotContainsString(
            'disabled',
            $approvalButton
        );

        $this->assertStringNotContainsString(
            'Verify both the Driver',
            $approvalButton
        );
    }

    public function test_pending_rider_keeps_read_only_vehicle_panel_without_assignment_controls(): void
    {
        $provider = $this->makeLogisticsProvider();

        $rider = User::factory()->create([
            'name' => 'Pending Assignment Rider',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'email' => 'pending-assignment@example.test',
            'logistics_id' => $provider['user']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'PENDING-1',
        ]);

        AccountApplication::query()->create([
            'application_no' => 'APP-PENDING-ASSIGNMENT',
            'user_id' => $rider->id,
            'requested_role_id' => Role::where(
                'name',
                UserRole::Rider->value
            )->value('id'),
            'sponsor_logistics_profile_id' => $provider['profile']->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.riders.show', $rider->id));

        $response
            ->assertOk()
            ->assertSee('Vehicle and assignment')
            ->assertSee('PENDING-1')
            ->assertDontSee('name="home_sorting_center_id"', false)
            ->assertDontSee('name="current_zone_id"', false)
            ->assertDontSee('Save assignment');
    }

    public function test_approved_rider_shows_the_automatic_home_area_match_and_keeps_documents_panel(): void
    {
        $provider = $this->makeLogisticsProvider();
        $otherProvider = $this->makeLogisticsProvider(
            'Other UI Logistics',
            'other-ui-logistics@example.test'
        );

        $activeCenter = $this->makeSortingCenter(
            $provider,
            'Active UI Center',
            'UI-ACTIVE'
        );
        $foreignCenter = $this->makeSortingCenter(
            $otherProvider,
            'Foreign UI Center',
            'UI-FOREIGN'
        );

        $activeZone = SortingZone::query()->create([
            'sorting_center_id' => $activeCenter->id,
            'code' => 'UI-ZONE-ACTIVE',
            'name' => 'Active UI Zone',
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'San Pablo City',
                'psgc_city_code' => '0403424000',
                'barangays' => ['San Rafael'],
            ],
            'status' => 'active',
        ]);
        SortingZone::query()->create([
            'sorting_center_id' => $activeCenter->id,
            'code' => 'UI-ZONE-INACTIVE',
            'name' => 'Inactive UI Zone',
            'destination_rules' => [],
            'status' => 'inactive',
        ]);

        $rider = User::factory()->create([
            'name' => 'Approved Assignment Rider',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Active->value,
            'email' => 'approved-assignment@example.test',
            'logistics_id' => $provider['user']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'APPROVED-1',
        ]);

        $application = AccountApplication::query()->create([
            'application_no' => 'APP-APPROVED-ASSIGNMENT',
            'user_id' => $rider->id,
            'requested_role_id' => Role::where(
                'name',
                UserRole::Rider->value
            )->value('id'),
            'sponsor_logistics_profile_id' => $provider['profile']->id,
            'status' => 'approved',
            'submitted_at' => now(),
            'decided_at' => now(),
            'reviewed_by' => $provider['user']->id,
        ]);

        $application->documents()->create([
            'document_type' => 'driver_license',
            'file_path' => 'test/approved-license.pdf',
            'original_name' => 'approved-license.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'verification_status' => 'verified',
        ]);

        Address::query()->create([
            'user_id' => $rider->id,
            'label' => 'Home',
            'recipient_name' => $rider->name,
            'phone' => '09170000001',
            'street' => 'Rider Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'psgc_city_code' => '0403424000',
        ]);

        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider['profile']->id,
            'home_sorting_center_id' => $activeCenter->id,
            'current_zone_id' => $activeZone->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'APPROVED-1',
            'availability_status' => 'offline',
            'verification_status' => 'approved',
        ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.riders.show', $rider->id));

        $response
            ->assertOk()
            ->assertSee('Current assignment')
            ->assertSee('Refresh home-area match')
            ->assertSee('Active UI Center')
            ->assertSee('Active UI Zone')
            ->assertDontSee('Save assignment')
            ->assertDontSee('name="home_sorting_center_id"', false)
            ->assertDontSee('name="current_zone_id"', false)
            ->assertSee('Submitted documents')
            ->assertSee('approved-license.pdf')
            ->assertDontSee('Inactive UI Zone')
            ->assertDontSee('Foreign UI Center');

        $this->assertNotSame(
            $foreignCenter->id,
            $activeCenter->id
        );
    }

    /**
     * @return array{
     *     user: User,
     *     profile: LogisticsProfile
     * }
     */
    private function makeLogisticsProvider(
        string $name = 'UI Logistics',
        string $email = 'ui-logistics@example.test'
    ): array
    {
        $location = str_contains(strtolower($name), 'other')
            ? ['code' => '0403405000', 'city' => 'Calamba City', 'postal_code' => '4027']
            : ['code' => '0403424000', 'city' => 'San Pablo City', 'postal_code' => '4000'];
        $user = User::factory()->create([
            'name' => $name,
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'email' => $email,
            'business_name' => $name,
        ]);

        $roleId = Role::where(
            'name',
            UserRole::Logistics->value
        )->value('id');

        $user->roles()->syncWithoutDetaching([
            $roleId,
        ]);

        $profile = LogisticsProfile::query()
            ->create([
                'user_id' => $user->id,
                'legal_name' => $name.' Incorporated',
                'display_name' => $name,
                'contact_phone' => '09170000001',
                'psgc_city_code' => $location['code'],
                'status' => 'active',
            ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => $name,
            'phone' => '09170000001',
            'street' => 'Service Road',
            'barangay' => 'San Rafael',
            'city_municipality' => $location['city'],
            'province' => 'Laguna',
            'postal_code' => $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        return compact(
            'user',
            'profile'
        );
    }

    private function makeSortingCenter(
        array $provider,
        string $name,
        string $code,
        string $status = 'active'
    ): SortingCenter {
        $location = $provider['profile']->psgc_city_code === '0403405000'
            ? ['code' => '0403405000', 'city' => 'Calamba City', 'postal_code' => '4027']
            : ['code' => '0403424000', 'city' => 'San Pablo City', 'postal_code' => '4000'];
        $address = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => $name,
            'recipient_name' => $provider['user']->name,
            'phone' => '09170000001',
            'street' => 'UI Hub Road',
            'barangay' => 'San Rafael',
            'city_municipality' => $location['city'],
            'province' => 'Laguna',
            'postal_code' => $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        return SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $address->id,
            'name' => $name,
            'code' => $code,
            'contact_phone' => '09170000001',
            'status' => $status,
        ]);
    }

    private function extractApprovalButton(
        string $html
    ): string {
        $matched = preg_match(
            '/<button\b[^>]*>[\s\S]*?Approve rider[\s\S]*?<\/button>/i',
            $html,
            $matches
        );

        $this->assertSame(
            1,
            $matched,
            'Approve Rider button was not found in the rendered HTML.'
        );

        return $matches[0];
    }
}
