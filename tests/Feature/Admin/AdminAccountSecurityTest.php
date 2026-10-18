<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function adminFixture(): User
    {
        $admin = User::factory()->create([
            'name' => 'Maria Admin',
            'first_name' => 'Maria',
            'last_name' => 'Admin',
            'email' => 'maria.admin@example.test',
            'phone' => '+639171112233',
            'role' => 'admin',
            'status' => 'active',
            'last_login_at' => now()->subHour(),
        ]);

        $adminRole = Role::query()->firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        return $admin;
    }

    public function test_account_page_shows_the_authenticated_admin_identity(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)
            ->get(route('admin.account'))
            ->assertOk()
            ->assertSee('Maria Admin')
            ->assertSee('maria.admin@example.test')
            ->assertSee('+639171112233')
            ->assertDontSee('Christian Joseph')
            ->assertDontSee('admin123@example.com')
            ->assertDontSee('bearlyAdminProfilePreview');
    }

    public function test_admin_can_change_password_with_current_password_verification(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)
            ->from(route('admin.account'))
            ->patch(route('admin.account.password'), [
                'current_password' => 'password',
                'new_password' => 'brand-new-password',
                'new_password_confirmation' => 'brand-new-password',
            ])
            ->assertRedirect(route('admin.account').'#security')
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('brand-new-password', $admin->fresh()->password));
    }

    public function test_admin_password_change_requires_the_current_password(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)
            ->from(route('admin.account'))
            ->patch(route('admin.account.password'), [
                'current_password' => 'wrong-password',
                'new_password' => 'brand-new-password',
                'new_password_confirmation' => 'brand-new-password',
            ])
            ->assertSessionHasErrors(['current_password']);

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_admin_password_change_validates_length_and_confirmation(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)
            ->from(route('admin.account'))
            ->patch(route('admin.account.password'), [
                'current_password' => 'password',
                'new_password' => 'short',
                'new_password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors(['new_password']);

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_non_admin_users_cannot_change_the_admin_password(): void
    {
        $admin = $this->adminFixture();

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($buyer)
            ->patch(route('admin.account.password'), [
                'current_password' => 'password',
                'new_password' => 'brand-new-password',
                'new_password_confirmation' => 'brand-new-password',
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }
}
