<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Mail\ApplicationDecisionNotice;
use App\Models\Address;
use App\Models\AccountApplication;
use App\Models\LogisticsProfile;
use App\Models\Role;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_decision_notice_renders_the_reason(): void
    {
        $message = (new ApplicationDecisionNotice(
            'Maria Santos',
            UserRole::Seller->value,
            'needs_revision',
            'Upload a readable business permit.'
        ))->render();

        $this->assertStringContainsString('Needs Revision', $message);
        $this->assertStringContainsString('Upload a readable business permit.', $message);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'email-verification.enabled' => true,
            'mail.mailers.smtp.host' => 'smtp.test',
            'mail.from.address' => 'noreply@bearly.test',
        ]);

        $this->seed(DatabaseSeeder::class);
        Mail::fake();
    }

    public function test_new_buyer_registration_activates_after_email_proof_without_id_or_admin_review(): void
    {
        Storage::fake('local');
        Notification::fake();

        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'status' => AccountStatus::Active->value,
        ]);

        $this->grantEmailProof('bianca@example.test');

        $this->post(route('register.submit'), [
            'role' => UserRole::Buyer->value,
            'first_name' => 'Bianca',
            'last_name' => 'Santos',
            'middle_initial' => 'M',
            'sex' => 'female',
            'birthday' => '2000-05-10',
            'email' => 'bianca@example.test',
            'contact_number' => '09171234567',
            'phone_country' => 'PH',
            'province' => 'Laguna',
            'city' => 'San Pablo City',
            'barangay' => 'San Rafael',
            'street_name' => 'Bearly Street',
            'house_number' => '12',
            'postal_code' => '4000',
            'valid_id' => UploadedFile::fake()->create(
                'unused-buyer-id.pdf',
                100,
                'application/pdf'
            ),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your Buyer account is active. Sign in with your email and password to start shopping.');

        $buyer = User::where(
            'email',
            'bianca@example.test'
        )->firstOrFail();

        $this->assertSame(
            UserRole::Buyer->value,
            $buyer->role
        );

        $this->assertSame(
            AccountStatus::Active->value,
            $buyer->status
        );

        $this->assertNotNull($buyer->email_verified_at);
        $this->assertNull($buyer->phone_verified_at);
        $this->assertNull($buyer->valid_id_path);
        $this->assertNull($buyer->approved_by);
        $this->assertNull($buyer->approved_at);
        $this->assertGuest();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $buyer->id,
            'role_id' => Role::query()->where('name', UserRole::Buyer->value)->value('id'),
            'assigned_by' => null,
        ]);

        $address = $buyer->addresses()->where('label', 'Home')->sole();
        $this->assertTrue($address->is_default_shipping);
        $this->assertSame('+639171234567', $address->phone);
        $this->assertSame('12', $address->house_number);
        $this->assertSame('Bearly Street', $address->street);
        $this->assertSame('San Pablo City', $address->city_municipality);
        $this->assertSame('Laguna', $address->province);
        $this->assertSame('4000', $address->postal_code);

        $this->assertFalse($buyer->applications()->exists());
        $this->assertSame([], Storage::disk('local')->allFiles('registration-documents/valid-ids'));
        Notification::assertNothingSent();

        $this->post(route('login.submit'), [
            'email' => 'bianca@example.test',
            'password' => 'Password123',
        ])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($buyer);
        $this->get(route('home'))->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.registrations'))
            ->assertOk()
            ->assertDontSee('Bianca M. Santos');

        $this->actingAs($admin)
            ->get(route('admin.registrations.buyers'))
            ->assertRedirect(route('admin.registrations'));

        app(RegistrationLifecycleService::class)->backfillUser($buyer->fresh());
        $this->assertFalse($buyer->applications()->exists());
        $this->assertNull($buyer->fresh()->approved_by);
        $this->assertNull($buyer->fresh()->approved_at);
    }

    public function test_seller_application_uses_the_canonical_category_and_document_records(): void
    {
        Storage::fake('local');
        $this->grantEmailProof(
            'selena@example.test'
        );

        $this->post(route('register.submit'), [
            'role' => UserRole::Seller->value,
            'first_name' => 'Selena',
            'last_name' => 'Reyes',
            'middle_initial' => 'T',
            'sex' => 'female',
            'birthday' => '1998-08-20',
            'email' => 'selena@example.test',
            'contact_number' => '09181234567',
            'phone_country' => 'PH',
            'province' => 'Laguna',
            'city' => 'Calamba City',
            'barangay' => 'Real',
            'street_name' => 'Market Road',
            'house_number' => '21',
            'postal_code' => '4027',
            'business_name' => 'Selena Gems',
            'business_category' =>
                'Jewelry and Watches',
            'valid_id' =>
                UploadedFile::fake()->create(
                    'seller-id.pdf',
                    100,
                    'application/pdf'
                ),
            'business_permit' =>
                UploadedFile::fake()->create(
                    'seller-permit.pdf',
                    100,
                    'application/pdf'
                ),
            'password' => 'Password123',
            'password_confirmation' =>
                'Password123',
            'terms' => '1',
        ])->assertRedirect(
            route('application.pending')
        );

        $seller = User::where(
            'email',
            'selena@example.test'
        )->firstOrFail();

        $this->assertSame(
            AccountStatus::Pending->value,
            $seller->status
        );

        $application = $seller
            ->applications()
            ->with(
                'requestedRole',
                'businessCategory',
                'documents'
            )
            ->sole();

        $this->assertSame(
            UserRole::Seller->value,
            $application->requestedRole->name
        );

        $this->assertSame(
            'Selena Gems',
            $application->business_name
        );

        $this->assertSame(
            'Jewelry and Watches',
            $application
                ->businessCategory
                ->name
        );

        $this->assertEqualsCanonicalizing(
            [
                'government_id',
                'business_permit',
            ],
            $application
                ->documents
                ->pluck('document_type')
                ->all(),
        );

        Storage::disk('local')
            ->assertExists(
                $seller->valid_id_path
            );

        Storage::disk('local')
            ->assertExists(
                $seller->business_permit_path
            );
    }

    public function test_logistics_application_is_saved_for_admin_approval(): void
    {
        Storage::fake('local');
        $this->grantEmailProof(
            'mika@example.test'
        );

        $this->post(
            route(
                'logistics.register.submit'
            ),
            [
                'business_name' =>
                    'Laguna Test Logistics',
                'first_name' => 'Mika',
                'last_name' => 'Santos',
                'middle_initial' => 'R',
                'sex' => 'Female',
                'email' => 'mika@example.test',
                'contact_number' =>
                    '09281234567',
                'birthday' => '1995-03-20',
                'province' => 'Laguna',
                'municipality' =>
                    'Santa Cruz',
                'city_code' => '0403426000',
                'barangay' => 'Poblacion',
                'street' => 'Guevara Avenue',
                'house_number' => '8',
                'postal_code' => '4009',
                'postal_manual' => '0',
                'valid_id' =>
                    UploadedFile::fake()
                        ->create(
                            'logistics-id.pdf',
                            100,
                            'application/pdf'
                        ),
                'business_permit' =>
                    UploadedFile::fake()
                        ->create(
                            'permit.pdf',
                            100,
                            'application/pdf'
                        ),
                'password' => 'Password123',
                'password_confirmation' =>
                    'Password123',
                'terms' => '1',
            ]
        )->assertRedirect(
            route('logistics.register')
        );

        $logistics = User::where(
            'email',
            'mika@example.test'
        )->firstOrFail();

        $this->assertSame(
            UserRole::Logistics->value,
            $logistics->role
        );

        $this->assertSame(
            AccountStatus::Pending->value,
            $logistics->status
        );

        Storage::disk('local')
            ->assertExists(
                $logistics->valid_id_path
            );

        Storage::disk('local')
            ->assertExists(
                $logistics
                    ->business_permit_path
            );

        $application = $logistics
            ->applications()
            ->with(
                'requestedRole',
                'documents'
            )
            ->sole();

        $this->assertSame(
            UserRole::Logistics->value,
            $application->requestedRole->name
        );

        $this->assertSame(
            'Laguna Test Logistics',
            $application->business_name
        );

        $this->assertEqualsCanonicalizing(
            [
                'government_id',
                'business_permit',
            ],
            $application
                ->documents
                ->pluck('document_type')
                ->all(),
        );
    }

    public function test_logistics_registration_rejects_a_city_already_owned_by_an_account(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);
        LogisticsProfile::query()->create([
            'user_id' => $owner->id,
            'legal_name' => 'Existing Pila Logistics',
            'display_name' => 'Existing Pila Logistics',
            'contact_phone' => '09170000001',
            'psgc_city_code' => '0403422000',
            'status' => 'active',
        ]);

        $this->from(route('logistics.register'))
            ->post(route('logistics.register.submit'), [
            'business_name' => 'Duplicate Pila Logistics',
            'first_name' => 'Nina',
            'last_name' => 'Santos',
            'sex' => 'Female',
            'email' => 'duplicate-pila@example.test',
            'contact_number' => '09281234568',
            'birthday' => '1995-03-20',
            'province' => 'Laguna',
            'municipality' => 'Pila',
            'city_code' => '0403422000',
            'barangay' => 'Pinagbayanan',
            'street' => 'Market Road',
            'house_number' => '8',
            'postal_code' => '4010',
            'postal_manual' => '0',
            'valid_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
            ])
            ->assertRedirect(route('logistics.register'))
            ->assertSessionHasErrors('municipality');

        $this->assertDatabaseMissing('users', [
            'email' => 'duplicate-pila@example.test',
        ]);
    }

    public function test_rider_application_belongs_to_the_selected_active_logistics_account(): void
    {
        Storage::fake('local');

        $this->grantEmailProof(
            'nico@example.test'
        );

        $logistics = User::factory()->create([
            'role' =>
                UserRole::Logistics->value,
            'status' =>
                AccountStatus::Active->value,
            'business_name' =>
                'Approved Logistics',
        ]);
        LogisticsProfile::query()->create([
            'user_id' => $logistics->id,
            'legal_name' => 'Approved Logistics',
            'display_name' => 'Approved Logistics',
            'contact_phone' => '09170000001',
            'psgc_city_code' => '0403424000',
            'status' => 'active',
        ]);

        $this->post(
            route('rider.register.submit'),
            [
                'logistics_partner' =>
                    $logistics->id,
                'first_name' => 'Nico',
                'last_name' => 'Flores',
                'middle_initial' => 'D',
                'sex' => 'Male',
                'email' => 'nico@example.test',
                'contact_number' =>
                    '09191234567',
                'birthday' => '1998-05-14',
                'province' => 'Laguna',
                'municipality' =>
                    'City of San Pablo',
                'barangay' => 'San Rafael',
                'street' => 'Rider Road',
                'house_number' => '3',
                'city_code' => '0403424000',
                'postal_code' => '4000',
                'postal_manual' => '0',
                'work_type' => 'courier',
                'vehicle_type' =>
                    'Van',
                'vehicle_model' => 'Toyota Hiace',
                'plate_number' =>
                    'abc 1234',
                // Applicants cannot grant themselves verified parcel capacity.
                'linehaul_capacity' => 999,
                'courier_vehicle_verified_by' => $logistics->id,
                'or_cr' =>
                    UploadedFile::fake()
                        ->create(
                            'or-cr.pdf',
                            100,
                            'application/pdf'
                        ),
                'driver_license' =>
                    UploadedFile::fake()
                        ->create(
                            'license.pdf',
                            100,
                            'application/pdf'
                        ),
                'password' =>
                    'Password123',
                'password_confirmation' =>
                    'Password123',
                'terms' => '1',
            ]
        )->assertRedirect(
            route('rider.register')
        );

        $rider = User::where(
            'email',
            'nico@example.test'
        )->firstOrFail();

        $this->assertSame(
            UserRole::Rider->value,
            $rider->role
        );

        $this->assertSame(
            AccountStatus::Pending->value,
            $rider->status
        );

        $riderApplication = AccountApplication::query()
            ->where('user_id', $rider->id)
            ->firstOrFail();
        $this->assertSame('courier', $riderApplication->requested_work_type);
        $this->assertSame('Toyota Hiace', $riderApplication->rider_vehicle_model);
        $this->assertNull($rider->riderProfile);
        $this->assertNull($riderApplication->getAttribute('linehaul_capacity'));

        $this->assertSame(
            $logistics->id,
            $rider->logistics_id
        );

        Storage::disk('local')
            ->assertExists(
                $rider->or_cr_path
            );

        Storage::disk('local')
            ->assertExists(
                $rider
                    ->driver_license_path
            );
    }

    public function test_admin_can_approve_buyer_seller_and_logistics_applications(): void
    {
        $admin = User::factory()->create([
            'role' =>
                UserRole::Admin->value,
            'status' =>
                AccountStatus::Active->value,
        ]);

        $queuedDecisions = 0;

        foreach (
            UserRole::adminApproved()
            as $role
        ) {
            $applicant =
                User::factory()->create([
                    'role' => $role,
                    'status' =>
                    AccountStatus::Pending
                            ->value,
                ]);

            if ($role === UserRole::Logistics->value) {
                Address::query()->create([
                    'user_id' => $applicant->id,
                    'label' => 'Sorting center',
                    'recipient_name' => $applicant->name,
                    'phone' => '09170000001',
                    'street' => 'Service Road',
                    'barangay' => 'Poblacion',
                    'city_municipality' => 'Santa Cruz',
                    'province' => 'Laguna',
                    'postal_code' => '4009',
                    'psgc_city_code' => '0403426000',
                ]);
            }

            $application =
                AccountApplication::query()
                    ->create([
                        'application_no' =>
                            'APP-APPROVAL-'
                            .str()
                                ->upper(
                                    str()
                                        ->random(8)
                                ),
                        'user_id' =>
                            $applicant->id,
                        'requested_role_id' =>
                            Role::where(
                                'name',
                                $role
                            )->value('id'),
                        'business_name' =>
                            $role ===
                            UserRole::Buyer->value
                                ? null
                                : $applicant
                                        ->name
                                    .' Business',
                        'status' =>
                            'under_review',
                        'submitted_at' =>
                            now(),
                        'review_started_at' =>
                            now(),
                    ]);

            $requiredDocuments =
                $role ===
                UserRole::Buyer->value
                    ? ['government_id']
                    : [
                        'government_id',
                        'business_permit',
                    ];

            foreach (
                $requiredDocuments
                as $documentType
            ) {
                $application
                    ->documents()
                    ->create([
                        'document_type' =>
                            $documentType,
                        'file_path' =>
                            'test/'
                            .$documentType
                            .'.pdf',
                        'original_name' =>
                            $documentType
                            .'.pdf',
                        'mime_type' =>
                            'application/pdf',
                        'size_bytes' => 100,
                        'verification_status' =>
                            'verified',
                        'verified_by' =>
                            $admin->id,
                        'verified_at' =>
                            now(),
                    ]);
            }

            $this->actingAs($admin)
                ->post(
                    route(
                        'admin.applications.approve',
                        $applicant
                    ),
                    [
                        'review_application_id' => $application->id,
                    ]
                )
                ->assertSessionHasNoErrors();

            $queuedDecisions++;

            Mail::assertQueued(
                ApplicationDecisionNotice::class,
                fn (ApplicationDecisionNotice $mail): bool =>
                    $mail->hasTo($applicant->email)
                    && $mail->decision === 'approved'
                    && $mail->role === $role
            );

            $application->refresh();
            $applicant->refresh();

            $this->assertSame(
                'approved',
                $application->status
            );

            $this->assertSame(
                $admin->id,
                $application->reviewed_by
            );

            $this->assertSame(
                AccountStatus::Active->value,
                $applicant->status
            );

            $this->assertSame(
                $admin->id,
                $applicant->approved_by
            );

            $this->assertTrue(
                $applicant
                    ->roles()
                    ->where(
                        'name',
                        $role
                    )
                    ->exists()
            );

            if (
                $role ===
                UserRole::Seller->value
            ) {
                $this->assertNotNull(
                    $applicant
                        ->sellerProfile
                );
            }

            if (
                $role ===
                UserRole::Logistics->value
            ) {
                $this->assertNotNull(
                    $applicant
                        ->logisticsProfile
                );
            }

            $queueRoute = match ($role) {
                UserRole::Buyer->value => 'admin.registrations',
                UserRole::Seller->value => 'admin.registrations.sellers',
                UserRole::Logistics->value => 'admin.registrations.logistics',
            };

            $this->actingAs($admin)
                ->post(
                    route('admin.applications.approve', $applicant),
                    ['review_application_id' => $application->id]
                )
                ->assertRedirect(route($queueRoute))
                ->assertSessionHas('warning');

            Mail::assertQueuedCount($queuedDecisions);
        }
    }

    public function test_admin_cannot_approve_a_different_applicants_selected_application(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'status' => AccountStatus::Active->value,
        ]);

        $target = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Pending->value,
        ]);

        $otherApplicant = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Pending->value,
        ]);

        $targetApplication = AccountApplication::query()->create([
            'application_no' => 'APP-LOGISTICS-TARGET',
            'user_id' => $target->id,
            'requested_role_id' => Role::where('name', UserRole::Logistics->value)->value('id'),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $otherApplication = AccountApplication::query()->create([
            'application_no' => 'APP-LOGISTICS-OTHER',
            'user_id' => $otherApplicant->id,
            'requested_role_id' => Role::where('name', UserRole::Logistics->value)->value('id'),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(
                route('admin.applications.approve', $target),
                ['review_application_id' => $otherApplication->id]
            )
            ->assertRedirect(route('admin.registrations.logistics'))
            ->assertSessionHas('warning');

        $this->assertSame('submitted', $targetApplication->fresh()->status);
        $this->assertSame('submitted', $otherApplication->fresh()->status);
        $this->assertSame(AccountStatus::Pending->value, $target->fresh()->status);
        $this->assertSame(AccountStatus::Pending->value, $otherApplicant->fresh()->status);
        $this->assertFalse($target->roles()->where('name', UserRole::Logistics->value)->exists());
        $this->assertFalse($otherApplicant->roles()->where('name', UserRole::Logistics->value)->exists());
        Mail::assertNothingOutgoing();

        $this->get(route('admin.registrations.logistics'))
            ->assertOk()
            ->assertSee('no longer matches a current pending application');
    }

    public function test_admin_approval_requires_verified_documents(): void
    {
        $admin = User::factory()->create([
            'role' =>
                UserRole::Admin->value,
            'status' =>
                AccountStatus::Active->value,
        ]);

        $buyer = User::factory()->create([
            'role' =>
                UserRole::Buyer->value,
            'status' =>
                AccountStatus::Pending->value,
        ]);

        $application =
            AccountApplication::query()
                ->create([
                    'application_no' =>
                        'APP-DOCUMENT-CHECK',
                    'user_id' =>
                        $buyer->id,
                    'requested_role_id' =>
                        Role::where(
                            'name',
                            UserRole::Buyer->value
                        )->value('id'),
                    'status' =>
                        'submitted',
                    'submitted_at' =>
                        now(),
                ]);

        $this->actingAs($admin)
            ->post(
                route(
                    'admin.applications.approve',
                    $buyer
                ),
                [
                    'review_application_id' =>
                        $application->id,
                ]
            )
            ->assertSessionHasErrors(
                'documents'
            );

        $this->assertSame(
            'submitted',
            $application
                ->fresh()
                ->status
        );

        $this->assertSame(
            AccountStatus::Pending->value,
            $buyer->fresh()->status
        );
    }

    public function test_admin_can_request_application_revision_using_the_user_route(): void
    {
        $admin = User::factory()->create([
            'role' =>
                UserRole::Admin->value,
            'status' =>
                AccountStatus::Active->value,
        ]);

        $seller = User::factory()->create([
            'role' =>
                UserRole::Seller->value,
            'status' =>
                AccountStatus::Pending->value,
        ]);

        $application =
            AccountApplication::query()
                ->create([
                    'application_no' =>
                        'APP-REVISION-CHECK',
                    'user_id' =>
                        $seller->id,
                    'requested_role_id' =>
                        Role::where(
                            'name',
                            UserRole::Seller->value
                        )->value('id'),
                    'status' =>
                        'under_review',
                    'submitted_at' =>
                        now(),
                    'review_started_at' =>
                        now(),
                ]);

        $this->actingAs($admin)
            ->post(
                route(
                    'admin.applications.request-revision',
                    $seller
                ),
                [
                    'revision_notes' =>
                        'Upload a readable business permit.',
                    'review_application_id' => $application->id,
                ]
            )
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'review_application_id',
                $application->id
            );

        Mail::assertQueued(
            ApplicationDecisionNotice::class,
            fn (ApplicationDecisionNotice $mail): bool =>
                $mail->hasTo($seller->email)
                && $mail->decision === 'needs_revision'
                && $mail->reason === 'Upload a readable business permit.'
        );

        $application->refresh();
        $seller->refresh();

        $this->assertSame(
            'needs_revision',
            $application->status
        );

        $this->assertSame(
            'Upload a readable business permit.',
            $application->revision_notes
        );

        $this->assertSame(
            $admin->id,
            $application->reviewed_by
        );

        $this->assertSame(
            AccountStatus::NeedsRevision->value,
            $seller->status
        );

        $this->assertSame(
            $admin->id,
            $seller->approved_by
        );

        $this->assertNull(
            $seller->approved_at
        );
    }

    public function test_admin_rejection_updates_the_user_and_normalized_application(): void
    {
        $admin = User::factory()->create([
            'role' =>
                UserRole::Admin->value,
            'status' =>
                AccountStatus::Active->value,
        ]);

        $buyer = User::factory()->create([
            'role' =>
                UserRole::Buyer->value,
            'status' =>
                AccountStatus::Pending->value,
        ]);

        $application =
            AccountApplication::query()
                ->create([
                    'application_no' =>
                        'APP-REJECTION-CHECK',
                    'user_id' =>
                        $buyer->id,
                    'requested_role_id' =>
                        Role::where(
                            'name',
                            UserRole::Buyer->value
                        )->value('id'),
                    'status' =>
                        'submitted',
                    'submitted_at' =>
                        now(),
                ]);

        $this->actingAs($admin)
            ->post(
                route(
                    'admin.applications.reject',
                    $buyer
                ),
                [
                    'reason' =>
                        'The submitted identity details do not match.',
                    'review_application_id' => $application->id,
                ]
            )
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'review_application_id',
                $application->id
            );

        Mail::assertQueued(
            ApplicationDecisionNotice::class,
            fn (ApplicationDecisionNotice $mail): bool =>
                $mail->hasTo($buyer->email)
                && $mail->decision === 'rejected'
                && $mail->reason === 'The submitted identity details do not match.'
        );

        $application->refresh();
        $buyer->refresh();

        $this->assertSame(
            'rejected',
            $application->status
        );

        $this->assertSame(
            'The submitted identity details do not match.',
            $application->decision_reason
        );

        $this->assertSame(
            AccountStatus::Rejected->value,
            $buyer->status
        );

        $this->assertSame(
            $admin->id,
            $buyer->approved_by
        );

        $this->assertSame(
            'The submitted identity details do not match.',
            $buyer->rejection_reason
        );
    }

    public function test_logistics_can_only_approve_its_own_rider_applicant(): void
    {
        $logistics =
            User::factory()->create([
                'role' =>
                    UserRole::Logistics->value,
                'status' =>
                    AccountStatus::Active->value,
                'business_name' =>
                    'Primary Logistics',
            ]);

        $logisticsProfile =
            LogisticsProfile::query()
                ->create([
                    'user_id' =>
                        $logistics->id,
                    'legal_name' =>
                        'Primary Logistics Incorporated',
                    'display_name' =>
                        'Primary Logistics',
                    'contact_phone' =>
                        '09170000001',
                    'psgc_city_code' => '0403424000',
                    'status' =>
                        'active',
                ]);

        $serviceAddress = Address::query()->create([
            'user_id' => $logistics->id,
            'label' => 'Sorting center',
            'recipient_name' => $logistics->name,
            'phone' => '09170000001',
            'street' => 'Service Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'City of San Pablo',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'psgc_city_code' => '0403424000',
        ]);
        $sortingCenter = SortingCenter::query()->create([
            'logistics_profile_id' => $logisticsProfile->id,
            'address_id' => $serviceAddress->id,
            'name' => 'San Pablo Center',
            'code' => 'SAN-PABLO-AUTO-1',
            'contact_phone' => '09170000001',
            'status' => 'active',
        ]);
        $matchingZone = SortingZone::query()->create([
            'sorting_center_id' => $sortingCenter->id,
            'code' => 'SANP-01',
            'name' => 'San Pablo / San Rafael',
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'San Pablo City',
                'psgc_city_code' => '0403424000',
                'barangays' => ['San Rafael'],
            ],
            'status' => 'active',
        ]);

        $otherLogistics =
            User::factory()->create([
                'role' =>
                    UserRole::Logistics->value,
                'status' =>
                    AccountStatus::Active->value,
                'business_name' =>
                    'Other Logistics',
            ]);

        $rider =
            User::factory()->create([
                'role' =>
                    UserRole::Rider->value,
                'status' =>
                    AccountStatus::Pending->value,
                'logistics_id' =>
                    $logistics->id,
                'vehicle_type' =>
                    'Motorcycle',
                'plate_number' =>
                    'TEST-1234',
            ]);

        Address::query()->create([
            'user_id' => $rider->id,
            'label' => 'Home',
            'recipient_name' => $rider->name,
            'phone' => '09170000002',
            'street' => 'Rider Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'City of San Pablo',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'psgc_city_code' => '0403424000',
        ]);

        $riderApplication =
            AccountApplication::query()
                ->create([
                    'application_no' =>
                        'APP-RIDER-OWNERSHIP',
                    'user_id' =>
                        $rider->id,
                    'requested_role_id' =>
                        Role::where(
                            'name',
                            UserRole::Rider->value
                        )->value('id'),
                    'sponsor_logistics_profile_id' =>
                        $logisticsProfile->id,
                    'status' =>
                        'under_review',
                    'submitted_at' =>
                        now(),
                    'review_started_at' =>
                        now(),
                ]);

        foreach (
            [
                'driver_license',
                'or_cr',
            ] as $documentType
        ) {
            $riderApplication
                ->documents()
                ->create([
                    'document_type' =>
                        $documentType,
                    'file_path' =>
                        'test/'
                        .$documentType
                        .'.pdf',
                    'original_name' =>
                        $documentType
                        .'.pdf',
                    'mime_type' =>
                        'application/pdf',
                    'size_bytes' =>
                        100,
                    'verification_status' =>
                        'verified',
                    'verified_by' =>
                        $logistics->id,
                    'verified_at' =>
                        now(),
                ]);
        }

        $this->actingAs(
            $otherLogistics
        )
            ->post(
                route(
                    'logistics.riders.approve',
                    $rider
                )
            )
            ->assertForbidden();

        $this->actingAs(
            $logistics
        )
            ->post(
                route(
                    'logistics.riders.approve',
                    $rider
                )
            )
            ->assertSessionHasNoErrors();

        Mail::assertQueued(
            ApplicationDecisionNotice::class,
            fn (ApplicationDecisionNotice $mail): bool =>
                $mail->hasTo($rider->email)
                && $mail->decision === 'approved'
                && $mail->role === UserRole::Rider->value
        );

        $rider->refresh();
        $riderApplication->refresh();

        $this->assertSame(
            AccountStatus::Active->value,
            $rider->status
        );

        $this->assertSame(
            $logistics->id,
            $rider->approved_by
        );

        $this->assertSame(
            'approved',
            $riderApplication->status
        );

        $this->assertNotNull(
            $rider->riderProfile
        );

        $this->assertSame(
            $logisticsProfile->id,
            $rider
                ->riderProfile
                ->logistics_profile_id
        );
        $this->assertSame(
            $sortingCenter->id,
            $rider->riderProfile->home_sorting_center_id
        );
        $this->assertSame(
            $matchingZone->id,
            $rider->riderProfile->current_zone_id
        );
    }

    public function test_courier_applicant_stays_pending_until_sponsor_verifies_capacity(): void
    {
        $logistics = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => 'Courier Sponsor Logistics',
        ]);
        $logisticsProfile = LogisticsProfile::query()->create([
            'user_id' => $logistics->id,
            'legal_name' => 'Courier Sponsor Logistics Inc.',
            'display_name' => 'Courier Sponsor Logistics',
            'contact_phone' => '09170000011',
            'psgc_city_code' => '0403424000',
            'status' => AccountStatus::Active->value,
        ]);
        $rider = User::factory()->create([
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'logistics_id' => $logistics->id,
            'vehicle_type' => 'Van',
            'plate_number' => 'COURIER-APP-01',
        ]);
        Address::query()->create([
            'user_id' => $rider->id,
            'label' => 'Home',
            'recipient_name' => $rider->name,
            'phone' => '09170000012',
            'street' => 'Courier Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'City of San Pablo',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'psgc_city_code' => '0403424000',
        ]);
        $application = AccountApplication::query()->create([
            'application_no' => 'APP-COURIER-APPROVAL-01',
            'user_id' => $rider->id,
            'requested_role_id' => Role::query()->where('name', UserRole::Rider->value)->value('id'),
            'requested_work_type' => 'courier',
            'rider_vehicle_model' => 'Toyota Hiace',
            'sponsor_logistics_profile_id' => $logisticsProfile->id,
            'status' => 'under_review',
            'submitted_at' => now(),
            'review_started_at' => now(),
        ]);

        foreach (['driver_license', 'or_cr'] as $documentType) {
            $application->documents()->create([
                'document_type' => $documentType,
                'file_path' => "test/courier-{$documentType}.pdf",
                'original_name' => "{$documentType}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'verification_status' => 'verified',
                'verified_by' => $logistics->id,
                'verified_at' => now(),
            ]);
        }

        $this->actingAs($logistics)
            ->post(route('logistics.riders.approve', $rider))
            ->assertSessionHasErrors('linehaul_capacity');

        $this->assertSame(AccountStatus::Pending->value, $rider->fresh()->status);
        $this->assertSame('under_review', $application->fresh()->status);
        $this->assertDatabaseMissing('rider_profiles', ['user_id' => $rider->id]);

        $this->actingAs($logistics)
            ->post(route('logistics.riders.approve', $rider), ['linehaul_capacity' => 24])
            ->assertSessionHasNoErrors();

        $profile = $rider->fresh()->riderProfile;
        $this->assertSame(AccountStatus::Active->value, $rider->fresh()->status);
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame('courier', $profile->work_type);
        $this->assertSame('Van', $profile->vehicle_type);
        $this->assertSame('Toyota Hiace', $profile->vehicle_model);
        $this->assertSame(24, $profile->linehaul_parcel_capacity);
        $this->assertSame($logistics->id, $profile->courier_vehicle_verified_by);
        $this->assertNotNull($profile->courier_vehicle_verified_at);
    }

    public function test_pending_accounts_cannot_enter_a_role_dashboard(): void
    {
        $seller =
            User::factory()->create([
                'email' =>
                    'pending-seller@example.test',
                'role' =>
                    UserRole::Seller->value,
                'status' =>
                    AccountStatus::Pending->value,
            ]);

        $this->post(
            route('login.submit'),
            [
                'email' =>
                    $seller->email,
                'password' =>
                    'password',
            ]
        )->assertRedirect(
            route('application.pending')
        );

        $this->assertGuest();
    }

    public function test_active_users_are_sent_to_their_role_dashboard(): void
    {
        $logistics =
            User::factory()->create([
                'email' =>
                    'active-logistics@example.test',
                'role' =>
                    UserRole::Logistics->value,
                'status' =>
                    AccountStatus::Active->value,
            ]);

        $this->post(
            route('login.submit'),
            [
                'email' =>
                    $logistics->email,
                'password' =>
                    'password',
            ]
        )->assertRedirect(
            route('logistics.dashboard')
        );

        $this->assertAuthenticatedAs(
            $logistics
        );
    }

    private function grantEmailProof(
        string $email
    ): void {
        $this->startSession();

        $sessionId =
            $this->app['session']->getId();

        $this->withCookie(
            (string) config(
                'session.cookie'
            ),
            $sessionId
        );

        $key =
            'buyer-email:'
            .hash_hmac(
                'sha256',
                $sessionId,
                (string) config(
                    'app.key'
                )
            );

        Cache::put(
            $key,
            [
                'email' =>
                    $email,
                'driver' =>
                    'smtp',
                'attempts' =>
                    0,
                'expires_at' =>
                    now()
                        ->addMinutes(10)
                        ->timestamp,
                'verified_until' =>
                    now()
                        ->addMinutes(30)
                        ->timestamp,
                'verified_at' =>
                    now()
                        ->toDateTimeString(),
            ],
            now()->addMinutes(30)
        );
    }
}
