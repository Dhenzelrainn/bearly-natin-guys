<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AccountApplication;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminRegistrationQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_active_registration_queue_excludes_historical_buyer_applications_without_changing_them(): void
    {
        Storage::fake('local');
        $buyer = $this->makeApplication(
            'buyer',
            'Bianca Real',
            'bianca.real@example.test'
        );
        $legacyIdPath = 'registration-documents/valid-ids/historical-buyer-id.pdf';
        Storage::disk('local')->put($legacyIdPath, 'historical ID fixture');
        $buyer->documents()->create([
            'document_type' => 'government_id',
            'file_path' => $legacyIdPath,
            'original_name' => 'historical-buyer-id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 21,
            'verification_status' => 'pending',
        ]);
        $seller = $this->makeApplication(
            'seller',
            'Seller In Queue',
            'seller.queue@example.test'
        );

        $this->actingAs($this->admin)
            ->get(route('admin.registrations'))
            ->assertOk()
            ->assertSee($seller->application_no)
            ->assertDontSee($buyer->application_no)
            ->assertDontSee('Bianca Real');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.buyers'))
            ->assertRedirect(route('admin.registrations'));

        $this->assertDatabaseHas('account_applications', [
            'id' => $buyer->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('application_documents', [
            'application_id' => $buyer->id,
            'file_path' => $legacyIdPath,
        ]);
        Storage::disk('local')->assertExists($legacyIdPath);
        $this->assertSame(AccountStatus::Pending->value, $buyer->user->fresh()->status);
    }

    public function test_admin_dashboard_pending_application_total_excludes_historical_buyer_applications(): void
    {
        $this->makeApplication('buyer', 'Historical Buyer', 'historical.buyer@example.test');
        $this->makeApplication('seller', 'Pending Seller', 'pending.seller@example.test');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('kpis', function (array $kpis): bool {
                $pending = collect($kpis)->firstWhere('label', 'Pending Applications');

                return ($pending['value'] ?? null) === '1';
            });
    }

    public function test_role_queues_show_only_the_requested_role(): void
    {
        $logistics = $this->makeApplication(
            'logistics',
            'Logistics Match',
            'logistics.match@example.test'
        );
        $seller = $this->makeApplication(
            'seller',
            'Seller Match',
            'seller.match@example.test',
            'under_review'
        );

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.logistics'))
            ->assertOk()
            ->assertSee($logistics->application_no)
            ->assertDontSee($seller->application_no);

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.sellers'))
            ->assertOk()
            ->assertSee($seller->application_no)
            ->assertDontSee($logistics->application_no);
    }

    public function test_admin_can_filter_a_role_queue_by_search_and_status(): void
    {
        $this->makeApplication(
            'seller',
            'Pending Seller',
            'pending.seller@example.test'
        );
        $match = $this->makeApplication(
            'seller',
            'Review Match',
            'review.match@example.test',
            'under_review'
        );

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.sellers', [
                'search' => 'Review Match',
                'status' => 'under_review',
            ]))
            ->assertOk()
            ->assertSee($match->application_no)
            ->assertSee('Review Match')
            ->assertDontSee('Pending Seller');
    }

    public function test_role_queue_is_paginated_ten_applications_per_page(): void
    {
        foreach (range(1, 11) as $index) {
            $this->makeApplication(
                'seller',
                sprintf('Applicant %02d', $index),
                sprintf('applicant%02d@example.test', $index),
                'submitted',
                now()->addMinutes($index),
            );
        }

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.sellers'))
            ->assertOk()
            ->assertSee('Applicant 11')
            ->assertDontSee('Applicant 01')
            ->assertSee('page=2');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.sellers', ['page' => 2]))
            ->assertOk()
            ->assertSee('Applicant 01')
            ->assertDontSee('Applicant 11');
    }

    public function test_admin_can_start_review_for_a_submitted_application(): void
    {
        $application = $this->makeApplication(
            'seller',
            'Seller Starting Review',
            'seller.start-review@example.test',
        );

        $this->actingAs($this->admin)
            ->from(route('admin.registrations.sellers'))
            ->post(route('admin.applications.start-review', $application->user), [
                'review_application_id' => $application->id,
            ])
            ->assertRedirect(route('admin.registrations.sellers'))
            ->assertSessionHas('success', "{$application->user->name}'s application is now under review.");

        $application->refresh();

        $this->assertSame('under_review', $application->status);
        $this->assertNotNull($application->review_started_at);
        $this->assertSame($this->admin->id, $application->reviewed_by);
        $this->assertSame(AccountStatus::Pending->value, $application->user->fresh()->status);
    }

    public function test_start_review_preserves_the_existing_review_cycle_for_an_under_review_application(): void
    {
        $previousReviewer = User::factory()->create([
            'role' => 'admin',
            'status' => AccountStatus::Active->value,
        ]);
        $startedAt = now()->subDay()->startOfSecond();
        $application = $this->makeApplication(
            'logistics',
            'Logistics Already Reviewing',
            'logistics.already-reviewing@example.test',
            'under_review',
        );
        $application->forceFill([
            'review_started_at' => $startedAt,
            'reviewed_by' => $previousReviewer->id,
        ])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.applications.start-review', $application->user), [
                'review_application_id' => $application->id,
            ])
            ->assertRedirect();

        $application->refresh();

        $this->assertSame('under_review', $application->status);
        $this->assertTrue($application->review_started_at->equalTo($startedAt));
        $this->assertSame($previousReviewer->id, $application->reviewed_by);
    }

    public function test_application_needing_revision_cannot_be_started_for_review(): void
    {
        $application = $this->makeApplication(
            'seller',
            'Seller Needs Revision',
            'seller.needs-revision@example.test',
            'needs_revision',
        );

        $this->actingAs($this->admin)
            ->post(route('admin.applications.start-review', $application->user), [
                'review_application_id' => $application->id,
            ])
            ->assertRedirect(route('admin.registrations.sellers'));

        $application->refresh();

        $this->assertSame('needs_revision', $application->status);
        $this->assertNull($application->review_started_at);
        $this->assertNull($application->reviewed_by);
        $this->assertSame(AccountStatus::NeedsRevision->value, $application->user->fresh()->status);
    }

    public function test_start_review_cannot_target_an_application_owned_by_another_user(): void
    {
        $routeApplication = $this->makeApplication(
            'seller',
            'Seller Route Applicant',
            'seller.route@example.test',
        );
        $otherApplication = $this->makeApplication(
            'seller',
            'Seller Other Applicant',
            'seller.other@example.test',
        );

        $this->actingAs($this->admin)
            ->post(route('admin.applications.start-review', $routeApplication->user), [
                'review_application_id' => $otherApplication->id,
            ])
            ->assertRedirect(route('admin.registrations.sellers'));

        $this->assertSame('submitted', $routeApplication->fresh()->status);
        $this->assertSame('submitted', $otherApplication->fresh()->status);
        $this->assertNull($otherApplication->fresh()->reviewed_by);
    }

    public function test_non_admin_cannot_start_application_review(): void
    {
        $application = $this->makeApplication(
            'seller',
            'Seller Protected Review',
            'seller.protected-review@example.test',
        );
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => AccountStatus::Active->value,
        ]);

        $this->actingAs($seller)
            ->post(route('admin.applications.start-review', $application->user), [
                'review_application_id' => $application->id,
            ])
            ->assertForbidden();

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertNull($application->fresh()->reviewed_by);
    }

    public function test_role_queues_render_a_csrf_protected_start_review_form_for_pending_applications(): void
    {
        $sellerApplication = $this->makeApplication(
            'seller',
            'Seller Form Applicant',
            'seller.form@example.test',
        );
        $logisticsApplication = $this->makeApplication(
            'logistics',
            'Logistics Form Applicant',
            'logistics.form@example.test',
        );

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.sellers'))
            ->assertOk()
            ->assertSee('Start review')
            ->assertSee('action="'.route('admin.applications.start-review', $sellerApplication->user).'"', false)
            ->assertSee('name="review_application_id" value="'.$sellerApplication->id.'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('Mark as needs review')
            ->assertDontSee('Mock email notification');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.logistics'))
            ->assertOk()
            ->assertSee('Start review')
            ->assertSee('action="'.route('admin.applications.start-review', $logisticsApplication->user).'"', false)
            ->assertSee('name="review_application_id" value="'.$logisticsApplication->id.'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('Mock email notification');
    }

    private function makeApplication(
        string $role,
        string $name,
        string $email,
        string $status = 'submitted',
        mixed $submittedAt = null,
    ): AccountApplication {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => $status === 'needs_revision'
                ? AccountStatus::NeedsRevision->value
                : AccountStatus::Pending->value,
        ]);

        return AccountApplication::query()->create([
            'application_no' => 'APP-TEST-'.str()->upper(str()->random(10)),
            'user_id' => $user->id,
            'requested_role_id' => Role::where('name', $role)->value('id'),
            'status' => $status,
            'submitted_at' => $submittedAt ?? now(),
        ]);
    }
}
