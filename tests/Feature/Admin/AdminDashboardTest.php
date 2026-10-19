<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
        $this->buyer = User::factory()->create(['name' => 'Trend Buyer', 'role' => 'buyer', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sales_trend_uses_net_completed_seller_order_items_by_completion_date(): void
    {
        [, , $store] = $this->sellerStore('Trend Store');

        $october = $this->sellerOrder($this->order('ORD-TREND-OCT'), $store, '2026-10-05 09:00:00', [
            ['subtotal_minor' => 100_000, 'quantity' => 2, 'discount_minor' => 10_000],
        ], shippingFeeMinor: 20_000);

        // Two paid payments must not duplicate the sale; the trend never uses
        // payments as its sales source.
        $this->payment('PMT-TREND-OCT-A', $october->order, 120_000, '2026-10-05 10:00:00');
        $this->payment('PMT-TREND-OCT-B', $october->order, 20_000, '2026-10-06 10:00:00');

        // Refund attributed by its own completion date, not the order date.
        $this->completedRefund($october, 'REF-TREND-OCT', 5_000, [5_000], '2026-10-10 10:00:00');

        $this->sellerOrder($this->order('ORD-TREND-SEP'), $store, '2026-09-25 09:00:00', [
            ['subtotal_minor' => 50_000, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-TREND-NOV'), $store, '2025-11-15 09:00:00', [
            ['subtotal_minor' => 25_000, 'quantity' => 1],
        ]);

        // Just before the 12M window (Nov 2025 – Oct 2026) must stay out.
        $this->sellerOrder($this->order('ORD-TREND-OLD'), $store, '2025-10-31 23:59:59', [
            ['subtotal_minor' => 999_900, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-TREND-CANCELLED'), $store, '2026-10-07 09:00:00', [
            ['subtotal_minor' => 777_700, 'quantity' => 1],
        ], 'cancelled');
        $this->sellerOrder($this->order('ORD-TREND-PENDING'), $store, null, [
            ['subtotal_minor' => 555_500, 'quantity' => 1],
        ], 'pending');

        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // Monthly net: Oct ₱1,000 gross − ₱100 discount − ₱50 refund; Sep ₱500;
        // Nov 2025 ₱250. 12M + 3M both show the current three months.
        $this->assertSame(2, substr_count($html, 'aria-label="Oct 2026: ₱850.00"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Sep 2026: ₱500.00"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Nov 2025: ₱250.00"'));

        // Daily buckets keep the gross day and the refund-only day separate.
        $this->assertSame(1, substr_count($html, 'aria-label="Oct 5, 2026: ₱900.00"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Oct 10, 2026: −₱50.00"'));

        $this->assertStringNotContainsString('aria-label="Oct 2025: ₱9,999.00"', $html);
        $this->assertStringNotContainsString('₱9,999.00', $html);
        $this->assertStringNotContainsString('₱7,777.00', $html);
        $this->assertStringNotContainsString('₱5,555.00', $html);

        // Negative net values use an honest symmetric axis and are not clamped.
        $this->assertStringContainsString('has-negative', $html);
        $this->assertStringContainsString('<span>−₱1K</span>', $html);
        $this->assertStringContainsString('data-value="−₱50.00"', $html);

        // 12M (12) + 3M (3) + 30D (30) tooltip-capable periods.
        $this->assertSame(45, substr_count($html, 'data-chart-point'));
        $this->assertStringContainsString('Net product sales from completed seller orders, grouped by completion date.', $html);
    }

    public function test_sales_trend_period_windows_are_calendar_aligned_and_inclusive(): void
    {
        [, , $store] = $this->sellerStore('Boundary Store');

        // 12M starts Nov 1, 2025 and 3M starts Aug 1, 2026.
        $this->sellerOrder($this->order('ORD-BOUND-12M-START'), $store, '2025-11-01 00:00:00', [
            ['subtotal_minor' => 110_000, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-BOUND-12M-BEFORE'), $store, '2025-10-31 23:59:59', [
            ['subtotal_minor' => 101_000, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-BOUND-3M-START'), $store, '2026-08-01 00:00:00', [
            ['subtotal_minor' => 800_000, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-BOUND-3M-BEFORE'), $store, '2026-07-31 23:59:59', [
            ['subtotal_minor' => 700_000, 'quantity' => 1],
        ]);

        // 30D starts Sep 18, 2026 and ends at the end of today.
        $this->sellerOrder($this->order('ORD-BOUND-30D-START'), $store, '2026-09-18 00:00:00', [
            ['subtotal_minor' => 918_000, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-BOUND-30D-BEFORE'), $store, '2026-09-17 23:59:59', [
            ['subtotal_minor' => 917_000, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-BOUND-TODAY'), $store, '2026-10-17 23:59:59', [
            ['subtotal_minor' => 1_017_000, 'quantity' => 1],
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // 12M is the current month plus the previous 11 calendar months.
        $this->assertSame(1, substr_count($html, 'aria-label="Nov 2025: ₱1,100.00"'));
        $this->assertStringNotContainsString('aria-label="Oct 2025: ₱1,010.00"', $html);
        $this->assertSame(1, substr_count($html, 'aria-label="Jul 2026: ₱7,000.00"'));

        // 3M includes August and both September dates but not July.
        $this->assertSame(2, substr_count($html, 'aria-label="Aug 2026: ₱8,000.00"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Sep 2026: ₱18,350.00"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Oct 2026: ₱10,170.00"'));

        // 30D includes Sep 18 00:00, excludes Sep 17 23:59, and includes today.
        $this->assertSame(1, substr_count($html, 'aria-label="Sep 18, 2026: ₱9,180.00"'));
        $this->assertStringNotContainsString('aria-label="Sep 17, 2026:', $html);
        $this->assertSame(1, substr_count($html, 'aria-label="Oct 17, 2026: ₱10,170.00"'));

        $this->assertSame(45, substr_count($html, 'data-chart-point'));

        // Buttons and chart views share the same canonical period keys.
        $this->assertStringContainsString('data-dashboard-period="12M" aria-pressed="true"', $html);
        $this->assertStringContainsString('data-dashboard-period="3M" aria-pressed="false"', $html);
        $this->assertStringContainsString('data-dashboard-period="30D" aria-pressed="false"', $html);
        $this->assertStringContainsString('data-dashboard-period-view="12M"', $html);
        $this->assertMatchesRegularExpression('/data-dashboard-period-view="3M"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/data-dashboard-period-view="30D"\s+hidden/', $html);
        $this->assertSame(3, substr_count($html, 'data-dashboard-period-view='));
        $this->assertStringNotContainsString('data-dashboard-period="12"', $html);
        $this->assertStringNotContainsString('data-dashboard-period="3"', $html);
        $this->assertStringNotContainsString('data-dashboard-period="30"', $html);
    }

    public function test_sales_trend_counts_multi_seller_orders_once_without_shipping_or_double_counting(): void
    {
        [, , $alphaStore] = $this->sellerStore('Alpha Trend Store');
        [, , $betaStore] = $this->sellerStore('Beta Trend Store');

        $sharedOrder = $this->order('ORD-TREND-SHARED', 140_000, 140_000);

        $this->sellerOrder($sharedOrder, $alphaStore, '2026-10-06 10:00:00', [
            ['subtotal_minor' => 40_000, 'quantity' => 1],
        ], shippingFeeMinor: 15_000);
        $this->sellerOrder($sharedOrder, $betaStore, '2026-10-06 10:00:00', [
            ['subtotal_minor' => 60_000, 'quantity' => 2, 'discount_minor' => 4_000],
        ], shippingFeeMinor: 25_000);

        // Payment records are collections, not sales: two paid payments for the
        // same marketplace order must not duplicate the trend.
        $this->payment('PMT-TREND-SHARED-A', $sharedOrder, 123_400, '2026-10-06 11:00:00');
        $this->payment('PMT-TREND-SHARED-B', $sharedOrder, 10_000, '2026-10-06 12:00:00');

        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // ₱400 + (₱600 − ₱40) = ₱960, never the ₱1,400 order total (shipping
        // included) and never doubled per Seller Order or payment.
        $this->assertSame(2, substr_count($html, 'aria-label="Oct 2026: ₱960.00"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Oct 6, 2026: ₱960.00"'));
        $this->assertStringNotContainsString('aria-label="Oct 2026: ₱1,400.00"', $html);
        $this->assertStringNotContainsString('aria-label="Oct 2026: ₱2,800.00"', $html);

        // Paid Collections is now explicitly a payment-date KPI.
        $this->assertStringContainsString('<p>Paid Collections</p>', $html);
        $this->assertStringContainsString('₱1,334.00', $html);
        $this->assertStringContainsString('2 payments marked paid', $html);
        $this->assertStringContainsString('Payments marked paid this year, by payment date.', $html);
        $this->assertStringNotContainsString('<p>Gross Sales</p>', $html);
    }

    public function test_sales_trend_shows_negative_net_sales_in_a_refund_only_period(): void
    {
        [, , $store] = $this->sellerStore('Refund Only Store');

        $july = $this->sellerOrder($this->order('ORD-TREND-REFUND'), $store, '2026-07-31 23:59:59', [
            ['subtotal_minor' => 50_000, 'quantity' => 1],
        ]);
        $this->completedRefund($july, 'REF-TREND-ONLY', 5_000, [5_000], '2026-10-10 10:00:00');

        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // October has no completion but the refund makes the period negative.
        $this->assertSame(2, substr_count($html, 'aria-label="Oct 2026: −₱50.00"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Oct 10, 2026: −₱50.00"'));
        $this->assertStringContainsString('data-value="−₱50.00"', $html);
        $this->assertStringNotContainsString('No net product sales recorded in the last 3 months.', $html);
        $this->assertStringContainsString('<span>−₱50</span>', $html);
        $this->assertStringContainsString('--zero-position: 50%', $html);
    }

    public function test_sales_trend_shows_an_honest_empty_state_without_completed_sales(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('No net product sales recorded yet', $html);
        $this->assertStringContainsString('Net product sales appear here after seller orders complete.', $html);
        $this->assertStringNotContainsString('data-dashboard-period-view', $html);
        $this->assertStringNotContainsString('data-chart-point', $html);

        // The controls keep canonical keys even when the global empty state shows.
        $this->assertStringContainsString('data-dashboard-period="12M" aria-pressed="true"', $html);
        $this->assertStringContainsString('data-dashboard-period="3M" aria-pressed="false"', $html);
        $this->assertStringContainsString('data-dashboard-period="30D" aria-pressed="false"', $html);
    }

    /**
     * @return array{0: User, 1: SellerProfile, 2: Store}
     */
    private function sellerStore(string $name): array
    {
        $seller = User::factory()->create(['name' => $name, 'role' => 'seller', 'status' => 'active']);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => $name,
            'approved_category_id' => Category::query()->firstOrFail()->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'publication_status' => 'published',
        ]);

        return [$seller, $profile, $store];
    }

    private function order(
        string $orderNo,
        int $subtotalMinor = 0,
        int $totalMinor = 0,
        string $status = 'processing',
        string $paymentStatus = 'unpaid',
    ): Order {
        return Order::query()->create([
            'order_no' => $orderNo,
            'buyer_id' => $this->buyer->id,
            'recipient_name' => $this->buyer->name,
            'recipient_phone' => '09171234567',
            'address_line' => '1 Trend Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => $subtotalMinor,
            'total_minor' => $totalMinor,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'placed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, int>>  $items
     */
    private function sellerOrder(
        Order $order,
        Store $store,
        ?string $completedAt,
        array $items,
        string $status = 'completed',
        int $shippingFeeMinor = 0,
    ): SellerOrder {
        $subtotalMinor = (int) array_sum(array_map(
            fn (array $item): int => (int) $item['subtotal_minor'],
            $items,
        ));
        $discountMinor = (int) array_sum(array_map(
            fn (array $item): int => (int) ($item['discount_minor'] ?? 0),
            $items,
        ));

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SORD-'.Str::upper(Str::random(8)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => $subtotalMinor,
            'discount_minor' => $discountMinor,
            'shipping_fee_minor' => $shippingFeeMinor,
            'total_minor' => $subtotalMinor - $discountMinor + $shippingFeeMinor,
            'completed_at' => $completedAt,
        ]);

        foreach (array_values($items) as $index => $item) {
            $quantity = (int) ($item['quantity'] ?? 1);

            $sellerOrder->items()->create([
                'product_name' => 'Trend Product '.$index,
                'variant_name' => 'Default',
                'sku' => 'TRD-'.$index.'-'.Str::upper(Str::random(4)),
                'unit_price_minor' => intdiv((int) $item['subtotal_minor'], max(1, $quantity)),
                'quantity' => $quantity,
                'discount_minor' => (int) ($item['discount_minor'] ?? 0),
                'subtotal_minor' => (int) $item['subtotal_minor'],
            ]);
        }

        return $sellerOrder;
    }

    /**
     * @param  array<int, int>  $allocations  Refund allocation per item, by item order.
     */
    private function completedRefund(
        SellerOrder $sellerOrder,
        string $refundNo,
        int $amountMinor,
        array $allocations,
        string $timestamp,
    ): Refund {
        $order = $sellerOrder->order;

        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper(Str::random(8)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $sellerOrder->store_id,
            'request_type' => 'refund_only',
            'reason_code' => 'not_as_described',
            'status' => 'completed',
            'requested_amount_minor' => $amountMinor,
            'submitted_at' => $timestamp,
        ]);

        $items = $sellerOrder->items()->orderBy('id')->get();

        foreach (array_values($allocations) as $index => $allocation) {
            ReturnItem::query()->create([
                'return_request_id' => $returnRequest->id,
                'order_item_id' => $items[$index]->id,
                'quantity' => 1,
                'refund_amount_minor' => $allocation,
            ]);
        }

        return Refund::query()->create([
            'refund_no' => $refundNo,
            'return_request_id' => $returnRequest->id,
            'order_id' => $order->id,
            'amount_minor' => $amountMinor,
            'status' => 'completed',
            'method' => 'gcash',
            'approved_at' => $timestamp,
            'processed_at' => $timestamp,
            'completed_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
    }

    private function payment(string $paymentNo, Order $order, int $amountMinor, ?string $paidAt): Payment
    {
        return Payment::query()->create([
            'payment_no' => $paymentNo,
            'order_id' => $order->id,
            'method' => 'gcash',
            'amount_minor' => $amountMinor,
            'status' => $paidAt === null ? 'pending' : 'paid',
            'initiated_at' => now(),
            'paid_at' => $paidAt,
        ]);
    }
}
