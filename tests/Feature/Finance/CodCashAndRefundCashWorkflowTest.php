<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundCashPayout;
use App\Models\RefundCashPayoutEvent;
use App\Models\ReturnRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\CodCashCollectionService;
use App\Services\SellerPayoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class CodCashAndRefundCashWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'Finance Admin');
    }

    public function test_cod_collection_requires_exact_due_and_seller_settlement_waits_for_finance_reconciliation(): void
    {
        $scenario = $this->scenario('settlement');
        $rider = $this->rider($scenario['provider'], 'settlement rider');
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $rider,
            20000,
        );

        $this->assertSame('collected', $collection->status);
        $this->assertSame('paid', $scenario['payment']->fresh()->status);
        $this->assertSame('paid', $scenario['order']->fresh()->payment_status);
        $this->assertCount(0, app(SellerPayoutService::class)->eligibleBySeller());
        $this->actingAs($this->admin)->get(route('admin.payments'))->assertOk();
        $this->actingAs($scenario['provider_user'])->get(route('logistics.cash.index'))->assertOk();

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), [
                'actual_amount' => '200.00',
                'reference' => 'LOG-SETTLEMENT-001',
            ])
            ->assertRedirect();
        $collection->refresh();
        $this->assertSame('received_by_logistics', $collection->status);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), [
                'actual_amount' => '200.00',
                'reference' => 'FIN-HANDOFF-SETTLEMENT-001',
            ])
            ->assertRedirect();
        $collection->refresh();
        $this->assertSame('remitted_to_finance', $collection->status);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '200.00',
                'reference' => 'FIN-RECEIPT-SETTLEMENT-001',
            ])
            ->assertRedirect();
        $collection->refresh();
        $this->assertSame('reconciled', $collection->status);
        $this->assertCount(1, app(SellerPayoutService::class)->eligibleBySeller());

        $this->actingAs($this->admin)
            ->post(route('admin.payments.seller-settlements.store'), [
                'seller_profile_id' => $scenario['seller_profile']->id,
                'reason' => 'COD receipts reconciled through Finance.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('seller_payouts', [
            'seller_profile_id' => $scenario['seller_profile']->id,
            'net_minor' => 18000,
            'status' => 'pending',
            'paid_at' => null,
        ]);
        $this->assertDatabaseCount('seller_payout_items', 1);

        $payout = SellerPayout::query()->where('seller_profile_id', $scenario['seller_profile']->id)->sole();
        $this->actingAs($this->admin)
            ->post(route('admin.payments.seller-settlements.record-paid', $payout), [
                'external_payout_confirmed' => '1',
                'actual_amount' => '180.00',
                'method' => 'bank_transfer',
                'reference' => 'BANK-SELLER-SETTLEMENT-001',
                'paid_on' => now()->toDateString(),
                'reason' => 'Bank transfer was completed outside Bearly.',
                'receipt' => UploadedFile::fake()->createWithContent(
                    'seller-receipt.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
                ),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame('bank_transfer', $payout->fresh()->external_method);
        $this->actingAs($this->admin)
            ->get(route('admin.payments.seller-settlements.receipt', $payout))
            ->assertOk();
    }

    public function test_multi_seller_cod_payment_is_not_captured_until_each_active_shipment_is_collected(): void
    {
        $scenario = $this->scenario('multi seller');
        $scenario['seller_order']->update([
            'subtotal_minor' => 9000,
            'shipping_fee_minor' => 1000,
            'total_minor' => 10000,
        ]);
        $scenario['shipment']->update(['cod_amount_minor' => 10000]);

        $secondSeller = $this->user(UserRole::Seller, 'Second Seller multi seller');
        $secondProfile = SellerProfile::query()->create([
            'user_id' => $secondSeller->id,
            'legal_business_name' => 'Second Seller multi seller',
            'commission_rate_bps' => 1000,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $secondStore = Store::query()->create([
            'seller_profile_id' => $secondProfile->id,
            'name' => 'Second Store multi seller',
            'slug' => 'second-store-multi-seller',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);
        $secondSellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $scenario['order']->id,
            'store_id' => $secondStore->id,
            'status' => 'completed',
            'subtotal_minor' => 9000,
            'discount_minor' => 0,
            'shipping_fee_minor' => 1000,
            'total_minor' => 10000,
            'completed_at' => now(),
        ]);
        $secondShipment = Shipment::query()->create([
            'shipment_no' => 'SHP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $secondSellerOrder->id,
            'logistics_profile_id' => $scenario['provider']->id,
            'purpose' => 'outbound',
            'status' => 'delivered',
            'cod_amount_minor' => 10000,
            'delivered_at' => now(),
        ]);
        $rider = $this->rider($scenario['provider'], 'multi seller rider');

        app(CodCashCollectionService::class)->recordRiderCollection($scenario['shipment'], $rider, 10000);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);

        app(CodCashCollectionService::class)->recordRiderCollection($secondShipment, $rider, 10000);
        $this->assertSame('paid', $scenario['payment']->fresh()->status);
        $this->assertSame('paid', $scenario['order']->fresh()->payment_status);
        $this->assertDatabaseCount('platform_commissions', 2);
        $this->assertDatabaseCount('seller_transactions', 2);
    }

    public function test_overcollection_is_preserved_as_a_disputed_cash_record_without_capturing_payment(): void
    {
        $scenario = $this->scenario('overcollection');
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $this->rider($scenario['provider'], 'overcollection rider'),
            20500,
        );

        $this->assertSame('disputed', $collection->status);
        $this->assertSame(500, (int) $collection->discrepancy_minor);
        $this->assertSame(20500, (int) $collection->amount_collected_minor);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
    }

    public function test_unpaid_cod_refund_cannot_enter_cash_custody(): void
    {
        $scenario = $this->scenario('unpaid refund');
        $refund = $this->refund($scenario, 5000);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.refunds.issue-cash', $refund), [
                'cash_issued_confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('refund');

        $this->assertDatabaseMissing('refund_cash_payouts', ['refund_id' => $refund->id]);
        $this->assertSame('pending', $refund->fresh()->status);
    }

    public function test_cod_difference_is_recorded_but_never_captured_or_settled_as_paid(): void
    {
        $scenario = $this->scenario('discrepancy');
        $rider = $this->rider($scenario['provider'], 'discrepancy rider');
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $rider,
            17500,
        );

        $this->assertSame('disputed', $collection->status);
        $this->assertSame(-2500, (int) $collection->discrepancy_minor);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);

        $otherProvider = $this->provider('different provider');
        $this->actingAs($otherProvider['user'])
            ->post(route('logistics.cash.cod.receive', $collection), [
                'actual_amount' => '175.00',
                'reference' => 'FOREIGN-HANDOFF',
            ])
            ->assertForbidden();

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), [
                'actual_amount' => '175.00',
                'reference' => 'LOG-DISCREPANCY-001',
            ])
            ->assertRedirect();
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), [
                'actual_amount' => '175.00',
                'reference' => 'FIN-HANDOFF-DISCREPANCY-001',
            ])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '175.00',
                'reference' => 'FIN-RECEIPT-DISCREPANCY-001',
            ])
            ->assertRedirect();

        $this->assertSame('reconciled', $collection->fresh()->status);
        $this->assertSame(-2500, (int) $collection->fresh()->discrepancy_minor);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertCount(0, app(SellerPayoutService::class)->eligibleBySeller());
        $this->expectException(ConflictHttpException::class);
        app(CodCashCollectionService::class)->assertPaymentCaptured($scenario['order']->fresh());
    }

    public function test_refund_cash_requires_logistics_receipt_rider_return_and_verified_buyer_acknowledgment(): void
    {
        Storage::fake('local');
        $scenario = $this->scenario('refund cash');
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $this->rider($scenario['provider'], 'cod collection rider'),
            20000,
        );
        $refund = $this->refund($scenario, 5000);
        $payout = null;

        $this->actingAs($this->admin)
            ->post(route('admin.payments.refunds.issue-cash', $refund), [])
            ->assertRedirect()
            ->assertSessionHasErrors('cash_issued_confirmed');
        $this->assertDatabaseMissing('refund_cash_payouts', ['refund_id' => $refund->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('generates a unique handoff reference')
            ->assertDontSee('name="finance_issue_reference"', false);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.refunds.issue-cash', $refund), [
                'cash_issued_confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Reference: CFH-'));
        $payout = RefundCashPayout::query()->where('refund_id', $refund->id)->sole();
        $this->assertSame('awaiting_logistics_receipt', $payout->status);
        $this->assertMatchesRegularExpression('/^CFH-[0-9A-HJKMNP-TV-Z]{26}$/', $payout->finance_issue_reference);
        $this->assertSame('CFH-'.substr($payout->payout_no, 4), $payout->finance_issue_reference);
        $this->assertDatabaseHas('refund_cash_payout_events', [
            'refund_cash_payout_id' => $payout->id,
            'event_type' => 'cash_issued_to_logistics',
            'reference' => $payout->finance_issue_reference,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.refunds.issue-cash', $refund), ['cash_issued_confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame($payout->finance_issue_reference, $payout->fresh()->finance_issue_reference);
        $this->assertDatabaseCount('refund_cash_payouts', 1);
        $this->assertDatabaseCount('refund_cash_payout_events', 1);

        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('cash-custody-table-wrap', false)
            ->assertSee('cash-custody-table--refund', false)
            ->assertSee('Cash payout ID')
            ->assertSee('Refund ID')
            ->assertSee('Finance handoff reference')
            ->assertSee($payout->finance_issue_reference);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.receive', $payout), [
                'actual_amount' => '50.00',
                'reference' => 'LOG-REFUND-RECEIPT-001',
            ])
            ->assertRedirect();
        $firstRider = $this->rider($scenario['provider'], 'refund rider one');
        $secondRider = $this->rider($scenario['provider'], 'refund rider two');

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.assign', $payout), ['rider_user_id' => $firstRider->id])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Print acknowledgment ACK-'));
        $payout->refresh();
        $this->assertSame('assigned_to_rider', $payout->status);
        $firstReference = 'ACK-'.$payout->payout_no.'-A01';
        $this->assertSame($firstReference, $payout->acknowledgment_reference);
        $this->assertDatabaseHas('refund_cash_payout_events', [
            'refund_cash_payout_id' => $payout->id,
            'event_type' => 'cash_assigned_to_rider',
            'reference' => $firstReference,
        ]);
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee($firstReference)
            ->assertSee(route('logistics.cash.refunds.acknowledgment-slip', $payout), false)
            ->assertSee('Print / Save as PDF');
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment-slip', $payout))
            ->assertOk()
            ->assertSee('images/bearly-logo.png')
            ->assertSee('Print / Save as PDF')
            ->assertSee($firstReference)
            ->assertSee('₱50.00')
            ->assertSee('Received by Buyer')
            ->assertSee('Delivered by Rider');

        // A Logistics operator can prepare a reference for an already-assigned legacy payout.
        $payout->update(['acknowledgment_reference' => null]);
        RefundCashPayoutEvent::query()
            ->where('refund_cash_payout_id', $payout->id)
            ->where('event_type', 'cash_assigned_to_rider')
            ->update(['reference' => null]);
        $this->actingAs($firstRider)
            ->get(route('rider.refund-cash.index'))
            ->assertOk()
            ->assertSee('refund-cash-card-content', false)
            ->assertSee('Cash payout ID')
            ->assertSee('Refund ID')
            ->assertSee('Finance-to-Logistics handoff reference')
            ->assertSee($payout->finance_issue_reference)
            ->assertSee('Wait for Logistics')
            ->assertDontSee('name="actual_amount"', false)
            ->assertDontSee('Confirm cash and form received');

        $this->actingAs($firstRider)
            ->post(route('rider.refund-cash.receive', $payout), [
                'actual_amount' => '50.00',
                'acknowledgment_reference' => $firstReference,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('acknowledgment_reference');
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('Prepare acknowledgment slip');
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.acknowledgment-slip.prepare', $payout))
            ->assertRedirect(route('logistics.cash.refunds.acknowledgment-slip', $payout));
        $this->assertSame($firstReference, $payout->fresh()->acknowledgment_reference);
        $this->assertDatabaseHas('refund_cash_payout_events', [
            'refund_cash_payout_id' => $payout->id,
            'event_type' => 'refund_cash_acknowledgment_reference_generated',
            'reference' => $firstReference,
        ]);
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.acknowledgment-slip.prepare', $payout))
            ->assertRedirect(route('logistics.cash.refunds.acknowledgment-slip', $payout));
        $this->assertSame(1, RefundCashPayoutEvent::query()
            ->where('refund_cash_payout_id', $payout->id)
            ->where('event_type', 'refund_cash_acknowledgment_reference_generated')
            ->count());
        $this->actingAs($firstRider)
            ->get(route('rider.refund-cash.index'))
            ->assertOk()
            ->assertSee($firstReference)
            ->assertSee('placeholder="50.00"', false)
            ->assertDontSee('value="50.00"', false)
            ->assertSee('Reference printed on the paper form')
            ->assertSee('Confirm cash and form received');
        $this->actingAs($firstRider)
            ->get(route('logistics.cash.refunds.acknowledgment-slip', $payout))
            ->assertForbidden();

        $this->actingAs($firstRider)
            ->post(route('rider.refund-cash.receive', $payout), [
                'actual_amount' => '50.00',
                'acknowledgment_reference' => $firstReference,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('refund_cash_payout_events', [
            'refund_cash_payout_id' => $payout->id,
            'event_type' => 'cash_received_by_rider',
            'reference' => $firstReference,
        ]);
        $this->actingAs($firstRider)
            ->post(route('rider.refund-cash.start', $payout))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'form provided by Logistics'));
        $payout->refresh();
        $this->assertSame('ACK-'.$payout->payout_no.'-A01', $firstReference);
        $startEvent = RefundCashPayoutEvent::query()
            ->where('refund_cash_payout_id', $payout->id)
            ->where('event_type', 'refund_cash_delivery_started')
            ->sole();
        $this->assertSame($firstReference, $startEvent->reference);

        $this->actingAs($firstRider)
            ->get(route('rider.refund-cash.index'))
            ->assertOk()
            ->assertSee('Buyer acknowledgment reference')
            ->assertSee($firstReference)
            ->assertSee('Use the printed form from Logistics')
            ->assertDontSee('data-print-refund-ack')
            ->assertDontSee('refund-cash-print-slip')
            ->assertDontSee('name="acknowledgment_reference"', false);

        $this->actingAs($firstRider)
            ->post(route('rider.refund-cash.fail', $payout), ['failure_reason' => 'Buyer not available at address.'])
            ->assertRedirect();
        $this->assertSame('cash_return_pending', $payout->fresh()->status);
        $this->assertDatabaseHas('refund_cash_payout_events', [
            'refund_cash_payout_id' => $payout->id,
            'event_type' => 'refund_cash_delivery_failed',
            'reference' => $firstReference,
        ]);

        $this->actingAs($firstRider)
            ->post(route('rider.refund-cash.return-cash', $payout), [
                'actual_amount' => '50.00',
                'reference' => 'RIDER-RETURN-001',
            ])
            ->assertRedirect();
        $this->assertSame('logistics_return_verification', $payout->fresh()->status);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.confirm-return', $payout), [
                'actual_amount' => '50.00',
                'reference' => 'LOG-RETURN-RECEIPT-001',
            ])
            ->assertRedirect();
        $this->assertSame('awaiting_rider_assignment', $payout->fresh()->status);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.assign', $payout), ['rider_user_id' => $secondRider->id])
            ->assertRedirect();
        $secondReference = $payout->fresh()->acknowledgment_reference;
        $this->assertSame('ACK-'.$payout->payout_no.'-A02', $secondReference);
        $this->assertDatabaseHas('refund_cash_payout_events', [
            'refund_cash_payout_id' => $payout->id,
            'event_type' => 'cash_assigned_to_rider',
            'reference' => $secondReference,
        ]);
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment-slip', $payout))
            ->assertOk()
            ->assertSee($secondReference)
            ->assertSee($secondRider->name);
        $this->actingAs($firstRider)
            ->post(route('rider.refund-cash.receive', $payout), [
                'actual_amount' => '50.00',
                'acknowledgment_reference' => $secondReference,
            ])
            ->assertForbidden();

        $this->actingAs($secondRider)
            ->post(route('rider.refund-cash.receive', $payout), [
                'actual_amount' => '50.00',
                'acknowledgment_reference' => $secondReference,
            ])
            ->assertRedirect();
        $this->actingAs($secondRider)
            ->post(route('rider.refund-cash.start', $payout))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'form provided by Logistics'));
        $this->assertSame($secondReference, $payout->fresh()->acknowledgment_reference);
        $this->assertNotSame($firstReference, $secondReference);
        $this->actingAs($secondRider)
            ->post(route('rider.refund-cash.deliver', $payout), [
                'actual_amount' => '50.00',
                'recipient_name' => 'Buyer Refund Recipient',
                'acknowledgment' => UploadedFile::fake()->createWithContent(
                    'signed-ack.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
                ),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('delivered_pending_verification', $payout->fresh()->status);
        $this->assertSame($secondReference, $payout->fresh()->acknowledgment_reference);
        $this->assertSame('pending', $refund->fresh()->status);
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment-slip', $payout))
            ->assertNotFound();
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('Buyer acknowledgment reference')
            ->assertSee('Match the Buyer acknowledgment reference above')
            ->assertSee($secondReference);
        $this->actingAs($this->admin)
            ->post(route('admin.payments.refund-cash.complete', $payout), [
                'buyer_receipt_verified' => '1',
                'completion_reason' => 'Attempted before independent Logistics verification.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('payout');
        $this->assertSame('pending', $refund->fresh()->status);
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment', $payout))
            ->assertOk();
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.verify-receipt', $payout), [
                'verification_note' => 'Signed paper acknowledgment and amount verified.',
            ])
            ->assertRedirect();
        $this->assertSame('awaiting_finance_completion', $payout->fresh()->status);

        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('Buyer acknowledgment reference:')
            ->assertSee($secondReference);
        $this->actingAs($this->admin)
            ->get(route('admin.payments.refund-cash.acknowledgment', $payout))
            ->assertOk();
        $this->actingAs($this->admin)
            ->post(route('admin.payments.refund-cash.complete', $payout), [
                'buyer_receipt_verified' => '1',
                'completion_reason' => 'Finance matched the signed acknowledgment and cash amount.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertSame('resolved', $scenario['return_request']->fresh()->status);
        $this->assertSame('completed', $payout->fresh()->status);
        $this->assertSame('partially_refunded', $scenario['payment']->fresh()->status);
        $this->assertDatabaseHas('refund_cash_payout_events', [
            'refund_cash_payout_id' => $payout->id,
            'event_type' => 'cash_return_received_by_logistics',
            'amount_minor' => 5000,
        ]);
        $this->assertDatabaseMissing('seller_refund_recoveries', ['refund_id' => $refund->id]);
    }

    /** @return array<string, mixed> */
    private function scenario(string $suffix): array
    {
        $buyer = $this->user(UserRole::Buyer, 'Buyer '.$suffix);
        $seller = $this->user(UserRole::Seller, 'Seller '.$suffix);
        $provider = $this->provider($suffix);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Seller '.$suffix,
            'commission_rate_bps' => 1000,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Store '.$suffix,
            'slug' => Str::slug('store '.$suffix.' '.$profile->id),
            'publication_status' => 'published',
            'published_at' => now(),
        ]);
        $order = Order::query()->create([
            'order_no' => 'ORD-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '25 Buyer Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'currency' => 'PHP',
            'subtotal_minor' => 18000,
            'shipping_fee_minor' => 2000,
            'discount_minor' => 0,
            'total_minor' => 20000,
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => 18000,
            'discount_minor' => 0,
            'shipping_fee_minor' => 2000,
            'total_minor' => 20000,
            'completed_at' => now(),
        ]);
        $payment = Payment::query()->create([
            'payment_no' => 'PAY-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'method' => 'cod',
            'amount_minor' => 20000,
            'status' => 'pending',
            'initiated_at' => now(),
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'status' => 'delivered',
            'cod_amount_minor' => 20000,
            'delivered_at' => now(),
        ]);

        return [
            'buyer' => $buyer,
            'seller' => $seller,
            'seller_profile' => $profile,
            'store' => $store,
            'provider_user' => $provider['user'],
            'provider' => $provider['profile'],
            'order' => $order,
            'seller_order' => $sellerOrder,
            'payment' => $payment,
            'shipment' => $shipment,
        ];
    }

    private function refund(array &$scenario, int $amountMinor): Refund
    {
        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper(Str::random(12)),
            'order_id' => $scenario['order']->id,
            'seller_order_id' => $scenario['seller_order']->id,
            'buyer_id' => $scenario['buyer']->id,
            'store_id' => $scenario['store']->id,
            'request_type' => 'refund_only',
            'reason_code' => 'item_arrived_damaged',
            'status' => 'refund_pending',
            'requested_amount_minor' => $amountMinor,
            'submitted_at' => now(),
            'seller_responded_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by' => $this->admin->id,
        ]);
        $scenario['return_request'] = $returnRequest;

        return Refund::query()->create([
            'refund_no' => 'RFD-'.Str::upper(Str::random(12)),
            'return_request_id' => $returnRequest->id,
            'payment_id' => $scenario['payment']->id,
            'order_id' => $scenario['order']->id,
            'amount_minor' => $amountMinor,
            'status' => 'pending',
            'method' => 'manual',
            'financially_responsible_party' => 'platform',
            'financial_responsibility_reason' => 'Platform-funded test refund.',
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ]);
    }

    private function provider(string $suffix): array
    {
        $user = $this->user(UserRole::Logistics, 'Logistics '.$suffix);
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Logistics '.$suffix,
            'display_name' => 'Provider '.$suffix,
            'contact_phone' => '+639171234568',
            'status' => 'active',
        ]);

        return ['user' => $user, 'profile' => $profile];
    }

    private function rider(LogisticsProfile $provider, string $suffix): User
    {
        $rider = $this->user(UserRole::Rider, 'Rider '.$suffix);
        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        return $rider;
    }

    private function user(UserRole $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'-'.(++$this->sequence).'@example.test',
            'role' => $role->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', $role->value)->value('id'),
        ]);

        return $user;
    }
}
