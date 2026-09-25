<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedRoleRoutes(): array
    {
        return [
            'admin' => ['admin.dashboard', UserRole::Admin],
            'buyer' => ['home', UserRole::Buyer],
            'seller' => ['seller.dashboard', UserRole::Seller],
            'logistics' => ['logistics.dashboard', UserRole::Logistics],
            'rider' => ['rider.dashboard.pickups', UserRole::Rider],
        ];
    }

    #[DataProvider('protectedRoleRoutes')]
    public function test_guests_are_redirected_from_role_workspaces(string $routeName, UserRole $role): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    #[DataProvider('protectedRoleRoutes')]
    public function test_wrong_roles_cannot_open_role_workspaces(string $routeName, UserRole $role): void
    {
        $wrongRole = $role === UserRole::Buyer ? UserRole::Seller : UserRole::Buyer;
        $user = User::factory()->create([
            'role' => $wrongRole->value,
            'status' => AccountStatus::Active->value,
        ]);

        $this->actingAs($user)->get(route($routeName))->assertForbidden();
    }

    #[DataProvider('protectedRoleRoutes')]
    public function test_active_users_can_open_their_own_role_workspace(string $routeName, UserRole $role): void
    {
        $user = User::factory()->create([
            'role' => $role->value,
            'status' => AccountStatus::Active->value,
        ]);

        $this->actingAs($user)->get(route($routeName))->assertOk();
    }

    #[DataProvider('protectedRoleRoutes')]
    public function test_inactive_users_cannot_open_role_workspaces(string $routeName, UserRole $role): void
    {
        $user = User::factory()->create([
            'role' => $role->value,
            'status' => AccountStatus::Suspended->value,
        ]);

        $this->actingAs($user)->get(route($routeName))->assertForbidden();
    }
}
