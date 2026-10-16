<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountStatusChangedNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()
            ->where('email', 'admin@bearly.test')
            ->firstOrFail();
    }

    public function test_admin_can_suspend_a_user_with_a_reason_and_audit_it(): void
    {
        Notification::fake();

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.users.status', $buyer), [
                'status' => 'suspended',
                'reason' => 'Repeated policy violations.',
            ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $buyer->id)
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.allowed_statuses', ['active', 'deactivated'])
            ->assertJsonPath('message', 'Account status was updated.');

        $this->assertSame('suspended', $buyer->fresh()->status);

        $audit = AuditLog::query()
            ->where('action', 'user.status_changed')
            ->where('auditable_id', $buyer->id)
            ->sole();

        $this->assertSame($this->admin->id, $audit->actor_user_id);
        $this->assertSame(['status' => 'active'], $audit->old_values);
        $this->assertSame([
            'status' => 'suspended',
            'reason' => 'Repeated policy violations.',
        ], $audit->new_values);

        Notification::assertSentTo(
            $buyer,
            AccountStatusChangedNotification::class,
            fn (AccountStatusChangedNotification $notification): bool => $notification->toDatabase($buyer)['reason'] === 'Repeated policy violations.'
        );
    }

    public function test_admin_user_pages_render_server_status_context_and_endpoint(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('data-user-status-context', false)
            ->assertSee('data-user-id="'.$buyer->id.'"', false)
            ->assertSee('data-status-url="'.route('admin.users.status', $buyer).'"', false)
            ->assertSee('data-user-status-dialog', false);

        $this->actingAs($this->admin)
            ->get(route('admin.users.buyers'))
            ->assertOk()
            ->assertSee('data-user-status-dialog', false)
            ->assertSee('data-user-id="'.$buyer->id.'"', false);
    }

    public function test_every_user_management_directory_renders_shared_profiles_and_status_actions(): void
    {
        $routes = [
            'all users' => route('admin.users'),
            'buyers' => route('admin.users.buyers'),
            'sellers' => route('admin.users.sellers'),
            'logistics' => route('admin.users.logistics'),
            'riders' => route('admin.users.riders'),
        ];

        foreach (['buyer', 'seller', 'logistics', 'rider'] as $role) {
            User::factory()->create([
                'role' => $role,
                'status' => 'deactivated',
            ]);
        }

        foreach ($routes as $routeName => $url) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                ->assertSee('admin-user-profile-modal', false)
                ->assertSee('admin-user-profile-information', false)
                ->assertSee('data-user-primary-action', false)
                ->assertSee('data-user-status="active"', false)
                ->assertSee('data-user-status-dialog', false, "The {$routeName} directory should include the shared status dialog.");
        }
    }

    public function test_admin_can_reactivate_a_deactivated_rider(): void
    {
        Notification::fake();

        $rider = User::factory()->create([
            'role' => 'rider',
            'status' => 'deactivated',
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.users.status', $rider), [
                'status' => 'active',
                'reason' => 'Account access restored after review.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.allowed_statuses', ['suspended', 'deactivated']);

        $this->assertSame('active', $rider->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.status_changed',
            'actor_user_id' => $this->admin->id,
            'auditable_id' => $rider->id,
        ]);
    }

    public function test_reason_is_required_and_validation_does_not_change_status(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.users.status', $buyer), [
                'status' => 'suspended',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame('active', $buyer->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.status_changed',
            'auditable_id' => $buyer->id,
        ]);
    }

    public function test_invalid_status_transition_is_rejected_without_mutation(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'deactivated',
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.users.status', $buyer), [
                'status' => 'suspended',
                'reason' => 'Attempted invalid transition.',
            ])
            ->assertUnprocessable();

        $this->assertSame('deactivated', $buyer->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.status_changed',
            'auditable_id' => $buyer->id,
        ]);
    }

    public function test_non_admin_cannot_change_an_account_status(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $anotherBuyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($buyer)
            ->patchJson(route('admin.users.status', $anotherBuyer), [
                'status' => 'suspended',
                'reason' => 'Attempted by a Buyer.',
            ])
            ->assertForbidden();

        $this->assertSame('active', $anotherBuyer->fresh()->status);
    }
}
