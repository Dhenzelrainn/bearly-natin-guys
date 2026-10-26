<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CodCashCollection;
use App\Models\CodCashCollectionEvent;
use App\Models\DeliveryAttempt;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformCommission;
use App\Models\Refund;
use App\Models\RefundCashPayout;
use App\Models\RefundCashPayoutEvent;
use App\Models\ReturnRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerPayoutItem;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\SellerWalletTransaction;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\CodCashCollectionService;
use App\Services\CommissionLedgerService;
use App\Services\RefundCashPayoutService;
use App\Services\RiderDeliveryService;
use App\Services\SellerPayoutService;
use App\Services\SellerWalletService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class CodCashAndRefundCashWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    /**
     * Refuse to run these financial workflow tests unless the process and
     * effective connection/cache are isolated from shared application data.
     */
    public function createApplication()
    {
        parent::prepareIsolatedTestEnvironment();

        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
        ] as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException("COD audit tests require {$key}={$expected}; refusing to boot the test application.");
            }
        }

        $configCachePath = getenv('APP_CONFIG_CACHE');
        $temporaryPrefix = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, sys_get_temp_dir()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedConfigCachePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $configCachePath);
        if ($configCachePath === false
            || $configCachePath === ''
            || file_exists($configCachePath)
            || ! str_starts_with(strtolower($normalizedConfigCachePath), strtolower($temporaryPrefix))) {
            throw new RuntimeException('COD audit tests require a non-shared temporary APP_CONFIG_CACHE path; refusing to boot the test application.');
        }

        $app = parent::createApplication();
        $config = $app['config'];
        $sqlite = $config->get('database.connections.sqlite', []);

        if ($config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || ($sqlite['database'] ?? null) !== ':memory:'
            || filled($sqlite['url'] ?? null)
            || $config->get('cache.default') !== 'array') {
            throw new RuntimeException('COD audit tests require effective SQLite :memory: and array-cache configuration; refusing to run migrations.');
        }

        $connection = $app['db']->connection();
        if ($connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:'
            || $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('COD audit tests could not verify an in-memory PDO SQLite connection; refusing to run migrations.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'Finance Admin');
    }

    public function test_cod_collection_reconciliation_does_not_bypass_wallet_completion_and_return_eligibility(): void
    {
        $scenario = $this->scenario('settlement');
        $scenario['seller_order']->items()->create([
            'product_name' => 'COD Finance Product',
            'variant_name' => 'Natural / One Size',
            'sku' => 'COD-FINANCE-001',
            'unit_price_minor' => 18000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 18000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/cod-finance.png',
        ]);
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
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('Count the cash. Enter the amount you received.')
            ->assertSee('Bearly creates this number after you confirm the Rider');

        $acknowledgment = $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), [
                'actual_amount' => '200.00',
            ])
            ->assertRedirect(route('logistics.cash.index', ['cod_remittance' => $collection->id]));
        $collection->refresh();
        $this->assertSame('received_by_logistics', $collection->status);
        $handoffReference = $collection->logistics_reference;
        $this->assertSame('CODH-'.Str::upper(Str::replaceFirst('COD-', '', $collection->collection_no)), $handoffReference);
        $this->assertMatchesRegularExpression('/^CODH-[A-Z0-9-]+$/', $handoffReference);
        $acknowledgment->assertSessionHas('success', fn (string $message): bool => str_contains($message, $handoffReference));
        $this->assertDatabaseHas('cod_cash_collection_events', [
            'cod_cash_collection_id' => $collection->id,
            'event_type' => 'cash_received_by_logistics',
            'reference' => $handoffReference,
        ]);
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('cash-remittance-panel', false)
            ->assertSee('Finance handoff')
            ->assertSee('Record remittance')
            ->assertSee('Selected')
            ->assertDontSee('Record Finance remittance')
            ->assertSee('Expected amount')
            ->assertSee('Collected amount')
            ->assertSee('Rider-to-Logistics reference:')
            ->assertSee($handoffReference)
            ->assertSee('Bearly creates a unique L2F (Logistics-to-Finance) reference.')
            ->assertDontSee('Finance handoff reference')
            ->assertSee('Give the cash to Finance.')
            ->assertSee(route('logistics.cash.cod.remit', $collection), false);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => '200.00'])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, $handoffReference));
        $this->assertSame($handoffReference, $collection->fresh()->logistics_reference);
        $this->assertSame(1, $collection->events()->where('event_type', 'cash_received_by_logistics')->count());

        $remittance = $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), [
                'actual_amount' => '200.00',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $collection->refresh();
        $this->assertSame('remitted_to_finance', $collection->status);
        $remittanceReference = $collection->finance_remittance_reference;
        $this->assertSame('L2F-'.Str::upper(Str::replaceFirst('COD-', '', $collection->collection_no)), $remittanceReference);
        $remittance->assertSessionHas('success', fn (string $message): bool => str_contains($message, $remittanceReference));
        $this->assertDatabaseHas('cod_cash_collection_events', [
            'cod_cash_collection_id' => $collection->id,
            'event_type' => 'cash_remitted_to_finance',
            'reference' => $remittanceReference,
        ]);
        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('finance-cod-custody-table', false)
            ->assertSee('finance-cod-products-cell', false)
            ->assertSee('COD Finance Product')
            ->assertSee('seller-products/cod-finance.png', false)
            ->assertSee('finance-cod-custody-reference', false)
            ->assertSee('Logistics-to-Finance:')
            ->assertSee($remittanceReference);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), ['actual_amount' => '200.00'])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, $remittanceReference));
        $this->assertSame($remittanceReference, $collection->fresh()->finance_remittance_reference);
        $this->assertSame(1, $collection->events()->where('event_type', 'cash_remitted_to_finance')->count());

        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '200.00',
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect();
        $collection->refresh();
        $this->assertSame('reconciled', $collection->status);
        $this->assertSame($remittanceReference, $collection->finance_remittance_reference);
        $financeReceiptReference = $collection->finance_reference;
        $this->assertMatchesRegularExpression('/^BFR-\d{4}-\d{8,}$/', $financeReceiptReference);
        $this->assertNotSame($collection->finance_remittance_reference, $collection->finance_reference);
        $this->assertDatabaseHas('cod_cash_collection_events', [
            'cod_cash_collection_id' => $collection->id,
            'event_type' => 'cash_reconciled_by_finance',
            'reference' => $financeReceiptReference,
        ]);
        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '200.00',
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, $financeReceiptReference));
        $this->assertSame($financeReceiptReference, $collection->fresh()->finance_reference);
        $this->assertSame(1, $collection->events()->where('event_type', 'cash_reconciled_by_finance')->count());
        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('Bearly Finance receipt:')
            ->assertSee($financeReceiptReference)
            ->assertSee('Print Bearly receipt')
            ->assertSee(route('admin.payments.cod.receipt', $collection), false);
        $this->actingAs($this->admin)
            ->get(route('admin.payments.cod.receipt', $collection))
            ->assertOk()
            ->assertSee('COD Cash Receipt')
            ->assertSee($financeReceiptReference)
            ->assertSee($handoffReference)
            ->assertSee($remittanceReference)
            ->assertSee('not a BIR/tax invoice or a bank deposit receipt');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cod_cash.finance_receipt_viewed',
            'actor_user_id' => $this->admin->id,
            'auditable_id' => $collection->id,
        ]);
        $this->assertCount(0, app(SellerPayoutService::class)->eligibleBySeller());
        $wallet = $scenario['seller_profile']->wallet()->firstOrFail();
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame(18000, (int) $wallet->pending_balance_minor);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.seller-settlements.store'), [
                'seller_profile_id' => $scenario['seller_profile']->id,
                'reason' => 'COD receipts reconciled through Finance.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('seller');

        $this->assertDatabaseCount('seller_payouts', 0);
        $this->assertDatabaseCount('seller_payout_items', 0);
    }

    public function test_legacy_direct_cash_seller_payouts_are_disabled_in_favor_of_wallet_withdrawals(): void
    {
        $scenario = $this->scenario('seller-cash-payout-disabled');
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $this->rider($scenario['provider'], 'legacy cash payout rider'),
            20000,
        );

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => '200.00'])
            ->assertRedirect();
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), ['actual_amount' => '200.00'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '200.00',
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('seller_payouts', 0);
        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame(18000, (int) $wallet->pending_balance_minor);

        $legacyPayout = SellerPayout::query()->create([
            'payout_no' => 'SPY-LEGACY-CASH-001',
            'seller_profile_id' => $scenario['seller_profile']->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_minor' => 20000,
            'commission_minor' => 2000,
            'adjustment_minor' => 0,
            'net_minor' => 18000,
            'status' => 'pending',
            'due_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.payments.seller-settlements.cash-acknowledgment', $legacyPayout))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('admin.payments.seller-settlements.record-paid', $legacyPayout), [
                'seller_payout_confirmed' => '1',
                'actual_amount' => '180.00',
                'method' => 'cash',
                'paid_on' => now()->toDateString(),
                'reason' => 'Cash handed directly to the Seller.',
                'receipt' => UploadedFile::fake()->createWithContent(
                    'signed-seller-acknowledgment.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
                ),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('method');

        $this->assertSame('pending', $legacyPayout->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'seller_payout.cash_paid',
            'auditable_id' => $legacyPayout->id,
        ]);
    }

    public function test_finance_remittance_amount_displays_and_accepts_thousands_separators(): void
    {
        $scenario = $this->scenario('formattedremittance');
        $scenario['shipment']->update(['cod_amount_minor' => 5_005_000]);
        $rider = $this->rider($scenario['provider'], 'formatted remittance rider');
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $rider,
            5_005_000,
        );

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => '50050.00'])
            ->assertRedirect();

        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index', ['cod_remittance' => $collection->id]))
            ->assertOk()
            ->assertSee('value="50,050.00"', false);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), [
                'actual_amount' => '50,050.00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cod_cash_collections', [
            'id' => $collection->id,
            'amount_remitted_to_finance_minor' => 5_005_000,
            'status' => 'remitted_to_finance',
        ]);
        $this->assertMatchesRegularExpression('/^L2F-[A-Z0-9-]+$/', $collection->fresh()->finance_remittance_reference);

        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('value="50,050.00"', false)
            ->assertSee('data-finance-cash-amount', false)
            ->assertSee('Bearly creates an internal Finance receipt number after confirmation.');
        $this->actingAs($this->admin)
            ->get(route('admin.payments.cod.receipt', $collection))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '50,050.00',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('cash_received_confirmed');
        $this->assertSame('remitted_to_finance', $collection->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '50,050.00',
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $financeReceiptReference = $collection->fresh()->finance_reference;
        $this->assertMatchesRegularExpression('/^BFR-\d{4}-\d{8,}$/', $financeReceiptReference);
        $this->assertDatabaseHas('cod_cash_collections', [
            'id' => $collection->id,
            'amount_reconciled_minor' => 5_005_000,
            'finance_reference' => $financeReceiptReference,
            'status' => 'reconciled',
        ]);
        $this->actingAs($this->admin)
            ->get(route('admin.payments.cod.receipt', $collection))
            ->assertOk()
            ->assertSee('₱50,050.00')
            ->assertSee($financeReceiptReference);
    }

    public function test_legacy_seller_payout_saves_receiving_bank_name_and_audits_it(): void
    {
        Storage::fake('local');
        $scenario = $this->scenario('receiving-bank');
        $payout = SellerPayout::query()->create([
            'payout_no' => 'SPY-RECEIVING-BANK-001',
            'seller_profile_id' => $scenario['seller_profile']->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_minor' => 20000,
            'commission_minor' => 2000,
            'adjustment_minor' => 0,
            'net_minor' => 18000,
            'status' => 'pending',
            'due_at' => now(),
        ]);
        $transaction = SellerTransaction::query()->create([
            'transaction_no' => 'STX-RECEIVING-BANK-001',
            'seller_profile_id' => $scenario['seller_profile']->id,
            'type' => 'sale',
            'amount_minor' => 18000,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        SellerPayoutItem::query()->create([
            'seller_payout_id' => $payout->id,
            'seller_transaction_id' => $transaction->id,
            'gross_minor' => 20000,
            'commission_minor' => 2000,
            'net_minor' => 18000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('Receiving bank name')
            ->assertSee('name="receiving_bank_name"', false)
            ->assertSee('data-finance-payout-details', false)
            ->assertSee('data-finance-payout-close', false);

        $basePayload = [
            'seller_payout_confirmed' => '1',
            'actual_amount' => '180.00',
            'method' => 'bank_transfer',
            'reference' => 'BANK-TRANSFER-RECEIVING-001',
            'paid_on' => now()->toDateString(),
            'reason' => 'Verified against the completed bank transfer.',
            'receipt' => UploadedFile::fake()->createWithContent(
                'seller-transfer-proof.pdf',
                "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
            ),
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.payments.seller-settlements.record-paid', $payout), $basePayload)
            ->assertRedirect()
            ->assertSessionHasErrors('receiving_bank_name');
        $this->assertSame('pending', $payout->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.seller-settlements.record-paid', $payout), $basePayload + [
                'receiving_bank_name' => 'MariBank Philippines, Inc.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('seller_payouts', [
            'id' => $payout->id,
            'status' => 'paid',
            'receiving_bank_name' => 'MariBank Philippines, Inc.',
        ]);
        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('Receiving bank: MariBank Philippines, Inc.');

        $audit = AuditLog::query()
            ->where('action', 'seller_payout.externally_paid')
            ->where('auditable_id', $payout->id)
            ->firstOrFail();
        $this->assertSame('MariBank Philippines, Inc.', $audit->new_values['receiving_bank_name']);
        $this->assertArrayNotHasKey('account_number', $audit->new_values);
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

    public function test_duplicate_outbound_shipments_block_new_cod_collection_before_financial_writes(): void
    {
        $scenario = $this->scenario('duplicate-outbound-cod');
        $duplicateShipment = $this->duplicateOutboundCodShipment($scenario);
        $rider = $this->rider($scenario['provider'], 'duplicate outbound COD rider');
        $cash = app(CodCashCollectionService::class);

        $this->assertOutboundShipmentConflict(fn () => $cash->recordRiderCollection($scenario['shipment'], $rider, 20000));
        $this->assertOutboundShipmentConflict(fn () => $cash->recordRiderCollection($duplicateShipment, $rider, 20000));

        $this->assertSame(40000, (int) Shipment::query()
            ->where('seller_order_id', $scenario['seller_order']->id)
            ->where('purpose', 'outbound')
            ->sum('cod_amount_minor'), 'The deliberately duplicated fixture still records the original PHP 400.00 aggregate due.');
        $this->assertSame(0, CodCashCollection::query()->where('seller_order_id', $scenario['seller_order']->id)->count());
        $this->assertSame(0, CodCashCollectionEvent::query()->count());
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);
        $this->assertFalse($cash->hasPaymentCaptured($scenario['order']->fresh()));
        $this->assertDatabaseCount('platform_commissions', 0);
        $this->assertDatabaseCount('seller_transactions', 0);
    }

    public function test_duplicate_outbound_shipment_freezes_existing_cod_custody_without_rewriting_it(): void
    {
        $scenario = $this->scenario('duplicate-cod-custody');
        $rider = $this->rider($scenario['provider'], 'duplicate custody rider');
        $cash = app(CodCashCollectionService::class);
        $collection = $cash->recordRiderCollection($scenario['shipment'], $rider, 20000);
        $this->assertSame('paid', $scenario['payment']->fresh()->status);

        $this->duplicateOutboundCodShipment($scenario);

        $this->assertOutboundShipmentConflict(fn () => $cash->recordRiderCollection($scenario['shipment'], $rider, 20000));
        $this->assertOutboundShipmentConflict(fn () => $cash->receiveAtLogistics($scenario['provider_user'], $collection->id, 20000));
        $this->assertOutboundShipmentConflict(fn () => $cash->remitToFinance($scenario['provider_user'], $collection->id, 20000));
        $this->assertOutboundShipmentConflict(fn () => $cash->reconcileAtFinance($this->admin, $collection->id, 20000));

        $collection->refresh();
        $this->assertSame('collected', $collection->status);
        $this->assertSame(20000, (int) $collection->amount_collected_minor);
        $this->assertNull($collection->amount_received_by_logistics_minor);
        $this->assertNull($collection->amount_remitted_to_finance_minor);
        $this->assertNull($collection->amount_reconciled_minor);
        $this->assertSame(1, CodCashCollection::query()->where('seller_order_id', $scenario['seller_order']->id)->count());
        $this->assertSame(1, $collection->events()->count());
        $this->assertSame('paid', $scenario['payment']->fresh()->status, 'A pre-existing captured Payment is preserved; this guard does not compensate historical records.');
        $this->assertFalse($cash->hasPaymentCaptured($scenario['order']->fresh()));
    }

    public function test_rider_delivery_completion_rejects_duplicate_outbound_cod_before_delivery_writes(): void
    {
        $scenario = $this->scenario('duplicate-outbound-delivery');
        $this->duplicateOutboundCodShipment($scenario);
        $rider = $this->rider($scenario['provider'], 'duplicate outbound delivery rider');
        Storage::fake('local');

        $this->assertOutboundShipmentConflict(fn () => app(RiderDeliveryService::class)->completeDelivery(
            shipmentNo: $scenario['shipment']->shipment_no,
            actor: $rider,
            proofPhoto: UploadedFile::fake()->createWithContent('duplicate-cod-proof.jpg', 'test proof'),
            recipientName: 'Buyer Recipient',
            codCollectedAmountMinor: 20000,
            codCash: app(CodCashCollectionService::class),
        ));

        $this->assertSame('delivered', $scenario['shipment']->fresh()->status);
        $this->assertSame(0, DeliveryAttempt::query()
            ->whereHas('parcel', fn ($query) => $query->where('shipment_id', $scenario['shipment']->id))
            ->count());
        $this->assertSame(0, CodCashCollection::query()->where('seller_order_id', $scenario['seller_order']->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('delivery-proofs'));
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);
    }

    public function test_return_shipment_does_not_block_cod_collection_for_one_outbound_shipment(): void
    {
        $scenario = $this->scenario('return-plus-outbound-cod');
        Shipment::query()->create([
            'shipment_no' => 'SHP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $scenario['seller_order']->id,
            'logistics_profile_id' => $scenario['provider']->id,
            'purpose' => 'return',
            'status' => 'returned',
            'cod_amount_minor' => 0,
            'shipping_fee_minor' => 0,
        ]);

        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $this->rider($scenario['provider'], 'return excluded COD rider'),
            20000,
        );

        $this->assertSame('collected', $collection->status);
        $this->assertSame(20000, (int) $collection->amount_due_minor);
        $this->assertSame('paid', $scenario['payment']->fresh()->status);
        $this->assertSame(1, CodCashCollection::query()->where('seller_order_id', $scenario['seller_order']->id)->count());
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

    public function test_short_collection_remains_unresolved_through_cash_custody_and_blocks_payment_receipt_and_seller_funds(): void
    {
        $scenario = $this->scenario('audit-short-collection');
        $rider = $this->rider($scenario['provider'], 'audit short collection');
        $cash = app(CodCashCollectionService::class);
        $collection = $cash->recordRiderCollection($scenario['shipment'], $rider, 17500);

        $this->assertSame(20000, (int) $collection->amount_due_minor);
        $this->assertSame(17500, (int) $collection->amount_collected_minor);
        $this->assertSame(-2500, (int) $collection->discrepancy_minor);
        $this->assertSame('disputed', $collection->status);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);
        $this->assertFalse($cash->hasPaymentCaptured($scenario['order']->fresh()));

        $this->actingAs($scenario['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertConflict()
            ->assertJsonPath('error.code', 'cod_cash_collection_incomplete');
        $this->assertSame('processing', $scenario['order']->fresh()->status);

        $outsider = $this->provider('audit-short-outsider');
        $this->actingAs($outsider['user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => '175.00'])
            ->assertForbidden();
        $this->assertSame('disputed', $collection->fresh()->status);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => '175.01'])
            ->assertSessionHasErrors('received_amount');
        $this->assertSame('disputed', $collection->fresh()->status);
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => '175.00'])
            ->assertRedirect();
        $this->assertSame('received_by_logistics', $collection->fresh()->status);
        $this->assertSame(-2500, (int) $collection->fresh()->discrepancy_minor);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), ['actual_amount' => '174.99'])
            ->assertSessionHasErrors('amount');
        $this->assertSame('received_by_logistics', $collection->fresh()->status);
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), ['actual_amount' => '175.00'])
            ->assertRedirect();
        $this->assertSame('remitted_to_finance', $collection->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), ['received_amount' => '175.00'])
            ->assertSessionHasErrors('cash_received_confirmed');
        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '175.01',
                'cash_received_confirmed' => '1',
            ])
            ->assertSessionHasErrors('received_amount');
        $this->assertSame('remitted_to_finance', $collection->fresh()->status);
        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '175.00',
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect();

        $collection = $collection->fresh();
        $events = $collection->events;
        $this->assertSame('reconciled', $collection->status);
        $this->assertSame(17500, (int) $collection->amount_received_by_logistics_minor);
        $this->assertSame(17500, (int) $collection->amount_remitted_to_finance_minor);
        $this->assertSame(17500, (int) $collection->amount_reconciled_minor);
        $this->assertSame(-2500, (int) $collection->discrepancy_minor);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);
        $this->assertFalse($cash->hasPaymentCaptured($scenario['order']->fresh()));
        $this->assertSame([
            'cash_collection_discrepancy_recorded',
            'disputed_cash_received_by_logistics',
            'cash_remitted_to_finance',
            'cash_reconciled_by_finance',
        ], $events->pluck('event_type')->all());
        $this->assertSame(
            [$rider->id, $scenario['provider_user']->id, $scenario['provider_user']->id, $this->admin->id],
            $events->pluck('actor_user_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $this->assertSame([17500, 17500, 17500, 17500], $events->pluck('amount_minor')->map(fn ($amount): int => (int) $amount)->all());
        $this->assertSame(
            'Cash handoff acknowledges actual cash; the amount due discrepancy remains unresolved.',
            $events[1]->note,
        );

        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertDatabaseCount('platform_commissions', 0);
        $this->assertDatabaseCount('seller_transactions', 0);
        $this->assertSame(0, $collection->events()->where('event_type', 'cash_discrepancy_resolved')->count());
    }

    public function test_overcollection_can_be_counted_at_each_cash_stage_but_does_not_capture_or_refund_the_excess(): void
    {
        $scenario = $this->scenario('audit-overcollection');
        $cash = app(CodCashCollectionService::class);
        $collection = $cash->recordRiderCollection(
            $scenario['shipment'],
            $this->rider($scenario['provider'], 'audit overcollection'),
            20500,
        );

        $this->assertSame(20000, (int) $collection->amount_due_minor);
        $this->assertSame(20500, (int) $collection->amount_collected_minor);
        $this->assertSame(500, (int) $collection->discrepancy_minor);
        $this->assertSame('disputed', $collection->status);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);

        $refund = $this->refund($scenario, 500);
        $this->assertCount(0, app(RefundCashPayoutService::class)->refundsReadyForCashDispatch());
        $this->actingAs($this->admin)
            ->post(route('admin.payments.refunds.issue-cash', $refund), ['cash_issued_confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHasErrors('refund');
        $this->assertDatabaseMissing('refund_cash_payouts', ['refund_id' => $refund->id]);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => '205.00'])
            ->assertRedirect();
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), ['actual_amount' => '205.00'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '205.00',
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect();

        $collection = $collection->fresh();
        $this->assertSame('reconciled', $collection->status);
        $this->assertSame(20500, (int) $collection->amount_received_by_logistics_minor);
        $this->assertSame(20500, (int) $collection->amount_remitted_to_finance_minor);
        $this->assertSame(20500, (int) $collection->amount_reconciled_minor);
        $this->assertSame(500, (int) $collection->discrepancy_minor);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);
        $this->assertFalse($cash->hasPaymentCaptured($scenario['order']->fresh()));
        $this->assertSame(4, $collection->events()->count());
        $this->assertSame(0, $collection->events()->where('event_type', 'cash_discrepancy_resolved')->count());
        $this->assertDatabaseCount('platform_commissions', 0);
        $this->assertDatabaseCount('seller_transactions', 0);

        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
    }

    public function test_multi_seller_cod_stays_unpaid_when_one_shipment_is_exact_and_another_is_disputed(): void
    {
        $scenario = $this->scenario('audit-multi-seller');
        $scenario['seller_order']->update([
            'subtotal_minor' => 9000,
            'shipping_fee_minor' => 1000,
            'total_minor' => 10000,
        ]);
        $scenario['shipment']->update(['cod_amount_minor' => 10000]);

        $secondSeller = $this->user(UserRole::Seller, 'Second Seller audit multi seller');
        $secondProfile = SellerProfile::query()->create([
            'user_id' => $secondSeller->id,
            'legal_business_name' => 'Second Seller audit multi seller',
            'commission_rate_bps' => 1000,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $secondStore = Store::query()->create([
            'seller_profile_id' => $secondProfile->id,
            'name' => 'Second Store audit multi seller',
            'slug' => 'second-store-audit-'.$secondProfile->id,
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
        $rider = $this->rider($scenario['provider'], 'audit multi seller');
        $cash = app(CodCashCollectionService::class);

        $exact = $cash->recordRiderCollection($scenario['shipment'], $rider, 10000);
        $this->assertSame('collected', $exact->status);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);

        $disputed = $cash->recordRiderCollection($secondShipment, $rider, 9000);
        $this->assertSame(10000, (int) $disputed->amount_due_minor);
        $this->assertSame(9000, (int) $disputed->amount_collected_minor);
        $this->assertSame(-1000, (int) $disputed->discrepancy_minor);
        $this->assertSame('disputed', $disputed->status);
        $this->assertSame(2, CodCashCollection::query()->where('order_id', $scenario['order']->id)->count());
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);
        $this->assertFalse($cash->hasPaymentCaptured($scenario['order']->fresh()));
        $this->assertDatabaseCount('platform_commissions', 0);
        $this->assertDatabaseCount('seller_transactions', 0);

        $this->actingAs($scenario['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertConflict()
            ->assertJsonPath('error.code', 'cod_cash_collection_incomplete');
    }

    public function test_duplicate_exact_rider_collection_is_same_actor_idempotent_and_conflicts_do_not_duplicate_ledger_effects(): void
    {
        $scenario = $this->scenario('audit-idempotent-collection');
        $rider = $this->rider($scenario['provider'], 'audit idempotent collection');
        $cash = app(CodCashCollectionService::class);
        $first = $cash->recordRiderCollection($scenario['shipment'], $rider, 20000);
        $replay = $cash->recordRiderCollection($scenario['shipment'], $rider, 20000);

        $this->assertSame($first->id, $replay->id);
        $this->assertCodConflict(fn () => $cash->recordRiderCollection($scenario['shipment'], $rider, 19999));
        $otherRider = $this->rider($scenario['provider'], 'audit alternate rider');
        $this->assertCodConflict(fn () => $cash->recordRiderCollection($scenario['shipment'], $otherRider, 20000));
        $this->assertDatabaseCount('cod_cash_collections', 1);
        $this->assertSame(1, $first->events()->count());
        $this->assertSame('paid', $scenario['payment']->fresh()->status);

        $ledger = app(CommissionLedgerService::class);
        $ledger->finalize($scenario['payment']->fresh());
        $ledger->finalize($scenario['payment']->fresh());

        $commission = PlatformCommission::query()
            ->where('seller_order_id', $scenario['seller_order']->id)
            ->where('type', 'sale')
            ->sole();
        $sellerTransaction = SellerTransaction::query()
            ->where('seller_order_id', $scenario['seller_order']->id)
            ->where('payment_id', $scenario['payment']->id)
            ->where('type', 'sale')
            ->sole();
        $this->assertSame('finalized', $commission->status);
        $this->assertSame('posted', $sellerTransaction->status);
        $this->assertSame(1, PlatformCommission::query()->where('seller_order_id', $scenario['seller_order']->id)->where('type', 'sale')->count());
        $this->assertSame(1, SellerTransaction::query()->where('seller_order_id', $scenario['seller_order']->id)->where('payment_id', $scenario['payment']->id)->where('type', 'sale')->count());

        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame((int) $sellerTransaction->amount_minor, (int) $wallet->pending_balance_minor);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_transaction_id', $sellerTransaction->id)
            ->where('type', 'sale_earning_pending')
            ->count());
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
            ])
            ->assertForbidden();

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.receive', $collection), [
                'actual_amount' => '175.00',
            ])
            ->assertRedirect();
        $this->assertMatchesRegularExpression('/^CODH-[A-Z0-9-]+$/', $collection->fresh()->logistics_reference);
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.cod.remit', $collection), [
                'actual_amount' => '175.00',
            ])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => '175.00',
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('reconciled', $collection->fresh()->status);
        $this->assertMatchesRegularExpression('/^BFR-\d{4}-\d{8,}$/', $collection->fresh()->finance_reference);
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
            ->assertSee("Check that the number above matches the number on the Buyer's signed receipt.", false)
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

    private function assertCodConflict(callable $operation): void
    {
        try {
            $operation();
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());

            return;
        }

        $this->fail('The COD operation should have returned a conflict.');
    }

    private function assertOutboundShipmentConflict(callable $operation): void
    {
        try {
            $operation();
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('exactly one outbound Shipment', $exception->getMessage());

            return;
        }

        $this->fail('Financial processing should have rejected the duplicate outbound shipment state.');
    }

    /** @param array<string, mixed> $scenario */
    private function duplicateOutboundCodShipment(array $scenario): Shipment
    {
        return Shipment::query()->create([
            'shipment_no' => 'SHP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $scenario['seller_order']->id,
            'logistics_profile_id' => $scenario['provider']->id,
            'purpose' => 'outbound',
            'status' => 'delivered',
            'cod_amount_minor' => 20000,
            'delivered_at' => now(),
        ]);
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
