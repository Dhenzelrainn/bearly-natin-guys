<?php

namespace Tests\Feature\Seller;

use App\Models\CodCashCollection;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerPayoutItem;
use App\Models\SellerProfile;
use App\Models\SellerRefundRecovery;
use App\Models\SellerTransaction;
use App\Models\SellerWalletTransaction;
use App\Models\SellerWithdrawal;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\SellerWalletService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerWalletWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@bearly.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paid_earnings_remain_pending_during_the_return_window_and_open_refund_case(): void
    {
        [, $profile, $store] = $this->sellerFixture('Pending earnings seller');
        [$order, $sellerOrder, $payment, $transaction] = $this->saleFixture(
            $profile,
            $store,
            'pending-return',
            completedDaysAgo: 10,
            method: 'gcash',
        );

        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $store->id,
            'request_type' => 'refund_only',
            'reason_code' => 'item_arrived_damaged',
            'status' => 'refund_pending',
            'requested_amount_minor' => 1000,
            'submitted_at' => now()->subDays(9),
        ]);

        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame(90_000, (int) $wallet->pending_balance_minor);

        $returnRequest->update(['status' => 'resolved', 'resolved_at' => now()]);
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);

        $this->assertSame(90_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_transaction_id' => $transaction->id,
            'type' => 'sale_earning_available',
            'available_delta_minor' => 90_000,
        ]);
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_transaction_id', $transaction->id)
            ->where('type', 'sale_earning_pending')
            ->count());
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_transaction_id', $transaction->id)
            ->where('type', 'sale_earning_available')
            ->count());
        $this->assertSame(90_000, app(SellerWalletService::class)->ledgerTotals($wallet)['available']);
    }

    public function test_paid_earnings_release_after_the_three_day_return_window(): void
    {
        [, $profile, $store] = $this->sellerFixture('Three-day window seller');
        [, , , $transaction] = $this->saleFixture(
            $profile,
            $store,
            'three-day-window',
            completedDaysAgo: 4,
            method: 'gcash',
        );

        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);

        $this->assertSame(90_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_transaction_id' => $transaction->id,
            'type' => 'sale_earning_available',
            'available_delta_minor' => 90_000,
        ]);
    }

    public function test_wallet_shows_when_the_three_day_return_period_ends_without_promising_availability(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        [$seller, $profile, $store] = $this->sellerFixture('Return period display seller');
        $this->saleFixture(
            $profile,
            $store,
            'return-period-display',
            completedDaysAgo: 2,
            method: 'gcash',
        );

        $this->actingAs($seller)
            ->get(route('seller.finance.wallet'))
            ->assertOk()
            ->assertSeeText('Paid earnings may move to Available after the 3-calendar-day return/refund period ends, counted from Seller Order completion.')
            ->assertSeeText('Return period ends Oct 23, 2026; may become Available after that if all other checks pass.')
            ->assertSeeText('Return period: 3 calendar days after order completion; other checks may extend the wait.');
    }

    public function test_wallet_migration_backfills_paid_legacy_payouts_and_refuses_data_loss_on_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_15_000001_create_seller_wallet_and_withdrawal_tables.php');
        $migration->down();

        [, $profile] = $this->sellerFixture('Legacy payout migration seller');
        $transaction = SellerTransaction::query()->create([
            'transaction_no' => 'STX-'.Str::upper(Str::random(12)),
            'seller_profile_id' => $profile->id,
            'type' => 'sale',
            'amount_minor' => 90_000,
            'status' => 'posted',
            'posted_at' => now()->subDays(20),
            'description' => 'Already included in a legacy paid payout.',
        ]);
        $payout = SellerPayout::query()->create([
            'payout_no' => 'SPY-'.Str::upper(Str::random(12)),
            'seller_profile_id' => $profile->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->subDays(20)->toDateString(),
            'gross_minor' => 100_000,
            'commission_minor' => 10_000,
            'adjustment_minor' => 0,
            'net_minor' => 90_000,
            'status' => 'paid',
            'paid_at' => now()->subDays(19),
            'reference' => 'BANK-HISTORY-001',
        ]);
        SellerPayoutItem::query()->create([
            'seller_payout_id' => $payout->id,
            'seller_transaction_id' => $transaction->id,
            'gross_minor' => 100_000,
            'commission_minor' => 10_000,
            'net_minor' => 90_000,
        ]);

        $migration->up();

        $wallet = DB::table('seller_wallets')->where('seller_profile_id', $profile->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame(90_000, (int) $wallet->total_withdrawn_minor);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_payout_id' => $payout->id,
            'type' => 'legacy_withdrawal_completion',
            'withdrawn_delta_minor' => 90_000,
        ]);
        $this->assertDatabaseMissing('seller_wallet_transactions', [
            'seller_transaction_id' => $transaction->id,
        ]);

        try {
            $migration->down();
            $this->fail('Rollback should refuse to remove initialized wallet history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be rolled back', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('seller_wallet_transactions'));
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_payout_id' => $payout->id,
            'type' => 'legacy_withdrawal_completion',
        ]);
    }

    public function test_wallet_history_shows_covered_orders_and_product_previews_for_legacy_paid_payouts(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Legacy preview seller');
        [$order, $sellerOrder, , $transaction] = $this->saleFixture(
            $profile,
            $store,
            'legacy-preview',
            completedDaysAgo: 20,
            method: 'cod',
        );
        [, $secondSellerOrder, , $secondTransaction] = $this->saleFixture(
            $profile,
            $store,
            'legacy-preview-two',
            completedDaysAgo: 18,
            method: 'cod',
        );

        $sellerOrder->items()->create([
            'product_name' => 'Legacy Preview Product',
            'variant_name' => 'Blue / Medium',
            'sku' => 'LEGACY-PREVIEW-001',
            'unit_price_minor' => 100_000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 100_000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/legacy-preview.png',
        ]);
        $secondSellerOrder->items()->create([
            'product_name' => 'Legacy Preview Second Product',
            'variant_name' => 'Red / Small',
            'sku' => 'LEGACY-PREVIEW-002',
            'unit_price_minor' => 100_000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 100_000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/legacy-preview-two.png',
        ]);

        $payout = SellerPayout::query()->create([
            'payout_no' => 'SPY-LEGACY-PREVIEW-001',
            'seller_profile_id' => $profile->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->subDays(18)->toDateString(),
            'gross_minor' => 200_000,
            'commission_minor' => 20_000,
            'adjustment_minor' => 0,
            'net_minor' => 180_000,
            'status' => 'paid',
            'paid_at' => now()->subDays(19),
            'reference' => 'BANK-LEGACY-PREVIEW-001',
        ]);
        SellerPayoutItem::query()->create([
            'seller_payout_id' => $payout->id,
            'seller_transaction_id' => $transaction->id,
            'gross_minor' => 100_000,
            'commission_minor' => 10_000,
            'net_minor' => 90_000,
        ]);
        SellerPayoutItem::query()->create([
            'seller_payout_id' => $payout->id,
            'seller_transaction_id' => $secondTransaction->id,
            'gross_minor' => 100_000,
            'commission_minor' => 10_000,
            'net_minor' => 90_000,
        ]);

        app(SellerWalletService::class)->recordLegacyPayoutCompletion($payout, $this->admin);

        $this->actingAs($seller)
            ->get(route('seller.finance.wallet'))
            ->assertOk()
            ->assertSee($order->order_no)
            ->assertSee('+1 more')
            ->assertSee('Legacy Preview Product')
            ->assertSee('Legacy Preview Second Product')
            ->assertSee(Storage::disk('public')->url('seller-products/legacy-preview.png'), false)
            ->assertSee(Storage::disk('public')->url('seller-products/legacy-preview-two.png'), false);

        foreach ([$transaction, $secondTransaction] as $legacyTransaction) {
            $this->assertDatabaseMissing('seller_wallet_transactions', [
                'seller_transaction_id' => $legacyTransaction->id,
            ]);
        }
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_payout_id' => $payout->id,
            'type' => 'legacy_withdrawal_completion',
        ]);
    }

    public function test_resolved_cod_refund_does_not_strand_earnings_or_offset_separate_seller_recovery(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Refunded COD wallet seller');
        [$order, $sellerOrder, $payment] = $this->saleFixture(
            $profile,
            $store,
            'refunded-cod-sale',
            completedDaysAgo: 10,
            method: 'cod',
            grossMinor: 30_000,
            netMinor: 27_000,
        );
        $providerUser = $this->user('logistics', 'Refunded COD provider');
        $provider = LogisticsProfile::query()->create([
            'user_id' => $providerUser->id,
            'legal_name' => 'Refunded COD provider',
            'display_name' => 'Refunded COD provider',
            'contact_phone' => '+639171234569',
            'status' => 'active',
        ]);
        $rider = $this->user('rider', 'Refunded COD rider');
        $riderProfile = RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
        $shipment = $this->codShipment($sellerOrder, $provider, 30_000);
        $this->codCollection($shipment, $sellerOrder, $order, $payment, $provider, $riderProfile, $rider, 30_000, true);

        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $store->id,
            'request_type' => 'refund_only',
            'reason_code' => 'item_arrived_damaged',
            'status' => 'resolved',
            'requested_amount_minor' => 30_000,
            'submitted_at' => now()->subDays(9),
            'resolved_at' => now()->subDays(2),
        ]);
        $refund = Refund::query()->create([
            'refund_no' => 'RFD-'.Str::upper(Str::random(12)),
            'return_request_id' => $returnRequest->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_minor' => 30_000,
            'payout_amount_minor' => 30_000,
            'status' => 'completed',
            'financially_responsible_party' => 'seller',
            'method' => 'cash',
            'completed_at' => Carbon::parse('2026-10-20 12:00:00'),
        ]);
        SellerRefundRecovery::query()->create([
            'refund_id' => $refund->id,
            'seller_profile_id' => $profile->id,
            'amount_minor' => 27_000,
            'recovered_amount_minor' => 0,
            'status' => 'pending',
        ]);
        $payment->update(['status' => 'refunded']);

        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);

        $this->assertSame(27_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertSame('pending', $refund->sellerRecovery->status);
        $this->assertSame(27_000, app(SellerWalletService::class)->ledgerTotals($wallet)['available']);
    }

    public function test_completed_seller_refund_after_cutoff_deducts_pending_wallet_before_release(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        [$seller, $profile, $store] = $this->sellerFixture('Post-cutoff pending refund seller');
        [$order, $sellerOrder, $payment, $transaction] = $this->saleFixture(
            $profile,
            $store,
            'post-cutoff-pending-refund',
            completedDaysAgo: 10,
            method: 'gcash',
        );
        [$returnRequest, $refund, $recovery] = $this->completedSellerRefund(
            $profile,
            $order,
            $sellerOrder,
            $payment,
            amountMinor: 10_000,
            recoveryMinor: 9_000,
        );

        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);

        $this->assertSame(81_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_transaction_id' => $transaction->id,
            'refund_id' => $refund->id,
            'type' => 'refund_adjustment',
            'amount_minor' => 9_000,
            'pending_delta_minor' => -9_000,
            'available_delta_minor' => 0,
        ]);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_transaction_id' => $transaction->id,
            'type' => 'sale_earning_available',
            'amount_minor' => 81_000,
        ]);
        $this->assertDatabaseHas('seller_refund_recoveries', [
            'id' => $recovery->id,
            'status' => 'recovered',
            'recovered_amount_minor' => 9_000,
        ]);
        $this->assertDatabaseHas('seller_refund_recovery_events', [
            'seller_refund_recovery_id' => $recovery->id,
            'method' => 'wallet_offset',
            'amount_minor' => 9_000,
        ]);

        app(SellerWalletService::class)->recordRefundAdjustment($refund->fresh(), $this->admin);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('refund_id', $refund->id)
            ->where('type', 'refund_adjustment')
            ->count());
    }

    public function test_completed_seller_refund_after_cutoff_deducts_available_wallet(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        [$seller, $profile, $store] = $this->sellerFixture('Post-cutoff available refund seller');
        [$order, $sellerOrder, $payment] = $this->saleFixture(
            $profile,
            $store,
            'post-cutoff-available-refund',
            completedDaysAgo: 10,
            method: 'gcash',
        );
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(90_000, (int) $wallet->available_balance_minor);

        [, $refund] = $this->completedSellerRefund(
            $profile,
            $order,
            $sellerOrder,
            $payment,
            amountMinor: 10_000,
            recoveryMinor: 9_000,
        );

        app(SellerWalletService::class)->recordRefundAdjustment($refund, $this->admin);
        $wallet->refresh();

        $this->assertSame(81_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'refund_id' => $refund->id,
            'type' => 'refund_adjustment',
            'amount_minor' => 9_000,
            'available_delta_minor' => -9_000,
            'pending_delta_minor' => 0,
        ]);
    }

    public function test_completed_refund_before_cutoff_remains_legacy_and_is_not_auto_deducted(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        [$seller, $profile, $store] = $this->sellerFixture('Pre-cutoff refund seller');
        [$order, $sellerOrder, $payment] = $this->saleFixture(
            $profile,
            $store,
            'pre-cutoff-refund',
            completedDaysAgo: 10,
            method: 'gcash',
        );
        [$returnRequest, $refund, $recovery] = $this->completedSellerRefund(
            $profile,
            $order,
            $sellerOrder,
            $payment,
            amountMinor: 10_000,
            recoveryMinor: 9_000,
            completedAt: Carbon::parse('2026-10-20 12:00:00'),
        );

        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);

        $this->assertSame(90_000, (int) $wallet->available_balance_minor);
        $this->assertDatabaseMissing('seller_wallet_transactions', [
            'refund_id' => $refund->id,
            'type' => 'refund_adjustment',
        ]);
        $this->assertDatabaseHas('seller_refund_recoveries', [
            'id' => $recovery->id,
            'status' => 'pending',
        ]);
    }

    public function test_outstanding_recovery_blocks_new_withdrawals_until_covered(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        [$seller, $profile, $store] = $this->sellerFixture('Outstanding recovery withdrawal seller');
        [$order, $sellerOrder, $payment] = $this->saleFixture(
            $profile,
            $store,
            'outstanding-recovery-sale',
            completedDaysAgo: 10,
            method: 'gcash',
            grossMinor: 5_000,
            netMinor: 5_000,
        );
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(5_000, (int) $wallet->available_balance_minor);

        [$returnRequest, $refund, $recovery] = $this->completedSellerRefund(
            $profile,
            $order,
            $sellerOrder,
            $payment,
            amountMinor: 6_000,
            recoveryMinor: 5_500,
        );

        app(SellerWalletService::class)->recordRefundAdjustment($refund, $this->admin);
        $wallet->refresh();

        $this->assertSame(5_000, (int) $wallet->available_balance_minor);
        $this->assertDatabaseMissing('seller_wallet_transactions', [
            'refund_id' => $refund->id,
            'type' => 'refund_adjustment',
        ]);
        $this->assertSame(5_500, $recovery->fresh()->outstandingMinor());

        $account = $this->createBankAccount($seller, '5555666677778888');
        $this->actingAs($seller)
            ->post(route('seller.finance.withdrawals.store'), [
                'amount' => '50.00',
                'seller_bank_account_id' => $account->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, SellerWithdrawal::query()->count());
    }

    public function test_legacy_void_command_closes_pre_cutoff_refund_and_pending_balance(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        [$seller, $profile, $store] = $this->sellerFixture('Legacy void refund seller');
        [$order, $sellerOrder, $payment, $transaction] = $this->saleFixture(
            $profile,
            $store,
            'legacy-void-refund',
            completedDaysAgo: 2,
            method: 'gcash',
        );
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(90_000, (int) $wallet->pending_balance_minor);

        [, $refund, $recovery] = $this->completedSellerRefund(
            $profile,
            $order,
            $sellerOrder,
            $payment,
            amountMinor: 10_000,
            recoveryMinor: 9_000,
            completedAt: Carbon::parse('2026-10-20 12:00:00'),
        );

        $this->artisan('seller-wallet:void-legacy-refund', [
            'refund' => $refund->id,
            '--apply' => true,
            '--actor' => $this->admin->id,
            '--reason' => 'Legacy test data was already paid outside the wallet.',
        ])->assertSuccessful();

        $wallet->refresh();
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame('voided', $recovery->fresh()->status);
        $this->assertSame(0, $recovery->fresh()->outstandingMinor());
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_transaction_id' => $transaction->id,
            'refund_id' => $refund->id,
            'type' => 'manual_adjustment',
            'amount_minor' => 90_000,
            'pending_delta_minor' => -90_000,
        ]);
        $this->assertDatabaseHas('seller_refund_recovery_events', [
            'seller_refund_recovery_id' => $recovery->id,
            'method' => 'legacy_void',
        ]);
    }

    public function test_cod_earnings_require_every_outbound_cod_shipment_to_be_finance_reconciled(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('COD wallet seller');
        [$order, $sellerOrder, $payment, $transaction] = $this->saleFixture(
            $profile,
            $store,
            'cod-sale',
            completedDaysAgo: 10,
            method: 'cod',
            grossMinor: 30_000,
            netMinor: 27_000,
        );
        $providerUser = $this->user('logistics', 'COD wallet provider');
        $provider = LogisticsProfile::query()->create([
            'user_id' => $providerUser->id,
            'legal_name' => 'COD wallet provider',
            'display_name' => 'COD wallet provider',
            'contact_phone' => '+639171234568',
            'status' => 'active',
        ]);
        $rider = $this->user('rider', 'COD wallet rider');
        $riderProfile = RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        $firstShipment = $this->codShipment($sellerOrder, $provider, 12_000);
        $secondShipment = $this->codShipment($sellerOrder, $provider, 18_000);
        $this->codCollection($firstShipment, $sellerOrder, $order, $payment, $provider, $riderProfile, $rider, 12_000, true);
        $secondCollection = $this->codCollection($secondShipment, $sellerOrder, $order, $payment, $provider, $riderProfile, $rider, 18_000, false);

        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame(27_000, (int) $wallet->pending_balance_minor);

        $secondCollection->update([
            'status' => 'reconciled',
            'amount_reconciled_minor' => 18_000,
            'reconciled_at' => now(),
            'reconciled_by' => $this->admin->id,
            'finance_reference' => 'BFR-'.Str::upper(Str::random(10)),
        ]);
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);

        $this->assertSame(27_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $this->assertDatabaseHas('seller_wallet_transactions', [
            'seller_transaction_id' => $transaction->id,
            'type' => 'sale_earning_available',
        ]);
    }

    public function test_bank_account_is_encrypted_and_withdrawal_reserves_funds_until_a_verified_transfer(): void
    {
        Storage::fake('local');
        [$seller, $profile, $store] = $this->sellerFixture('Wallet lifecycle seller');
        $this->saleFixture($profile, $store, 'lifecycle-sale', completedDaysAgo: 10, method: 'gcash');
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(90_000, (int) $wallet->available_balance_minor);

        $accountNumber = '1234567890123456';
        $this->actingAs($seller)->post(route('seller.finance.bank-accounts.store'), [
            'bank_name' => 'Bearly Test Bank',
            'account_name' => 'Wallet lifecycle seller',
            'account_number' => $accountNumber,
        ])->assertRedirect();

        $account = $profile->bankAccounts()->sole();
        $storedAccountNumber = (string) DB::table('seller_bank_accounts')->where('id', $account->id)->value('account_number');
        $this->assertNotSame($accountNumber, $storedAccountNumber);
        $this->assertSame($accountNumber, $account->account_number);
        $this->assertArrayNotHasKey('account_number', $account->toArray());

        $this->actingAs($seller)
            ->get(route('seller.finance.bank-accounts'))
            ->assertOk()
            ->assertSee('3456')
            ->assertDontSee($accountNumber);

        $idempotencyKey = (string) Str::uuid();
        $withdrawalRequest = [
            'amount' => '500.00',
            'seller_bank_account_id' => $account->id,
            'idempotency_key' => $idempotencyKey,
        ];
        $this->actingAs($seller)
            ->post(route('seller.finance.withdrawals.store'), $withdrawalRequest)
            ->assertRedirect(route('seller.finance.wallet'));
        $withdrawal = SellerWithdrawal::query()->sole();

        $this->actingAs($seller)
            ->post(route('seller.finance.withdrawals.store'), $withdrawalRequest)
            ->assertRedirect(route('seller.finance.wallet'));

        $wallet->refresh();
        $this->assertSame(40_000, (int) $wallet->available_balance_minor);
        $this->assertSame(50_000, (int) $wallet->reserved_balance_minor);
        $this->assertDatabaseCount('seller_withdrawals', 1);
        $this->assertDatabaseHas('seller_withdrawal_status_histories', [
            'seller_withdrawal_id' => $withdrawal->id,
            'from_status' => null,
            'to_status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $seller->id,
            'data' => json_encode([
                'event' => 'seller_withdrawal_status_changed',
                'withdrawal_id' => $withdrawal->id,
                'withdrawal_no' => $withdrawal->withdrawal_no,
                'amount_minor' => 50_000,
                'status' => 'pending_review',
                'reason' => 'Seller submitted a withdrawal request.',
                'message' => "Withdrawal {$withdrawal->withdrawal_no} was submitted and is waiting for Finance review.",
            ]),
        ]);

        $otherSeller = $this->sellerFixture('Different wallet seller')[0];
        $this->actingAs($otherSeller)
            ->post(route('seller.finance.withdrawals.store'), $withdrawalRequest + ['idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('seller_bank_account_id');

        $this->actingAs($seller)
            ->get(route('admin.seller-payouts.show', $withdrawal))
            ->assertForbidden();
        $this->actingAs($this->admin)
            ->get(route('admin.seller-payouts.index'))
            ->assertOk()
            ->assertDontSee($accountNumber);
        $this->actingAs($this->admin)
            ->get(route('admin.seller-payouts.show', $withdrawal))
            ->assertOk()
            ->assertSee($accountNumber)
            ->assertSee('Reveal full bank account number');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seller_withdrawal.review_viewed',
            'auditable_id' => $withdrawal->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.approve', $withdrawal), ['review_note' => 'Account details reviewed.'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.processing', $withdrawal), ['processing_note' => 'Finance is arranging transfer.'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.complete', $withdrawal), [
                'transfer_verified' => '1',
                'verification_note' => 'Confirmed on the bank statement.',
            ])
            ->assertStatus(409);

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.transfer', $withdrawal), [
                'transfer_amount' => '500.00',
                'bank_reference' => 'BANK-REF-'.Str::upper(Str::random(8)),
                'transfer_date' => now()->toDateString(),
                'transfer_outcome' => 'succeeded',
                'proof' => UploadedFile::fake()->create('bank-proof.pdf', 16, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.transfer', $withdrawal), [
                'transfer_amount' => '500.00',
                'bank_reference' => $transferReference = SellerWithdrawal::query()->findOrFail($withdrawal->id)->transfer?->bank_reference,
                'transfer_date' => now()->toDateString(),
                'transfer_outcome' => 'succeeded',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $withdrawal->fresh()->transfer()->count());
        $this->assertNotEmpty($transferReference);

        $transfer = $withdrawal->fresh()->transfer;
        $this->assertSame('pending_verification', $transfer->status);
        $this->actingAs($this->admin)
            ->get(route('admin.seller-payouts.proof', $withdrawal))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($seller)
            ->get(route('seller.finance.withdrawals.proof', $withdrawal))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.complete', $withdrawal), [
                'transfer_verified' => '1',
                'verification_note' => 'Confirmed on the bank statement.',
            ])
            ->assertRedirect();

        $withdrawal->refresh();
        $wallet->refresh();
        $this->assertSame('completed', $withdrawal->status);
        $this->assertSame('verified', $withdrawal->transfer->status);
        $this->assertSame(0, (int) $wallet->reserved_balance_minor);
        $this->assertSame(50_000, (int) $wallet->total_withdrawn_minor);
        $this->assertSame(40_000, (int) $wallet->available_balance_minor);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->where('type', 'withdrawal_completion')
            ->count());
        foreach ([
            'seller_withdrawal.requested',
            'seller_withdrawal.approved',
            'seller_withdrawal.processing_started',
            'seller_withdrawal.transfer_recorded',
            'seller_withdrawal.completed',
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'auditable_id' => $withdrawal->id,
            ]);
        }
        $notificationStatuses = DB::table('notifications')
            ->where('notifiable_id', $seller->id)
            ->pluck('data')
            ->map(fn (string $data): ?string => json_decode($data, true)['status'] ?? null)
            ->all();
        $this->assertContains('approved', $notificationStatuses);
        $this->assertContains('processing', $notificationStatuses);
        $this->assertContains('completed', $notificationStatuses);

        $this->actingAs($otherSeller)
            ->get(route('seller.finance.withdrawals.proof', $withdrawal))
            ->assertNotFound();

        $this->actingAs($seller)
            ->get(route('seller.finance.withdrawals.proof', $withdrawal))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.complete', $withdrawal), [
                'transfer_verified' => '1',
                'verification_note' => 'Confirmed on the bank statement.',
            ])
            ->assertRedirect();
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->where('type', 'withdrawal_completion')
            ->count());
    }

    public function test_rejection_and_confirmed_transfer_failure_release_reserved_balance_only_once(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Wallet release seller');
        $this->saleFixture($profile, $store, 'release-sale', completedDaysAgo: 10, method: 'gcash');
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $account = $this->createBankAccount($seller, '222233334444');

        $firstKey = (string) Str::uuid();
        $this->actingAs($seller)->post(route('seller.finance.withdrawals.store'), [
            'amount' => '100.00',
            'seller_bank_account_id' => $account->id,
            'idempotency_key' => $firstKey,
        ])->assertRedirect();
        $rejected = SellerWithdrawal::query()->where('idempotency_key', $firstKey)->firstOrFail();

        $rejectionData = ['rejection_reason' => 'Bank account needs correction.'];
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.reject', $rejected), $rejectionData)
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.reject', $rejected), $rejectionData)
            ->assertRedirect();

        $wallet->refresh();
        $this->assertSame(90_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->reserved_balance_minor);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_withdrawal_id', $rejected->id)
            ->where('type', 'withdrawal_release')
            ->count());

        $failedKey = (string) Str::uuid();
        $this->actingAs($seller)->post(route('seller.finance.withdrawals.store'), [
            'amount' => '200.00',
            'seller_bank_account_id' => $account->id,
            'idempotency_key' => $failedKey,
        ])->assertRedirect();
        $failed = SellerWithdrawal::query()->where('idempotency_key', $failedKey)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.approve', $failed), ['review_note' => 'Verified seller request.'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.processing', $failed), ['processing_note' => 'Starting manual transfer attempt.'])
            ->assertRedirect();

        $failedTransferData = [
            'transfer_amount' => '200.00',
            'transfer_date' => now()->toDateString(),
            'transfer_outcome' => 'failed',
            'no_successful_transfer_confirmed' => '1',
            'failure_reason' => 'Receiving bank rejected the account.',
        ];
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.transfer', $failed), $failedTransferData)
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.transfer', $failed), $failedTransferData)
            ->assertRedirect();

        $failed->refresh();
        $wallet->refresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', $failed->transfer->status);
        $this->assertSame(90_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->reserved_balance_minor);
        $this->assertSame(0, (int) $wallet->total_withdrawn_minor);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_withdrawal_id', $failed->id)
            ->where('type', 'withdrawal_release')
            ->count());
    }

    public function test_finance_can_confirm_a_recorded_but_unverified_transfer_failed(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Recorded transfer failure seller');
        $this->saleFixture($profile, $store, 'recorded-failure-sale', completedDaysAgo: 10, method: 'gcash');
        $wallet = app(SellerWalletService::class)->refreshForSeller($profile);
        $account = $this->createBankAccount($seller, '333344445555');
        $key = (string) Str::uuid();

        $this->actingAs($seller)->post(route('seller.finance.withdrawals.store'), [
            'amount' => '200.00',
            'seller_bank_account_id' => $account->id,
            'idempotency_key' => $key,
        ])->assertRedirect();
        $withdrawal = SellerWithdrawal::query()->where('idempotency_key', $key)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.approve', $withdrawal), ['review_note' => 'Seller account details reviewed.'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.processing', $withdrawal), ['processing_note' => 'Manual bank transfer initiated.'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.transfer', $withdrawal), [
                'transfer_amount' => '200.00',
                'bank_reference' => 'BANK-ATTEMPT-'.Str::upper(Str::random(8)),
                'transfer_date' => now()->toDateString(),
                'transfer_outcome' => 'succeeded',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.fail-transfer', $withdrawal), [
                'failure_reason' => 'Receiving bank rejected the transfer.',
            ])
            ->assertSessionHasErrors('no_successful_transfer_confirmed');
        $this->assertSame('processing', $withdrawal->fresh()->status);

        $failureData = [
            'no_successful_transfer_confirmed' => '1',
            'failure_reason' => 'Receiving bank rejected the transfer.',
        ];
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.fail-transfer', $withdrawal), $failureData)
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.seller-payouts.fail-transfer', $withdrawal), $failureData)
            ->assertRedirect();

        $withdrawal->refresh();
        $wallet->refresh();
        $this->assertSame('failed', $withdrawal->status);
        $this->assertSame('failed', $withdrawal->transfer->status);
        $this->assertSame(90_000, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->reserved_balance_minor);
        $this->assertSame(0, (int) $wallet->total_withdrawn_minor);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->where('type', 'withdrawal_release')
            ->count());
        $this->assertDatabaseHas('seller_withdrawal_transfers', [
            'seller_withdrawal_id' => $withdrawal->id,
            'bank_reference' => $withdrawal->transfer->bank_reference,
            'failure_confirmed_by' => $this->admin->id,
        ]);
    }

    private function sellerFixture(string $name): array
    {
        $seller = $this->user('seller', $name);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => $name,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $name.' Store',
            'slug' => Str::slug($name.'-'.$profile->id),
            'publication_status' => 'published',
        ]);

        return [$seller, $profile, $store];
    }

    private function saleFixture(
        SellerProfile $profile,
        Store $store,
        string $suffix,
        int $completedDaysAgo,
        string $method,
        int $grossMinor = 100_000,
        int $netMinor = 90_000,
    ): array {
        $buyer = $this->user('buyer', 'Buyer '.$suffix);
        $completedAt = now()->subDays($completedDaysAgo);
        $order = Order::query()->create([
            'order_no' => 'ORD-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '25 Wallet Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'currency' => 'PHP',
            'subtotal_minor' => $grossMinor,
            'total_minor' => $grossMinor,
            'status' => 'completed',
            'payment_status' => 'paid',
            'placed_at' => $completedAt,
            'completed_at' => $completedAt,
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => $grossMinor,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => $grossMinor,
            'completed_at' => $completedAt,
        ]);
        $payment = Payment::query()->create([
            'payment_no' => 'PAY-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'method' => $method,
            'amount_minor' => $grossMinor,
            'status' => 'paid',
            'initiated_at' => $completedAt,
            'paid_at' => $completedAt,
        ]);
        $transaction = SellerTransaction::query()->create([
            'transaction_no' => 'STX-'.Str::upper(Str::random(12)),
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'type' => 'sale',
            'amount_minor' => $netMinor,
            'status' => 'posted',
            'available_at' => $completedAt,
            'posted_at' => $completedAt,
            'description' => 'Net sale proceeds for '.$suffix,
        ]);

        return [$order, $sellerOrder, $payment, $transaction];
    }

    private function completedSellerRefund(
        SellerProfile $profile,
        Order $order,
        SellerOrder $sellerOrder,
        Payment $payment,
        int $amountMinor,
        int $recoveryMinor,
        ?Carbon $completedAt = null,
    ): array {
        $completedAt ??= now();
        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $sellerOrder->store_id,
            'request_type' => 'refund_only',
            'reason_code' => 'item_arrived_damaged',
            'status' => 'resolved',
            'requested_amount_minor' => $amountMinor,
            'submitted_at' => $completedAt->copy()->subDay(),
            'resolved_at' => $completedAt,
        ]);
        $refund = Refund::query()->create([
            'refund_no' => 'RFD-'.Str::upper(Str::random(12)),
            'return_request_id' => $returnRequest->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_minor' => $amountMinor,
            'payout_amount_minor' => $amountMinor,
            'status' => 'completed',
            'financially_responsible_party' => 'seller',
            'financial_responsibility_reason' => 'Item not as described',
            'method' => 'gcash',
            'approved_at' => $completedAt,
            'processed_at' => $completedAt,
            'completed_at' => $completedAt,
        ]);
        $recovery = SellerRefundRecovery::query()->create([
            'refund_id' => $refund->id,
            'seller_profile_id' => $profile->id,
            'amount_minor' => $recoveryMinor,
            'recovered_amount_minor' => 0,
            'status' => 'pending',
        ]);

        return [$returnRequest, $refund, $recovery];
    }

    private function createBankAccount(User $seller, string $accountNumber)
    {
        $this->actingAs($seller)->post(route('seller.finance.bank-accounts.store'), [
            'bank_name' => 'Wallet Test Bank',
            'account_name' => $seller->name,
            'account_number' => $accountNumber,
        ])->assertRedirect();

        return $seller->sellerProfile->bankAccounts()->sole();
    }

    private function codShipment(SellerOrder $sellerOrder, LogisticsProfile $provider, int $amountMinor): Shipment
    {
        return Shipment::query()->create([
            'shipment_no' => 'SHP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider->id,
            'purpose' => 'outbound',
            'status' => 'delivered',
            'cod_amount_minor' => $amountMinor,
            'delivered_at' => now()->subDays(9),
        ]);
    }

    private function codCollection(
        Shipment $shipment,
        SellerOrder $sellerOrder,
        Order $order,
        Payment $payment,
        LogisticsProfile $provider,
        RiderProfile $riderProfile,
        User $rider,
        int $amountMinor,
        bool $reconciled,
    ): CodCashCollection {
        return CodCashCollection::query()->create([
            'collection_no' => 'COD-'.Str::upper(Str::random(12)),
            'shipment_id' => $shipment->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider->id,
            'rider_profile_id' => $riderProfile->id,
            'amount_due_minor' => $amountMinor,
            'amount_collected_minor' => $amountMinor,
            'discrepancy_minor' => 0,
            'amount_received_by_logistics_minor' => $amountMinor,
            'amount_remitted_to_finance_minor' => $amountMinor,
            'amount_reconciled_minor' => $reconciled ? $amountMinor : null,
            'status' => $reconciled ? 'reconciled' : 'received_by_logistics',
            'collected_at' => now()->subDays(9),
            'collected_by' => $rider->id,
            'logistics_reference' => 'CODH-'.Str::upper(Str::random(10)),
            'logistics_received_at' => now()->subDays(9),
            'finance_remittance_reference' => $reconciled ? 'L2F-'.Str::upper(Str::random(10)) : null,
            'remitted_to_finance_at' => $reconciled ? now()->subDays(9) : null,
            'finance_reference' => $reconciled ? 'BFR-'.Str::upper(Str::random(10)) : null,
            'reconciled_at' => $reconciled ? now()->subDays(9) : null,
            'reconciled_by' => $reconciled ? $this->admin->id : null,
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
