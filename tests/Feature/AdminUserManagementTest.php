<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_all_user_directories_display_real_role_scoped_accounts(): void
    {
        $buyer = $this->makeUser('buyer', 'Real Buyer');
        $seller = $this->makeUser('seller', 'Real Seller');
        $logistics = $this->makeUser('logistics', 'Real Logistics');
        $rider = $this->makeUser('rider', 'Real Rider');

        $cases = [
            'admin.users.buyers' => [$buyer, 'Real Seller', 'BUY-000001', 'buyer-user-'],
            'admin.users.sellers' => [$seller, 'Real Buyer', 'Mara Home Goods', 'seller-user-'],
            'admin.users.logistics' => [$logistics, 'Real Buyer', 'Laguna Central Logistics', 'logistics-user-'],
            'admin.users.riders' => [$rider, 'Real Buyer', 'Jared Molina', 'rider-user-'],
        ];

        foreach ($cases as $route => [$expected, $unexpected, $mockValue, $modalPrefix]) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->assertSee($expected->name)
                ->assertSee(route('admin.users.show', $expected))
                ->assertSee('data-open-modal="'.$modalPrefix, false)
                ->assertSee('data-modal="'.$modalPrefix, false)
                ->assertDontSee($unexpected)
                ->assertDontSee($mockValue);
        }
    }

    public function test_user_detail_is_admin_only_and_excludes_pending_accounts(): void
    {
        $user = $this->makeUser('buyer', 'Managed Buyer');

        $this->get(route('admin.users.show', $user))->assertRedirect(route('login'));

        $otherBuyer = $this->makeUser('buyer', 'Other Buyer');
        $this->actingAs($otherBuyer)->get(route('admin.users.show', $user))->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('Managed Buyer')
            ->assertSee('Change account status');

        $pending = User::factory()->create([
            'role' => 'seller',
            'status' => AccountStatus::Pending->value,
        ]);
        $this->actingAs($this->admin)->get(route('admin.users.show', $pending))->assertNotFound();
    }

    public function test_status_change_requires_reason_and_records_audit_and_notification(): void
    {
        $user = $this->makeUser('seller', 'Status Seller');
        $url = route('admin.users.status', $user);

        $this->actingAs($this->admin)
            ->patch($url, ['status' => AccountStatus::Suspended->value])
            ->assertSessionHasErrors('reason');

        $this->assertSame(AccountStatus::Active->value, $user->fresh()->status);

        $this->actingAs($this->admin)
            ->patch($url, [
                'status' => AccountStatus::Suspended->value,
                'reason' => 'Repeated marketplace policy violations.',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $log = AuditLog::sole();

        $this->assertSame(AccountStatus::Suspended->value, $user->status);
        $this->assertNotNull($user->suspended_at);
        $this->assertSame('user.status_changed', $log->action);
        $this->assertSame('Repeated marketplace policy violations.', $log->new_values['reason']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_admin_can_reactivate_and_deactivate_using_allowed_transitions(): void
    {
        $user = $this->makeUser('logistics', 'Transition Logistics', AccountStatus::Suspended->value);
        $user->forceFill(['suspended_at' => now()])->save();
        $url = route('admin.users.status', $user);

        $this->actingAs($this->admin)->patch($url, [
            'status' => AccountStatus::Active->value,
            'reason' => 'Compliance review completed.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(AccountStatus::Active->value, $user->fresh()->status);
        $this->assertNull($user->fresh()->suspended_at);

        $this->actingAs($this->admin)->patch($url, [
            'status' => AccountStatus::Deactivated->value,
            'reason' => 'Operator requested account closure.',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(AccountStatus::Deactivated->value, $user->status);
        $this->assertNotNull($user->deactivated_at);

        $this->actingAs($this->admin)->patch($url, [
            'status' => AccountStatus::Suspended->value,
            'reason' => 'This transition is not allowed.',
        ])->assertUnprocessable();

        $this->assertSame(AccountStatus::Deactivated->value, $user->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    private function makeUser(
        string $role,
        string $name,
        string $status = AccountStatus::Active->value,
    ): User {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'status' => $status,
            'business_name' => in_array($role, ['seller', 'logistics'], true) ? $name.' Business' : null,
        ]);
    }
}
