<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_application_is_saved_for_admin_approval(): void
    {
        Storage::fake('local');

        $this->post(route('register.submit'), [
            'role' => UserRole::Buyer->value,
            'first_name' => 'Bianca',
            'last_name' => 'Santos',
            'middle_initial' => 'M',
            'sex' => 'female',
            'birthday' => '2000-05-10',
            'email' => 'bianca@example.test',
            'contact_number' => '09171234567',
            'province' => 'Laguna',
            'city' => 'San Pablo City',
            'barangay' => 'San Rafael',
            'street_name' => 'Bearly Street',
            'house_number' => '12',
            'postal_code' => '4000',
            'valid_id' => UploadedFile::fake()->create('buyer-id.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ])->assertRedirect(route('application.pending'));

        $buyer = User::where('email', 'bianca@example.test')->firstOrFail();
        $this->assertSame(UserRole::Buyer->value, $buyer->role);
        $this->assertSame(AccountStatus::Pending->value, $buyer->status);
        $this->assertGuest();
        Storage::disk('local')->assertExists($buyer->valid_id_path);
    }

    public function test_logistics_application_is_saved_for_admin_approval(): void
    {
        Storage::fake('local');

        $this->post(route('logistics.register.submit'), [
            'business_name' => 'Laguna Test Logistics',
            'first_name' => 'Mika',
            'last_name' => 'Santos',
            'middle_initial' => 'R',
            'sex' => 'Female',
            'email' => 'mika@example.test',
            'contact_number' => '09181234567',
            'birthday' => '1995-03-20',
            'province' => 'Laguna',
            'municipality' => 'Santa Cruz',
            'barangay' => 'Poblacion',
            'street' => 'Guevara Avenue',
            'house_number' => '8',
            'valid_id' => UploadedFile::fake()->create('logistics-id.pdf', 100, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect(route('logistics.register'));

        $logistics = User::where('email', 'mika@example.test')->firstOrFail();
        $this->assertSame(UserRole::Logistics->value, $logistics->role);
        $this->assertSame(AccountStatus::Pending->value, $logistics->status);
        Storage::disk('local')->assertExists($logistics->valid_id_path);
        Storage::disk('local')->assertExists($logistics->business_permit_path);
    }

    public function test_rider_application_belongs_to_the_selected_active_logistics_account(): void
    {
        Storage::fake('local');
        $logistics = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => 'Approved Logistics',
        ]);

        $this->post(route('rider.register.submit'), [
            'logistics_partner' => $logistics->id,
            'first_name' => 'Nico',
            'last_name' => 'Flores',
            'middle_initial' => 'D',
            'sex' => 'Male',
            'email' => 'nico@example.test',
            'contact_number' => '09191234567',
            'birthday' => '1998-05-14',
            'province' => 'Laguna',
            'municipality' => 'San Pablo City',
            'barangay' => 'San Rafael',
            'street' => 'Rider Road',
            'house_number' => '3',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'abc 1234',
            'or_cr' => UploadedFile::fake()->create('or-cr.pdf', 100, 'application/pdf'),
            'driver_license' => UploadedFile::fake()->create('license.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect(route('rider.register'));

        $rider = User::where('email', 'nico@example.test')->firstOrFail();
        $this->assertSame(UserRole::Rider->value, $rider->role);
        $this->assertSame(AccountStatus::Pending->value, $rider->status);
        $this->assertSame($logistics->id, $rider->logistics_id);
        Storage::disk('local')->assertExists($rider->or_cr_path);
        Storage::disk('local')->assertExists($rider->driver_license_path);
    }

    public function test_admin_can_approve_buyer_seller_and_logistics_applications(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'status' => AccountStatus::Active->value,
        ]);

        foreach (UserRole::adminApproved() as $role) {
            $application = User::factory()->create([
                'role' => $role,
                'status' => AccountStatus::Pending->value,
            ]);

            $this->actingAs($admin)
                ->post(route('admin.applications.approve', $application))
                ->assertSessionHasNoErrors();

            $application->refresh();
            $this->assertSame(AccountStatus::Active->value, $application->status);
            $this->assertSame($admin->id, $application->approved_by);
            $this->assertNotNull($application->approved_at);
        }
    }

    public function test_logistics_can_only_approve_its_own_rider_applicant(): void
    {
        $logistics = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);
        $otherLogistics = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);
        $rider = User::factory()->create([
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'logistics_id' => $logistics->id,
        ]);

        $this->actingAs($otherLogistics)
            ->post(route('logistics.riders.approve', $rider))
            ->assertForbidden();

        $this->actingAs($logistics)
            ->post(route('logistics.riders.approve', $rider))
            ->assertSessionHasNoErrors();

        $rider->refresh();
        $this->assertSame(AccountStatus::Active->value, $rider->status);
        $this->assertSame($logistics->id, $rider->approved_by);
    }

    public function test_pending_accounts_cannot_enter_a_role_dashboard(): void
    {
        $seller = User::factory()->create([
            'email' => 'pending-seller@example.test',
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Pending->value,
        ]);

        $this->post(route('login.submit'), [
            'email' => $seller->email,
            'password' => 'password',
        ])->assertRedirect(route('application.pending'));

        $this->assertGuest();
    }

    public function test_active_users_are_sent_to_their_role_dashboard(): void
    {
        $logistics = User::factory()->create([
            'email' => 'active-logistics@example.test',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        $this->post(route('login.submit'), [
            'email' => $logistics->email,
            'password' => 'password',
        ])->assertRedirect(route('logistics.dashboard'));

        $this->assertAuthenticatedAs($logistics);
    }
}
