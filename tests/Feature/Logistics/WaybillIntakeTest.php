<?php

namespace Tests\Feature\Logistics;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\ParcelIntakeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaybillIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $logisticsA;
    private User $logisticsB;
    private LogisticsProfile $profileA;
    private LogisticsProfile $profileB;
    private SortingCenter $centerA;
    private SortingCenter $centerB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->logisticsA, $this->profileA, $this->centerA] =
            $this->makeLogisticsProvider('A');

        [$this->logisticsB, $this->profileB, $this->centerB] =
            $this->makeLogisticsProvider('B');
    }

    public function test_logistics_can_look_up_its_own_waybill(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A1');

        $this->actingAs($this->logisticsA)
            ->getJson(
                route(
                    'logistics.waybills.lookup',
                    $waybill->waybill_no
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.waybill_no',
                $waybill->waybill_no
            )
            ->assertJsonPath(
                'data.status',
                ShipmentStatus::PickedUp->value
            );
    }

    public function test_logistics_cannot_look_up_another_providers_waybill(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileB, 'B1');

        $this->actingAs($this->logisticsA)
            ->getJson(
                route(
                    'logistics.waybills.lookup',
                    $waybill->waybill_no
                )
            )
            ->assertNotFound();
    }

    public function test_logistics_can_receive_its_own_parcel_into_its_own_center(): void
    {
        [
            'shipment' => $shipment,
            'waybill' => $waybill,
            'parcel' => $parcel,
        ] = $this->makeShipmentFor(
            $this->profileA,
            'A2'
        );

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerA->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Parcel received at sorting center.'
            );

        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'status' => ParcelStatus::Received->value,
            'current_sorting_center_id' => $this->centerA->id,
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'status' => ShipmentStatus::AtSortingCenter->value,
        ]);

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->id,
            'parcel_id' => $parcel->id,
            'event_type' => 'received_at_center',
            'from_status' => ParcelStatus::PickedUp->value,
            'to_status' => ParcelStatus::Received->value,
            'sorting_center_id' => $this->centerA->id,
            'actor_user_id' => $this->logisticsA->id,
            'source' => 'scan',
            'scan_method' => 'manual',
        ]);
    }

    public function test_logistics_cannot_receive_into_another_providers_center(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A3');

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerB->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertNotFound();
    }

    public function test_logistics_cannot_receive_another_providers_shipment(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileB, 'B2');

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerA->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertNotFound();
    }

    public function test_duplicate_intake_returns_conflict(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A4');

        $url = route(
            'logistics.waybills.receive',
            $waybill->waybill_no
        );

        $this->actingAs($this->logisticsA)
            ->postJson($url, [
                'sorting_center_id' => $this->centerA->id,
                'scan_method' => 'barcode',
            ])
            ->assertOk();

        $this->actingAs($this->logisticsA)
            ->postJson($url, [
                'sorting_center_id' => $this->centerA->id,
                'scan_method' => 'barcode',
            ])
            ->assertStatus(409);
    }

    public function test_intake_rejects_an_inactive_owned_sorting_center(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'INACTIVE');
        $this->centerA->update(['status' => 'inactive']);

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerA->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertStatus(409);
    }

    public function test_service_rejects_foreign_shipment_even_when_called_directly(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileB, 'B3');

        $this->expectException(
            AuthorizationException::class
        );

        app(ParcelIntakeService::class)->receive(
            $waybill,
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
    }

    public function test_service_rejects_foreign_sorting_center_even_when_called_directly(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A5');

        $this->expectException(
            AuthorizationException::class
        );

        app(ParcelIntakeService::class)->receive(
            $waybill,
            $this->centerB,
            $this->logisticsA,
            'manual'
        );
    }

    public function test_logistics_can_receive_a_waybill_from_the_intake_form(): void
    {
        [
            'waybill' => $waybill,
            'parcel' => $parcel,
        ] = $this->makeShipmentFor($this->profileA, 'FORM');

        $this->actingAs($this->logisticsA)
            ->post(
                route('logistics.sorting.incoming.receive'),
                [
                    'identifier' => $waybill->barcode_value,
                    'sorting_center_id' => $this->centerA->id,
                ]
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'status' => ParcelStatus::Received->value,
            'current_sorting_center_id' => $this->centerA->id,
        ]);
    }

    public function test_intake_ledger_only_displays_the_current_providers_parcels(): void
    {
        $own = $this->makeShipmentFor($this->profileA, 'LEDGER-OWN');
        $foreign = $this->makeShipmentFor($this->profileB, 'LEDGER-FOREIGN');

        app(ParcelIntakeService::class)->receive(
            $own['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
        app(ParcelIntakeService::class)->receive(
            $foreign['waybill'],
            $this->centerB,
            $this->logisticsB,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.sorting.incoming'))
            ->assertOk()
            ->assertSee($own['waybill']->waybill_no)
            ->assertSee('Store LEDGER-OWN')
            ->assertDontSee($foreign['waybill']->waybill_no)
            ->assertDontSee('Store LEDGER-FOREIGN')
            ->assertDontSee('data-incoming-form', false)
            ->assertDontSee('data-send-sorting', false);
    }

    public function test_sorting_queue_only_displays_owned_parcels_and_zones(): void
    {
        $own = $this->makeShipmentFor($this->profileA, 'QUEUE-OWN');
        $foreign = $this->makeShipmentFor($this->profileB, 'QUEUE-FOREIGN');
        $ownZone = $this->makeZone($this->centerA, 'OWN-ZONE');
        $foreignZone = $this->makeZone($this->centerB, 'FOREIGN-ZONE');

        app(ParcelIntakeService::class)->receive(
            $own['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
        app(ParcelIntakeService::class)->receive(
            $foreign['waybill'],
            $this->centerB,
            $this->logisticsB,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.sorting.center'))
            ->assertOk()
            ->assertSee($own['parcel']->parcel_no)
            ->assertSee($ownZone->code)
            ->assertDontSee($foreign['parcel']->parcel_no)
            ->assertDontSee($foreignZone->code)
            ->assertDontSee('data-sort-parcel', false)
            ->assertDontSee('data-set-status', false);
    }

    public function test_logistics_can_sort_an_owned_received_parcel(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'SORT');
        $zone = $this->makeZone($this->centerA, 'SORT-ZONE');

        app(ParcelIntakeService::class)->receive(
            $record['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route(
                    'logistics.sorting.parcels.update',
                    $record['parcel']
                ),
                ['sorting_zone_id' => $zone->id]
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('parcels', [
            'id' => $record['parcel']->id,
            'status' => ParcelStatus::Sorted->value,
            'current_zone_id' => $zone->id,
        ]);
        $this->assertDatabaseHas('shipments', [
            'id' => $record['shipment']->id,
            'status' => ShipmentStatus::Sorted->value,
        ]);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $record['shipment']->id,
            'parcel_id' => $record['parcel']->id,
            'event_type' => 'parcel_sorted',
            'sorting_center_id' => $this->centerA->id,
            'sorting_zone_id' => $zone->id,
            'actor_user_id' => $this->logisticsA->id,
            'source' => 'logistics',
        ]);
    }

    public function test_shipment_is_only_sorted_after_every_parcel_is_sorted(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'MULTI');
        $secondParcel = Parcel::query()->create([
            'shipment_id' => $record['shipment']->id,
            'waybill_id' => $record['waybill']->id,
            'parcel_no' => 'PARCEL-MULTI-2',
            'piece_sequence' => 2,
            'weight_kg' => 0.750,
            'status' => ParcelStatus::PickedUp->value,
        ]);
        $record['waybill']->update([
            'piece_count' => 2,
            'total_weight_kg' => 2.000,
        ]);
        $zone = $this->makeZone($this->centerA, 'MULTI-ZONE');

        app(ParcelIntakeService::class)->receive(
            $record['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $record['parcel']),
                ['sorting_zone_id' => $zone->id]
            )
            ->assertRedirect();

        $this->assertSame(
            ShipmentStatus::AtSortingCenter->value,
            $record['shipment']->fresh()->status
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $secondParcel),
                ['sorting_zone_id' => $zone->id]
            )
            ->assertRedirect();

        $this->assertSame(
            ShipmentStatus::Sorted->value,
            $record['shipment']->fresh()->status
        );
    }

    public function test_sorting_rejects_foreign_parcels_and_wrong_center_zones(): void
    {
        $own = $this->makeShipmentFor($this->profileA, 'SECURE-OWN');
        $foreign = $this->makeShipmentFor($this->profileB, 'SECURE-FOREIGN');
        $ownZone = $this->makeZone($this->centerA, 'SECURE-OWN-ZONE');
        $foreignZone = $this->makeZone($this->centerB, 'SECURE-FOREIGN-ZONE');

        app(ParcelIntakeService::class)->receive(
            $own['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
        app(ParcelIntakeService::class)->receive(
            $foreign['waybill'],
            $this->centerB,
            $this->logisticsB,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $foreign['parcel']),
                ['sorting_zone_id' => $ownZone->id]
            )
            ->assertNotFound();

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $own['parcel']),
                ['sorting_zone_id' => $foreignZone->id]
            )
            ->assertNotFound();

        $this->assertSame(
            ParcelStatus::Received->value,
            $own['parcel']->fresh()->status
        );
        $this->assertSame(
            ParcelStatus::Received->value,
            $foreign['parcel']->fresh()->status
        );
    }

    /**
     * @return array{
     *     User,
     *     LogisticsProfile,
     *     SortingCenter
     * }
     */
    private function makeLogisticsProvider(
        string $suffix
    ): array {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'first_name' => 'Logistics',
            'last_name' => $suffix,
            'email' => "logistics-{$suffix}@example.test",
            'role' => 'logistics',
            'status' => 'active',
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Incorporated",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '0917000000'
                . ($suffix === 'A' ? '1' : '2'),
            'status' => 'active',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting Center',
            'recipient_name' => $user->name,
            'phone' => $profile->contact_phone,
            'street' => "{$suffix} Logistics Road",
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);

        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $address->id,
            'name' => "Sorting Center {$suffix}",
            'code' => "CENTER-{$suffix}",
            'contact_phone' => $profile->contact_phone,
            'status' => 'active',
        ]);

        return [$user, $profile, $center];
    }

    private function makeZone(
        SortingCenter $center,
        string $code
    ): SortingZone {
        return SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'name' => str_replace('-', ' ', $code),
            'code' => $code,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{
     *     shipment: Shipment,
     *     waybill: Waybill,
     *     parcel: Parcel
     * }
     */
    private function makeShipmentFor(
        LogisticsProfile $profile,
        string $suffix
    ): array {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);

        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => "Seller {$suffix}",
            'standing_status' => 'good_standing',
        ]);

        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => "Store {$suffix}",
            'slug' => 'store-' . strtolower($suffix),
            'publication_status' => 'published',
        ]);

        $order = Order::query()->create([
            'order_no' => "ORDER-{$suffix}",
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Test Buyer',
            'recipient_phone' => '09171234567',
            'address_line' => '123 Test Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => "SELLER-ORDER-{$suffix}",
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
        ]);

        $shipment = Shipment::query()->create([
            'shipment_no' => "SHIP-{$suffix}",
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $profile->id,
            'status' => ShipmentStatus::PickedUp->value,
            'shipping_fee_minor' => 0,
            'cod_amount_minor' => 0,
        ]);

        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => "WB-{$suffix}",
            'scan_token' => hash(
                'sha256',
                "scan-{$suffix}"
            ),
            'barcode_value' => "BARCODE-{$suffix}",
            'piece_count' => 1,
            'total_weight_kg' => 1.250,
            'status' => 'generated',
            'generated_at' => now(),
        ]);

        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => "PARCEL-{$suffix}",
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'status' => ParcelStatus::PickedUp->value,
        ]);

        return compact(
            'shipment',
            'waybill',
            'parcel'
        );
    }
}
