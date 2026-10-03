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

    public function test_approved_rider_shows_only_active_owned_assignment_options_and_keeps_documents_panel(): void
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
        $inactiveCenter = $this->makeSortingCenter(
            $provider,
            'Inactive UI Center',
            'UI-INACTIVE',
            'inactive'
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
            'destination_rules' => [],
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
            ->assertSee('name="home_sorting_center_id"', false)
            ->assertSee('name="current_zone_id"', false)
            ->assertSee('Active UI Center')
            ->assertSee('Active UI Zone')
            ->assertSee('Save assignment')
            ->assertSee('Submitted documents')
            ->assertSee('approved-license.pdf')
            ->assertDontSee('Inactive UI Center')
            ->assertDontSee('Inactive UI Zone')
            ->assertDontSee('Foreign UI Center');

        $this->assertStringContainsString(
            'selected',
            $response->getContent()
        );

        $this->assertNotSame(
            $inactiveCenter->id,
            $activeCenter->id
        );
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
                'status' => 'active',
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
        $address = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => $name,
            'recipient_name' => $provider['user']->name,
            'phone' => '09170000001',
            'street' => 'UI Hub Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
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
