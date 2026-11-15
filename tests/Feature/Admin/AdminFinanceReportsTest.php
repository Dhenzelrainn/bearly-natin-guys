<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Order;
use App\Models\PlatformCommission;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFinanceReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@bearly.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_invalid_report_filters_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports', ['from' => '2026-09-30', 'to' => '2026-09-01']))
            ->assertRedirect()
            ->assertSessionHasErrors(['to']);

        $this->actingAs($this->admin)
            ->get(route('admin.reports', ['from' => 'yesterday']))
            ->assertRedirect()
            ->assertSessionHasErrors(['from']);

        $this->actingAs($this->admin)
            ->get(route('admin.reports', ['type' => 'not_a_report']))
            ->assertRedirect()
            ->assertSessionHasErrors(['type']);

        $this->actingAs($this->admin)
            ->get(route('admin.reports', ['seller' => 999999]))
            ->assertRedirect()
            ->assertSessionHasErrors(['seller']);

        $this->actingAs($this->admin)
            ->get(route('admin.reports', ['category' => 999999]))
            ->assertRedirect()
            ->assertSessionHasErrors(['category']);
    }

    public function test_sales_summary_filters_completed_orders_inclusively(): void
    {
        [, , $store] = $this->sellerStore('Summary Store');

        $this->sellerOrder($this->order('ORD-SUM-START'), $store, '2026-09-01 00:00:00', [
            ['subtotal_minor' => 100_000, 'quantity' => 1],
        ]);
        $boundary = $this->sellerOrder($this->order('ORD-SUM-END'), $store, '2026-09-30 23:59:59', [
            ['subtotal_minor' => 50_000, 'quantity' => 2, 'discount_minor' => 10_000],
        ]);
        $this->completedRefund($boundary, 'REF-SUM-END', 10_000, [10_000], '2026-09-20 10:00:00');

        $this->sellerOrder($this->order('ORD-SUM-BEFORE'), $store, '2026-08-31 23:59:59', [
            ['subtotal_minor' => 999_900, 'quantity' => 7],
        ]);
        $this->sellerOrder($this->order('ORD-SUM-AFTER'), $store, '2026-10-01 00:00:00', [
            ['subtotal_minor' => 300_000, 'quantity' => 1],
        ]);
        $this->sellerOrder($this->order('ORD-SUM-CANCELLED'), $store, null, [
            ['subtotal_minor' => 777_700, 'quantity' => 1],
        ], 'cancelled');
        $this->sellerOrder($this->order('ORD-SUM-PENDING'), $store, null, [
            ['subtotal_minor' => 555_500, 'quantity' => 1],
        ], 'placed');

        $response = $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'sales_summary',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]));

        $response->assertOk()
            ->assertSee('Sales Summary')
            ->assertSee('September 2026')
            ->assertSee('not the Finance commission ledger')
            // KPIs: ₱1,500 gross product sales − ₱100 completed refund.
            ->assertSee('₱1,500.00')
            ->assertSee('₱1,300.00')
            ->assertSee('<strong>2</strong>', false)
            ->assertSee('<strong>3</strong>', false)
            // Inclusive first-day bucket and last-day bucket.
            ->assertSee('Sep 1 – Sep 3, 2026')
            ->assertSee('Sep 29 – Sep 30, 2026')
            ->assertSee('₱1,000.00')
            ->assertSee('₱400.00')
            // Refund-only buckets keep their true (negative) tooltip value.
            ->assertSee('data-value="−₱100.00"', false)
            ->assertDontSee('₱9,999.00')
            ->assertDontSee('₱3,000.00')
            ->assertDontSee('₱7,777.00')
            ->assertDontSee('₱5,555.00');
    }

    public function test_sales_by_seller_groups_stores_without_double_counting_multi_seller_orders(): void
    {
        [, $alphaProfile, $alphaStore] = $this->sellerStore('Alpha Seller Store');
        [, , $betaStore] = $this->sellerStore('Beta Seller Store');

        $sharedOrder = $this->order('ORD-SELLER-SHARED');
        $this->sellerOrder($sharedOrder, $alphaStore, '2026-09-10 10:00:00', [
            ['subtotal_minor' => 100_000, 'quantity' => 1],
        ]);
        $betaOrder = $this->sellerOrder($sharedOrder, $betaStore, '2026-09-10 10:00:00', [
            ['subtotal_minor' => 40_000, 'quantity' => 3, 'discount_minor' => 4_000],
        ]);
        $this->sellerOrder($this->order('ORD-SELLER-ALPHA'), $alphaStore, '2026-09-12 10:00:00', [
            ['subtotal_minor' => 20_000, 'quantity' => 1],
        ]);
        $this->completedRefund($betaOrder, 'REF-SELLER-BETA', 10_000, [10_000], '2026-09-13 10:00:00');

        $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'sales_seller',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertSee('Alpha Seller Store')
            ->assertSee('Beta Seller Store')
            // Marketplace KPIs: ₱1,200 + ₱400 gross, net of discounts/refunds.
            ->assertSee('₱1,600.00')
            ->assertSee('₱1,460.00')
            ->assertSee('₱1,200.00')
            ->assertSee('₱400.00')
            ->assertSee('₱260.00')
            ->assertSee('75.0%')
            ->assertSee('25.0%')
            // The shared marketplace order is split per store, never doubled.
            ->assertDontSee('₱1,400.00')
            ->assertDontSee('₱2,800.00');

        $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'sales_seller',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'seller' => $alphaProfile->id,
        ]))
            ->assertOk()
            ->assertSee('Seller: Alpha Seller Store')
            ->assertSee('₱1,200.00')
            ->assertDontSee('₱1,600.00')
            ->assertDontSee('₱1,460.00')
            ->assertDontSee('₱400.00')
            ->assertDontSee('₱260.00')
            ->assertDontSee('25.0%');
    }

    public function test_sales_by_category_uses_purchase_time_snapshots_and_item_level_refunds(): void
    {
        [, , $store] = $this->sellerStore('Category Store');

        $categoryA = Category::query()->create(['name' => 'Report Category Alpha', 'slug' => 'report-category-alpha']);
        $categoryB = Category::query()->create(['name' => 'Report Category Beta', 'slug' => 'report-category-beta']);

        $sellerOrder = $this->sellerOrder($this->order('ORD-CAT-001'), $store, '2026-09-05 10:00:00', [
            [
                'subtotal_minor' => 100_000,
                'quantity' => 1,
                'category_id_snapshot' => $categoryA->id,
                'category_name_snapshot' => $categoryA->name,
            ],
            [
                'subtotal_minor' => 50_000,
                'quantity' => 2,
                'discount_minor' => 5_000,
                'category_id_snapshot' => $categoryB->id,
                'category_name_snapshot' => $categoryB->name,
            ],
            ['subtotal_minor' => 30_000, 'quantity' => 3],
        ]);
        $this->completedRefund($sellerOrder, 'REF-CAT-001', 14_000, [10_000, 4_000], '2026-09-06 10:00:00');

        $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'sales_category',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertSee('Report Category Alpha')
            ->assertSee('Report Category Beta')
            ->assertSee('Category not recorded')
            ->assertSee('No purchase-time category recorded')
            ->assertSee('Order counts can overlap')
            // KPIs: ₱1,800 gross − ₱50 discounts − ₱140 item refunds.
            ->assertSee('₱1,800.00')
            ->assertSee('₱1,610.00')
            ->assertSee('₱900.00')
            ->assertSee('₱410.00')
            ->assertSee('₱300.00')
            ->assertSee('55.6%')
            ->assertSee('27.8%')
            ->assertSee('16.7%');

        $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'sales_category',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'category' => $categoryB->id,
        ]))
            ->assertOk()
            ->assertSee('Category: Report Category Beta')
            ->assertSee('₱500.00')
            ->assertSee('₱410.00')
            ->assertDontSee('₱1,800.00')
            ->assertDontSee('₱1,610.00')
            ->assertDontSee('₱1,000.00')
            ->assertDontSee('₱900.00')
            ->assertDontSee('₱300.00');
    }

    public function test_commission_settlement_and_refund_reports_respect_the_selected_period(): void
    {
        [, $inProfile, $inStore] = $this->sellerStore('Period In Store');
        [, $outProfile, $outStore] = $this->sellerStore('Period Out Store');

        $inOrder = $this->sellerOrder($this->order('ORD-PER-COM-IN'), $inStore, '2026-09-10 10:00:00', [
            ['subtotal_minor' => 100_000, 'quantity' => 1],
        ]);
        $outOrder = $this->sellerOrder($this->order('ORD-PER-COM-OUT'), $outStore, '2026-08-10 10:00:00', [
            ['subtotal_minor' => 50_000, 'quantity' => 1],
        ]);

        $this->finalizedCommission($inOrder, 100_000, 10_000, '2026-09-10 10:00:00');
        $this->finalizedCommission($outOrder, 50_000, 5_000, '2026-08-10 10:00:00');

        SellerPayout::query()->create([
            'payout_no' => 'SPY-PER-IN',
            'seller_profile_id' => $inProfile->id,
            'period_start' => '2026-08-25',
            'period_end' => '2026-09-05',
            'gross_minor' => 100_000,
            'commission_minor' => 10_000,
            'adjustment_minor' => 0,
            'net_minor' => 90_000,
            'status' => 'pending',
            'due_at' => '2026-09-05',
        ]);
        SellerPayout::query()->create([
            'payout_no' => 'SPY-PER-OUT',
            'seller_profile_id' => $outProfile->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-08-01',
            'gross_minor' => 50_000,
            'commission_minor' => 5_000,
            'adjustment_minor' => 0,
            'net_minor' => 45_000,
            'status' => 'pending',
            'due_at' => '2026-08-01',
        ]);

        $this->completedRefund($inOrder, 'REF-PER-IN', 10_000, [10_000], '2026-09-14 10:00:00');
        $this->completedRefund($outOrder, 'REF-PER-OUT', 5_000, [5_000], '2026-08-15 10:00:00');

        $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'commission',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertSee('Period In Store')
            ->assertSee('₱1,000.00')
            ->assertSee('₱100.00')
            ->assertDontSee('Period Out Store')
            ->assertDontSee('₱500.00')
            ->assertDontSee('₱50.00');

        $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'settlement',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertSee('Period In Store')
            ->assertSee('Aug 25–Sep 5, 2026')
            ->assertSee('₱900.00')
            ->assertDontSee('Period Out Store')
            ->assertDontSee('₱450.00');

        $this->actingAs($this->admin)->get(route('admin.reports', [
            'type' => 'refund',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertSee('REF-PER-IN')
            ->assertSee('₱100.00')
            ->assertDontSee('REF-PER-OUT')
            ->assertDontSee('₱50.00');
    }

    public function test_csv_export_contains_the_full_filtered_dataset(): void
    {
        [, , $store] = $this->sellerStore('Export Store');

        $this->sellerOrder($this->order('ORD-EXP-IN'), $store, '2026-09-10 10:00:00', [
            ['subtotal_minor' => 100_000, 'quantity' => 2, 'discount_minor' => 5_000],
        ]);
        $this->sellerOrder($this->order('ORD-EXP-OUT'), $store, '2026-08-10 10:00:00', [
            ['subtotal_minor' => 900_000, 'quantity' => 9],
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.export', [
            'type' => 'sales_seller',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Sales by Seller', $csv);
        $this->assertStringContainsString('September 2026', $csv);
        $this->assertStringContainsString('Export Store', $csv);
        $this->assertStringContainsString('1,000.00', $csv);
        $this->assertStringContainsString('950.00', $csv);
        $this->assertStringNotContainsString('9,000.00', $csv);
    }

    public function test_reports_render_honest_empty_states(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('₱0.00')
            ->assertSee('No data')
            ->assertSee('No completed sales in this period')
            ->assertSee('No category sales yet');

        foreach ([
            'sales_seller' => 'No seller sales in this period',
            'sales_category' => 'No category sales in this period',
            'commission' => 'No finalized commission entries',
            'settlement' => 'No seller settlements in this period',
            'refund' => 'No refund cases in this period',
        ] as $type => $emptyCopy) {
            $this->actingAs($this->admin)->get(route('admin.reports', ['type' => $type]))
                ->assertOk()
                ->assertSee($emptyCopy);
        }
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

    private function order(string $orderNo): Order
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        return Order::query()->create([
            'order_no' => $orderNo,
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '09171234567',
            'address_line' => '1 Report Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => 0,
            'total_minor' => 0,
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, int|string|null>>  $items
     */
    private function sellerOrder(
        Order $order,
        Store $store,
        ?string $completedAt,
        array $items,
        string $status = 'completed',
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
            'shipping_fee_minor' => 0,
            'total_minor' => $subtotalMinor - $discountMinor,
            'completed_at' => $completedAt,
        ]);

        foreach (array_values($items) as $index => $item) {
            $sellerOrder->items()->create([
                'product_name' => 'Report Product '.$index,
                'variant_name' => 'Default',
                'sku' => 'RPT-'.$index.'-'.Str::upper(Str::random(4)),
                'unit_price_minor' => (int) $item['subtotal_minor'],
                'quantity' => (int) ($item['quantity'] ?? 1),
                'discount_minor' => (int) ($item['discount_minor'] ?? 0),
                'subtotal_minor' => (int) $item['subtotal_minor'],
                'category_id_snapshot' => $item['category_id_snapshot'] ?? null,
                'category_name_snapshot' => $item['category_name_snapshot'] ?? null,
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

    private function finalizedCommission(SellerOrder $sellerOrder, int $baseMinor, int $amountMinor, string $timestamp): PlatformCommission
    {
        return PlatformCommission::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'type' => 'sale',
            'base_amount_minor' => $baseMinor,
            'rate_bps' => 1000,
            'amount_minor' => $amountMinor,
            'status' => 'finalized',
            'calculated_at' => $timestamp,
            'finalized_at' => $timestamp,
        ]);
    }
}
