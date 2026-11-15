<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\ApplicationDocument;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RiderDocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_logistics_can_view_document_from_its_own_rider_application(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'provider-a@example.test'
        );

        $application = $this->makeRiderApplication(
            $provider,
            'A'
        );

        $document = $this->makeDocument(
            $application,
            'driver_license',
            'driver-license-a.pdf'
        );

        $response = $this
            ->actingAs($provider['user'])
            ->get(
                route(
                    'logistics.rider-documents.show',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                )
            );

        $response->assertOk();
    }

    public function test_logistics_cannot_view_or_update_another_providers_rider_document(): void
    {
        $providerA = $this->makeLogisticsProvider(
            'A',
            'provider-a@example.test'
        );

        $providerB = $this->makeLogisticsProvider(
            'B',
            'provider-b@example.test'
        );

        $application = $this->makeRiderApplication(
            $providerA,
            'FOREIGN'
        );

        $document = $this->makeDocument(
            $application,
            'driver_license',
            'foreign-license.pdf'
        );

        $this
            ->actingAs($providerB['user'])
            ->get(
                route(
                    'logistics.rider-documents.show',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                )
            )
            ->assertNotFound();

        $this
            ->actingAs($providerB['user'])
            ->patch(
                route(
                    'logistics.rider-documents.update',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                ),
                [
                    'verification_status' => 'verified',
                ]
            )
            ->assertNotFound();

        $document->refresh();

        $this->assertSame(
            'pending',
            $document->verification_status
        );

        $this->assertNull(
            $document->verified_by
        );
    }

    public function test_logistics_cannot_review_document_from_non_rider_application(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'provider@example.test'
        );

        $seller = User::factory()->create([
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Pending->value,
        ]);

        $application = AccountApplication::query()
            ->create([
                'application_no' =>
                    'APP-SELLER-DOCUMENT',
                'user_id' =>
                    $seller->id,
                'requested_role_id' =>
                    Role::where(
                        'name',
                        UserRole::Seller->value
                    )->value('id'),
                'sponsor_logistics_profile_id' =>
                    $provider['profile']->id,
                'status' =>
                    'submitted',
                'submitted_at' =>
                    now(),
            ]);

        $document = $this->makeDocument(
            $application,
            'government_id',
            'seller-id.pdf'
        );

        $this
            ->actingAs($provider['user'])
            ->get(
                route(
                    'logistics.rider-documents.show',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                )
            )
            ->assertNotFound();

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.rider-documents.update',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                ),
                [
                    'verification_status' => 'verified',
                ]
            )
            ->assertNotFound();
    }

    public function test_logistics_can_verify_own_rider_document_and_start_review(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'provider@example.test'
        );

        $application = $this->makeRiderApplication(
            $provider,
            'VERIFY'
        );

        $document = $this->makeDocument(
            $application,
            'driver_license',
            'verify-license.pdf'
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.rider-documents.update',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                ),
                [
                    'verification_status' =>
                        'verified',
                ]
            )
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'review_application_id',
                $application->id
            );

        $document->refresh();
        $application->refresh();

        $this->assertSame(
            'verified',
            $document->verification_status
        );

        $this->assertSame(
            $provider['user']->id,
            $document->verified_by
        );

        $this->assertNotNull(
            $document->verified_at
        );

        $this->assertNull(
            $document->rejection_reason
        );

        $this->assertSame(
            'under_review',
            $application->status
        );

        $this->assertSame(
            $provider['user']->id,
            $application->reviewed_by
        );

        $this->assertNotNull(
            $application->review_started_at
        );
    }

    public function test_rider_document_rejection_requires_and_saves_reason(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'provider@example.test'
        );

        $application = $this->makeRiderApplication(
            $provider,
            'REJECT'
        );

        $document = $this->makeDocument(
            $application,
            'or_cr',
            'reject-or-cr.pdf'
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.rider-documents.update',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                ),
                [
                    'verification_status' =>
                        'rejected',
                    'rejection_reason' =>
                        '',
                ]
            )
            ->assertSessionHasErrors(
                'rejection_reason'
            );

        $document->refresh();

        $this->assertSame(
            'pending',
            $document->verification_status
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.rider-documents.update',
                    [
                        'application' => $application,
                        'document' => $document,
                    ]
                ),
                [
                    'verification_status' =>
                        'rejected',
                    'rejection_reason' =>
                        'The uploaded OR/CR is unreadable.',
                ]
            )
            ->assertSessionHasNoErrors();

        $document->refresh();

        $this->assertSame(
            'rejected',
            $document->verification_status
        );

        $this->assertSame(
            'The uploaded OR/CR is unreadable.',
            $document->rejection_reason
        );

        $this->assertSame(
            $provider['user']->id,
            $document->verified_by
        );

        $this->assertNotNull(
            $document->verified_at
        );
    }

    public function test_rider_cannot_be_approved_until_both_required_documents_are_verified(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'provider@example.test'
        );

        $application = $this->makeRiderApplication(
            $provider,
            'APPROVAL'
        );

        $driverLicense = $this->makeDocument(
            $application,
            'driver_license',
            'approval-license.pdf',
            'verified',
            $provider['user']->id
        );

        $orCr = $this->makeDocument(
            $application,
            'or_cr',
            'approval-or-cr.pdf'
        );

        $rider = $application->user;

        /*
         * Driver License is verified but OR/CR is still
         * pending, so approval must be rejected.
         */
        $this
            ->actingAs($provider['user'])
            ->post(
                route(
                    'logistics.riders.approve',
                    $rider
                )
            )
            ->assertSessionHasErrors(
                'documents'
            );

        $rider->refresh();
        $application->refresh();

        $this->assertSame(
            AccountStatus::Pending->value,
            $rider->status
        );

        $this->assertNotSame(
            'approved',
            $application->status
        );

        /*
         * Review and verify the remaining required
         * document through the real Logistics route.
         */
        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.rider-documents.update',
                    [
                        'application' => $application,
                        'document' => $orCr,
                    ]
                ),
                [
                    'verification_status' =>
                        'verified',
                ]
            )
            ->assertSessionHasNoErrors();

        $driverLicense->refresh();
        $orCr->refresh();

        $this->assertSame(
            'verified',
            $driverLicense->verification_status
        );

        $this->assertSame(
            'verified',
            $orCr->verification_status
        );

        /*
         * With both required documents verified, the
         * sponsoring Logistics provider may approve.
         */
        $this
            ->actingAs($provider['user'])
            ->post(
                route(
                    'logistics.riders.approve',
                    $rider
                )
            )
            ->assertSessionHasNoErrors();

        $rider->refresh();
        $application->refresh();

        $this->assertSame(
            AccountStatus::Active->value,
            $rider->status
        );

        $this->assertSame(
            'approved',
            $application->status
        );

        $this->assertNotNull(
            $rider->riderProfile
        );
    }

    public function test_document_must_belong_to_the_application_in_the_route(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'provider@example.test'
        );

        $applicationA = $this->makeRiderApplication(
            $provider,
            'A'
        );

        $applicationB = $this->makeRiderApplication(
            $provider,
            'B'
        );

        $documentB = $this->makeDocument(
            $applicationB,
            'driver_license',
            'application-b-license.pdf'
        );

        $this
            ->actingAs($provider['user'])
            ->get(
                route(
                    'logistics.rider-documents.show',
                    [
                        'application' => $applicationA,
                        'document' => $documentB,
                    ]
                )
            )
            ->assertNotFound();

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.rider-documents.update',
                    [
                        'application' => $applicationA,
                        'document' => $documentB,
                    ]
                ),
                [
                    'verification_status' =>
                        'verified',
                ]
            )
            ->assertNotFound();
    }

    /**
     * @return array{
     *     user: User,
     *     profile: LogisticsProfile
     * }
     */
    private function makeLogisticsProvider(
        string $suffix,
        string $email
    ): array {
        $location = $this->locationFor($suffix);
        $user = User::factory()->create([
            'name' =>
                "Logistics {$suffix}",
            'role' =>
                UserRole::Logistics->value,
            'status' =>
                AccountStatus::Active->value,
            'email' =>
                $email,
            'business_name' =>
                "Logistics {$suffix}",
        ]);

        /*
         * Keep both canonical role representations
         * available for middleware and hasRole().
         */
        $logisticsRoleId = Role::where(
            'name',
            UserRole::Logistics->value
        )->value('id');

        $user->roles()->syncWithoutDetaching([
            $logisticsRoleId,
        ]);

        $profile = LogisticsProfile::query()
            ->create([
                'user_id' =>
                    $user->id,
                'legal_name' =>
                    "Logistics {$suffix} Incorporated",
                'display_name' =>
                    "Logistics {$suffix}",
                'contact_phone' =>
                    '09170000001',
                'psgc_city_code' => $location['code'],
                'status' =>
                    'active',
            ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => $user->name,
            'phone' => '09170000001',
            'street' => 'Service Road',
            'barangay' => $location['barangay'],
            'city_municipality' => $location['city'],
            'province' => $location['province'],
            'postal_code' => $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        return compact(
            'user',
            'profile'
        );
    }

    private function makeRiderApplication(
        array $provider,
        string $suffix
    ): AccountApplication {
        $location = $this->locationFor((string) $provider['profile']->psgc_city_code);
        $rider = User::factory()->create([
            'name' =>
                "Rider {$suffix}",
            'role' =>
                UserRole::Rider->value,
            'status' =>
                AccountStatus::Pending->value,
            'email' =>
                'rider-'
                .strtolower($suffix)
                .'@example.test',
            'logistics_id' =>
                $provider['user']->id,
            'vehicle_type' =>
                'Motorcycle',
            'plate_number' =>
            "PLATE-{$suffix}",
        ]);

        Address::query()->create([
            'user_id' => $rider->id,
            'label' => 'Home',
            'recipient_name' => $rider->name,
            'phone' => '09170000001',
            'street' => 'Rider Street',
            'barangay' => $location['barangay'],
            'city_municipality' => $location['city'],
            'province' => $location['province'],
            'postal_code' => $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        return AccountApplication::query()
            ->create([
                'application_no' =>
                    "APP-RIDER-{$suffix}",
                'user_id' =>
                    $rider->id,
                'requested_role_id' =>
                    Role::where(
                        'name',
                        UserRole::Rider->value
                    )->value('id'),
                'sponsor_logistics_profile_id' =>
                    $provider['profile']->id,
                'status' =>
                    'submitted',
                'submitted_at' =>
                    now(),
            ]);
    }

    /** @return array{code: string, city: string, province: string, barangay: string, postal_code: string} */
    private function locationFor(string $value): array
    {
        $calamba = $value === 'B' || $value === '0403405000';

        return $calamba
            ? ['code' => '0403405000', 'city' => 'Calamba City', 'province' => 'Laguna', 'barangay' => 'San Rafael', 'postal_code' => '4027']
            : ['code' => '0403424000', 'city' => 'San Pablo City', 'province' => 'Laguna', 'barangay' => 'San Rafael', 'postal_code' => '4000'];
    }

    private function makeDocument(
        AccountApplication $application,
        string $type,
        string $filename,
        string $status = 'pending',
        ?int $verifiedBy = null
    ): ApplicationDocument {
        $path =
            'test/rider-documents/'
            .$filename;

        Storage::disk('local')->put(
            $path,
            'fake-document-content'
        );

        return $application
            ->documents()
            ->create([
                'document_type' =>
                    $type,
                'file_path' =>
                    $path,
                'original_name' =>
                    $filename,
                'mime_type' =>
                    'application/pdf',
                'size_bytes' =>
                    100,
                'verification_status' =>
                    $status,
                'verified_by' =>
                    $verifiedBy,
                'verified_at' =>
                    $status === 'verified'
                        ? now()
                        : null,
            ]);
    }
}
