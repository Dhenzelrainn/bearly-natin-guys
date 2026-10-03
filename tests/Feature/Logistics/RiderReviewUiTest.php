<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\LogisticsProfile;
use App\Models\Role;
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

    /**
     * @return array{
     *     user: User,
     *     profile: LogisticsProfile
     * }
     */
    private function makeLogisticsProvider(): array
    {
        $user = User::factory()->create([
            'name' => 'UI Logistics',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'email' => 'ui-logistics@example.test',
            'business_name' => 'UI Logistics',
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
                'legal_name' => 'UI Logistics Incorporated',
                'display_name' => 'UI Logistics',
                'contact_phone' => '09170000001',
                'status' => 'active',
            ]);

        return compact(
            'user',
            'profile'
        );
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