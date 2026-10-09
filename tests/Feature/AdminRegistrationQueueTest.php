<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AccountApplication;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_role_queue_uses_only_normalized_applications(): void
    {
        $application = $this->makeApplication(
            'buyer',
            'Bianca Real',
            'bianca.real@example.test'
        );

        User::factory()->create([
            'name' => 'Legacy Only',
            'role' => 'buyer',
            'status' => AccountStatus::Pending->value,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.buyers'))
            ->assertOk()
            ->assertSee($application->application_no)
            ->assertSee('Bianca Real')
            ->assertDontSee('Legacy Only');
    }

    public function test_role_queues_show_only_the_requested_role(): void
    {
        $buyer = $this->makeApplication(
            'buyer',
            'Buyer Match',
            'buyer.match@example.test'
        );
        $seller = $this->makeApplication(
            'seller',
            'Seller Match',
            'seller.match@example.test',
            'under_review'
        );

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.buyers'))
            ->assertOk()
            ->assertSee($buyer->application_no)
            ->assertDontSee($seller->application_no);

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.sellers'))
            ->assertOk()
            ->assertSee($seller->application_no)
            ->assertDontSee($buyer->application_no);
    }

    public function test_admin_can_filter_a_role_queue_by_search_and_status(): void
    {
        $this->makeApplication(
            'buyer',
            'Pending Buyer',
            'pending.buyer@example.test'
        );
        $match = $this->makeApplication(
            'buyer',
            'Review Match',
            'review.match@example.test',
            'under_review'
        );

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.buyers', [
                'search' => 'Review Match',
                'status' => 'under_review',
            ]))
            ->assertOk()
            ->assertSee($match->application_no)
            ->assertSee('Review Match')
            ->assertDontSee('Pending Buyer');
    }

    public function test_role_queue_is_paginated_ten_applications_per_page(): void
    {
        foreach (range(1, 11) as $index) {
            $this->makeApplication(
                'buyer',
                sprintf('Applicant %02d', $index),
                sprintf('applicant%02d@example.test', $index),
                'submitted',
                now()->addMinutes($index),
            );
        }

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.buyers'))
            ->assertOk()
            ->assertSee('Applicant 11')
            ->assertDontSee('Applicant 01')
            ->assertSee('page=2');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.buyers', ['page' => 2]))
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
