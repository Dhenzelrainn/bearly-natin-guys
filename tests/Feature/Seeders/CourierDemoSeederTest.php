<?php

namespace Tests\Feature\Seeders;

use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CourierDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class CourierDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_idempotent_available_courier_for_an_active_logistics_provider(): void
    {
        [$sponsor, $sponsorProfile] = $this->makeLogisticsSponsor();

        $seeder = $this->app->make(CourierDemoSeeder::class);
        $seeder->run();
        $seeder->run();

        $courier = User::query()
            ->where('email', 'courier.demo@bearly.test')
            ->firstOrFail();
        $profile = RiderProfile::query()
            ->where('user_id', $courier->id)
            ->firstOrFail();
        $application = AccountApplication::query()
            ->where('application_no', 'DEMO-COURIER-0001')
            ->firstOrFail();

        $this->assertSame('rider', $courier->role);
        $this->assertSame('active', $courier->status);
        $this->assertTrue($courier->hasRole('rider'));
        $this->assertTrue(Hash::check('Password123', $courier->password));
        $this->assertSame((int) $sponsor->id, (int) $courier->approved_by);
        $this->assertSame((int) $sponsor->id, (int) $courier->logistics_id);

        $this->assertSame((int) $sponsorProfile->id, (int) $profile->logistics_profile_id);
        $this->assertSame('courier', $profile->work_type);
        $this->assertSame('Van', $profile->vehicle_type);
        $this->assertSame('available', $profile->availability_status);
        $this->assertSame('approved', $profile->verification_status);
        $this->assertSame(10, (int) $profile->linehaul_parcel_capacity);
        $this->assertSame((int) $sponsor->id, (int) $profile->courier_vehicle_verified_by);
        $this->assertNotNull($profile->courier_vehicle_verified_at);

        $this->assertSame((int) $courier->id, (int) $application->user_id);
        $this->assertSame((int) $sponsorProfile->id, (int) $application->sponsor_logistics_profile_id);
        $this->assertSame('approved', $application->status);
        $this->assertSame('courier', $application->requested_work_type);
        $this->assertSame(1, User::query()->where('email', 'courier.demo@bearly.test')->count());
        $this->assertSame(1, RiderProfile::query()->where('user_id', $courier->id)->count());
        $this->assertSame(1, AccountApplication::query()->where('application_no', 'DEMO-COURIER-0001')->count());
        $this->assertSame(1, Address::query()->where('user_id', $courier->id)->where('label', 'Home')->count());
        $this->assertSame(1, $courier->roles()->where('roles.name', 'rider')->count());
        $this->assertSame(0, $application->documents()->count());

        RiderShift::query()->create([
            'rider_profile_id' => $profile->id,
            'checked_in_at' => Carbon::parse('2026-11-04 06:45:00', 'UTC'),
            'checked_out_at' => Carbon::parse('2026-11-04 07:45:00', 'UTC'),
            'check_in_source' => 'rider',
            'check_out_source' => 'rider',
        ]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $courier->id,
            'role_id' => Role::query()->where('name', 'rider')->value('id'),
            'assigned_by' => $sponsor->id,
        ]);

        $this->actingAs($sponsor)
            ->get(route('logistics.riders.index'))
            ->assertOk()
            ->assertSee('Demo Courier')
            ->assertSee('Van');

        $this->actingAs($sponsor)
            ->get(route('logistics.riders.show', $courier->id))
            ->assertOk()
            ->assertSee('DEMO-COURIER-0001')
            ->assertSee('Demo four-wheel van')
            ->assertSee('No registration documents were recorded.')
            ->assertSee('Work date (PHT)')
            ->assertSee('Check-in (PHT)')
            ->assertSee('Check-out (PHT)')
            ->assertSee('Nov 4, 2026')
            ->assertSee('2:45 PM')
            ->assertSee('3:45 PM');
    }

    public function test_it_refuses_to_seed_outside_local_or_testing(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            try {
                $this->app->make(CourierDemoSeeder::class)->run();
                $this->fail('The demo seeder should reject production environments.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'CourierDemoSeeder may only run in local or testing environments.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'courier.demo@bearly.test',
        ]);
    }

    public function test_it_requires_an_active_city_matched_logistics_sponsor_before_writing(): void
    {
        try {
            $this->app->make(CourierDemoSeeder::class)->run();
            $this->fail('The demo seeder should require an active Logistics sponsor.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'needs an active Logistics account',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'courier.demo@bearly.test',
        ]);
        $this->assertDatabaseMissing('roles', [
            'name' => 'rider',
        ]);
    }

    /** @return array{0: User, 1: LogisticsProfile} */
    private function makeLogisticsSponsor(): array
    {
        $logisticsRole = Role::query()->firstOrCreate(
            ['name' => 'logistics'],
            ['display_name' => 'Logistics'],
        );

        $sponsor = User::factory()->create([
            'name' => 'Demo Logistics Provider',
            'first_name' => 'Demo',
            'last_name' => 'Logistics',
            'email' => 'demo-logistics@example.test',
            'role' => 'logistics',
            'status' => 'active',
        ]);
        $sponsor->roles()->syncWithoutDetaching([
            $logisticsRole->id => ['assigned_at' => now()],
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $sponsor->id,
            'legal_name' => 'Demo Logistics Provider Inc.',
            'display_name' => 'Demo Logistics Provider',
            'contact_phone' => '091700000099',
            'psgc_city_code' => '0403424000',
            'status' => 'active',
        ]);

        Address::query()->create([
            'user_id' => $sponsor->id,
            'label' => 'Sorting center',
            'recipient_name' => $sponsor->name,
            'phone' => $profile->contact_phone,
            'street' => 'Demo Logistics Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'City of San Pablo',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'psgc_city_code' => '0403424000',
        ]);

        return [$sponsor, $profile];
    }
}
