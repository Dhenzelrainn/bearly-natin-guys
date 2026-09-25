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

    public function test_admin_queue_uses_only_normalized_applications(): void
    {
        $application = $this->makeApplication('buyer', 'Bianca Real', 'bianca.real@example.test');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations'))
            ->assertOk()
            ->assertSee($application->application_no)
            ->assertSee('Bianca Real')
            ->assertDontSee('REG-2041')
            ->assertDontSee('Northstar Logistics');
    }

    public function test_admin_can_filter_the_queue_by_search_role_and_status(): void
    {
        $this->makeApplication('buyer', 'Buyer Match', 'buyer.match@example.test');
        $seller = $this->makeApplication('seller', 'Seller Match', 'seller.match@example.test', 'under_review');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations', [
                'search' => 'Seller Match',
                'role' => 'seller',
                'status' => 'under_review',
            ]))
            ->assertOk()
            ->assertSee($seller->application_no)
            ->assertSee('Seller Match')
            ->assertDontSee('Buyer Match');
    }

    public function test_admin_queue_is_paginated_ten_applications_per_page(): void
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
            ->get(route('admin.registrations'))
            ->assertOk()
            ->assertSee('Applicant 11')
            ->assertDontSee('Applicant 01')
            ->assertSee('page=2');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations', ['page' => 2]))
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
