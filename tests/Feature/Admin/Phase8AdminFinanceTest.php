<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformCommission;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase8AdminFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_finance_pages_are_database_backed_and_zero_safe(): void
    {
        $this->actingAs($this->admin)->get(route('admin.commissions'))
            ->assertOk()
            ->assertSee('₱0.00')
            ->assertDontSee('Mara Home Goods');

        $this->actingAs($this->admin)->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('0</strong><small>Ledger entries', false)
            ->assertDontSee('TXN-260824-0192');

        $this->actingAs($this->admin)->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('0/0')
            ->assertDontSee('PAY-2608-041');

        $this->actingAs($this->admin)->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('₱0.00')
            ->assertSee('No data');
    }

    public function test_completed_money_movements_drive_ledgers_reports_and_payouts(): void
    {
        [$profile, $store, $order, $sellerOrder, $payment] = $this->commerceFixture();

        $saleCommission = PlatformCommission::create([
            'seller_order_id' => $sellerOrder->id,
            'type' => 'sale',
            'base_amount_minor' => 100000,
            'rate_bps' => 1000,
            'amount_minor' => 10000,
            'status' => 'finalized',
            'calculated_at' => now(),
            'finalized_at' => now(),
        ]);
        SellerTransaction::create([
            'transaction_no' => 'STX-SALE-001',
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'commission_id' => $saleCommission->id,
            'type' => 'sale',
            'amount_minor' => 90000,
            'status' => 'posted',
            'available_at' => now(),
            'posted_at' => now(),
        ]);

        $completedReturn = $this->returnRequest($order, $sellerOrder, $store, 'RET-COMPLETE-001', 10000);
        $completedRefund = Refund::create([
            'refund_no' => 'REF-COMPLETE-001',
            'return_request_id' => $completedReturn->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'status' => 'completed',
            'method' => 'gcash',
            'approved_at' => now(),
            'processed_at' => now(),
            'completed_at' => now(),
        ]);
        $completedReversal = PlatformCommission::create([
            'seller_order_id' => $sellerOrder->id,
            'refund_id' => $completedRefund->id,
            'type' => 'refund_reversal',
            'base_amount_minor' => 10000,
            'rate_bps' => 1000,
            'amount_minor' => 1000,
            'status' => 'finalized',
            'calculated_at' => now(),
            'finalized_at' => now(),
        ]);
        SellerTransaction::create([
            'transaction_no' => 'STX-REFUND-001',
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'refund_id' => $completedRefund->id,
            'commission_id' => $completedReversal->id,
            'type' => 'refund_reversal',
            'amount_minor' => -9000,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $approvedReturn = $this->returnRequest($order, $sellerOrder, $store, 'RET-APPROVED-001', 20000);
        $approvedRefund = Refund::create([
            'refund_no' => 'REF-APPROVED-001',
            'return_request_id' => $approvedReturn->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_minor' => 20000,
            'status' => 'approved',
            'method' => 'gcash',
            'approved_at' => now(),
        ]);
        $approvedReversal = PlatformCommission::create([
            'seller_order_id' => $sellerOrder->id,
            'refund_id' => $approvedRefund->id,
            'type' => 'refund_reversal',
            'base_amount_minor' => 20000,
            'rate_bps' => 1000,
            'amount_minor' => 2000,
            'status' => 'estimated',
            'calculated_at' => now(),
        ]);
        SellerTransaction::create([
            'transaction_no' => 'STX-REFUND-PENDING',
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'refund_id' => $approvedRefund->id,
            'commission_id' => $approvedReversal->id,
            'type' => 'refund_reversal',
            'amount_minor' => -18000,
            'status' => 'pending',
        ]);

        SellerPayout::create([
            'payout_no' => 'PAY-DB-001',
            'seller_profile_id' => $profile->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
            'gross_minor' => 100000,
            'commission_minor' => 9000,
            'adjustment_minor' => -10000,
            'net_minor' => 81000,
            'status' => 'processing',
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.commissions'))
            ->assertOk()
            ->assertSee('Phase 8 Store')
            ->assertSee('₱900.00')
            ->assertSee('₱90.00');

        $this->actingAs($this->admin)->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('STX-SALE-001')
            ->assertSee('Completed')
            ->assertSee('STX-REFUND-001')
            ->assertSee('Refunded')
            ->assertSee('STX-REFUND-PENDING')
            ->assertSee('Pending');

        $this->actingAs($this->admin)->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('PAY-DB-001')
            ->assertSee('Processing')
            ->assertSee('disabled title=', false);

        $this->actingAs($this->admin)->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('₱900.00')
            ->assertSee('₱90.00')
            ->assertSee('REF-APPROVED-001')
            ->assertSee('Approved');
    }

    public function test_payout_page_has_no_client_authoritative_finance_state(): void
    {
        $script = file_get_contents(resource_path('js/admin.js'));

        $this->assertStringNotContainsString('bearlyAdminPaymentStatuses', $script);
        $this->assertStringNotContainsString('Payment status saved', $script);
        $this->assertStringNotContainsString('DEMO-', $script);
    }

    private function commerceFixture(): array
    {
        $buyer = User::factory()->create(['name' => 'Phase 8 Buyer', 'role' => 'buyer', 'status' => 'active']);
        $seller = User::factory()->create(['name' => 'Phase 8 Seller', 'role' => 'seller', 'status' => 'active']);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Phase 8 Store',
            'approved_category_id' => Category::firstOrFail()->id,
            'approved_at' => now(),
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Phase 8 Store',
            'slug' => 'phase-8-store',
            'publication_status' => 'published',
        ]);
        $order = Order::create([
            'order_no' => 'ORD-PHASE8-001',
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '09171234567',
            'address_line' => '1 Finance Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => 100000,
            'total_minor' => 100000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'placed_at' => now(),
            'completed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_no' => 'SORD-PHASE8-001',
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => 100000,
            'total_minor' => 100000,
            'completed_at' => now(),
        ]);
        $payment = Payment::create([
            'payment_no' => 'PMT-PHASE8-001',
            'order_id' => $order->id,
            'method' => 'gcash',
            'amount_minor' => 100000,
            'status' => 'paid',
            'initiated_at' => now(),
            'paid_at' => now(),
        ]);

        return [$profile, $store, $order, $sellerOrder, $payment];
    }

    private function returnRequest(
        Order $order,
        SellerOrder $sellerOrder,
        Store $store,
        string $number,
        int $amountMinor,
    ): ReturnRequest {
        return ReturnRequest::create([
            'return_no' => $number,
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $store->id,
            'request_type' => 'refund_only',
            'reason_code' => 'not_as_described',
            'status' => 'approved',
            'requested_amount_minor' => $amountMinor,
            'submitted_at' => now(),
        ]);
    }
}
