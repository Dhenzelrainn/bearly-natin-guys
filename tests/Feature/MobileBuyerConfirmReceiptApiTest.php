<?php

namespace Tests\Feature;

use App\Enums\ReturnRequestStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryProof;
use App\Models\Dispute;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\PromotionRedemption;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Notifications\WorkflowNotification;
use App\Services\CodCashCollectionService;
use App\Services\NotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MobileBuyerConfirmReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_buyer_can_confirm_and_replay_without_rewriting_timestamp_or_repeating_effects(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'confirm');
        [$seller, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'confirm');
        $sellerOrder = $this->sellerOrder($order, $store, 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'delivered');
        $this->collectCod($shipment, $provider);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('sendAfterCommit')
            ->once()
            ->withArgs(fn (User $recipient, WorkflowNotification $notice): bool => $recipient->is($seller)
                && $notice->eventKey() === 'seller_shipment_updates');
        $this->app->instance(NotificationService::class, $notifications);

        $route = route('mobile.buyer.orders.confirm-receipt', $order->id);
        $first = $this->actingAs($buyer)->postJson($route)->assertOk()
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.order_reference', $order->order_no)
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.receipt_confirmed', true)
            ->assertJsonPath('data.already_confirmed', false)
            ->assertJsonPath('data.seller_orders.0.status', 'completed');

        $originalCompletedAt = $first->json('data.completed_at');
        $this->assertNotEmpty($originalCompletedAt);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('completed', $sellerOrder->fresh()->status);
        $this->assertSame(1, $sellerOrder->statusHistory()->count());
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame('delivered', $shipment->fresh()->status);

        $this->travel(2)->hours();
        $this->actingAs($buyer)->postJson($route)->assertOk()
            ->assertJsonPath('data.completed_at', $originalCompletedAt)
            ->assertJsonPath('data.already_confirmed', true)
            ->assertJsonPath('message', 'Order receipt was already confirmed.');

        $this->assertSame($originalCompletedAt, Carbon::parse($order->fresh()->completed_at)->toIso8601String());
        $this->assertSame(1, $sellerOrder->statusHistory()->count());
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    public function test_successful_multi_seller_confirmation_completes_every_active_seller_order(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'multi');
        [, $firstStore] = $this->seller();
        [, $secondStore] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'multi');
        $firstSellerOrder = $this->sellerOrder($order, $firstStore, 'handed_to_logistics', 10000);
        $secondSellerOrder = $this->sellerOrder($order, $secondStore, 'handed_to_logistics', 10000);
        $firstShipment = $this->shipment($firstSellerOrder, $provider, 'delivered');
        $secondShipment = $this->shipment($secondSellerOrder, $provider, 'delivered');
        $this->collectCod($firstShipment, $provider);
        $this->collectCod($secondShipment, $provider);

        $response = $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertOk()
            ->assertJsonPath('data.receipt_confirmed', true);

        $this->assertCount(2, $response->json('data.seller_orders'));
        $this->assertSame('completed', $firstSellerOrder->fresh()->status);
        $this->assertSame('completed', $secondSellerOrder->fresh()->status);
        $this->assertSame(1, $firstSellerOrder->statusHistory()->count());
        $this->assertSame(1, $secondSellerOrder->statusHistory()->count());
    }

    public function test_authentication_role_ownership_and_numeric_order_routes_are_enforced(): void
    {
        $owner = $this->user(UserRole::Buyer, 'owner');
        $otherBuyer = $this->user(UserRole::Buyer, 'other');
        [, $store] = $this->seller();
        $order = $this->marketplaceOrder($owner, 'private');
        $this->sellerOrder($order, $store, 'handed_to_logistics');
        $route = route('mobile.buyer.orders.confirm-receipt', $order->id);

        $this->postJson($route)->assertUnauthorized();
        $this->actingAs($otherBuyer)->postJson($route)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'order_not_found');
        $this->actingAs($owner)->postJson('/api/mobile/buyer/orders/not-numeric/confirm-receipt')
            ->assertNotFound();

        foreach ([UserRole::Rider, UserRole::Seller, UserRole::Logistics, UserRole::Admin] as $role) {
            $this->actingAs($this->user($role, strtolower($role->value)))
                ->postJson($route)
                ->assertForbidden();
        }

        $inactiveBuyer = $this->user(UserRole::Buyer, 'inactive', 'suspended');
        $this->actingAs($inactiveBuyer)->postJson($route)->assertForbidden();

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_undelivered_seller_order_is_rejected_without_mutation(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'transit');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'transit');
        $order->payments()->update(['method' => 'card']);
        $sellerOrder = $this->sellerOrder($order, $store, 'ready_for_pickup');
        $shipment = $this->shipment($sellerOrder, $provider, 'out_for_delivery');

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_fully_delivered');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('ready_for_pickup', $sellerOrder->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertSame(0, SellerOrderStatusHistory::query()->count());
        $this->assertSame('out_for_delivery', $shipment->fresh()->status);
    }

    public function test_cod_requires_capture_and_all_required_rider_cash_collection_evidence(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'cash-proof');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'cash-proof');
        $sellerOrder = $this->sellerOrder($order, $store, 'handed_to_logistics');
        $this->shipment($sellerOrder, $provider, 'delivered');

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'cod_cash_collection_incomplete');

        $this->assertSame('pending', $order->payments()->sole()->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('handed_to_logistics', $sellerOrder->fresh()->status);
        $this->assertSame(0, SellerOrderStatusHistory::query()->count());
    }

    public function test_cancelled_or_inactive_orders_are_rejected(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'blocked');
        [, $store] = $this->seller();
        $order = $this->marketplaceOrder($buyer, 'blocked');
        $order->payments()->update(['method' => 'card']);
        $sellerOrder = $this->sellerOrder($order, $store, 'cancelled');

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'no_active_seller_orders');

        $sellerOrder->update(['status' => 'handed_to_logistics']);
        $order->update(['status' => 'cancelled']);
        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_cancelled');

        $this->assertSame('handed_to_logistics', $sellerOrder->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
    }

    public function test_historical_completed_order_keeps_its_timestamp_on_replay(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'legacy');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'legacy');
        $sellerOrder = $this->sellerOrder($order, $store, 'completed');
        $shipment = $this->shipment($sellerOrder, $provider, 'delivered');
        $this->collectCod($shipment, $provider);

        $originalCompletedAt = '2024-01-02 03:04:05';
        $sellerOrder->update(['completed_at' => $originalCompletedAt]);
        $order->update([
            'status' => 'fulfilled',
            'payment_status' => 'paid',
            'completed_at' => $originalCompletedAt,
        ]);
        SellerOrderStatusHistory::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'from_status' => 'handed_to_logistics',
            'to_status' => 'completed',
            'actor_user_id' => $buyer->id,
            'reason' => 'Legacy completion',
            'occurred_at' => $originalCompletedAt,
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('sendAfterCommit');
        $this->app->instance(NotificationService::class, $notifications);

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', true)
            ->assertJsonPath('data.completed_at', '2024-01-02T03:04:05+00:00');

        $this->assertSame('2024-01-02 03:04:05', $order->fresh()->completed_at);
        $this->assertSame(1, $sellerOrder->statusHistory()->count());
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_unexpected_failure_rolls_back_partial_confirmation_changes(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'rollback');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'rollback');
        $sellerOrder = $this->sellerOrder($order, $store, 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'delivered');
        $this->collectCod($shipment, $provider);
        $originalPaymentStatus = $order->fresh()->payment_status;

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('sendAfterCommit')
            ->once()
            ->andThrow(new \RuntimeException('test-only downstream failure'));
        $this->app->instance(NotificationService::class, $notifications);

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertServerError()
            ->assertJsonPath('error.code', 'receipt_confirmation_failed');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame($originalPaymentStatus, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertSame('handed_to_logistics', $sellerOrder->fresh()->status);
        $this->assertNull($sellerOrder->fresh()->completed_at);
        $this->assertSame(0, $sellerOrder->statusHistory()->count());
    }

    public function test_only_processing_parent_orders_allow_first_confirmation_and_get_matches_post(): void
    {
        $valid = $this->receiptFixture('eligible-processing');
        $this->collectCod($valid['shipment'], $valid['provider']);

        $this->actingAs($valid['buyer'])
            ->getJson('/api/mobile/buyer/orders/'.$valid['order']->id)
            ->assertOk()
            ->assertJsonPath('data.buyer_actions.can_confirm_receipt', true);
        $this->actingAs($valid['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$valid['order']->id.'/confirm-receipt')
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled');

        foreach (['pending_payment', 'in_transit', 'fulfilled', 'cancelled'] as $status) {
            $fixture = $this->receiptFixture('ineligible-'.$status);
            $fixture['order']->update(['status' => $status]);

            $this->actingAs($fixture['buyer'])
                ->getJson('/api/mobile/buyer/orders/'.$fixture['order']->id)
                ->assertOk()
                ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);

            $expectedCode = $status === 'cancelled' ? 'order_cancelled' : 'order_state_ineligible';
            $this->actingAs($fixture['buyer'])
                ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
                ->assertStatus(409)
                ->assertJsonPath('error.code', $expectedCode);
        }
    }

    public function test_each_active_seller_order_needs_delivered_parcels_and_latest_rider_proof(): void
    {
        $mutations = [
            'shipment-without-parcels' => function (array $fixture): void {
                $parcelIds = $fixture['shipment']->parcels()->pluck('id');
                DeliveryAttempt::query()->whereIn('parcel_id', $parcelIds)->delete();
                Parcel::query()->whereIn('id', $parcelIds)->delete();
            },
            'missing-successful-attempt' => function (array $fixture): void {
                DeliveryAttempt::query()
                    ->whereIn('parcel_id', $fixture['shipment']->parcels()->pluck('id'))
                    ->delete();
            },
            'missing-proof-record' => function (array $fixture): void {
                $attempt = DeliveryAttempt::query()
                    ->whereIn('parcel_id', $fixture['shipment']->parcels()->pluck('id'))
                    ->sole();
                $attempt->proofs()->delete();
            },
            'missing-recipient-name' => function (array $fixture): void {
                $attempt = DeliveryAttempt::query()
                    ->whereIn('parcel_id', $fixture['shipment']->parcels()->pluck('id'))
                    ->sole();
                $attempt->proofs()->sole()->update(['recipient_name' => null]);
            },
            'blank-recipient-name' => function (array $fixture): void {
                $attempt = DeliveryAttempt::query()
                    ->whereIn('parcel_id', $fixture['shipment']->parcels()->pluck('id'))
                    ->sole();
                $attempt->proofs()->sole()->update(['recipient_name' => '   ']);
            },
            'missing-photo-file' => function (array $fixture): void {
                $attempt = DeliveryAttempt::query()
                    ->whereIn('parcel_id', $fixture['shipment']->parcels()->pluck('id'))
                    ->sole();
                $attempt->proofs()->sole()->update(['file_path' => ' ']);
            },
            'latest-attempt-not-delivered' => function (array $fixture): void {
                $parcel = $fixture['shipment']->parcels()->sole();
                $attempt = DeliveryAttempt::query()->create([
                    'parcel_id' => $parcel->id,
                    'rider_profile_id' => DeliveryAttempt::query()->where('parcel_id', $parcel->id)->sole()->rider_profile_id,
                    'attempt_no' => 2,
                    'outcome' => 'failed',
                    'attempted_at' => now()->addSecond(),
                ]);
                $this->assertSame('failed', $attempt->outcome);
            },
            'one-undelivered-parcel' => function (array $fixture): void {
                $parcel = $fixture['shipment']->parcels()->sole();
                $waybill = $parcel->waybill;
                $waybill->update(['piece_count' => 2]);
                Parcel::query()->create([
                    'shipment_id' => $fixture['shipment']->id,
                    'waybill_id' => $waybill->id,
                    'parcel_no' => 'MOBILE-UNDELIVERED-'.Str::upper(Str::random(12)),
                    'piece_sequence' => 2,
                    'weight_kg' => 1,
                    'status' => 'out_for_delivery',
                ]);
            },
        ];

        foreach ($mutations as $suffix => $mutate) {
            $fixture = $this->receiptFixture($suffix);
            $this->useNonCodPayment($fixture['order']);
            $mutate($fixture);

            $this->actingAs($fixture['buyer'])
                ->getJson('/api/mobile/buyer/orders/'.$fixture['order']->id)
                ->assertOk()
                ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);
            $this->actingAs($fixture['buyer'])
                ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'order_not_fully_delivered');
            $this->assertSame('processing', $fixture['order']->fresh()->status);
            $this->assertSame('handed_to_logistics', $fixture['seller_order']->fresh()->status);
            $this->assertSame(0, $fixture['seller_order']->statusHistory()->count());
        }
    }

    public function test_delivery_from_another_seller_or_an_undelivered_seller_order_cannot_complete_parent(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'multi-delivery');
        [, $firstStore] = $this->seller();
        [, $secondStore] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'multi-delivery');
        $deliveredSellerOrder = $this->sellerOrder($order, $firstStore, 'handed_to_logistics');
        $undeliveredSellerOrder = $this->sellerOrder($order, $secondStore, 'handed_to_logistics');
        $this->shipment($deliveredSellerOrder, $provider, 'delivered');
        $undeliveredShipment = $this->shipment($undeliveredSellerOrder, $provider, 'out_for_delivery');
        $this->useNonCodPayment($order);

        $this->actingAs($buyer)
            ->postJson('/api/mobile/buyer/orders/'.$order->id.'/confirm-receipt')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_fully_delivered');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('handed_to_logistics', $deliveredSellerOrder->fresh()->status);
        $this->assertSame('handed_to_logistics', $undeliveredSellerOrder->fresh()->status);
        $this->assertSame('out_for_delivery', $undeliveredShipment->fresh()->status);
        $this->assertSame(0, SellerOrderStatusHistory::query()->count());
    }

    public function test_active_seller_order_without_an_outbound_shipment_is_ineligible(): void
    {
        $fixture = $this->receiptFixture('no-outbound', withShipment: false);
        $this->useNonCodPayment($fixture['order']);

        $this->actingAs($fixture['buyer'])
            ->getJson('/api/mobile/buyer/orders/'.$fixture['order']->id)
            ->assertOk()
            ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);
        $this->actingAs($fixture['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_fully_delivered');
    }

    public function test_admin_delivered_status_override_without_rider_records_does_not_authorize_receipt(): void
    {
        $fixture = $this->receiptFixture('admin-override', shipmentStatus: 'out_for_delivery');
        $this->useNonCodPayment($fixture['order']);
        $admin = $this->user(UserRole::Admin, 'fulfillment-override');

        $this->actingAs($admin)
            ->patch(route('admin.fulfillment.override', $fixture['shipment']), [
                'status' => 'delivered',
                'note' => 'Manually corrected delivery label.',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $fixture['shipment']->id,
            'event_type' => 'admin_override',
            'source' => 'admin',
            'to_status' => 'delivered',
        ]);

        $this->actingAs($fixture['buyer'])
            ->getJson('/api/mobile/buyer/orders/'.$fixture['order']->id)
            ->assertOk()
            ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);
        $this->actingAs($fixture['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_fully_delivered');
    }

    public function test_completed_seller_order_does_not_skip_delivery_evidence_for_unconfirmed_parent(): void
    {
        $fixture = $this->receiptFixture('completed-child');
        $this->useNonCodPayment($fixture['order']);
        $fixture['seller_order']->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $attempt = DeliveryAttempt::query()
            ->whereIn('parcel_id', $fixture['shipment']->parcels()->pluck('id'))
            ->sole();
        $attempt->delete();

        $this->actingAs($fixture['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_fully_delivered');
        $this->assertSame('processing', $fixture['order']->fresh()->status);
        $this->assertSame('completed', $fixture['seller_order']->fresh()->status);
        $this->assertSame(0, $fixture['seller_order']->statusHistory()->count());
    }

    public function test_unresolved_return_states_and_open_return_disputes_block_first_confirmation(): void
    {
        foreach (ReturnRequestStatus::cases() as $status) {
            if (in_array($status, [ReturnRequestStatus::Resolved, ReturnRequestStatus::Rejected], true)) {
                continue;
            }

            $fixture = $this->receiptFixture('return-'.$status->value);
            $this->useNonCodPayment($fixture['order']);
            $this->createReturnRequest($fixture, $status->value);

            $this->actingAs($fixture['buyer'])
                ->getJson('/api/mobile/buyer/orders/'.$fixture['order']->id)
                ->assertOk()
                ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);
            $this->actingAs($fixture['buyer'])
                ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'return_refund_in_progress');
        }

        $fixture = $this->receiptFixture('resolved-return-dispute');
        $this->useNonCodPayment($fixture['order']);
        $return = $this->createReturnRequest($fixture, ReturnRequestStatus::Resolved->value);
        Dispute::query()->create([
            'dispute_no' => 'DSP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $fixture['seller_order']->id,
            'return_request_id' => $return->id,
            'opened_by' => $fixture['buyer']->id,
            'subject' => 'Refund dispute',
            'description' => 'Dispute remains open after the return state changed.',
            'priority' => 'normal',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($fixture['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'return_refund_in_progress');
    }

    public function test_standalone_open_dispute_blocks_receipt_until_admin_resolves_it(): void
    {
        $fixture = $this->receiptFixture('standalone-open-dispute');
        $this->collectCod($fixture['shipment'], $fixture['provider']);

        $dispute = Dispute::query()->create([
            'dispute_no' => 'DSP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $fixture['seller_order']->id,
            'return_request_id' => null,
            'shipment_id' => $fixture['shipment']->id,
            'opened_by' => $fixture['buyer']->id,
            'subject' => 'Standalone receipt dispute',
            'description' => 'An unresolved dispute without a ReturnRequest must block receipt confirmation.',
            'priority' => 'normal',
            'status' => 'open',
            'amount_minor' => (int) $fixture['seller_order']->total_minor,
            'opened_at' => now(),
        ]);

        $orderId = (int) $fixture['order']->id;
        $route = '/api/mobile/buyer/orders/'.$orderId.'/confirm-receipt';
        $canConfirm = $this->actingAs($fixture['buyer'])
            ->getJson('/api/mobile/buyer/orders/'.$orderId)
            ->assertOk()
            ->json('data.buyer_actions.can_confirm_receipt');

        $receiptState = function () use ($fixture): array {
            $order = $fixture['order']->fresh();
            $sellerOrder = $fixture['seller_order']->fresh();
            $payment = $fixture['order']->payments()->sole();

            return [
                'order_status' => $order->status,
                'order_payment_status' => $order->payment_status,
                'order_completed_at' => $order->getRawOriginal('completed_at'),
                'seller_order_status' => $sellerOrder->status,
                'seller_order_completed_at' => $sellerOrder->getRawOriginal('completed_at'),
                'seller_order_history_count' => $sellerOrder->statusHistory()->count(),
                'payment_status' => $payment->status,
                'payment_paid_at' => $payment->getRawOriginal('paid_at'),
                'promotion_redemptions' => PromotionRedemption::query()
                    ->where('order_id', $fixture['order']->id)
                    ->orderBy('id')
                    ->get(['id', 'status', 'redeemed_at'])
                    ->map(fn (PromotionRedemption $redemption): array => [
                        (int) $redemption->id,
                        $redemption->status,
                        $redemption->getRawOriginal('redeemed_at'),
                    ])
                    ->all(),
            ];
        };

        $stateBeforeOpenAttempt = $receiptState();
        DB::beginTransaction();
        try {
            $openAttempt = $this->actingAs($fixture['buyer'])->postJson($route);
            $stateAfterOpenAttempt = $receiptState();
        } finally {
            // Keep this intentionally failing regression from retaining the
            // premature confirmation if the current implementation accepts it.
            DB::rollBack();
        }

        $admin = $this->user(UserRole::Admin, 'resolve-standalone-dispute');
        $this->actingAs($admin)
            ->postJson(route('admin.disputes.resolve', $dispute), [
                'outcome' => 'seller_favored',
                'note' => 'The case was reviewed and resolved before Buyer receipt confirmation.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Dispute resolved successfully.');
        $this->assertSame('resolved', $dispute->fresh()->status);

        $this->actingAs($fixture['buyer'])
            ->postJson($route)
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.receipt_confirmed', true)
            ->assertJsonPath('data.already_confirmed', false);

        $historyCountAfterConfirmation = $fixture['seller_order']->statusHistory()->count();
        $this->actingAs($fixture['buyer'])
            ->postJson($route)
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', true);
        $this->assertSame($historyCountAfterConfirmation, $fixture['seller_order']->statusHistory()->count());

        $this->assertSame([
            'can_confirm' => false,
            'response_status' => 409,
            'error_code' => 'return_refund_in_progress',
            'state_unchanged' => true,
        ], [
            'can_confirm' => $canConfirm,
            'response_status' => $openAttempt->status(),
            'error_code' => $openAttempt->json('error.code'),
            'state_unchanged' => $stateBeforeOpenAttempt === $stateAfterOpenAttempt,
        ], 'An unresolved standalone dispute must block receipt without advancing order, payment, or promotion state. Before: '
            .json_encode($stateBeforeOpenAttempt, JSON_THROW_ON_ERROR)
            .' After: '.json_encode($stateAfterOpenAttempt, JSON_THROW_ON_ERROR));
    }

    public function test_pending_completed_and_unknown_refunds_block_new_confirmation_but_inactive_refund_states_do_not(): void
    {
        foreach (['pending', 'completed', 'under_review'] as $status) {
            $fixture = $this->receiptFixture('refund-'.$status);
            $this->useNonCodPayment($fixture['order']);
            $this->createRefund($fixture['order'], $status);

            $this->actingAs($fixture['buyer'])
                ->getJson('/api/mobile/buyer/orders/'.$fixture['order']->id)
                ->assertOk()
                ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);
            $this->actingAs($fixture['buyer'])
                ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'return_refund_in_progress');
        }

        foreach (['failed', 'cancelled'] as $status) {
            $fixture = $this->receiptFixture('inactive-refund-'.$status);
            $this->useNonCodPayment($fixture['order']);
            $this->createRefund($fixture['order'], $status);

            $this->actingAs($fixture['buyer'])
                ->getJson('/api/mobile/buyer/orders/'.$fixture['order']->id)
                ->assertOk()
                ->assertJsonPath('data.buyer_actions.can_confirm_receipt', true);
            $this->actingAs($fixture['buyer'])
                ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
                ->assertOk()
                ->assertJsonPath('data.status', 'fulfilled');
        }
    }

    public function test_confirmed_order_replay_is_read_only_after_a_later_completed_refund(): void
    {
        $fixture = $this->receiptFixture('replay-after-refund');
        $this->collectCod($fixture['shipment'], $fixture['provider']);
        $route = '/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt';
        $first = $this->actingAs($fixture['buyer'])
            ->postJson($route)
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', false);
        $originalCompletedAt = $first->json('data.completed_at');
        $historyCount = $fixture['seller_order']->statusHistory()->count();

        $return = $this->createReturnRequest($fixture, ReturnRequestStatus::Resolved->value);
        $this->createRefund($fixture['order'], 'completed', $return);
        $fixture['order']->payments()->sole()->update([
            'status' => 'partially_refunded',
            'refunded_at' => now(),
        ]);
        $fixture['order']->update(['payment_status' => 'partially_refunded']);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('sendAfterCommit');
        $this->app->instance(NotificationService::class, $notifications);

        $this->actingAs($fixture['buyer'])
            ->postJson($route)
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', true)
            ->assertJsonPath('data.completed_at', $originalCompletedAt);

        $this->assertSame($originalCompletedAt, Carbon::parse($fixture['order']->fresh()->completed_at)->toIso8601String());
        $this->assertSame('partially_refunded', $fixture['order']->fresh()->payment_status);
        $this->assertSame('partially_refunded', $fixture['order']->payments()->sole()->status);
        $this->assertSame($historyCount, $fixture['seller_order']->statusHistory()->count());
        $this->assertSame('resolved', $return->fresh()->status);
        $this->assertSame('completed', Refund::query()->where('order_id', $fixture['order']->id)->sole()->status);
    }

    public function test_cancelled_seller_orders_remain_excluded_from_a_valid_confirmation(): void
    {
        $fixture = $this->receiptFixture('cancelled-child');
        [, $cancelledStore] = $this->seller();
        $cancelledSellerOrder = $this->sellerOrder($fixture['order'], $cancelledStore, 'cancelled');
        $this->collectCod($fixture['shipment'], $fixture['provider']);

        $this->actingAs($fixture['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$fixture['order']->id.'/confirm-receipt')
            ->assertOk()
            ->assertJsonPath('data.receipt_confirmed', true);

        $this->assertSame('completed', $fixture['seller_order']->fresh()->status);
        $this->assertSame('cancelled', $cancelledSellerOrder->fresh()->status);
        $this->assertSame(0, $cancelledSellerOrder->statusHistory()->count());
    }

    /**
     * @return array{buyer: User, provider: array{user: User, profile: LogisticsProfile}, order: Order, seller_order: SellerOrder, shipment: ?Shipment}
     */
    private function receiptFixture(string $suffix, bool $withShipment = true, string $shipmentStatus = 'delivered'): array
    {
        $buyer = $this->user(UserRole::Buyer, $suffix);
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, $suffix);
        $sellerOrder = $this->sellerOrder($order, $store, 'handed_to_logistics');
        $shipment = $withShipment ? $this->shipment($sellerOrder, $provider, $shipmentStatus) : null;

        return [
            'buyer' => $buyer,
            'provider' => $provider,
            'order' => $order,
            'seller_order' => $sellerOrder,
            'shipment' => $shipment,
        ];
    }

    private function useNonCodPayment(Order $order): void
    {
        $order->payments()->sole()->update([
            'method' => 'online',
            'provider' => 'fixture',
            'status' => 'paid',
            'paid_at' => now(),
            'metadata' => ['collection_method' => 'online'],
        ]);
        $order->update(['payment_status' => 'paid']);
    }

    /** @param array{buyer: User, order: Order, seller_order: SellerOrder} $fixture */
    private function createReturnRequest(array $fixture, string $status): ReturnRequest
    {
        return ReturnRequest::query()->create([
            'return_no' => 'RR-'.Str::upper(Str::random(12)),
            'order_id' => $fixture['order']->id,
            'seller_order_id' => $fixture['seller_order']->id,
            'buyer_id' => $fixture['buyer']->id,
            'store_id' => $fixture['seller_order']->store_id,
            'request_type' => 'return_refund',
            'reason_code' => 'item_arrived_damaged',
            'status' => $status,
            'requested_amount_minor' => 1000,
            'submitted_at' => now(),
        ]);
    }

    private function createRefund(Order $order, string $status, ?ReturnRequest $return = null): Refund
    {
        return Refund::query()->create([
            'refund_no' => 'RFD-'.Str::upper(Str::random(12)),
            'return_request_id' => $return?->id,
            'payment_id' => $order->payments()->sole()->id,
            'order_id' => $order->id,
            'amount_minor' => 1000,
            'status' => $status,
            'method' => 'manual',
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
    }

    private function user(UserRole $role, string $suffix, string $status = 'active'): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($role->value).' '.(++$this->sequence),
            'email' => 'mobile-confirm-'.$role->value.'-'.Str::slug($suffix).'-'.$this->sequence.'@example.test',
            'role' => $role->value,
            'status' => $status,
        ]);
        $roleId = Role::query()->where('name', $role->value)->value('id');
        if ($roleId) {
            $user->roles()->syncWithoutDetaching([$roleId]);
        }

        return $user;
    }

    /** @return array{0: User, 1: Store} */
    private function seller(): array
    {
        $user = $this->user(UserRole::Seller, 'store');
        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'house_number' => '12',
            'street' => 'Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'is_default_pickup' => true,
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'legal_business_name' => 'Test Seller Business '.$user->id,
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Test Seller Store '.$user->id,
            'slug' => 'mobile-confirm-store-'.$user->id,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return [$user, $store];
    }

    /** @return array{user: User, profile: LogisticsProfile} */
    private function provider(): array
    {
        $user = $this->user(UserRole::Logistics, 'provider');
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Test Logistics '.$user->id,
            'display_name' => 'Test Logistics',
            'contact_phone' => '+639171234567',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function marketplaceOrder(User $buyer, string $suffix): Order
    {
        $address = Address::query()->create([
            'user_id' => $buyer->id,
            'label' => 'Home',
            'recipient_name' => $buyer->name,
            'phone' => '+639171234568',
            'house_number' => '20',
            'street' => 'Buyer Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $order = Order::query()->create([
            'order_no' => 'BRY-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $address->phone,
            'address_line' => '20 Buyer Street',
            'barangay' => $address->barangay,
            'city_municipality' => $address->city_municipality,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
            'currency' => 'PHP',
            'subtotal_minor' => 16600,
            'shipping_fee_minor' => 3400,
            'discount_minor' => 0,
            'total_minor' => 20000,
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);
        Payment::query()->create([
            'payment_no' => 'PAY-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'method' => 'cod',
            'amount_minor' => 20000,
            'status' => 'pending',
            'initiated_at' => now(),
        ]);

        return $order;
    }

    private function sellerOrder(Order $order, Store $store, string $status, int $totalMinor = 20000): SellerOrder
    {
        return SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => $totalMinor,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => $totalMinor,
            'ready_at' => now(),
        ]);
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function shipment(SellerOrder $sellerOrder, array $provider, string $status): Shipment
    {
        $shipment = Shipment::query()->create([
            'shipment_no' => 'MOBILE-CONFIRM-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'status' => $status,
            'cod_amount_minor' => (int) $sellerOrder->total_minor,
        ]);

        if ($status === 'delivered') {
            $this->attachDeliveredEvidence($shipment, $sellerOrder, $provider);
        }

        return $shipment;
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function attachDeliveredEvidence(Shipment $shipment, SellerOrder $sellerOrder, array $provider): void
    {
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'MOBILE-CONFIRM-WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(64)),
            'barcode_value' => 'MOBILE-CONFIRM-BC-'.Str::upper(Str::random(12)),
            'piece_count' => 1,
            'total_weight_kg' => 1,
            'generated_at' => now(),
        ]);
        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'MOBILE-CONFIRM-PARCEL-'.Str::upper(Str::random(12)),
            'piece_sequence' => 1,
            'weight_kg' => 1,
            'status' => 'delivered',
            'last_event_at' => now(),
        ]);
        $rider = $this->user(UserRole::Rider, 'delivery-proof');
        $riderProfile = RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
        $attempt = DeliveryAttempt::query()->create([
            'parcel_id' => $parcel->id,
            'rider_profile_id' => $riderProfile->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => now(),
        ]);
        $proofPath = 'delivery-proofs/mobile-confirm-'.Str::lower(Str::random(16)).'.webp';
        Storage::disk('local')->put($proofPath, 'test-only persisted delivery photo fixture');
        DeliveryProof::query()->create([
            'delivery_attempt_id' => $attempt->id,
            'type' => 'photo',
            'file_path' => $proofPath,
            'recipient_name' => (string) $sellerOrder->order->recipient_name,
            'captured_at' => now(),
            'uploaded_by' => $rider->id,
        ]);
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function collectCod(Shipment $shipment, array $provider): void
    {
        $rider = $this->user(UserRole::Rider, 'cash-collector');
        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        app(CodCashCollectionService::class)->recordRiderCollection(
            $shipment,
            $rider,
            (int) $shipment->cod_amount_minor,
        );
    }
}
