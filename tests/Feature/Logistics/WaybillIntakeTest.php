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