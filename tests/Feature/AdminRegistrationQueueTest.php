<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AccountApplication;
use App\Models\ApplicationDocument;
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
