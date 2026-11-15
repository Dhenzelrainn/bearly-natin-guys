<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Events\LogisticsRefundCashPayoutUpdated;
use App\Models\Address;
use App\Models\AuditLog;
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
use App\Models\SellerPayoutItem;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Services\CodCashCollectionService;
use App\Services\SellerPayoutService;
use App\Services\SellerWalletService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

        $adminCodVersionBeforeRemittance = $this->actingAs($this->admin)
            ->getJson(route('admin.payments.cod-status-version'))
            ->assertOk()
            ->json('data.version');
        $sellerCodVersionBeforeReconciliation = $this->actingAs($scenario['seller'])
            ->getJson(route('seller.finance.wallet.cod-status-version'))
            ->assertOk()
            ->json('data.version');

        $this->assertSame('collected', $collection->status);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);
        $this->assertCount(0, app(SellerPayoutService::class)->eligibleBySeller());
        $this->actingAs($this->admin)->get(route('admin.payments'))->assertOk();
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('cash-custody-card-list', false)
            ->assertSee(route('logistics.cash.refunds.status-version'), false)
            ->assertSee('data-status-version=', false)
            ->assertSee('cash-custody-card', false)
            ->assertSee('Collection details')
            ->assertSee('Amount &amp; status', false)
            ->assertSee('Custody action')
            ->assertSee('COD collection ID')
            ->assertSee('data-copy-cash-reference', false)
            ->assertSee('Count the cash and enter the amount received.')
            ->assertSee('Generated after cash is acknowledged');

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
            ->assertSee('Rider-to-Logistics reference')
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
        $adminRemittanceNotification = $this->admin->notifications()
            ->where('data->event', 'admin_cod_remitted')
            ->latest()
            ->firstOrFail();
        $this->assertSame($collection->id, (int) $adminRemittanceNotification->data['meta']['collection_id']);
        $adminCodVersionAfterRemittance = $this->actingAs($this->admin)
            ->getJson(route('admin.payments.cod-status-version'))
            ->assertOk()
            ->json('data.version');
        $this->assertNotSame($adminCodVersionBeforeRemittance, $adminCodVersionAfterRemittance);
        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('data-finance-cod-live', false)
            ->assertSee(route('admin.payments.cod-status-version'), false)
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
        $this->assertSame('paid', $scenario['payment']->fresh()->status);
        $this->assertSame('paid', $scenario['order']->fresh()->payment_status);
        $this->assertSame($remittanceReference, $collection->finance_remittance_reference);
        $financeReceiptReference = $collection->finance_reference;
        $this->assertMatchesRegularExpression('/^BFR-\d{4}-\d{8,}$/', $financeReceiptReference);
        $this->assertNotSame($collection->finance_remittance_reference, $collection->finance_reference);
        $this->assertDatabaseHas('cod_cash_collection_events', [
            'cod_cash_collection_id' => $collection->id,
            'event_type' => 'cash_reconciled_by_finance',
            'reference' => $financeReceiptReference,
        ]);
        $sellerCodVersionAfterReconciliation = $this->actingAs($scenario['seller'])
            ->getJson(route('seller.finance.wallet.cod-status-version'))
            ->assertOk()
            ->json('data.version');
        $this->assertNotSame($sellerCodVersionBeforeReconciliation, $sellerCodVersionAfterReconciliation);
        $sellerFinanceNotification = $scenario['seller']->notifications()
            ->where('data->event', 'seller_cod_cash_reconciled')
            ->latest()
            ->firstOrFail();
        $this->assertSame($scenario['seller_order']->id, (int) $sellerFinanceNotification->data['meta']['seller_order_id']);
        $this->actingAs($scenario['seller'])
            ->get(route('seller.finance.wallet'))
            ->assertOk()
            ->assertSee('data-seller-wallet-live', false)
            ->assertSee(route('seller.finance.wallet.cod-status-version'), false);
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

    public function test_finance_reconciliation_releases_cod_wallet_earnings_only_when_existing_gates_pass(): void
    {
        $scenario = $this->scenario('matured cod wallet');
        $scenario['seller_order']->update(['completed_at' => now()->subDays(4)]);
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $this->rider($scenario['provider'], 'matured COD wallet rider'),
            20000,
        );

        $this->assertDatabaseMissing('seller_wallets', [
            'seller_profile_id' => $scenario['seller_profile']->id,
        ]);

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

        $wallet = $scenario['seller_profile']->wallet()->firstOrFail();
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertSame(18000, (int) $wallet->available_balance_minor);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'type' => 'sale_earning_available',
            'seller_transaction_id' => SellerTransaction::query()
                ->where('seller_order_id', $scenario['seller_order']->id)
                ->where('type', 'sale')
                ->sole()
                ->id,
        ]);
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
        $scenario['seller_order']->update(['total_minor' => 5_005_000]);
        $scenario['order']->update(['total_minor' => 5_005_000]);
        $scenario['payment']->update(['amount_minor' => 5_005_000]);
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

    public function test_multi_seller_cod_payment_is_not_captured_until_finance_reconciles_each_exact_allocation(): void
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

        $codCash = app(CodCashCollectionService::class);
        $firstCollection = $codCash->recordRiderCollection($scenario['shipment'], $rider, 10000);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);

        $secondCollection = $codCash->recordRiderCollection($secondShipment, $rider, 10000);
        $this->assertSame('pending', $scenario['payment']->fresh()->status);
        $this->assertSame('unpaid', $scenario['order']->fresh()->payment_status);

        foreach ([$firstCollection, $secondCollection] as $collection) {
            $codCash->receiveAtLogistics($scenario['provider_user'], (int) $collection->id, 10000);
            $codCash->remitToFinance($scenario['provider_user'], (int) $collection->id, 10000);
            $codCash->reconcileAtFinance($this->admin, (int) $collection->id, 10000);
        }

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
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('Over ₱5.00')
            ->assertSee('Disputed');
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
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('Short ₱25.00')
            ->assertSee('Disputed');

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

    public function test_refund_cash_assignment_is_zone_first_and_audits_same_center_fallback_reason(): void
    {
        $scenario = $this->scenario('refund assignment policy');
        $refund = $this->refund($scenario, 5000);
        [$center, $buyerZone, $nearbyZone, $farZone] = $this->refundAssignmentZones($scenario);
        $payout = RefundCashPayout::query()->create([
            'payout_no' => 'RCP-'.Str::upper(Str::random(12)),
            'refund_id' => $refund->id,
            'return_request_id' => $scenario['return_request']->id,
            'logistics_profile_id' => $scenario['provider']->id,
            'amount_minor' => 5000,
            'status' => 'awaiting_rider_assignment',
            'finance_issue_reference' => 'CFH-'.Str::upper(Str::random(26)),
        ]);

        $inZoneRider = $this->rider($scenario['provider'], 'refund in-zone');
        $inZoneProfile = $this->assignRiderToZone($inZoneRider, $center, $buyerZone);
        $nearbyRider = $this->rider($scenario['provider'], 'refund nearby');
        $nearbyProfile = $this->assignRiderToZone($nearbyRider, $center, $nearbyZone);
        $farRider = $this->rider($scenario['provider'], 'refund far fallback');
        $farProfile = $this->assignRiderToZone($farRider, $center, $farZone);

        $courier = $this->rider($scenario['provider'], 'refund courier');
        $this->assignRiderToZone($courier, $center, $buyerZone, 'courier', 'both');
        $pickupOnly = $this->rider($scenario['provider'], 'refund pickup-only');
        $this->assignRiderToZone($pickupOnly, $center, $buyerZone, 'local', 'pickup_only');

        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('Buyer destination zone')
            ->assertSee($inZoneRider->name)
            ->assertDontSee($nearbyRider->name)
            ->assertDontSee($courier->name)
            ->assertDontSee($pickupOnly->name)
            ->assertDontSee('Nearby-zone fallback reason');

        // A direct request cannot bypass the in-zone-first recommendation.
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.assign', $payout), [
                'rider_user_id' => $nearbyRider->id,
                'assignment_reason' => 'Nearby Rider is ready for the Buyer.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rider_user_id');
        $this->assertSame('awaiting_rider_assignment', $payout->fresh()->status);
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.assign', $payout), [
                'rider_user_id' => $courier->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rider_user_id');

        // Once no eligible Rider remains in the destination zone, only same-center
        // fallback Riders are offered, and a clear reason is required and audited.
        $inZoneProfile->update(['availability_status' => 'offline']);
        $scenario['order']->update([
            'delivery_latitude' => 14.282,
            'delivery_longitude' => 121.088,
        ]);
        $nearbyProfile->liveLocation()->create([
            'latitude' => 14.2825,
            'longitude' => 121.0885,
            'accuracy_meters' => 150,
            'last_seen_at' => now(),
        ]);
        $farProfile->liveLocation()->create([
            'latitude' => 14.36,
            'longitude' => 121.16,
            'accuracy_meters' => 20,
            'last_seen_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('No eligible Rider is available in')
            ->assertSee($nearbyRider->name)
            ->assertSee($farRider->name)
            ->assertSee('Nearby-zone fallback reason')
            ->assertDontSee($inZoneRider->name)
            ->assertSee('Fresh accurate GPS is not available for every eligible Rider')
            ->assertDontSee('Closest fresh GPS estimate');

        $nearbyProfile->liveLocation()->update([
            'accuracy_meters' => 20,
            'last_seen_at' => now(),
        ]);
        $farProfile->liveLocation()->update([
            'accuracy_meters' => 20,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('No eligible Rider is available in')
            ->assertSee($nearbyRider->name)
            ->assertSee($farRider->name)
            ->assertDontSee($inZoneRider->name)
            ->assertSee('Closest fresh GPS estimate')
            ->assertSee('straight-line, not road distance or an ETA');

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.assign', $payout), [
                'rider_user_id' => $nearbyRider->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('assignment_reason');

        $reason = 'The Buyer-zone Rider is off duty; this same-center Rider is available for delivery today.';
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.assign', $payout), [
                'rider_user_id' => $nearbyRider->id,
                'assignment_reason' => $reason,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payout->refresh();
        $this->assertSame((int) $nearbyProfile->id, (int) $payout->rider_profile_id);
        $assignmentEvent = RefundCashPayoutEvent::query()
            ->where('refund_cash_payout_id', $payout->id)
            ->where('event_type', 'cash_assigned_to_rider')
            ->sole();
        $this->assertSame('nearby_zone', $assignmentEvent->metadata['assignment_mode']);
        $this->assertSame((int) $buyerZone->id, $assignmentEvent->metadata['buyer_destination_zone_id']);
        $this->assertSame((int) $nearbyZone->id, $assignmentEvent->metadata['rider_zone_id']);
        $this->assertSame((int) $center->id, $assignmentEvent->metadata['sorting_center_id']);
        $this->assertSame($reason, $assignmentEvent->metadata['cross_zone_reason']);
        $this->assertStringContainsString($reason, $assignmentEvent->note);
    }

    public function test_refund_cash_requires_logistics_receipt_rider_return_and_verified_buyer_acknowledgment(): void
    {
        Storage::fake('local');
        Queue::fake();
        $scenario = $this->scenario('refund cash');
        $collection = app(CodCashCollectionService::class)->recordRiderCollection(
            $scenario['shipment'],
            $this->rider($scenario['provider'], 'cod collection rider'),
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

        $refund = $this->refund($scenario, 5000);
        $orderItem = $scenario['seller_order']->items()->create([
            'product_name' => 'Refund Cash Test Product',
            'variant_name' => 'Black / One Size',
            'sku' => 'REFUND-CASH-TEST-001',
            'unit_price_minor' => 5000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 5000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/refund-cash-test.png',
        ]);
        $scenario['return_request']->items()->create([
            'order_item_id' => $orderItem->id,
            'quantity' => 1,
            'refund_amount_minor' => 5000,
        ]);
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
            ->get(route('admin.compliance.returns-refunds'))
            ->assertOk()
            ->assertSee('Cash refund in progress')
            ->assertSee('Awaiting Logistics Receipt')
            ->assertSee('Logistics must acknowledge the physical cash handoff.')
            ->assertSee(route('admin.payments').'#refund-cash-assignments', false)
            ->assertDontSee(route('admin.compliance.returns-refunds.record-payout', $refund), false);

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
            ->assertSee('cash-custody-card-list', false)
            ->assertSee(route('logistics.cash.refunds.status-version'), false)
            ->assertSee('data-status-version=', false)
            ->assertSee('cash-custody-card', false)
            ->assertSee('Cash payout ID')
            ->assertSee('Refund ID')
            ->assertSee('Amount &amp; status', false)
            ->assertSee('Custody action')
            ->assertSee('data-copy-cash-reference', false)
            ->assertSee('data-refund-product-preview', false)
            ->assertSee('Refund Cash Test Product')
            ->assertSee('seller-products/refund-cash-test.png')
            ->assertSee('Black / One Size')
            ->assertSee('This acknowledgment will be recorded against Finance handoff')
            ->assertSee($payout->finance_issue_reference)
            ->assertDontSee('Enter receipt reference')
            ->assertSee('Finance handoff reference')
            ->assertSee($payout->finance_issue_reference);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.receive', $payout), [
                'actual_amount' => '50.00',
            ])
            ->assertRedirect();
        $payout->refresh();
        $this->assertSame('awaiting_rider_assignment', $payout->status);
        $this->assertSame($payout->finance_issue_reference, $payout->logistics_receipt_reference);
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment-slip', $payout))
            ->assertRedirect(route('logistics.cash.index'))
            ->assertSessionHas('error', 'Assign an eligible Rider before printing this acknowledgment form.');
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee('Assign an eligible Rider before printing this acknowledgment form.')
            ->assertSee('role="alert"', false);

        $adminNotification = $this->admin->notifications()
            ->where('data->event', 'admin_refund_cash_received_by_logistics')
            ->sole();
        $this->assertStringContainsString($payout->payout_no, $adminNotification->data['body']);
        $this->assertStringContainsString($scenario['provider']->display_name, $adminNotification->data['body']);
        $this->assertStringContainsString($payout->finance_issue_reference, $adminNotification->data['body']);
        $this->assertSame(route('admin.payments').'#refund-cash-assignments', $adminNotification->data['url']);
        $this->assertSame((int) $payout->id, $adminNotification->data['meta']['refund_cash_payout_id']);

        // A retried acknowledgment is idempotent and must not notify Admin twice.
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.receive', $payout), [
                'actual_amount' => '50.00',
            ])
            ->assertRedirect();
        $this->assertSame(
            1,
            $this->admin->notifications()->where('data->event', 'admin_refund_cash_received_by_logistics')->count(),
        );

        [$refundCenter, $buyerZone] = $this->refundAssignmentZones($scenario);
        $firstRider = $this->rider($scenario['provider'], 'refund rider one');
        $this->assignRiderToZone($firstRider, $refundCenter, $buyerZone);
        $secondRider = $this->rider($scenario['provider'], 'refund rider two');
        $this->assignRiderToZone($secondRider, $refundCenter, $buyerZone);

        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.assign', $payout), ['rider_user_id' => $firstRider->id])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Print acknowledgment form ACK-'));
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
            ->assertSee('Prepare acknowledgment form');
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment-slip', $payout))
            ->assertRedirect(route('logistics.cash.index'))
            ->assertSessionHas('error', 'Prepare the acknowledgment form from Cash custody before printing it.');
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
        $this->assertSame(
            1,
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->count(),
        );
        $this->assertSame(
            'Cash refund out for delivery',
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->sole()->data['title'],
        );

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
        $this->assertSame(
            2,
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->count(),
        );

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
        $this->assertSame(
            3,
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->count(),
        );
        $this->actingAs($secondRider)
            ->post(route('rider.refund-cash.start', $payout))
            ->assertRedirect();
        $this->assertSame(
            3,
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->count(),
            'A retry of the same Rider delivery start must not create a duplicate Buyer alert.',
        );
        $versionBeforeBuyerReceipt = $this->actingAs($scenario['provider_user'])
            ->getJson(route('logistics.cash.refunds.status-version'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['version']])
            ->json('data.version');
        Event::fake([LogisticsRefundCashPayoutUpdated::class]);

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

        Event::assertDispatched(
            LogisticsRefundCashPayoutUpdated::class,
            fn (LogisticsRefundCashPayoutUpdated $event): bool => $event->logisticsUserId === (int) $scenario['provider_user']->id
                && $event->payoutId === (int) $payout->id
                && $event->status === 'delivered_pending_verification',
        );
        $versionAfterBuyerReceipt = $this->actingAs($scenario['provider_user'])
            ->getJson(route('logistics.cash.refunds.status-version'))
            ->assertOk()
            ->json('data.version');
        $this->assertNotSame($versionBeforeBuyerReceipt, $versionAfterBuyerReceipt);
        $this->actingAs($secondRider)
            ->getJson(route('logistics.cash.refunds.status-version'))
            ->assertForbidden();

        $this->assertSame('delivered_pending_verification', $payout->fresh()->status);
        $this->assertSame($secondReference, $payout->fresh()->acknowledgment_reference);
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertSame(
            0,
            $this->admin->notifications()->where('data->event', 'admin_refund_cash_ready_for_finance')->count(),
            'Admin should be notified once Logistics verification makes the payout ready for Finance.',
        );
        $this->assertSame(
            4,
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->count(),
        );
        $logisticsVerificationAlert = $scenario['provider_user']->notifications()
            ->where('data->event', 'logistics_refund_cash_verification_required')
            ->sole();
        $this->assertStringContainsString($payout->payout_no, $logisticsVerificationAlert->data['body']);
        $this->assertSame(route('logistics.cash.index'), $logisticsVerificationAlert->data['url']);
        $this->actingAs($scenario['buyer'])
            ->get(route('buyer.returns.show', $scenario['return_request']->id))
            ->assertOk()
            ->assertSee('Cash refund delivery tracking')
            ->assertSee('The Rider reported delivery; Logistics verification is pending')
            ->assertSee('The Rider reported the refund delivered')
            ->assertSee('This is not live Rider location.')
            ->assertDontSee($secondReference)
            ->assertDontSee('Buyer Refund Recipient')
            ->assertDontSee('signed-ack.pdf');
        $otherBuyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $this->actingAs($otherBuyer)
            ->get(route('buyer.returns.show', $scenario['return_request']->id))
            ->assertNotFound();
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment-slip', $payout))
            ->assertRedirect(route('logistics.cash.index'))
            ->assertSessionHas('error', "This acknowledgment form is not available at the payout's current step. Return to Cash custody to review the next required action.");
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.index'))
            ->assertOk()
            ->assertSee("This acknowledgment form is not available at the payout's current step. Return to Cash custody to review the next required action.")
            ->assertSee('Delivered Pending Verification')
            ->assertSee('Buyer acknowledgment reference')
            ->assertSee("Check that the number above matches the number on the Buyer's signed receipt.", false)
            ->assertSee($secondReference);
        $this->actingAs($scenario['provider_user'])
            ->get(route('logistics.cash.refunds.acknowledgment-slip', 999999))
            ->assertNotFound();
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
        $financeReadyAlert = $this->admin->notifications()
            ->where('data->event', 'admin_refund_cash_ready_for_finance')
            ->sole();
        $this->assertStringContainsString($payout->payout_no, $financeReadyAlert->data['body']);
        $this->assertStringContainsString('Provider refund cash', $financeReadyAlert->data['body']);
        $this->assertSame(route('admin.payments').'#refund-cash-assignments', $financeReadyAlert->data['url']);
        $this->assertSame(
            5,
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->count(),
        );
        $this->actingAs($scenario['buyer'])
            ->get(route('buyer.returns.show', $scenario['return_request']->id))
            ->assertOk()
            ->assertSee('Logistics verified delivery; Finance completion is pending')
            ->assertSee('Logistics verified the signed acknowledgment')
            ->assertDontSee('Signed paper acknowledgment and amount verified.');
        $this->actingAs($scenario['provider_user'])
            ->post(route('logistics.cash.refunds.verify-receipt', $payout), [
                'verification_note' => 'Signed paper acknowledgment and amount verified.',
            ])
            ->assertRedirect();
        $this->assertSame(
            1,
            $this->admin->notifications()->where('data->event', 'admin_refund_cash_ready_for_finance')->count(),
            'Retrying Logistics verification must not duplicate the Finance alert.',
        );
        $this->assertSame(
            5,
            $scenario['buyer']->notifications()->where('data->event', 'buyer_refund_cash_tracking')->count(),
            'Retrying Logistics verification must not duplicate the Buyer alert.',
        );

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
        $buyerCompletionAlert = $scenario['buyer']->notifications()
            ->where('data->event', 'buyer_return_updates')
            ->where('data->title', 'Cash refund completed')
            ->sole();
        $this->assertStringContainsString('Finance completed cash refund', $buyerCompletionAlert->data['body']);
        $this->actingAs($scenario['buyer'])
            ->get(route('buyer.returns.show', $scenario['return_request']->id))
            ->assertOk()
            ->assertSee('Finance completed the cash refund')
            ->assertSee('Finance completed the cash refund after Logistics verified the Buyer acknowledgment.')
            ->assertDontSee($secondReference)
            ->assertDontSee('Buyer Refund Recipient')
            ->assertDontSee('Download payout receipt');
        $this->actingAs($scenario['buyer'])
            ->get(route('buyer.returns.refund-receipt', $refund))
            ->assertNotFound();
        $this->assertDatabaseMissing('seller_refund_recoveries', ['refund_id' => $refund->id]);
    }

    /** @return array{0: SortingCenter, 1: SortingZone, 2: SortingZone, 3: SortingZone} */
    private function refundAssignmentZones(array $scenario): array
    {
        $address = Address::query()->create([
            'user_id' => $scenario['provider_user']->id,
            'label' => 'Sorting Center',
            'recipient_name' => $scenario['provider_user']->name,
            'phone' => '+639171234568',
            'street' => 'Refund Cash Facility Road',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $scenario['provider']->id,
            'address_id' => $address->id,
            'name' => 'Refund Cash Sorting Center',
            'code' => 'REFUND-CENTER-'.$scenario['provider']->id,
            'status' => 'active',
        ]);

        $makeZone = function (string $code, string $name, string $barangay) use ($center): SortingZone {
            return SortingZone::query()->create([
                'sorting_center_id' => $center->id,
                'code' => $code,
                'name' => $name,
                'destination_rules' => [
                    'province' => 'Laguna',
                    'city_municipality' => 'Santa Rosa',
                    'barangays' => [$barangay],
                ],
                'status' => 'active',
            ]);
        };

        return [
            $center,
            $makeZone('BUYER-ZONE', 'Santa Rosa / Balibago', 'Balibago'),
            $makeZone('NEAR-ZONE', 'Santa Rosa / Canlubang', 'Canlubang'),
            $makeZone('FAR-ZONE', 'Santa Rosa / Pulong Santa Cruz', 'Pulong Santa Cruz'),
        ];
    }

    private function assignRiderToZone(
        User $user,
        SortingCenter $center,
        SortingZone $zone,
        string $workType = 'local',
        string $specialization = 'both',
    ): RiderProfile {
        $profile = $user->riderProfile()->firstOrFail();
        $profile->update([
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
            'work_type' => $workType,
            'specialization' => $specialization,
        ]);

        return $profile->fresh(['user', 'currentZone.sortingCenter']);
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
