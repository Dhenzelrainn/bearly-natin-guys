<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AccountApplication;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountApplicationDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_approval_requires_every_required_document_to_be_verified(): void
    {
        $application = $this->makeApplication('seller', false);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.registrations.sellers'))
            ->post(route('admin.applications.approve', $application))
            ->assertRedirect(route('admin.registrations.sellers'))
            ->assertSessionHasErrors('documents')
            ->assertSessionHas('review_application_id', $application->id);

        $this->get(route('admin.registrations.sellers'))
            ->assertOk()
            ->assertSee('All required documents must be verified before approval.');

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(AccountStatus::Pending->value, $application->user->fresh()->status);
        $this->assertDatabaseCount('seller_profiles', 0);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_seller_approval_is_transactional_and_safe_to_retry(): void
    {
        $application = $this->makeApplication('seller');

        $this->actingAs($this->admin)
            ->post(route('admin.applications.approve', $application))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.applications.approve', $application))
            ->assertSessionHasNoErrors();

        $applicant = $application->user->fresh();
        $application->refresh();

        $this->assertSame('approved', $application->status);
        $this->assertSame(AccountStatus::Active->value, $applicant->status);
        $this->assertTrue($applicant->roles()->where('name', 'seller')->exists());
        $this->assertDatabaseCount('seller_profiles', 1);
        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('draft', $applicant->sellerProfile->store->publication_status);
    }

    public function test_admin_can_reject_an_application_with_a_reason(): void
    {
        $application = $this->makeApplication('buyer', false);

        $this->actingAs($this->admin)
            ->post(route('admin.applications.reject', $application), [
                'reason' => 'The identity details could not be validated.',
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $applicant = $application->user->fresh();

        $this->assertSame('rejected', $application->status);
        $this->assertSame('The identity details could not be validated.', $application->decision_reason);
        $this->assertSame(AccountStatus::Rejected->value, $applicant->status);
        $this->assertSame($this->admin->id, $application->reviewed_by);
        $this->assertNotNull($application->decided_at);
        $this->assertSame('application.rejected', AuditLog::sole()->action);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_admin_can_request_revision_without_provisioning_access(): void
    {
        $application = $this->makeApplication('logistics', false);

        $this->actingAs($this->admin)
            ->post(route('admin.applications.request-revision', $application), [
                'revision_notes' => 'Upload a clear and current business permit.',
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $applicant = $application->user->fresh();

        $this->assertSame('needs_revision', $application->status);
        $this->assertSame('Upload a clear and current business permit.', $application->revision_notes);
        $this->assertSame(AccountStatus::NeedsRevision->value, $applicant->status);
        $this->assertFalse($applicant->roles()->where('name', 'logistics')->exists());
        $this->assertDatabaseCount('logistics_profiles', 0);
        $this->assertSame('application.needs_revision', AuditLog::sole()->action);
        $this->assertDatabaseCount('notifications', 1);
    }

    private function makeApplication(string $role, bool $verified = true): AccountApplication
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => AccountStatus::Pending->value,
            'business_name' => $role === 'buyer' ? null : 'Canonical Test Business',
        ]);

        $application = AccountApplication::query()->create([
            'application_no' => 'APP-DECISION-'.str()->upper(str()->random(8)),
            'user_id' => $user->id,
            'requested_role_id' => Role::where('name', $role)->value('id'),
            'business_name' => $user->business_name,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $types = $role === 'buyer'
            ? ['government_id']
            : ['government_id', 'business_permit'];

        foreach ($types as $index => $type) {
            $application->documents()->create([
                'document_type' => $type,
                'file_path' => 'test/'.$type.'.pdf',
                'original_name' => $type.'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'verification_status' => $verified || $index === 0 ? 'verified' : 'pending',
                'verified_by' => $verified || $index === 0 ? $this->admin->id : null,
                'verified_at' => $verified || $index === 0 ? now() : null,
            ]);
        }

        return $application->load('user');
    }
}
