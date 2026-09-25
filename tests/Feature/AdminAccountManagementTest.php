<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_account_page_uses_the_authenticated_admin_record(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.account'))
            ->assertOk()
            ->assertSee('Bearly Admin')
            ->assertSee('admin@bearly.test')
            ->assertSee('data-profile-edit', false)
            ->assertSee(route('admin.account.profile'))
            ->assertSee(route('admin.account.password'))
            ->assertDontSee('admin123@example.com')
            ->assertDontSee('Christian Joseph');
    }

    public function test_admin_can_update_profile_and_change_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.account.profile'), [
                'first_name' => 'Christian Joseph',
                'middle_initial' => 'P',
                'last_name' => 'Aquino',
                'email' => 'christian.admin@example.test',
                'contact_number' => '09171234567',
                'sex' => 'male',
                'birthday' => '2000-05-10',
                'province' => 'Laguna',
                'city' => 'San Pablo City',
                'barangay' => 'San Rafael',
                'street_address' => '12 Bearly Street',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->admin->refresh();
        $log = AuditLog::sole();

        $this->assertSame('Christian Joseph P Aquino', $this->admin->name);
        $this->assertSame('christian.admin@example.test', $this->admin->email);
        $this->assertSame('09171234567', $this->admin->contact_number);
        $this->assertSame('admin.profile_updated', $log->action);
        $this->assertSame('Bearly', $log->old_values['first_name']);
        $this->assertSame('Christian Joseph', $log->new_values['first_name']);
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_profile_email_must_remain_unique(): void
    {
        $other = User::factory()->create(['email' => 'used@example.test']);

        $this->actingAs($this->admin)
            ->patch(route('admin.account.profile'), [
                'first_name' => $this->admin->first_name,
                'last_name' => $this->admin->last_name,
                'email' => $other->email,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('admin@bearly.test', $this->admin->fresh()->email);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->patch(route('admin.account.profile'), [
            'first_name' => $this->admin->first_name,
            'last_name' => $this->admin->last_name,
            'email' => $this->admin->email,
            'avatar' => UploadedFile::fake()->createWithContent('admin.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
            )),
        ])->assertSessionHasNoErrors();

        $path = $this->admin->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_password_change_requires_current_password_and_never_audits_secrets(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.account.password'), [
                'current_password' => 'WrongPassword123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('Password123', $this->admin->fresh()->password));

        $this->actingAs($this->admin)
            ->patch(route('admin.account.password'), [
                'current_password' => 'Password123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertAuthenticatedAs($this->admin);
        $this->assertTrue(Hash::check('NewPassword456', $this->admin->fresh()->password));

        $log = AuditLog::sole();
        $this->assertSame('admin.password_updated', $log->action);
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertStringNotContainsString('NewPassword456', $log->description);
    }
}
