<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_sales_trend_uses_paid_orders_grouped_by_earliest_payment_date(): void
    {
        $this->paidOrder('ORD-TREND-001', 100_000, '2026-10-05 09:00:00');
        $this->paidOrder('ORD-TREND-002', 70_000, '2026-05-12 09:00:00');
        $this->paidOrder('ORD-TREND-003', 50_000, '2026-09-25 09:00:00');

        // Paid twice: the order must be counted once, at its earliest paid
        // payment (Aug), never summed per payment into October.
        $doublePaid = $this->paidOrder('ORD-TREND-004', 40_000, '2026-08-20 09:00:00');
        $this->payment('PMT-TREND-004B', $doublePaid, 40_000, '2026-10-01 09:00:00');

        $this->unpaidOrder('ORD-TREND-005', 999_000);

        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // Monthly buckets: 12M view plus the truthfully relabeled 3M view.
        $this->assertSame(2, substr_count($html, 'aria-label="Oct 2026: ₱1,000.00"'), 'October must hold only the single ₱1,000 order.');
        $this->assertSame(1, substr_count($html, 'aria-label="May 2026: ₱700.00"'), 'May is outside the 3M window.');
        $this->assertSame(2, substr_count($html, 'aria-label="Sep 2026: ₱500.00"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Aug 2026: ₱400.00"'));

        // Daily buckets: the last 30 days are real days, not one month bucket.
        $this->assertSame(1, substr_count($html, 'aria-label="Oct 5, 2026: ₱1,000.00"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Sep 25, 2026: ₱500.00"'));
        $this->assertSame(1, substr_count($html, 'aria-label="Oct 17, 2026: ₱0.00"'));
        $this->assertStringNotContainsString('aria-label="May 12, 2026', $html);

        // Unpaid orders never enter the trend.
        $this->assertStringNotContainsString('₱9,990.00', $html);

        // Axis scale is derived from the real series (₱1,000 max ⇒ ₱1.5K top).
        $this->assertStringContainsString('<span>₱1.5K</span>', $html);
        $this->assertStringContainsString('<span>₱0</span>', $html);

        // 12M (12) + 3M (3) + 30D (30) tooltip-capable bars.
        $this->assertSame(45, substr_count($html, 'data-chart-point'));
        $this->assertStringContainsString('data-dashboard-period="12" aria-pressed="true"', $html);
        $this->assertStringContainsString('data-dashboard-period="30"', $html);
    }

    public function test_sales_trend_shows_an_honest_empty_state_without_paid_orders(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('No paid sales recorded yet', $html);
        $this->assertStringNotContainsString('data-dashboard-period-view', $html);
        $this->assertStringNotContainsString('data-chart-point', $html);
    }

    private function paidOrder(string $orderNo, int $totalMinor, string $paidAt): Order
    {
        $order = $this->order($orderNo, $totalMinor, 'completed');
        $this->payment('PMT-'.$orderNo, $order, $totalMinor, $paidAt);

        return $order;
    }

    private function unpaidOrder(string $orderNo, int $totalMinor): Order
    {
        $order = $this->order($orderNo, $totalMinor, 'pending');
        $this->payment('PMT-'.$orderNo, $order, $totalMinor, null);

        return $order;
    }

    private function order(string $orderNo, int $totalMinor, string $status): Order
    {
        return Order::create([
            'order_no' => $orderNo,
            'buyer_id' => $this->buyer->id,
            'recipient_name' => $this->buyer->name,
            'recipient_phone' => '09171234567',
            'address_line' => '1 Trend Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => $totalMinor,
            'total_minor' => $totalMinor,
            'status' => $status,
            'payment_status' => $status === 'completed' ? 'paid' : 'pending',
            'placed_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
    }

    private function payment(string $paymentNo, Order $order, int $amountMinor, ?string $paidAt): Payment
    {
        return Payment::create([
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
