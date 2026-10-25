<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CodCashCollection;
use App\Models\DeliveryAttempt;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\PlatformCommission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentTransfer;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\PostalCodeLookup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CrossCityCodHandoffIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify the explicit process environment and effective PDO before
     * RefreshDatabase can run any migrations.
     */
    public function createApplication()
    {
        parent::prepareIsolatedTestEnvironment();

        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ] as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException("M4E.2A tests require {$key}={$expected}; refusing to boot the test application.");
            }
        }
        $processDatabaseUrl = getenv('DB_URL');
        if ($processDatabaseUrl !== false && $processDatabaseUrl !== '') {
            throw new RuntimeException('M4E.2A tests require an empty DB_URL; refusing to boot the test application.');
        }

        $app = parent::createApplication();
        $config = $app['config'];
        $sqlite = $config->get('database.connections.sqlite', []);

        if ($config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || ($sqlite['database'] ?? null) !== ':memory:'
            || filled($sqlite['url'] ?? null)) {
            throw new RuntimeException('M4E.2A tests require effective SQLite :memory: configuration; refusing to run migrations.');
        }

        $connection = $app['db']->connection();
        $pdoDriver = $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:'
            || $pdoDriver !== 'sqlite') {
            throw new RuntimeException('M4E.2A tests could not verify a SQLite :memory: PDO connection; refusing to run migrations.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));

        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        Storage::fake('local');
    }

    public function test_cross_city_cod_order_completes_through_real_handoff_delivery_and_buyer_receipt(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);

        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->origin_logistics_profile_id);
        $this->assertSame(0, ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->count());
        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertSame($scenario['originCenter']->id, (int) $scenario['parcel']->fresh()->current_sorting_center_id);

        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertRedirect(route('logistics.transfers.index'))
            ->assertSessionHasNoErrors();

        $transfer = ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->sole();
        $scenario['transfer'] = $transfer;
        $this->assertSame('sent', $transfer->status);
        $this->assertSame($scenario['originProfile']->id, (int) $transfer->from_logistics_profile_id);
        $this->assertSame($scenario['destinationProfile']->id, (int) $transfer->to_logistics_profile_id);
        $this->assertSame($scenario['originCenter']->id, (int) $transfer->from_sorting_center_id);
        $this->assertSame($scenario['originUser']->id, (int) $transfer->sent_by);
        $this->assertNotNull($transfer->sent_at);
        $this->assertSame('handoff_pending', $scenario['shipment']->fresh()->status);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('in_transit', $scenario['parcel']->fresh()->status);
        $this->assertSame($scenario['originCenter']->id, (int) $scenario['parcel']->fresh()->current_sorting_center_id);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'parcel_id' => $scenario['parcel']->id,
            'event_type' => 'logistics_handoff_sent',
            'from_status' => 'received',
            'to_status' => 'in_transit',
            'sorting_center_id' => $scenario['originCenter']->id,
            'actor_user_id' => $scenario['originUser']->id,
            'source' => 'logistics',
        ]);
        Auth::forgetGuards();
        $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertStatus(409);
        $this->assertNull($scenario['order']->fresh()->completed_at);

        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertConflict();
        $this->assertSame(1, ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->count());

        $this->actingAs($scenario['destinationUser'])
            ->get(route('logistics.transfers.index'))
            ->assertOk()
            ->assertSee($scenario['waybill']->waybill_no)
            ->assertSee('Accept an incoming handoff');

        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => 'UNKNOWN-'.$scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertNotFound();
        $this->assertSame('sent', $transfer->fresh()->status);
        $this->assertSame('handoff_pending', $scenario['shipment']->fresh()->status);

        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertForbidden();
        $this->assertSame('sent', $transfer->fresh()->status);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);

        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect(route('logistics.transfers.index'))
            ->assertSessionHasNoErrors();

        $transfer->refresh();
        $this->assertSame('accepted', $transfer->status);
        $this->assertSame($scenario['destinationCenter']->id, (int) $transfer->to_sorting_center_id);
        $this->assertSame($scenario['destinationUser']->id, (int) $transfer->accepted_by);
        $this->assertNotNull($transfer->accepted_at);
        $this->assertSame($scenario['destinationProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->origin_logistics_profile_id);
        $this->assertSame('at_sorting_center', $scenario['shipment']->fresh()->status);
        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertSame($scenario['destinationCenter']->id, (int) $scenario['parcel']->fresh()->current_sorting_center_id);
        $this->assertNull($scenario['parcel']->fresh()->current_zone_id);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'parcel_id' => $scenario['parcel']->id,
            'event_type' => 'logistics_handoff_accepted',
            'from_status' => 'in_transit',
            'to_status' => 'received',
            'sorting_center_id' => $scenario['destinationCenter']->id,
            'actor_user_id' => $scenario['destinationUser']->id,
            'source' => 'scan',
            'scan_method' => 'barcode',
        ]);
        $this->assertBuyerCannotConfirmReceipt($scenario);

        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertConflict();
        $this->assertSame('accepted', $transfer->fresh()->status);
        $this->assertSame(1, ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->count());

        $this->actingAs($scenario['destinationUser'])
            ->patch(route('logistics.sorting.parcels.update', $scenario['parcel']), [
                'sorting_zone_id' => $scenario['destinationZone']->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('sorted', $scenario['parcel']->fresh()->status);
        $this->assertSame('sorted', $scenario['shipment']->fresh()->status);
        $this->assertSame($scenario['destinationZone']->id, (int) $scenario['parcel']->fresh()->current_zone_id);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'parcel_id' => $scenario['parcel']->id,
            'event_type' => 'parcel_sorted',
            'sorting_center_id' => $scenario['destinationCenter']->id,
            'sorting_zone_id' => $scenario['destinationZone']->id,
            'actor_user_id' => $scenario['destinationUser']->id,
            'source' => 'logistics',
        ]);
        Auth::forgetGuards();
        $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertStatus(409);
        $this->assertNull($scenario['order']->fresh()->completed_at);

        // The shipment is now sorted through the destination workflow; it cannot be sent again.
        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertConflict();
        $this->assertSame(1, ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->count());

        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.dispatch.store', $scenario['destinationZone']), [
                'rider_profile_id' => $scenario['deliveryRider']->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $scenario['parcel']->refresh();
        $scenario['shipment']->refresh();
        $scenario['batch'] = DispatchBatch::query()->where('sorting_zone_id', $scenario['destinationZone']->id)->sole();
        $this->assertSame('dispatched', $scenario['batch']->status);
        $this->assertSame('dispatched', $scenario['parcel']->status);
        $this->assertSame('dispatched', $scenario['shipment']->status);
        $this->assertSame($scenario['destinationProfile']->id, (int) $scenario['shipment']->logistics_profile_id);

        $this->actingAs($scenario['deliveryRider']->user)
            ->post(route('rider.orders.delivery.start', $scenario['shipment']->shipment_no))
            ->assertRedirect();

        $codAmount = number_format(((int) $scenario['shipment']->cod_amount_minor) / 100, 2, '.', '');
        $this->actingAs($scenario['deliveryRider']->user)
            ->post(route('rider.orders.delivery.confirm', $scenario['shipment']->shipment_no), [
                'recipient_name' => $scenario['order']->recipient_name,
                'proof_photo' => $this->fakeProofPhoto('m4e2a-cross-city-delivery.png'),
                'cod_collected_amount' => $codAmount,
                'notes' => 'Cross-city COD delivered to the named recipient.',
            ])
            ->assertRedirect(route('rider.orders.delivery', $scenario['shipment']->shipment_no))
            ->assertSessionHas('job_status', 'Delivery completed successfully.');

        $scenario['shipment']->refresh();
        $scenario['parcel']->refresh();
        $scenario['order']->refresh();
        $scenario['seller_order']->refresh();
        $this->assertSame('delivered', $scenario['shipment']->status);
        $this->assertSame('delivered', $scenario['parcel']->status);
        $this->assertSame('handed_to_logistics', $scenario['seller_order']->status);
        $this->assertSame($scenario['destinationProfile']->id, (int) $scenario['shipment']->logistics_profile_id);

        $attempt = DeliveryAttempt::query()->where('parcel_id', $scenario['parcel']->id)->where('outcome', 'delivered')->sole();
        $proof = $attempt->proofs()->sole();
        $this->assertSame($scenario['order']->recipient_name, $proof->recipient_name);
        $this->assertSame($scenario['deliveryRider']->user_id, (int) $proof->uploaded_by);
        $this->assertNotEmpty($proof->file_path);
        $this->assertNotNull($proof->captured_at);
        Storage::disk('local')->assertExists($proof->file_path);

        $collection = CodCashCollection::query()->where('shipment_id', $scenario['shipment']->id)->sole();
        $payment = Payment::query()->where('order_id', $scenario['order']->id)->sole();
        $this->assertSame('collected', $collection->status);
        $this->assertSame((int) $scenario['shipment']->cod_amount_minor, (int) $collection->amount_due_minor);
        $this->assertSame((int) $scenario['shipment']->cod_amount_minor, (int) $collection->amount_collected_minor);
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('paid', $scenario['order']->payment_status);
        $this->assertSame(1, PlatformCommission::query()->where('seller_order_id', $scenario['seller_order']->id)->where('type', 'sale')->count());
        $this->assertSame(1, ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->count());

        Auth::forgetGuards();
        $this->withToken($scenario['token'])
            ->getJson('/api/mobile/buyer/orders/'.$scenario['order']->id)
            ->assertOk()
            ->assertJsonPath('data.buyer_actions.can_confirm_receipt', true);

        Auth::forgetGuards();
        $trackingResponse = $this->withToken($scenario['token'])
            ->getJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/tracking')
            ->assertOk()
            ->assertJsonPath('data.tracking_state', 'available');

        $tracking = $trackingResponse->json('data.seller_orders.0.shipments.0');
        $eventTypes = collect($tracking['events'])->pluck('event_type')->all();
        foreach ([
            'shipment_picked_up',
            'received_at_center',
            'logistics_handoff_sent',
            'logistics_handoff_accepted',
            'parcel_sorted',
            'shipment_dispatched',
            'shipment_out_for_delivery',
            'shipment_delivered',
        ] as $eventType) {
            $this->assertContains($eventType, $eventTypes, "Buyer tracking must show {$eventType}.");
        }
        $milestoneSequence = [
            'shipment_picked_up',
            'received_at_center',
            'logistics_handoff_sent',
            'logistics_handoff_accepted',
            'parcel_sorted',
            'shipment_dispatched',
            'shipment_out_for_delivery',
            'shipment_delivered',
        ];
        $visibleMilestones = array_values(array_filter(
            $eventTypes,
            fn (string $eventType): bool => in_array($eventType, $milestoneSequence, true),
        ));
        $this->assertSame($milestoneSequence, $visibleMilestones);
        foreach ($tracking['events'] as $event) {
            $this->assertEqualsCanonicalizing([
                'event_id', 'scope', 'event_type', 'from_status', 'to_status', 'parcel_reference', 'occurred_at',
            ], array_keys($event));
            $this->assertArrayNotHasKey('metadata', $event);
            $this->assertArrayNotHasKey('scan_token', $event);
        }

        Auth::forgetGuards();
        $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.receipt_confirmed', true)
            ->assertJsonPath('data.already_confirmed', false)
            ->assertJsonPath('data.seller_orders.0.status', 'completed');

        $confirmedAt = $scenario['order']->fresh()->completed_at;
        $this->assertSame('fulfilled', $scenario['order']->fresh()->status);
        $this->assertSame('completed', $scenario['seller_order']->fresh()->status);
        $this->assertNotNull($confirmedAt);
        $paymentCount = Payment::query()->where('order_id', $scenario['order']->id)->count();
        $commissionCount = PlatformCommission::query()->where('seller_order_id', $scenario['seller_order']->id)->count();

        Auth::forgetGuards();
        $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', true);

        $this->assertEquals($confirmedAt, $scenario['order']->fresh()->completed_at);
        $this->assertSame(1, $paymentCount);
        $this->assertSame($paymentCount, Payment::query()->where('order_id', $scenario['order']->id)->count());
        $this->assertSame($commissionCount, PlatformCommission::query()->where('seller_order_id', $scenario['seller_order']->id)->count());
    }

    public function test_handoff_send_requires_origin_intake_and_available_destination_provider(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);

        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertConflict();
        $this->assertSame('picked_up', $scenario['parcel']->fresh()->status);
        $this->assertSame('picked_up', $scenario['shipment']->fresh()->status);
        $this->assertSame(0, ShipmentTransfer::query()->count());

        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertForbidden();
        $this->assertSame(0, ShipmentTransfer::query()->count());

        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario['destinationProfile']->update(['status' => 'inactive']);
        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertConflict();
        $this->assertSame('at_sorting_center', $scenario['shipment']->fresh()->status);
        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertSame(0, ShipmentTransfer::query()->count());

        $scenario['destinationProfile']->update(['status' => 'active']);
        $scenario['destinationCenter']->update(['status' => 'inactive']);
        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertConflict();
        $this->assertSame(0, ShipmentTransfer::query()->count());

        $scenario['destinationCenter']->update(['status' => 'active']);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('received', $scenario['parcel']->fresh()->status);
    }

    public function test_destination_without_matching_barangay_zone_is_rejected_before_handoff_send(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario(['Other Barangay']));
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);

        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertConflict();

        $shipment = $scenario['shipment']->fresh();
        $parcel = $scenario['parcel']->fresh();
        $this->assertSame(0, ShipmentTransfer::query()->where('shipment_id', $shipment->id)->count());
        $this->assertSame($scenario['originProfile']->id, (int) $shipment->logistics_profile_id);
        $this->assertSame('at_sorting_center', $shipment->status);
        $this->assertSame('received', $parcel->status);
        $this->assertSame($scenario['originCenter']->id, (int) $parcel->current_sorting_center_id);
        $this->assertNull($parcel->current_zone_id);
        $this->assertSame(0, $shipment->events()->where('event_type', 'logistics_handoff_sent')->count());
    }

    public function test_acceptance_rechecks_coverage_and_original_transfer_can_be_retried_after_restoration(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario = $this->sendHandoff($scenario);

        $this->actingAs($scenario['destinationUser'])
            ->from(route('logistics.sorting.center'))
            ->patch(route('logistics.sorting.zones.status', $scenario['destinationZone']), [
                'status' => 'inactive',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame('active', $scenario['destinationZone']->fresh()->status);

        // Simulate legacy or out-of-band coverage loss, then verify acceptance rechecks it.
        $scenario['destinationZone']->update(['status' => 'inactive']);
        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertConflict();

        $this->assertSame('inactive', $scenario['destinationZone']->fresh()->status);
        $this->assertSame('sent', $scenario['transfer']->fresh()->status);
        $this->assertNull($scenario['transfer']->fresh()->accepted_at);
        $this->assertSame('handoff_pending', $scenario['shipment']->fresh()->status);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('in_transit', $scenario['parcel']->fresh()->status);
        $this->assertSame($scenario['originCenter']->id, (int) $scenario['parcel']->fresh()->current_sorting_center_id);
        $this->assertSame(0, $scenario['shipment']->events()->where('event_type', 'logistics_handoff_accepted')->count());

        $this->actingAs($scenario['destinationUser'])
            ->patch(route('logistics.sorting.zones.status', $scenario['destinationZone']), [
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $scenario = $this->acceptHandoff($scenario);
        $this->assertSame(1, ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->count());
    }

    public function test_zone_deactivation_is_blocked_for_an_accepted_unsorted_parcel(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario = $this->sendHandoff($scenario);
        $scenario = $this->acceptHandoff($scenario);

        $this->actingAs($scenario['destinationUser'])
            ->from(route('logistics.sorting.center'))
            ->patch(route('logistics.sorting.zones.status', $scenario['destinationZone']), ['status' => 'inactive'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $scenario['destinationZone']->fresh()->status);
        $this->actingAs($scenario['destinationUser'])
            ->get(route('logistics.sorting.center'))
            ->assertOk()
            ->assertSee('This change would remove the last active zone covering an in-progress shipment.');
        $this->actingAs($scenario['destinationUser'])
            ->patch(route('logistics.sorting.parcels.update', $scenario['parcel']), [
                'sorting_zone_id' => $scenario['destinationZone']->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('accepted', $scenario['transfer']->fresh()->status);
        $this->assertSame('sorted', $scenario['shipment']->fresh()->status);
        $this->assertSame('sorted', $scenario['parcel']->fresh()->status);
    }

    public function test_coverage_edit_and_archive_cannot_remove_the_last_match_for_a_sent_transfer(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario = $this->sendHandoff($scenario);

        $this->actingAs($scenario['destinationUser'])
            ->from(route('logistics.sorting.center'))
            ->patch(route('logistics.sorting.zones.update', $scenario['destinationZone']), [
                'city_code' => $scenario['destinationProfile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Other Barangay'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('barangays');
        $this->actingAs($scenario['destinationUser'])
            ->get(route('logistics.sorting.center'))
            ->assertOk()
            ->assertSee('This change would remove the last active zone covering an in-progress shipment.');

        $this->actingAs($scenario['destinationUser'])
            ->from(route('logistics.sorting.center'))
            ->patch(route('logistics.sorting.zones.archive', $scenario['destinationZone']))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame(['Pinagbayanan'], $scenario['destinationZone']->fresh()->destination_rules['barangays']);
        $this->assertSame('active', $scenario['destinationZone']->fresh()->status);
        $this->assertSame('sent', $scenario['transfer']->fresh()->status);
        $this->assertSame('handoff_pending', $scenario['shipment']->fresh()->status);
    }

    public function test_removing_one_redundant_active_zone_is_allowed_when_another_still_covers_the_transfer(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario = $this->sendHandoff($scenario);

        $redundantZone = SortingZone::query()->create([
            'sorting_center_id' => $scenario['destinationCenter']->id,
            'name' => 'M4E.2B redundant Pila coverage',
            'code' => 'M4E2B-REDUNDANT-'.Str::upper(Str::random(6)),
            'destination_rules' => $scenario['destinationZone']->destination_rules,
            'status' => 'active',
        ]);

        $this->actingAs($scenario['destinationUser'])
            ->patch(route('logistics.sorting.zones.status', $scenario['destinationZone']), [
                'status' => 'inactive',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('inactive', $scenario['destinationZone']->fresh()->status);
        $this->assertSame('active', $redundantZone->fresh()->status);
        $scenario = $this->acceptHandoff($scenario);

        $this->actingAs($scenario['destinationUser'])
            ->patch(route('logistics.sorting.parcels.update', $scenario['parcel']), [
                'sorting_zone_id' => $redundantZone->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($redundantZone->id, (int) $scenario['parcel']->fresh()->current_zone_id);
    }

    public function test_archived_zone_with_only_recoverable_coverage_cannot_be_permanently_purged(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario = $this->sendHandoff($scenario);
        $scenario = $this->acceptHandoff($scenario);

        // Build a legacy archived state directly so the purge guard is tested
        // independently of the archive endpoint's earlier protection.
        $scenario['destinationZone']->update(['status' => 'inactive']);
        $scenario['destinationZone']->delete();

        $this->actingAs($scenario['destinationUser'])
            ->from(route('logistics.sorting.center', ['zone_view' => 'archived']))
            ->delete(route('logistics.sorting.zones.destroy', $scenario['destinationZone']->id))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSoftDeleted('sorting_zones', ['id' => $scenario['destinationZone']->id]);
        $this->assertSame('accepted', $scenario['transfer']->fresh()->status);
        $this->assertSame($scenario['destinationProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertNull($scenario['parcel']->fresh()->current_zone_id);
    }

    public function test_accepted_uncovered_parcel_recovers_through_authorized_zone_creation_and_normal_sorting(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario = $this->sendHandoff($scenario);
        $scenario = $this->acceptHandoff($scenario);
        $transferId = (int) $scenario['transfer']->id;
        $acceptedAt = $scenario['transfer']->accepted_at;

        // Reproduce an already-accepted uncovered state from the M4E.2A audit.
        $scenario['destinationZone']->update([
            'destination_rules' => [
                ...$scenario['destinationZone']->destination_rules,
                'barangays' => ['Other Barangay'],
            ],
        ]);

        $this->actingAs($scenario['destinationUser'])
            ->patch(route('logistics.sorting.parcels.update', $scenario['parcel']), [
                'sorting_zone_id' => $scenario['destinationZone']->id,
            ])
            ->assertConflict();
        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertNull($scenario['parcel']->fresh()->current_zone_id);

        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.sorting.zones.store'), [
                'city_code' => $scenario['destinationProfile']->psgc_city_code,
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Pinagbayanan'],
            ])
            ->assertRedirect(route('logistics.sorting.center'))
            ->assertSessionHasNoErrors();

        $restoredZone = SortingZone::query()
            ->where('sorting_center_id', $scenario['destinationCenter']->id)
            ->where('id', '<>', $scenario['destinationZone']->id)
            ->where('status', 'active')
            ->firstOrFail();
        $zoneAudit = AuditLog::query()
            ->where('actor_user_id', $scenario['destinationUser']->id)
            ->where('action', 'logistics.sorting_zone.created')
            ->where('auditable_type', $restoredZone->getMorphClass())
            ->where('auditable_id', $restoredZone->id)
            ->sole();
        $this->assertSame($scenario['destinationCenter']->id, $zoneAudit->new_values['sorting_center_id']);
        $this->assertSame(['Pinagbayanan'], $zoneAudit->new_values['destination_rules']['barangays']);

        $this->actingAs($scenario['destinationUser'])
            ->patch(route('logistics.sorting.parcels.update', $scenario['parcel']), [
                'sorting_zone_id' => $restoredZone->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('sorted', $scenario['parcel']->fresh()->status);
        $this->assertSame($restoredZone->id, (int) $scenario['parcel']->fresh()->current_zone_id);
        $this->assertSame($transferId, (int) $scenario['transfer']->fresh()->id);
        $this->assertSame('accepted', $scenario['transfer']->fresh()->status);
        $this->assertEquals($acceptedAt, $scenario['transfer']->fresh()->accepted_at);
        $this->assertSame(1, ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->count());
        $this->assertSame(1, $scenario['shipment']->events()->where('event_type', 'logistics_handoff_accepted')->count());
    }

    public function test_destination_center_deactivation_after_send_blocks_acceptance_without_custody_change(): void
    {
        $scenario = $this->placeAndPrepareOrder($this->scenario());
        $scenario = $this->requestAndCompletePickup($scenario);
        $scenario = $this->receiveAtOriginCenter($scenario);
        $scenario = $this->sendHandoff($scenario);
        $scenario['destinationCenter']->update(['status' => 'inactive']);

        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertConflict();

        $this->assertSame('sent', $scenario['transfer']->fresh()->status);
        $this->assertSame('handoff_pending', $scenario['shipment']->fresh()->status);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('in_transit', $scenario['parcel']->fresh()->status);
        $this->assertSame($scenario['originCenter']->id, (int) $scenario['parcel']->fresh()->current_sorting_center_id);
    }

    public function test_destination_provider_city_change_before_acceptance_is_rejected_without_custody_change(): void
    {
        $changed = $this->placeAndPrepareOrder($this->scenario());
        $changed = $this->requestAndCompletePickup($changed);
        $changed = $this->receiveAtOriginCenter($changed);
        $changed = $this->sendHandoff($changed);
        // Canonical PSGC code for a third Laguna city from the bundled snapshot.
        $thirdCity = '0403403000';
        $this->assertNotSame($changed['originCode'], $thirdCity);
        $this->assertNotSame($changed['destinationCode'], $thirdCity);
        $changed['destinationProfile']->update(['psgc_city_code' => $thirdCity]);

        $this->actingAs($changed['destinationUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $changed['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertConflict();

        $this->assertSame('sent', $changed['transfer']->fresh()->status);
        $this->assertSame('handoff_pending', $changed['shipment']->fresh()->status);
        $this->assertSame($changed['originProfile']->id, (int) $changed['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('in_transit', $changed['parcel']->fresh()->status);
    }

    private function scenario(array $destinationBarangays = ['Pinagbayanan']): array
    {
        $suffix = Str::lower(Str::random(9));
        $postalCodes = app(PostalCodeLookup::class);
        $originCode = (string) $postalCodes->canonicalCityCode(null, 'Laguna', 'Santa Rosa');
        $destinationCode = (string) $postalCodes->canonicalCityCode(null, 'Laguna', 'Pila');
        if ($originCode === '' || $destinationCode === '' || $originCode === $destinationCode) {
            throw new RuntimeException('M4E.2A requires two distinct canonical Laguna city codes.');
        }

        $buyer = $this->actor('buyer', "M4E.2A Buyer {$suffix}");
        $buyerAddress = $this->address($buyer, 'Buyer Home', 'Pinagbayanan', 'Pila', 'Laguna', '4010', $destinationCode, true, false);
        $seller = $this->actor('seller', "M4E.2A Seller {$suffix}");
        $sellerAddress = $this->address($seller, 'Seller Pickup', 'Balibago', 'Santa Rosa', 'Laguna', '4026', $originCode, false, true);

        $category = Category::query()->create([
            'name' => "M4E.2A Category {$suffix}",
            'slug' => 'm4e2a-category-'.$suffix,
        ]);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => "M4E.2A Seller {$suffix}",
            'approved_category_id' => $category->id,
            'pickup_address_id' => $sellerAddress->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => "M4E.2A Store {$suffix}",
            'slug' => 'm4e2a-store-'.$suffix,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => "M4E.2A Cotton Shirt {$suffix}",
            'slug' => 'm4e2a-product-'.$suffix,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'voucher_eligible' => true,
            'payment_methods' => ['cod'],
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'M4E2A-'.Str::upper($suffix),
            'name' => 'Regular / Blue',
            'options' => ['Size' => 'Regular', 'Color' => 'Blue'],
            'price_minor' => 19900,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'package_weight_kg' => 0.5,
            'package_length_cm' => 20,
            'package_width_cm' => 15,
            'package_height_cm' => 10,
            'is_active' => true,
        ]);
        $cart = Cart::query()->create(['user_id' => $buyer->id, 'status' => 'active']);
        $cartItem = CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'selected' => true,
        ]);

        [$originUser, $originProfile, $originCenter, $originZone] = $this->provider(
            $suffix.'-origin', $originCode, 'Santa Rosa', '4026', ['Balibago']
        );
        [$destinationUser, $destinationProfile, $destinationCenter, $destinationZone] = $this->provider(
            $suffix.'-destination', $destinationCode, 'Pila', '4010', $destinationBarangays
        );
        $pickupRider = $this->rider($originUser, $originProfile, $originCenter, $originZone, $suffix.'-pickup');
        $deliveryRider = $this->rider($destinationUser, $destinationProfile, $destinationCenter, $destinationZone, $suffix.'-delivery');

        return compact(
            'buyer', 'buyerAddress', 'cartItem', 'destinationCenter', 'destinationCode',
            'destinationProfile', 'destinationUser', 'destinationZone', 'deliveryRider',
            'originCenter', 'originCode', 'originProfile', 'originUser', 'originZone',
            'pickupRider', 'product', 'seller', 'sellerAddress', 'sellerProfile', 'store',
            'suffix', 'variant'
        );
    }

    private function placeAndPrepareOrder(array $scenario): array
    {
        $scenario['token'] = $scenario['buyer']->createToken('m4e2a-cross-city-test')->plainTextToken;
        $quote = $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/checkout/quote', [
                'address_id' => $scenario['buyerAddress']->id,
                'cart_item_ids' => [$scenario['cartItem']->id],
                'payment_method' => 'cod',
            ])
            ->assertOk()
            ->json('data');

        $placement = $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/checkout/place', [
                'checkout_request_key' => (string) Str::uuid(),
                'quote_token' => $quote['quote_token'],
                'address_id' => $scenario['buyerAddress']->id,
                'cart_item_ids' => [$scenario['cartItem']->id],
                'payment_method' => 'cod',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $scenario['order'] = Order::query()->findOrFail($placement->json('data.id'));
        $scenario['seller_order'] = $scenario['order']->sellerOrders()->sole();
        $this->assertSame('pending', $scenario['seller_order']->status);
        $this->assertSame('pending', $scenario['order']->payments()->sole()->status);

        $this->actingAs($scenario['seller'])
            ->patchJson(route('seller.orders.status', $scenario['seller_order']->id), ['action' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->actingAs($scenario['seller'])
            ->patchJson(route('seller.orders.status', $scenario['seller_order']->id), ['action' => 'start_preparing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'packing');

        $scenario['shipment'] = $scenario['seller_order']->fresh()->shipments()->sole();
        $scenario['waybill'] = Waybill::query()->where('shipment_id', $scenario['shipment']->id)->sole();
        $scenario['parcel'] = Parcel::query()->where('shipment_id', $scenario['shipment']->id)->sole();
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->logistics_profile_id);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->origin_logistics_profile_id);
        $this->assertSame($scenario['destinationCode'], $scenario['shipment']->destination_city_code);
        $this->assertSame((int) $scenario['seller_order']->total_minor, (int) $scenario['shipment']->cod_amount_minor);
        $this->assertSame('generated', $scenario['waybill']->status);
        $this->assertSame('created', $scenario['parcel']->status);

        $this->actingAs($scenario['seller'])
            ->post(route('seller.fulfillment.waybills.pdf'), ['waybill_ids' => [$scenario['waybill']->id]])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($scenario['seller'])
            ->post(route('seller.fulfillment.waybills.printed', $scenario['waybill']))
            ->assertRedirect(route('seller.fulfillment.waybills'));
        $this->assertNotNull($scenario['waybill']->fresh()->printed_at);

        $this->actingAs($scenario['seller'])
            ->patchJson(route('seller.orders.status', $scenario['seller_order']->id), ['action' => 'mark_ready'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_pickup');
        $this->assertSame('ready_for_pickup', $scenario['shipment']->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'event_type' => 'shipment_ready_for_pickup',
            'to_status' => 'ready_for_pickup',
            'actor_user_id' => $scenario['seller']->id,
            'source' => 'seller',
        ]);

        return $scenario;
    }

    private function requestAndCompletePickup(array $scenario): array
    {
        $this->actingAs($scenario['seller'])
            ->post(route('seller.fulfillment.pickups.store'), [
                'order_ids' => [$scenario['seller_order']->id],
                'requested_date' => today()->addDay()->toDateString(),
                'pickup_window' => '10:00-12:00',
                'instructions' => 'Cross-city COD parcel in sealed packaging.',
            ])
            ->assertRedirect();

        $scenario['pickup'] = PickupRequest::query()->with('parcels')->sole();
        $this->assertSame('requested', $scenario['pickup']->status);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['pickup']->logistics_profile_id);
        $this->assertCount(1, $scenario['pickup']->parcels);

        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.pickups.verify', $scenario['pickup']))
            ->assertRedirect();
        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.pickups.assign', $scenario['pickup']), [
                'rider_profile_id' => $scenario['pickupRider']->id,
            ])
            ->assertRedirect();

        $scenario['pickupAssignment'] = PickupAssignment::query()
            ->where('pickup_request_id', $scenario['pickup']->id)
            ->sole();
        $this->assertSame($scenario['pickupRider']->id, (int) $scenario['pickupAssignment']->rider_profile_id);
        $this->assertSame('scheduled', $scenario['pickup']->fresh()->status);

        $this->actingAs($scenario['pickupRider']->user)
            ->post(route('rider.orders.pickup.accept', $scenario['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($scenario['pickupRider']->user)
            ->post(route('rider.orders.pickup.confirm', $scenario['pickup']->pickup_no), [
                'waybill_identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect(route('rider.orders.pickup', $scenario['pickup']->pickup_no));

        $this->assertSame('picked_up', $scenario['pickupAssignment']->fresh()->status);
        $this->assertSame('picked_up', $scenario['shipment']->fresh()->status);
        $this->assertSame('picked_up', $scenario['parcel']->fresh()->status);
        $this->assertSame('handed_to_logistics', $scenario['seller_order']->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'parcel_id' => $scenario['parcel']->id,
            'event_type' => 'parcel_picked_up',
            'actor_user_id' => $scenario['pickupRider']->user_id,
            'source' => 'rider',
        ]);

        return $scenario;
    }

    private function receiveAtOriginCenter(array $scenario): array
    {
        $this->actingAs($scenario['originUser'])
            ->postJson(route('logistics.waybills.receive', $scenario['waybill']->waybill_no), [
                'sorting_center_id' => $scenario['originCenter']->id,
                'scan_method' => 'manual',
            ])
            ->assertOk();

        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertSame($scenario['originCenter']->id, (int) $scenario['parcel']->fresh()->current_sorting_center_id);
        $this->assertSame('at_sorting_center', $scenario['shipment']->fresh()->status);
        $this->assertSame('completed', $scenario['pickup']->fresh()->status);
        $this->assertSame('completed', $scenario['pickupAssignment']->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'parcel_id' => $scenario['parcel']->id,
            'event_type' => 'received_at_center',
            'to_status' => 'received',
            'sorting_center_id' => $scenario['originCenter']->id,
            'source' => 'scan',
        ]);

        return $scenario;
    }

    private function sendHandoff(array $scenario): array
    {
        $this->actingAs($scenario['originUser'])
            ->post(route('logistics.transfers.send', $scenario['shipment']))
            ->assertRedirect(route('logistics.transfers.index'))
            ->assertSessionHasNoErrors();
        $scenario['transfer'] = ShipmentTransfer::query()->where('shipment_id', $scenario['shipment']->id)->sole();
        $this->assertSame('sent', $scenario['transfer']->status);
        $this->assertSame('handoff_pending', $scenario['shipment']->fresh()->status);
        $this->assertSame($scenario['originProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('in_transit', $scenario['parcel']->fresh()->status);

        return $scenario;
    }

    private function acceptHandoff(array $scenario): array
    {
        $this->actingAs($scenario['destinationUser'])
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect(route('logistics.transfers.index'))
            ->assertSessionHasNoErrors();
        $scenario['transfer']->refresh();
        $this->assertSame('accepted', $scenario['transfer']->status);
        $this->assertSame($scenario['destinationProfile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
        $this->assertSame('at_sorting_center', $scenario['shipment']->fresh()->status);
        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertSame($scenario['destinationCenter']->id, (int) $scenario['parcel']->fresh()->current_sorting_center_id);
        $this->assertNull($scenario['parcel']->fresh()->current_zone_id);

        return $scenario;
    }

    private function assertBuyerCannotConfirmReceipt(array $scenario): void
    {
        Auth::forgetGuards();
        $this->withToken($scenario['token'])
            ->getJson('/api/mobile/buyer/orders/'.$scenario['order']->id)
            ->assertOk()
            ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);
        Auth::forgetGuards();
        $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertStatus(409);
        $this->assertNull($scenario['order']->fresh()->completed_at);
    }

    private function provider(string $suffix, string $cityCode, string $city, string $postalCode, array $barangays): array
    {
        $user = $this->actor('logistics', "M4E.2A Logistics {$suffix}");
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "M4E.2A Logistics {$suffix} LLC",
            'display_name' => "M4E.2A Logistics {$suffix}",
            'contact_phone' => '+639171234568',
            'psgc_city_code' => $cityCode,
            'status' => 'active',
        ]);
        $centerAddress = $this->address($user, 'Sorting Center', 'San Rafael', $city, 'Laguna', $postalCode, $cityCode, false, false);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $centerAddress->id,
            'name' => "M4E.2A Sorting Center {$suffix}",
            'code' => 'M4E2A-CENTER-'.Str::upper(Str::random(8)),
            'contact_phone' => '+639171234568',
            'status' => 'active',
        ]);
        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'name' => "M4E.2A {$city} Zone {$suffix}",
            'code' => 'M4E2A-ZONE-'.Str::upper(Str::random(8)),
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => $city,
                'psgc_city_code' => $cityCode,
                'barangays' => $barangays,
            ],
            'status' => 'active',
        ]);

        return [$user, $profile, $center, $zone];
    }

    private function rider(User $logisticsUser, LogisticsProfile $profile, SortingCenter $center, SortingZone $zone, string $suffix): RiderProfile
    {
        $user = $this->actor('rider', "M4E.2A Rider {$suffix}");
        $user->update(['logistics_id' => $logisticsUser->id]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $profile->id,
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'M4E2A-'.Str::upper(Str::random(8)),
            'parcel_capacity' => 5,
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
    }

    private function actor(string $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => 'm4e2a-'.Str::lower(Str::random(18)).'@example.test',
            'role' => $role,
            'status' => 'active',
        ]);
        $roleId = Role::query()->where('name', $role)->value('id');
        if ($roleId) {
            $user->roles()->syncWithoutDetaching([$roleId => ['assigned_at' => now()]]);
        }

        return $user;
    }

    private function address(
        User $user,
        string $label,
        string $barangay,
        string $city,
        string $province,
        string $postalCode,
        string $cityCode,
        bool $defaultShipping,
        bool $defaultPickup,
    ): Address {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => $label,
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'house_number' => '10',
            'street' => 'M4E.2A Market Road',
            'barangay' => $barangay,
            'city_municipality' => $city,
            'province' => $province,
            'postal_code' => $postalCode,
            'psgc_province_code' => '0434',
            'psgc_city_code' => $cityCode,
            'is_default_shipping' => $defaultShipping,
            'is_default_pickup' => $defaultPickup,
        ]);
    }

    private function fakeProofPhoto(string $name): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if ($contents === false) {
            throw new RuntimeException('Unable to create isolated M4E.2A delivery proof.');
        }

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
