<?php

namespace Tests\Feature\Finance;

use App\Models\Address;
use App\Models\CodCashCollection;
use App\Models\DeliveryAttempt;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Parcel;
use App\Models\PlatformCommission;
use App\Models\RiderEarning;
use App\Models\RiderEarningPayout;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShippingSettlement;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\CommissionLedgerService;
use App\Services\ShippingSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZoneShippingSettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_eligible_shipping_fee_is_split_once_and_only_the_final_completion_rider_earns_the_pool(): void
    {
        $scenario = $this->scenario('final-rider', ['shipping_fee_minor' => 5001, 'platform_subsidy_minor' => 1000]);
        $originProvider = $this->provider('origin-custodian');
        $scenario['shipment']->update(['origin_logistics_profile_id' => $originProvider['profile']->id]);
        $service = app(ShippingSettlementService::class);

        $service->materializeEligibleSettlements();
        $service->materializeEligibleSettlements();

        $settlement = ShippingSettlement::query()->sole();
        $this->assertSame(5001, (int) $settlement->original_shipping_fee_minor);
        $this->assertSame(4001, (int) $settlement->buyer_paid_shipping_fee_minor);
        $this->assertSame(1000, (int) $settlement->platform_shipping_subsidy_minor);
        $this->assertSame('buyer_and_platform', $settlement->shipping_funding_source);
        $this->assertSame(2000, (int) $settlement->logistics_share_minor);
        $this->assertSame(3001, (int) $settlement->rider_share_minor);
        $this->assertSame(
            (int) $settlement->original_shipping_fee_minor,
            (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor,
        );
        $this->assertSame($scenario['finalAttempt']->id, (int) $settlement->delivery_attempt_id);
        $this->assertSame($scenario['finalRider']->id, (int) $settlement->rider_profile_id);
        $this->assertSame($scenario['provider']['profile']->id, (int) $settlement->logistics_profile_id);
        $this->assertNotSame($originProvider['profile']->id, (int) $settlement->logistics_profile_id);
        $this->assertSame(1, ShippingSettlement::query()->count());

        $earning = RiderEarning::query()->sole();
        $this->assertSame('shipping_delivery', $earning->type);
        $this->assertSame(3001, (int) $earning->amount_minor);
        $this->assertSame('pending', $earning->status);
        $this->assertNull($earning->paid_at);
        $this->assertSame($scenario['finalRider']->id, (int) $earning->rider_profile_id);
        $this->assertSame($scenario['shipment']->id, (int) $earning->shipment_id);
        $this->assertSame($scenario['provider']['profile']->id, (int) $earning->logistics_profile_id);
        $this->assertDatabaseMissing('rider_earnings', [
            'rider_profile_id' => $scenario['earlierRider']->id,
            'shipping_settlement_id' => $settlement->id,
        ]);
    }

    public function test_cod_shipping_settlement_waits_for_exact_finance_reconciliation(): void
    {
        $scenario = $this->scenario('cod-gate', ['payment_method' => 'cod']);
        $collection = CodCashCollection::query()->create([
            'collection_no' => 'COD-'.Str::upper((string) Str::ulid()),
            'shipment_id' => $scenario['shipment']->id,
            'payment_id' => $scenario['payment']->id,
            'order_id' => $scenario['order']->id,
            'seller_order_id' => $scenario['sellerOrder']->id,
            'logistics_profile_id' => $scenario['provider']['profile']->id,
            'rider_profile_id' => $scenario['finalRider']->id,
            'amount_due_minor' => $scenario['shipment']->cod_amount_minor,
            'amount_collected_minor' => $scenario['shipment']->cod_amount_minor,
            'discrepancy_minor' => 0,
            'amount_remitted_to_finance_minor' => $scenario['shipment']->cod_amount_minor,
            'status' => 'remitted_to_finance',
            'collected_at' => now()->subDays(9),
            'collected_by' => $scenario['finalRiderUser']->id,
            'finance_remittance_reference' => 'L2F-COD-GATE',
            'remitted_to_finance_at' => now()->subDays(8),
            'remitted_to_finance_by' => $scenario['provider']['user']->id,
        ]);

        $service = app(ShippingSettlementService::class);
        $service->materializeEligibleSettlements();
        $this->assertSame(0, ShippingSettlement::query()->count());
        $this->assertSame(0, RiderEarning::query()->whereNotNull('shipping_settlement_id')->count());

        $collection->update([
            'status' => 'reconciled',
            'amount_reconciled_minor' => $scenario['shipment']->cod_amount_minor,
            'finance_reference' => 'BFR-2026-COD-GATE',
            'reconciled_at' => now()->subDays(7),
            'reconciled_by' => $this->user('admin', 'COD Gate Finance')->id,
        ]);

        $service->materializeEligibleSettlements();

        $this->assertSame(1, ShippingSettlement::query()->count());
        $this->assertSame('pending', RiderEarning::query()->sole()->status);
    }

    public function test_all_five_zone_fees_have_exact_centavo_40_60_allocations(): void
    {
        $scenarios = [];
        for ($zone = 0; $zone <= 4; $zone++) {
            $scenarios[$zone] = $this->scenario('split-zone-'.$zone, [
                'zone_level' => $zone,
                'shipping_fee_minor' => 5000 + (500 * $zone),
            ]);
        }

        app(ShippingSettlementService::class)->materializeEligibleSettlements();

        foreach ($scenarios as $zone => $scenario) {
            $settlement = ShippingSettlement::query()
                ->where('seller_order_id', $scenario['sellerOrder']->id)
                ->firstOrFail();
            $fee = 5000 + (500 * $zone);
            $logisticsShare = intdiv($fee * 40, 100);
            $riderShare = $fee - $logisticsShare;

            $this->assertSame($fee, (int) $settlement->original_shipping_fee_minor);
            $this->assertSame($logisticsShare, (int) $settlement->logistics_share_minor);
            $this->assertSame($riderShare, (int) $settlement->rider_share_minor);
            $this->assertSame($fee, (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor);
        }
    }

    public function test_failed_cancelled_returned_and_open_return_work_do_not_create_shipping_earnings(): void
    {
        $failed = $this->scenario('failed', ['shipment_status' => 'delivery_failed']);
        $cancelled = $this->scenario('cancelled', [
            'seller_order_status' => 'cancelled',
            'shipment_status' => 'returned',
        ]);
        $openReturn = $this->scenario('open-return');

        \App\Models\ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper((string) Str::ulid()),
            'order_id' => $openReturn['order']->id,
            'seller_order_id' => $openReturn['sellerOrder']->id,
            'buyer_id' => $openReturn['buyer']->id,
            'store_id' => $openReturn['store']->id,
            'request_type' => 'refund_only',
            'reason_code' => 'item_arrived_damaged',
            'status' => 'under_review',
            'requested_amount_minor' => 1000,
            'submitted_at' => now()->subDay(),
        ]);

        app(ShippingSettlementService::class)->materializeEligibleSettlements();

        $this->assertSame(0, ShippingSettlement::query()->count());
        $this->assertSame(0, RiderEarning::query()->whereNotNull('shipping_settlement_id')->count());
        $this->assertSame('delivery_failed', $failed['shipment']->fresh()->status);
        $this->assertSame('cancelled', $cancelled['sellerOrder']->fresh()->status);
    }

    public function test_admin_remits_only_the_full_shipping_allocation_then_logistics_records_actual_rider_payment(): void
    {
        $scenario = $this->scenario('remittance', ['shipping_fee_minor' => 5000, 'platform_subsidy_minor' => 1000]);
        app(ShippingSettlementService::class)->materializeEligibleSettlements();
        $settlement = ShippingSettlement::query()->sole();
        $earning = RiderEarning::query()->sole();
        $admin = $this->user('admin', 'Finance Admin');

        $this->actingAs($admin)->post(route('admin.shipping-settlements.record-paid', $settlement), [
            'settlement_id' => $settlement->id,
            'remittance_confirmed' => '1',
            'actual_amount' => '49.99',
            'method' => 'bank_transfer',
            'reference' => 'BANK-SHIP-INVALID',
            'paid_on' => now()->toDateString(),
            'reason' => 'Record shipping transfer.',
        ])->assertSessionHasErrors('actual_amount');
        $this->assertSame('pending', $settlement->fresh()->status);
        $this->assertSame('pending', $earning->fresh()->status);

        $remittanceData = [
            'settlement_id' => $settlement->id,
            'remittance_confirmed' => '1',
            'actual_amount' => '50.00',
            'method' => 'bank_transfer',
            'reference' => 'BANK-SHIP-5000',
            'paid_on' => now()->toDateString(),
            'reason' => 'Shipping allocation sent to provider.',
        ];
        $this->actingAs($admin)
            ->post(route('admin.shipping-settlements.record-paid', $settlement), $remittanceData)
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('admin.shipping-settlements.record-paid', $settlement), $remittanceData)
            ->assertRedirect();

        $settlement->refresh();
        $earning->refresh();
        $this->assertSame('remitted', $settlement->status);
        $this->assertSame(5000, (int) $settlement->remitted_amount_minor);
        $this->assertSame('posted', $earning->status);
        $this->assertNull($earning->paid_at, 'Admin remittance to Logistics must not mark the Rider paid.');
        $this->assertSame(1, \App\Models\AuditLog::query()->where('action', 'shipping_settlement.remitted_to_logistics')->count());

        $this->actingAs($admin)
            ->get(route('admin.shipping-settlements.index'))
            ->assertOk()
            ->assertSee('Shipping allocations to Logistics')
            ->assertSee($scenario['shipment']->shipment_no)
            ->assertSee('BANK-SHIP-5000')
            ->assertSee('Rider payout: Awaiting Logistics');

        $this->actingAs($scenario['provider']['user'])
            ->get(route('logistics.settlements.index'))
            ->assertOk()
            ->assertSee($scenario['finalRiderUser']->name)
            ->assertSee('Rider pool: ₱30.00')
            ->assertSeeText('3-day return period ended Oct 15, 2026')
            ->assertSee('Actual Rider payout');

        $payoutData = [
            'earning_id' => $earning->id,
            'actual_amount' => '30.00',
            'method' => 'gcash',
            'reference' => 'GCASH-RIDER-3000',
            'paid_on' => now()->toDateString(),
            'reason' => 'Rider received shipping pool payout.',
            'payout_confirmed' => '1',
        ];
        $this->actingAs($scenario['provider']['user'])
            ->post(route('logistics.settlements.rider-earnings.record-paid', $earning->id), $payoutData)
            ->assertRedirect();
        $this->actingAs($scenario['provider']['user'])
            ->post(route('logistics.settlements.rider-earnings.record-paid', $earning->id), $payoutData)
            ->assertRedirect();

        $earning->refresh();
        $this->assertSame('paid', $earning->status);
        $this->assertNotNull($earning->paid_at);
        $this->assertSame(1, RiderEarningPayout::query()->count());
        $this->assertSame(1, \App\Models\AuditLog::query()->where('action', 'rider_earning.externally_paid')->count());

        $otherProvider = $this->provider('other-provider');
        $this->actingAs($otherProvider['user'])
            ->post(route('logistics.settlements.rider-earnings.record-paid', $earning->id), $payoutData)
            ->assertNotFound();
        $this->assertSame(1, RiderEarningPayout::query()->count());

        $this->actingAs($scenario['finalRiderUser'])
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertSee('Paid')
            ->assertSee('GCASH-RIDER-3000')
            ->assertSee('Rider received shipping pool payout.');

        $this->actingAs($admin)
            ->get(route('admin.shipping-settlements.index'))
            ->assertOk()
            ->assertSee('GCASH-RIDER-3000')
            ->assertSee('Rider paid');
    }

    public function test_new_zone_orders_exclude_shipping_from_seller_commission_and_proceeds_but_legacy_orders_keep_their_basis(): void
    {
        $zone = $this->scenario('commission-zone', ['shipping_fee_minor' => 5000, 'platform_subsidy_minor' => 1000]);
        $legacy = $this->scenario('commission-legacy', [
            'quote_rule' => 'flat_per_seller_order',
            'shipping_fee_minor' => 5000,
            'platform_subsidy_minor' => 0,
        ]);

        app(CommissionLedgerService::class)->finalize($zone['payment']);
        app(CommissionLedgerService::class)->finalize($legacy['payment']);

        $zoneCommission = PlatformCommission::query()->where('seller_order_id', $zone['sellerOrder']->id)->where('type', 'sale')->firstOrFail();
        $legacyCommission = PlatformCommission::query()->where('seller_order_id', $legacy['sellerOrder']->id)->where('type', 'sale')->firstOrFail();
        $zoneTransaction = \App\Models\SellerTransaction::query()->where('seller_order_id', $zone['sellerOrder']->id)->firstOrFail();
        $legacyTransaction = \App\Models\SellerTransaction::query()->where('seller_order_id', $legacy['sellerOrder']->id)->firstOrFail();

        $this->assertSame(20000, (int) $zoneCommission->base_amount_minor);
        $this->assertSame(2000, (int) $zoneCommission->amount_minor);
        $this->assertSame(18000, (int) $zoneTransaction->amount_minor);
        $this->assertSame(25000, (int) $legacyCommission->base_amount_minor);
        $this->assertSame(2500, (int) $legacyCommission->amount_minor);
        $this->assertSame(22500, (int) $legacyTransaction->amount_minor);
        $this->assertSame(24000, (int) $zone['order']->total_minor);
        $this->assertSame(5000, (int) $zone['sellerOrder']->shipping_fee_minor);
    }

    public function test_rider_earnings_page_materializes_only_the_final_riders_eligible_settlement_and_remains_idempotent(): void
    {
        $scenario = $this->scenario('rider-page', ['shipping_fee_minor' => 5000]);
        $this->assertSame(0, ShippingSettlement::query()->count());

        $this->actingAs($scenario['finalRiderUser'])
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertSee($scenario['shipment']->shipment_no)
            ->assertSee('₱30.00')
            ->assertSee('Pending')
            ->assertSeeText('3-calendar-day return/refund period')
            ->assertSeeText('3-day return period ended Oct 15, 2026');

        $settlement = ShippingSettlement::query()->sole();
        $earning = RiderEarning::query()->sole();
        $this->assertSame($scenario['finalRider']->id, (int) $settlement->rider_profile_id);
        $this->assertSame($scenario['provider']['profile']->id, (int) $settlement->logistics_profile_id);
        $this->assertSame($scenario['finalRider']->id, (int) $earning->rider_profile_id);

        $this->actingAs($scenario['finalRiderUser'])
            ->get(route('rider.earnings.index'))
            ->assertOk();

        $this->actingAs($scenario['earlierRiderUser'])
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertDontSee($scenario['shipment']->shipment_no);

        $this->assertSame(1, ShippingSettlement::query()->count());
        $this->assertSame(1, RiderEarning::query()->count());

        $this->actingAs($scenario['finalRiderUser'])
            ->get(route('rider.history.index'))
            ->assertOk()
            ->assertSee($scenario['shipment']->shipment_no)
            ->assertSee('₱30.00')
            ->assertSeeText('Delivery history and earnings are separate records')
            ->assertSeeText('3-calendar-day return/refund period starts at Seller Order completion');

        $this->actingAs($scenario['earlierRiderUser'])
            ->get(route('rider.history.index'))
            ->assertOk()
            ->assertSee($scenario['shipment']->shipment_no)
            ->assertSeeText('No ledger entry');

        $this->actingAs($scenario['provider']['user'])
            ->get(route('logistics.settlements.index'))
            ->assertOk()
            ->assertSee($scenario['shipment']->shipment_no)
            ->assertSee('Rider pool: ₱30.00')
            ->assertSeeText('Awaiting Admin/Finance remittance to your provider.')
            ->assertSeeText('the 3-calendar-day return/refund period');

        $this->actingAs($this->user('admin', 'Shipping Admin'))
            ->get(route('admin.shipping-settlements.index'))
            ->assertOk()
            ->assertSee($scenario['shipment']->shipment_no)
            ->assertSee('Shipping allocations to Logistics')
            ->assertSee(route('admin.shipping-settlements.record-paid', $settlement), false);

        $this->assertSame(1, ShippingSettlement::query()->count());
        $this->assertSame(1, RiderEarning::query()->count());
    }

    public function test_legacy_flat_quote_delivery_is_excluded_and_pages_show_accurate_empty_states(): void
    {
        $scenario = $this->scenario('legacy-page', [
            'quote_rule' => 'flat_per_seller_order',
            'shipping_fee_minor' => 5000,
        ]);

        app(ShippingSettlementService::class)->materializeEligibleSettlements();

        $this->assertSame(0, ShippingSettlement::query()->count());
        $this->assertSame(0, RiderEarning::query()->whereNotNull('shipping_settlement_id')->count());

        $this->actingAs($scenario['finalRiderUser'])
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertSeeText('No earning ledger entries are currently recorded for your Rider profile.')
            ->assertSeeText('Delivery History is separate and does not create an earning by itself.')
            ->assertSeeText('After the 3-calendar-day return/refund period ends');

        $this->actingAs($scenario['finalRiderUser'])
            ->get(route('rider.history.index'))
            ->assertOk()
            ->assertSeeText('Delivery history and earnings are separate records')
            ->assertSeeText('No ledger entry');

        $this->actingAs($this->user('admin', 'Empty Shipping Finance'))
            ->get(route('admin.shipping-settlements.index'))
            ->assertOk()
            ->assertSeeText('No eligible shipping allocations are recorded yet.')
            ->assertSeeText('legacy flat-fee or other non-zone quotes do not create a row here.');

        $this->actingAs($scenario['provider']['user'])
            ->get(route('logistics.settlements.index'))
            ->assertOk()
            ->assertSeeText('After the 3-calendar-day return/refund period ends');

        $this->actingAs($scenario['provider']['user'])
            ->get(route('logistics.settlements.index'))
            ->assertOk()
            ->assertSeeText('No shipping Rider earnings are currently recorded for this provider.')
            ->assertSeeText('Delivery history alone does not create an earning.');

        $this->assertSame(0, ShippingSettlement::query()->count());
        $this->assertSame(0, RiderEarning::query()->whereNotNull('shipping_settlement_id')->count());
    }

    public function test_admin_shipping_settlements_page_is_separate_from_seller_payments_and_role_scoped(): void
    {
        $scenario = $this->scenario('admin-separation');
        app(ShippingSettlementService::class)->materializeEligibleSettlements();
        $settlement = ShippingSettlement::query()->sole();
        $admin = $this->user('admin', 'Shipping Separation Admin');

        $this->actingAs($admin)
            ->get(route('admin.shipping-settlements.index'))
            ->assertOk()
            ->assertSee('Shipping allocations to Logistics')
            ->assertSee($scenario['shipment']->shipment_no)
            ->assertSee(route('admin.shipping-settlements.record-paid', $settlement), false)
            ->assertSee('Shipping Settlements');

        $this->actingAs($admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertDontSee('Shipping allocations to Logistics')
            ->assertDontSee(route('admin.shipping-settlements.record-paid', $settlement), false)
            ->assertSee('finance-cod-custody-table', false)
            ->assertSee(route('admin.shipping-settlements.index'), false);

        foreach ([$scenario['buyer'], $scenario['provider']['user'], $scenario['finalRiderUser']] as $user) {
            $this->actingAs($user)
                ->get(route('admin.shipping-settlements.index'))
                ->assertForbidden();
        }
    }

    /** @return array<string, mixed> */
    private function scenario(string $suffix, array $overrides = []): array
    {
        $buyer = $this->user('buyer', 'Buyer '.$suffix);
        $seller = $this->user('seller', 'Seller '.$suffix);
        $provider = $this->provider($suffix);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Seller '.$suffix,
            'commission_rate_bps' => 1000,
            'standing_status' => 'good_standing',
            'approved_at' => now()->subDays(30),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => 'Store '.$suffix,
            'slug' => Str::slug('store '.$suffix.' '.$sellerProfile->id),
            'publication_status' => 'published',
            'published_at' => now()->subDays(30),
        ]);
        $completedAt = now()->subDays(10);
        $shippingFeeMinor = (int) ($overrides['shipping_fee_minor'] ?? 5000);
        $zoneLevel = (int) ($overrides['zone_level'] ?? 0);
        $baseFeeMinor = (int) ($overrides['base_fee_minor'] ?? ($zoneLevel === 0 ? $shippingFeeMinor : 5000));
        $zoneSurchargeMinor = (int) ($overrides['zone_surcharge_minor'] ?? ($shippingFeeMinor - $baseFeeMinor));
        $subsidyMinor = (int) ($overrides['platform_subsidy_minor'] ?? 0);
        $buyerPaidMinor = $shippingFeeMinor - $subsidyMinor;
        $subtotalMinor = 20000;
        $buyerTotalMinor = $subtotalMinor + $shippingFeeMinor - $subsidyMinor;
        $paymentMethod = (string) ($overrides['payment_method'] ?? 'online');
        $paymentStatus = (string) ($overrides['payment_status'] ?? 'paid');
        $sellerOrderStatus = (string) ($overrides['seller_order_status'] ?? 'completed');
        $shipmentStatus = (string) ($overrides['shipment_status'] ?? 'delivered');
        $quoteRule = (string) ($overrides['quote_rule'] ?? 'zone_based_v1');
        $order = Order::query()->create([
            'order_no' => 'ORD-ZONE-'.Str::upper((string) Str::ulid()),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '25 Buyer Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'currency' => 'PHP',
            'subtotal_minor' => $subtotalMinor,
            'shipping_fee_minor' => $shippingFeeMinor,
            'buyer_paid_shipping_fee_minor' => $buyerPaidMinor,
            'shipping_funding_source' => $subsidyMinor > 0 ? 'buyer_and_platform' : 'buyer',
            'platform_shipping_discount_minor' => $subsidyMinor,
            'discount_minor' => 0,
            'total_minor' => $buyerTotalMinor,
            'status' => $paymentStatus === 'paid' ? 'fulfilled' : 'processing',
            'payment_status' => $paymentStatus,
            'placed_at' => now()->subDays(12),
            'completed_at' => $completedAt,
        ]);
        $shippingQuote = [
            'pricing_rule' => $quoteRule,
            'zone_level' => $zoneLevel,
            'base_fee_minor' => $baseFeeMinor,
            'zone_surcharge_per_level_minor' => 500,
            'zone_surcharge_minor' => $zoneSurchargeMinor,
            'original_shipping_fee_minor' => $shippingFeeMinor,
            'shipping_fee_minor' => $shippingFeeMinor,
            'shipping_fee_breakdown' => [
                'original_fee_minor' => $shippingFeeMinor,
                'buyer_paid_minor' => $buyerPaidMinor,
                'platform_subsidy_minor' => $subsidyMinor,
                'funding_source' => $subsidyMinor > 0 ? 'buyer_and_platform' : 'buyer',
            ],
        ];
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-ZONE-'.Str::upper((string) Str::ulid()),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $sellerOrderStatus,
            'subtotal_minor' => $subtotalMinor,
            'discount_minor' => 0,
            'shipping_fee_minor' => $shippingFeeMinor,
            'buyer_paid_shipping_fee_minor' => $buyerPaidMinor,
            'shipping_funding_source' => $subsidyMinor > 0 ? 'buyer_and_platform' : 'buyer',
            'platform_shipping_discount_minor' => $subsidyMinor,
            'total_minor' => $subtotalMinor + $shippingFeeMinor,
            'completed_at' => $sellerOrderStatus === 'completed' ? $completedAt : null,
            'shipping_quote' => $shippingQuote,
        ]);
        $payment = Payment::query()->create([
            'payment_no' => 'PAY-ZONE-'.Str::upper((string) Str::ulid()),
            'order_id' => $order->id,
            'method' => $paymentMethod,
            'amount_minor' => $buyerTotalMinor,
            'status' => $paymentStatus,
            'initiated_at' => now()->subDays(12),
            'paid_at' => $paymentStatus === 'paid' ? now()->subDays(11) : null,
        ]);
        $expectedCod = $paymentMethod === 'cod' ? $buyerTotalMinor : 0;
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-ZONE-'.Str::upper((string) Str::ulid()),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'origin_address_id' => null,
            'destination_address_id' => null,
            'purpose' => 'outbound',
            'status' => $shipmentStatus,
            'shipping_fee_minor' => $shippingFeeMinor,
            'cod_amount_minor' => $expectedCod,
            'delivered_at' => $shipmentStatus === 'delivered' ? now() : null,
        ]);
        $sortingAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => 'Sorting Center',
            'recipient_name' => $provider['user']->name,
            'phone' => '+639171234568',
            'street' => '1 Logistics Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $sortingAddress->id,
            'name' => 'Center '.$suffix,
            'code' => 'CENTER-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'code' => 'ZONE-'.Str::upper(Str::random(8)),
            'name' => 'Zone '.$suffix,
            'status' => 'active',
        ]);
        $earlierRiderUser = $this->user('rider', 'Earlier Rider '.$suffix);
        $earlierRider = $this->riderProfile($earlierRiderUser, $provider['profile'], $center, $zone, $suffix.'-EARLIER');
        $finalRiderUser = $this->user('rider', 'Final Rider '.$suffix);
        $finalRider = $this->riderProfile($finalRiderUser, $provider['profile'], $center, $zone, $suffix.'-FINAL');
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'WB-ZONE-'.Str::upper((string) Str::ulid()),
            'scan_token' => Str::random(64),
            'barcode_value' => 'BAR-ZONE-'.Str::upper((string) Str::ulid()),
            'piece_count' => 2,
            'total_weight_kg' => 1,
            'generated_at' => now()->subDays(12),
        ]);
        $earlierParcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'PCL-ZONE-'.Str::upper((string) Str::ulid()),
            'piece_sequence' => 1,
            'weight_kg' => 0.5,
            'status' => 'delivered',
            'current_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
        ]);
        $finalParcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'PCL-ZONE-'.Str::upper((string) Str::ulid()),
            'piece_sequence' => 2,
            'weight_kg' => 0.5,
            'status' => 'delivered',
            'current_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
        ]);
        $earlierAttempt = DeliveryAttempt::query()->create([
            'parcel_id' => $earlierParcel->id,
            'rider_profile_id' => $earlierRider->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => now()->subMinute(),
        ]);
        $finalAttempt = DeliveryAttempt::query()->create([
            'parcel_id' => $finalParcel->id,
            'rider_profile_id' => $finalRider->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => $shipment->delivered_at ?? now(),
        ]);
        if ($shipmentStatus === 'delivered') {
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'event_type' => 'shipment_delivered',
                'from_status' => 'out_for_delivery',
                'to_status' => 'delivered',
                'actor_user_id' => $finalRiderUser->id,
                'source' => 'rider',
                'metadata' => ['rider_profile_id' => $finalRider->id, 'dispatch_batch_ids' => []],
                'occurred_at' => $shipment->delivered_at,
            ]);
        }

        return compact(
            'buyer',
            'seller',
            'sellerProfile',
            'store',
            'order',
            'sellerOrder',
            'payment',
            'shipment',
            'provider',
            'earlierRider',
            'earlierRiderUser',
            'finalRider',
            'finalRiderUser',
            'earlierAttempt',
            'finalAttempt',
        );
    }

    private function provider(string $suffix): array
    {
        $user = $this->user('logistics', 'Logistics '.$suffix);
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Logistics '.$suffix,
            'display_name' => 'Provider '.$suffix,
            'contact_phone' => '+639171234568',
            'status' => 'active',
        ]);

        return ['user' => $user, 'profile' => $profile];
    }

    private function riderProfile(
        User $user,
        LogisticsProfile $provider,
        SortingCenter $center,
        SortingZone $zone,
        string $suffix,
    ): RiderProfile {
        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider->id,
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => Str::upper(Str::limit($suffix, 20, '')),
            'parcel_capacity' => 20,
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
    }

    private function user(string $role, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'-'.(++$this->sequence).'@example.test',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
