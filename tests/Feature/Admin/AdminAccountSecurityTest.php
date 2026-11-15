<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_admin_can_upload_replace_and_remove_their_own_profile_photo(): void
    {
        Storage::fake('public');
        $admin = $this->adminFixture();

        $this->actingAs($admin)
            ->patch(route('admin.account.photo.update'), [
                'profile_photo' => UploadedFile::fake()->create('admin-photo.png', 500, 'image/png'),
            ])
            ->assertRedirect(route('admin.account').'#profile-photo-editor')
            ->assertSessionHas('success');

        $firstPhoto = $admin->fresh()->profile_photo_path;
        $this->assertNotEmpty($firstPhoto);
        Storage::disk('public')->assertExists($firstPhoto);

        $photoUrl = Storage::disk('public')->url($firstPhoto);
        $this->actingAs($admin)
            ->get(route('admin.account'))
            ->assertOk()
            ->assertSee($photoUrl, false)
            ->assertSee('Remove photo');

        $this->actingAs($admin)
            ->patch(route('admin.account.photo.update'), [
                'profile_photo' => UploadedFile::fake()->create('replacement.webp', 500, 'image/webp'),
            ])
            ->assertRedirect(route('admin.account').'#profile-photo-editor');

        $replacementPhoto = $admin->fresh()->profile_photo_path;
        $this->assertNotSame($firstPhoto, $replacementPhoto);
        Storage::disk('public')->assertMissing($firstPhoto);
        Storage::disk('public')->assertExists($replacementPhoto);

        $this->actingAs($admin)
            ->delete(route('admin.account.photo.destroy'))
            ->assertRedirect(route('admin.account').'#profile-photo-editor')
            ->assertSessionHas('success');

        $this->assertNull($admin->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($replacementPhoto);
    }

    public function test_admin_profile_photo_appears_in_the_shared_header_and_popover_on_admin_pages(): void
    {
        Storage::fake('public');
        $admin = $this->adminFixture();
        $photoPath = UploadedFile::fake()->create('admin.png', 500, 'image/png')->store('profile-photos', 'public');
        $admin->forceFill(['profile_photo_path' => $photoPath])->save();

        $photoMarkup = '<img class="avatar-photo" src="'.Storage::disk('public')->url($photoPath).'" alt="">';

        foreach ([
            route('admin.dashboard') => 2,
            route('admin.account') => 4,
        ] as $url => $expectedPhotoCount) {
            $response = $this->actingAs($admin)->get($url)->assertOk();

            $this->assertSame(
                $expectedPhotoCount,
                substr_count($response->getContent(), $photoMarkup),
                "Expected {$expectedPhotoCount} rendered profile photos on {$url}.",
            );
        }
    }

    public function test_admin_profile_photo_rejects_invalid_uploads_and_non_admins(): void
    {
        Storage::fake('public');
        $admin = $this->adminFixture();

        $this->actingAs($admin)
            ->from(route('admin.account'))
            ->patch(route('admin.account.photo.update'), [
                'profile_photo' => UploadedFile::fake()->create('notes.txt', 30, 'text/plain'),
            ])
            ->assertSessionHasErrors('profile_photo');

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($buyer)
            ->patch(route('admin.account.photo.update'), [
                'profile_photo' => UploadedFile::fake()->create('buyer.png', 100, 'image/png'),
            ])
            ->assertForbidden();

        $this->assertNull($admin->fresh()->profile_photo_path);
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
