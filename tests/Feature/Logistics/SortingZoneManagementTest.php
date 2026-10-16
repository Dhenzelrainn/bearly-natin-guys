<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use App\Services\SortingZoneMatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SortingZoneManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_logistics_zone_is_automatically_saved_to_its_single_center(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');

        $this->actingAs($provider['user'])
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => $provider['profile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => [
                    'Pinagbayanan',
                    'Wawa',
                ],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '2 zones were created: PILA-01, PILA-02.');

        $zones = SortingZone::query()
            ->where('sorting_center_id', $center->id)
            ->orderBy('code')
            ->get();

        $this->assertCount(2, $zones);
        $this->assertSame('PILA-01', $zones[0]->code);
        $this->assertSame('Pila / Pinagbayanan', $zones[0]->name);
        $this->assertSame([
            'province' => 'Laguna',
            'city_municipality' => 'Pila',
            'psgc_city_code' => '0403422000',
            'barangays' => ['Pinagbayanan'],
        ], $zones[0]->destination_rules);
        $this->assertSame('PILA-02', $zones[1]->code);
        $this->assertSame('Pila / Wawa', $zones[1]->name);
        $this->assertSame([
            'province' => 'Laguna',
            'city_municipality' => 'Pila',
            'psgc_city_code' => '0403422000',
            'barangays' => ['Wawa'],
        ], $zones[1]->destination_rules);

        $this->actingAs($provider['user'])
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => $provider['profile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['San Miguel'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sorting_zones', [
            'sorting_center_id' => $center->id,
            'code' => 'PILA-03',
            'name' => 'Pila / San Miguel',
        ]);
    }

    public function test_zone_batch_creation_is_atomic_when_a_selected_barangay_is_already_covered(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $existingZone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);

        $this->actingAs($provider['user'])
            ->from(route('logistics.sorting.center'))
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => $provider['profile']->psgc_city_code,
                'barangays' => ['Wawa', 'Pinagbayanan'],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasErrors('barangays');

        $this->assertDatabaseCount('sorting_zones', 1);
        $this->assertSame('PILA-01', $existingZone->fresh()->code);
        $this->assertSame(['Pinagbayanan'], $existingZone->fresh()->destination_rules['barangays']);
    }

    public function test_archiving_zone_deactivates_it_unassigns_riders_and_lists_it_in_archive(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);
        $rider = $this->makeRider($provider, $center, $zone);

        $this->actingAs($provider['user'])
            ->patch(route('logistics.sorting.zones.archive', $zone))
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('sorting_zones', ['id' => $zone->id]);
        $this->assertDatabaseHas('sorting_zones', [
            'id' => $zone->id,
            'status' => 'inactive',
        ]);
        $this->assertNull($rider->fresh()->current_zone_id);

        $this->actingAs($provider['user'])
            ->get(route('logistics.sorting.center', ['zone_view' => 'current']))
            ->assertOk()
            ->assertDontSee(route('logistics.sorting.center', ['edit_zone' => $zone->id]));

        $this->actingAs($provider['user'])
            ->get(route('logistics.sorting.center', ['zone_view' => 'archived']))
            ->assertOk()
            ->assertSee('PILA-01')
            ->assertSee('Unarchive')
            ->assertSee('Delete permanently')
            ->assertDontSee('Edit coverage');
    }

    public function test_archiving_zone_is_blocked_while_an_active_dispatch_batch_uses_it(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);
        $rider = $this->makeRider($provider, $center, $zone);
        DispatchBatch::query()->create([
            'batch_no' => 'DB-ZONE-ARCHIVE-01',
            'sorting_center_id' => $center->id,
            'sorting_zone_id' => $zone->id,
            'rider_profile_id' => $rider->id,
            'status' => 'loading',
        ]);

        $this->actingAs($provider['user'])
            ->from(route('logistics.sorting.center'))
            ->patch(route('logistics.sorting.zones.archive', $zone))
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasErrors('status');

        $this->assertNotSoftDeleted('sorting_zones', ['id' => $zone->id]);
        $this->assertSame('active', $zone->fresh()->status);
    }

    public function test_archived_zone_can_be_restored_and_automatically_activated(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);
        $rider = $this->makeRider($provider, $center, $zone);

        $this->actingAs($provider['user'])
            ->patch(route('logistics.sorting.zones.archive', $zone))
            ->assertSessionHasNoErrors();

        $this->actingAs($provider['user'])
            ->patch(route('logistics.sorting.zones.restore', $zone->id))
            ->assertRedirect(route('logistics.sorting.center', ['zone_view' => 'archived']))
            ->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted('sorting_zones', ['id' => $zone->id]);
        $this->assertSame('active', $zone->fresh()->status);
        $this->assertSame($zone->id, $rider->fresh()->current_zone_id);
    }

    public function test_archived_zone_cannot_be_restored_when_active_coverage_now_overlaps(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $archivedZone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);
        $activeZone = $this->makeZone($center, 'PILA-02', ['Pinagbayanan']);
        $archivedZone->update(['status' => 'inactive']);
        $archivedZone->delete();

        $this->actingAs($provider['user'])
            ->from(route('logistics.sorting.center', ['zone_view' => 'archived']))
            ->patch(route('logistics.sorting.zones.restore', $archivedZone->id))
            ->assertRedirect(route('logistics.sorting.center', ['zone_view' => 'archived']))
            ->assertSessionHasErrors('status');

        $this->assertSoftDeleted('sorting_zones', ['id' => $archivedZone->id]);
        $this->assertSame('active', $activeZone->fresh()->status);
    }

    public function test_permanent_delete_requires_archive_and_preserves_dispatch_history_with_zone_reference_cleared(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);
        $rider = $this->makeRider($provider, $center, $zone);
        $batch = DispatchBatch::query()->create([
            'batch_no' => 'DB-ZONE-PURGE-01',
            'sorting_center_id' => $center->id,
            'sorting_zone_id' => $zone->id,
            'rider_profile_id' => $rider->id,
            'status' => 'completed',
        ]);

        $this->actingAs($provider['user'])
            ->delete(route('logistics.sorting.zones.destroy', $zone->id))
            ->assertNotFound();
        $this->assertDatabaseHas('sorting_zones', ['id' => $zone->id]);

        $this->actingAs($provider['user'])
            ->patch(route('logistics.sorting.zones.archive', $zone))
            ->assertSessionHasNoErrors();

        $this->actingAs($provider['user'])
            ->delete(route('logistics.sorting.zones.destroy', $zone->id))
            ->assertRedirect(route('logistics.sorting.center', ['zone_view' => 'archived']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sorting_zones', ['id' => $zone->id]);
        $this->assertDatabaseHas('dispatch_batches', [
            'id' => $batch->id,
            'sorting_zone_id' => null,
        ]);
        $this->assertDatabaseHas('rider_profiles', [
            'id' => $rider->id,
            'current_zone_id' => null,
        ]);
    }

    public function test_archived_zone_code_is_not_reused_for_a_new_zone(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);
        $zone->update(['status' => 'inactive']);
        $zone->delete();

        $this->actingAs($provider['user'])
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => $provider['profile']->psgc_city_code,
                'barangays' => ['Wawa'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sorting_zones', [
            'sorting_center_id' => $center->id,
            'code' => 'PILA-02',
        ]);
    }

    public function test_archived_zone_restore_and_permanent_delete_are_provider_scoped(): void
    {
        $owner = $this->makeProvider('A');
        $foreignProvider = $this->makeProvider('B');
        $center = $this->makeCenter($foreignProvider, 'B');
        $zone = $this->makeZone($center, 'SANP-01', ['San Rafael']);
        $zone->update(['status' => 'inactive']);
        $zone->delete();

        $this->actingAs($owner['user'])
            ->patch(route('logistics.sorting.zones.restore', $zone->id))
            ->assertNotFound();
        $this->actingAs($owner['user'])
            ->delete(route('logistics.sorting.zones.destroy', $zone->id))
            ->assertNotFound();

        $this->assertSoftDeleted('sorting_zones', ['id' => $zone->id]);
    }

    public function test_zone_management_searches_coverage_and_paginates_results(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');

        for ($sequence = 1; $sequence <= 13; $sequence++) {
            $code = sprintf('PILA-%02d', $sequence);
            $this->makeZone($center, $code, ["Barangay {$sequence}"]);
        }

        $this->actingAs($provider['user'])
            ->get(route('logistics.sorting.center', [
                'zone_view' => 'current',
                'zone_search' => 'Barangay 13',
            ]))
            ->assertOk()
            ->assertSee('PILA-13')
            ->assertDontSee('PILA-01');

        $this->actingAs($provider['user'])
            ->get(route('logistics.sorting.center', [
                'zone_view' => 'current',
                'zone_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Page 2 of 2')
            ->assertSee('PILA-13')
            ->assertDontSee('PILA-01');
    }

    public function test_logistics_cannot_create_a_zone_under_another_providers_center(): void
    {
        $providerA = $this->makeProvider('A');
        $providerB = $this->makeProvider('B');
        $foreignCenter = $this->makeCenter($providerB, 'B');

        $this->actingAs($providerA['user'])
            ->from(route('logistics.sorting.center'))
            ->post(route('logistics.sorting.zones.store'), [
                'sorting_center_id' => $foreignCenter->id,
                'city_code' => $providerA['profile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Pinagbayanan'],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasErrors('sorting_center_id');

        $this->assertDatabaseCount('sorting_zones', 0);
    }

    public function test_zone_city_code_is_bound_to_the_logistics_service_city(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');

        $this->actingAs($provider['user'])
            ->from(route('logistics.sorting.center'))
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => '0403424000',
                'barangays' => ['Pinagbayanan'],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasErrors('city_code');

        $this->assertDatabaseCount('sorting_zones', 0);
    }

    public function test_psgc_city_code_is_authoritative_when_city_labels_differ(): void
    {
        $matcher = app(SortingZoneMatcher::class);
        $coverage = [
            'province' => 'Laguna',
            'city_municipality' => 'City of San Pablo',
            'psgc_city_code' => '0403424000',
            'barangays' => ['San Rafael'],
        ];

        $this->assertTrue($matcher->coversDestination($coverage, [
            'province' => 'Laguna',
            'city_municipality' => 'San Pablo City',
            'psgc_city_code' => '0403424000',
            'barangay' => 'San Rafael',
        ]));

        $this->assertFalse($matcher->coversDestination($coverage, [
            'province' => 'Laguna',
            'city_municipality' => 'City of San Pablo',
            'psgc_city_code' => '0403422000',
            'barangay' => 'San Rafael',
        ]));
    }

    public function test_active_zone_coverage_cannot_overlap_at_the_same_center(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');

        SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'code' => 'PILA-01',
            'name' => 'Pila / Pinagbayanan',
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Pinagbayanan'],
            ],
            'status' => 'active',
        ]);

        $this->actingAs($provider['user'])
            ->from(route('logistics.sorting.center'))
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => $provider['profile']->psgc_city_code,
                'province' => 'laguna',
                'city_municipality' => 'PILA',
                'barangays' => ['pinagbayanan'],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasErrors('barangays');

        $this->assertDatabaseCount('sorting_zones', 1);
    }

    public function test_logistics_can_edit_coverage_for_an_unused_owned_zone(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);

        $this->actingAs($provider['user'])
            ->patch(route('logistics.sorting.zones.update', $zone), [
                'city_code' => $provider['profile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Wawa'],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Zone PILA-01 coverage was updated.');

        $zone->refresh();

        $this->assertSame('Pila / Wawa', $zone->name);
        $this->assertSame([
            'province' => 'Laguna',
            'city_municipality' => 'Pila',
            'psgc_city_code' => '0403422000',
            'barangays' => ['Wawa'],
        ], $zone->destination_rules);
    }

    public function test_zone_coverage_changes_automatically_refresh_rider_barangay_assignment(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = $this->makeZone($center, 'PILA-01', ['Pinagbayanan']);
        $riderUser = User::factory()->create([
            'name' => 'Assigned Rider',
            'email' => 'assigned-rider-zone@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Active->value,
        ]);
        Address::query()->create([
            'user_id' => $riderUser->id,
            'label' => 'Home',
            'recipient_name' => $riderUser->name,
            'phone' => '09170000002',
            'street' => 'Rider Street',
            'barangay' => 'Pinagbayanan',
            'city_municipality' => 'Pila',
            'province' => 'Laguna',
            'postal_code' => '4010',
            'psgc_city_code' => '0403422000',
        ]);
        $rider = RiderProfile::query()->create([
            'user_id' => $riderUser->id,
            'logistics_profile_id' => $provider['profile']->id,
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
            'vehicle_type' => 'Motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        $this->actingAs($provider['user'])
            ->from(route('logistics.sorting.center', ['edit_zone' => $zone->id]))
            ->patch(route('logistics.sorting.zones.update', $zone), [
                'city_code' => $provider['profile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Wawa'],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Wawa'], $zone->fresh()->destination_rules['barangays']);
        $this->assertNull($rider->fresh()->current_zone_id);

        $this->actingAs($provider['user'])
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => $provider['profile']->psgc_city_code,
                'barangays' => ['Pinagbayanan'],
            ])
            ->assertSessionHasNoErrors();

        $matchingZone = SortingZone::query()
            ->where('sorting_center_id', $center->id)
            ->where('id', '<>', $zone->id)
            ->firstOrFail();
        $this->assertSame($matchingZone->id, $rider->fresh()->current_zone_id);

        $this->actingAs($provider['user'])
            ->patch(route('logistics.sorting.zones.status', $zone), [
                'status' => 'inactive',
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasNoErrors();

        $this->assertSame('inactive', $zone->fresh()->status);
        $this->assertSame($matchingZone->id, $rider->fresh()->current_zone_id);
    }

    public function test_logistics_cannot_edit_another_providers_zone(): void
    {
        $providerA = $this->makeProvider('A');
        $providerB = $this->makeProvider('B');
        $foreignCenter = $this->makeCenter($providerB, 'B');
        $foreignZone = $this->makeZone($foreignCenter, 'PILA-01', ['Pinagbayanan']);

        $this->actingAs($providerA['user'])
            ->patch(route('logistics.sorting.zones.update', $foreignZone), [
                'city_code' => $providerA['profile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Wawa'],
            ])
            ->assertNotFound();

        $this->assertSame(['Pinagbayanan'], $foreignZone->fresh()->destination_rules['barangays']);
    }

    public function test_inactive_zone_cannot_be_activated_with_overlapping_coverage(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $activeZone = $this->makeZone($center, 'PILA-01', ['Wawa']);
        $inactiveZone = $this->makeZone($center, 'PILA-02', ['Wawa']);
        $inactiveZone->update(['status' => 'inactive']);

        $this->actingAs($provider['user'])
            ->from(route('logistics.sorting.center'))
            ->patch(route('logistics.sorting.zones.status', $inactiveZone), [
                'status' => 'active',
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $activeZone->fresh()->status);
        $this->assertSame('inactive', $inactiveZone->fresh()->status);
    }

    public function test_sorting_center_displays_zone_creation_and_coverage(): void
    {
        $provider = $this->makeProvider('A');
        $center = $this->makeCenter($provider, 'A');
        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'code' => 'PILA-01',
            'name' => 'Pila / Pinagbayanan',
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Pinagbayanan'],
            ],
            'status' => 'active',
        ]);

        $this->actingAs($provider['user'])
            ->get(route('logistics.sorting.center'))
            ->assertOk()
            ->assertSee('Create delivery zones')
            ->assertSee('A separate zone will be created for each selection.')
            ->assertSee('Create zones')
            ->assertSee('Your Sorting Center')
            ->assertSee('class="metric-strip sorting-metric-strip"', false)
            ->assertDontSee('name="sorting_center_id"', false)
            ->assertSee('Edit coverage')
            ->assertSee('Archive')
            ->assertSee('PILA-01')
            ->assertSee('Laguna / Pila · Pinagbayanan');

        $this->actingAs($provider['user'])
            ->get(route('logistics.sorting.center', ['edit_zone' => $zone->id]))
            ->assertOk()
            ->assertSee('Edit zone coverage')
            ->assertSee('data-city-code="0403422000"', false)
            ->assertSee('value="Pila"', false);
    }

    /** @return array{user: User, profile: LogisticsProfile, address: Address} */
    private function makeProvider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Zone Logistics {$suffix}",
            'email' => "zone-logistics-{$suffix}@example.test",
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        $roleId = Role::query()
            ->where('name', UserRole::Logistics->value)
            ->value('id');
        $user->roles()->syncWithoutDetaching([$roleId]);

        $cityCode = $suffix === 'A' ? '0403422000' : '0403424000';
        $city = $suffix === 'A' ? 'Pila' : 'City of San Pablo';
        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => $user->name,
            'phone' => '09170000001',
            'street' => "Service Road {$suffix}",
            'barangay' => 'San Rafael',
            'city_municipality' => $city,
            'province' => 'Laguna',
            'postal_code' => $suffix === 'A' ? '4010' : '4000',
            'psgc_city_code' => $cityCode,
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Zone Logistics {$suffix} Inc.",
            'display_name' => "Zone Logistics {$suffix}",
            'contact_phone' => '09170000001',
            'psgc_city_code' => $cityCode,
            'status' => 'active',
        ]);

        return compact('user', 'profile', 'address');
    }

    private function makeCenter(array $provider, string $suffix): SortingCenter
    {
        $address = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => "Sorting Center {$suffix}",
            'recipient_name' => $provider['user']->name,
            'phone' => '09170000001',
            'street' => "Hub Road {$suffix}",
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);

        return SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $address->id,
            'name' => "Sorting Center {$suffix}",
            'code' => "ZONE-CENTER-{$suffix}-{$provider['profile']->id}",
            'contact_phone' => '09170000001',
            'status' => 'active',
        ]);
    }

    private function makeRider(
        array $provider,
        SortingCenter $center,
        SortingZone $zone
    ): RiderProfile {
        $user = User::factory()->create([
            'name' => 'Zone Test Rider',
            'email' => 'zone-rider-'.$provider['profile']->id.'-'.$center->id.'-'.User::query()->count().'@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Active->value,
        ]);
        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '09170000002',
            'street' => 'Rider Street',
            'barangay' => 'Pinagbayanan',
            'city_municipality' => 'Pila',
            'province' => 'Laguna',
            'postal_code' => '4010',
            'psgc_city_code' => '0403422000',
        ]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider['profile']->id,
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
            'vehicle_type' => 'Motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
    }

    private function makeZone(
        SortingCenter $center,
        string $code,
        array $barangays
    ): SortingZone {
        return SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'code' => $code,
            'name' => 'Pila / '.$barangays[0],
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'psgc_city_code' => $center->logisticsProfile->psgc_city_code,
                'barangays' => $barangays,
            ],
            'status' => 'active',
        ]);
    }
}
