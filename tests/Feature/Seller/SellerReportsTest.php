<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PlatformCommission;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerPayoutItem;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\SellerWalletTransaction;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use App\Services\SellerEarningsService;
use App\Services\SellerWalletService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_reports_and_dashboard_pages_are_seller_scoped(): void
    {
        [$sellerA, $profileA, $storeA] = $this->sellerFixture('Alpha Seller', 'alpha-seller');
        [, $profileB, $storeB] = $this->sellerFixture('Beta Seller', 'beta-seller');

        $saleA = $this->createSale($storeA, [
            ['name' => 'Alpha Basket', 'sku' => 'AB-001', 'unit_minor' => 100_000],
        ]);
        $this->createLedgerSale($profileA, $saleA[1], $saleA[2], baseMinor: 100_000, commissionMinor: 10_000);

        $saleB = $this->createSale($storeB, [
            ['name' => 'Beta Lamp', 'sku' => 'BL-001', 'unit_minor' => 55_000],
        ]);
        $this->createLedgerSale($profileB, $saleB[1], $saleB[2], baseMinor: 55_000, commissionMinor: 5_500);

        $this->actingAs($sellerA)->get(route('seller.reports'))
            ->assertOk()
            ->assertSee('Alpha Basket', false)
            ->assertDontSee('Beta Lamp', false);

        $this->actingAs($sellerA)->get(route('seller.reports.sales'))
            ->assertOk()
            ->assertSee('Alpha Basket', false)
            ->assertDontSee('Beta Lamp', false);

        $this->actingAs($sellerA)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('Alpha Basket', false)
            ->assertDontSee('Beta Lamp', false)
            ->assertDontSee('₱495.00');

        $this->actingAs($sellerA)->get(route('seller.reports.financial'))
            ->assertOk()
            ->assertSee('₱1,000.00')
            ->assertDontSee('₱550.00');

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $this->actingAs($buyer)->get(route('seller.reports'))->assertForbidden();
        $this->actingAs($buyer)->get(route('seller.reports.sales.export'))->assertForbidden();
        $this->actingAs($buyer)->get(route('seller.reports.financial'))->assertForbidden();
    }

    public function test_report_date_input_is_validated_normalized_and_inclusive(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Date Seller', 'date-seller');
        $earnings = app(SellerEarningsService::class);

        $this->createSale($store, [
            ['name' => 'Start Boundary Item', 'sku' => 'SB-001', 'unit_minor' => 55_000],
        ], completedAt: CarbonImmutable::parse('2026-08-05 00:00:00'));
        $this->createSale($store, [
            ['name' => 'End Boundary Item', 'sku' => 'EB-001', 'unit_minor' => 100_000],
        ], completedAt: CarbonImmutable::parse('2026-08-20 23:30:00'));
        $this->createSale($store, [
            ['name' => 'Outside Item', 'sku' => 'OU-001', 'unit_minor' => 30_000],
        ], completedAt: CarbonImmutable::parse('2026-08-21 00:10:00'));

        // Invalid input falls back to the current calendar month.
        $this->actingAs($seller)->get(route('seller.reports', ['from' => 'not-a-date', 'to' => '2026-99-99']))
            ->assertOk()
            ->assertSee(CarbonImmutable::now()->format('F Y'));

        // Reversed ranges are normalized instead of failing.
        $this->actingAs($seller)->get(route('seller.reports.sales', ['from' => '2026-08-20', 'to' => '2026-08-05']))
            ->assertOk()
            ->assertSee('Aug 5 – Aug 20, 2026');

        $bounded = $earnings->salesReportFor($profile, CarbonImmutable::parse('2026-08-05'), CarbonImmutable::parse('2026-08-20'));
        $this->assertSame(2, $bounded['totals']['orders']);
        $this->assertSame(155_000, $bounded['totals']['gross_minor']);
        $this->assertNull($bounded['change']['gross']);

        $empty = $earnings->salesReportFor($profile, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));
        $this->assertSame(0, $empty['totals']['orders']);
        $this->assertSame(0, $empty['totals']['gross_minor']);
        $this->assertSame(0, $empty['totals']['average_order_minor']);
        $this->assertSame([], $empty['products']);
        $this->assertNull($empty['change']['gross']);

        $this->actingAs($seller)->get(route('seller.reports.sales', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertSee('No completed sales yet. Rankings appear once orders complete.')
            ->assertSee('No product sales yet')
            ->assertSee('₱0.00');
    }

    public function test_financial_money_math_does_not_double_count_refunds_or_commission_reversals(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Ledger Seller', 'ledger-seller');

        $partialSale = $this->createSale($store, [
            ['name' => 'Partial Refund Item', 'sku' => 'PR-001', 'unit_minor' => 100_000],
        ], discountMinor: 10_000);
        $this->createLedgerSale($profile, $partialSale[1], $partialSale[2], baseMinor: 90_000, commissionMinor: 9_000, amountMinor: 81_000);
        $partialRefund = $this->createRefund($partialSale, 10_000);
        $this->createRefundLedger($profile, $partialRefund, reversalMinor: 1_000, transactionMinor: -9_000);

        $fullSale = $this->createSale($store, [
            ['name' => 'Full Refund Item', 'sku' => 'FR-001', 'unit_minor' => 100_000],
        ], discountMinor: 10_000);
        $this->createLedgerSale($profile, $fullSale[1], $fullSale[2], baseMinor: 90_000, commissionMinor: 9_000, amountMinor: 81_000);
        $fullRefund = $this->createRefund($fullSale, 90_000);
        $this->createRefundLedger($profile, $fullRefund, reversalMinor: 9_000, transactionMinor: -81_000);

        $earnings = app(SellerEarningsService::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = CarbonImmutable::now()->endOfMonth();
        $data = $earnings->financialReportFor($profile, $from, $to);
        $summary = $data['summary'];

        $this->assertSame(200_000, $summary['gross_sales_minor']);
        $this->assertSame(20_000, $summary['seller_discounts_minor']);
        $this->assertSame(100_000, $summary['refunds_minor']);
        $this->assertSame(80_000, $summary['commissionable_sales_minor']);
        $this->assertSame(8_000, $summary['net_commission_minor']);
        $this->assertSame(72_000, $summary['net_earnings_minor']);
        $this->assertSame(128_000, $summary['total_deductions_minor']);
        $this->assertSame(64.0, $data['deduction_rate']);
        $this->assertSame(['label' => 'Posted', 'key' => 'posted'], $data['statement_status']);
        $this->assertSame(6.3, $data['deductions'][0]['percent']);
        $this->assertSame(15.6, $data['deductions'][1]['percent']);
        $this->assertSame(78.1, $data['deductions'][2]['percent']);

        $rows = $earnings->transactionsFor($profile);

        $saleRow = $rows->firstWhere('gross_minor', 100_000);
        $this->assertSame(90_000, $saleRow['commissionable_minor']);
        $this->assertSame(9_000, $saleRow['commission_minor']);
        $this->assertSame(0, $saleRow['commission_reversal_minor']);
        $this->assertSame(0, $saleRow['refund_minor']);
        $this->assertSame(81_000, $saleRow['net_minor']);

        $partialRow = $rows->firstWhere('refund_minor', 10_000);
        $this->assertSame('Reversed', $partialRow['status']);
        $this->assertSame(1_000, $partialRow['commission_reversal_minor']);
        $this->assertSame(-9_000, $partialRow['net_minor']);

        $fullRow = $rows->firstWhere('refund_minor', 90_000);
        $this->assertSame(9_000, $fullRow['commission_reversal_minor']);
        $this->assertSame(-81_000, $fullRow['net_minor']);

        // Refunds are counted from refund amounts only: 100,000 total, never
        // 110,000 from adding the commission reversals on top.
        $this->assertSame(100_000, $summary['refunds_minor']);

        $this->actingAs($seller)->get(route('seller.reports.financial'))
            ->assertOk()
            ->assertSee('₱2,000.00')
            ->assertSee('₱720.00')
            ->assertSee('report-status is-posted', false)
            ->assertDontSee('report-status is-reversed', false);
    }

    public function test_commission_reversal_larger_than_period_commission_renders_as_credit(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Reversal Seller', 'reversal-seller');

        $sale = $this->createSale($store, [
            ['name' => 'Reversal Basket', 'sku' => 'RV-001', 'unit_minor' => 100_000],
        ], completedAt: CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->createLedgerSale(
            $profile,
            $sale[1],
            $sale[2],
            baseMinor: 100_000,
            commissionMinor: 10_000,
            amountMinor: 90_000,
            postedAt: CarbonImmutable::parse('2026-07-15 13:00:00'),
        );

        // The original sale stays outside the selected period; only the
        // completed full refund and its commission reversal post in August.
        $refund = $this->createRefund($sale, 100_000, CarbonImmutable::parse('2026-08-05 12:00:00'));
        $this->createRefundLedger(
            $profile,
            $refund,
            reversalMinor: 10_000,
            transactionMinor: -90_000,
            postedAt: CarbonImmutable::parse('2026-08-05 13:00:00'),
        );

        $earnings = app(SellerEarningsService::class);
        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        $data = $earnings->financialReportFor($profile, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));

        $this->assertSame(0, $data['summary']['gross_sales_minor']);
        $this->assertSame(100_000, $data['summary']['refunds_minor']);
        $this->assertSame(-10_000, $data['summary']['net_commission_minor']);
        $this->assertSame(-90_000, $data['summary']['net_earnings_minor']);
        $this->assertSame(['label' => 'Reversed', 'key' => 'reversed'], $data['statement_status']);

        // With no gross sales in the period, the deduction rate has no
        // denominator and must be reported as unavailable, not as 0.0%.
        $this->assertNull($data['deduction_rate']);

        // The reversal is a credit, not a deduction: it must not take a
        // negative share of deductions, and refund shares must stay valid.
        $deductions = collect($data['deductions'])->keyBy('key');
        $this->assertTrue($deductions['commission']['is_credit']);
        $this->assertSame(0.0, $deductions['commission']['percent']);
        $this->assertSame(100.0, $deductions['refunds']['percent']);

        $this->actingAs($seller)->get(route('seller.reports.financial', $range))
            ->assertOk()
            ->assertSee('+₱100.00')
            ->assertSee('100.0% of deductions')
            ->assertSee('Credit — not a deduction this period.')
            // The commission row renders through the explicit credit kind -
            // never the deduction row style - and labels itself a credit.
            ->assertSee('Platform Commission (10%) · credit')
            ->assertSee('class="is-credit"', false)
            ->assertDontSee('class="is-deduction"><dt>Platform Commission', false)
            ->assertSee('<strong>—</strong><span>no gross sales to measure against</span>', false)
            ->assertDontSee('<strong>0.0%</strong>', false)
            ->assertDontSee('−−₱')
            ->assertDontSee('style="width:-', false)
            ->assertDontSee('-11.1');

        $csv = $this->actingAs($seller)->get(route('seller.reports.financial.export', $range))->streamedContent();
        $this->assertStringContainsString('"Platform Commission (credit)",100.00,0.0', $csv);
        $this->assertStringContainsString('Refunds,1000.00,100.0', $csv);
        $this->assertStringContainsString('"Platform Commission",+100.00', $csv);
        $this->assertStringContainsString('Refunds,-1000.00', $csv);
        $this->assertStringNotContainsString('-11.1', $csv);
        $this->assertStringNotContainsString('--', $csv);
        $this->assertStringNotContainsString('+-', $csv);
    }

    public function test_zero_gross_sales_reports_an_unavailable_deduction_rate(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('No Gross Seller', 'no-gross-seller');

        $sale = $this->createSale($store, [
            ['name' => 'Prior Period Item', 'sku' => 'NG-001', 'unit_minor' => 100_000],
        ], completedAt: CarbonImmutable::parse('2026-07-20 12:00:00'));
        $this->createLedgerSale(
            $profile,
            $sale[1],
            $sale[2],
            baseMinor: 100_000,
            commissionMinor: 10_000,
            amountMinor: 90_000,
            postedAt: CarbonImmutable::parse('2026-07-20 13:00:00'),
        );

        // Only the partial refund posts in August while the sale stays in
        // July: gross sales for the period are zero, so the deduction rate
        // has no denominator and must render as unavailable, not 0.0%.
        $refund = $this->createRefund($sale, 50_000, CarbonImmutable::parse('2026-08-06 12:00:00'));
        $this->createRefundLedger($profile, $refund, reversalMinor: 5_000, transactionMinor: -45_000, postedAt: CarbonImmutable::parse('2026-08-06 13:00:00'));

        $data = app(SellerEarningsService::class)->financialReportFor(
            $profile,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $this->assertSame(0, $data['summary']['gross_sales_minor']);
        $this->assertSame(50_000, $data['summary']['refunds_minor']);
        $this->assertNull($data['deduction_rate']);

        $this->actingAs($seller)
            ->get(route('seller.reports.financial', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('<strong>—</strong><span>no gross sales to measure against</span>', false)
            ->assertDontSee('<strong>0.0%</strong>', false);
    }

    public function test_cancelled_orders_and_unpaid_cod_are_not_recognized_earnings(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Honest Seller', 'honest-seller');

        $this->createSale($store, [
            ['name' => 'Cancelled Item', 'sku' => 'CX-001', 'unit_minor' => 50_000],
        ], status: 'cancelled');

        $codSale = $this->createSale($store, [
            ['name' => 'COD Item', 'sku' => 'COD-001', 'unit_minor' => 100_000],
        ], paymentMethod: 'cod', paymentStatus: 'pending');
        $this->createLedgerSale(
            $profile,
            $codSale[1],
            $codSale[2],
            baseMinor: 100_000,
            commissionMinor: 0,
            amountMinor: 100_000,
            withCommission: false,
        );

        $earnings = app(SellerEarningsService::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = CarbonImmutable::now()->endOfMonth();

        $sales = $earnings->salesReportFor($profile, $from, $to);
        $this->assertSame(1, $sales['totals']['orders']);
        $this->assertSame(100_000, $sales['totals']['gross_minor']);
        $this->assertNotContains('Cancelled Item', array_column($sales['products'], 'name'));

        $this->assertSame('Pending', $earnings->transactionsFor($profile)->first()['status']);

        $financial = $earnings->financialReportFor($profile, $from, $to);
        $this->assertSame(0, $financial['summary']['net_earnings_minor']);
        $this->assertSame(['label' => 'No activity', 'key' => 'none'], $financial['statement_status']);

        $fulfillment = $earnings->fulfillmentRateFor($profile, $from, $to);
        $this->assertSame(50.0, $fulfillment['rate']);
        $this->assertSame(1, $fulfillment['completed']);
        $this->assertSame(1, $fulfillment['cancelled']);
        $this->assertSame(0, $fulfillment['open']);

        // Wallet refresh must not present unpaid COD money as available.
        app(SellerWalletService::class)->refreshForSeller($profile);
        $this->assertSame(0, SellerWalletTransaction::query()
            ->where('type', 'sale_earning_available')
            ->count());

        $this->actingAs($seller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('₱0.00')
            ->assertSee('No prior-period data');
    }

    public function test_fulfillment_rate_uses_the_finalized_cohort_and_renders_on_dashboard(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Fulfill Seller', 'fulfill-seller');

        foreach (range(1, 3) as $index) {
            $this->createSale($store, [
                ['name' => 'Completed Item '.$index, 'sku' => 'FI-00'.$index, 'unit_minor' => 10_000],
            ]);
        }
        $this->createSale($store, [
            ['name' => 'Cancelled Cohort Item', 'sku' => 'FI-C', 'unit_minor' => 10_000],
        ], status: 'cancelled');
        $this->createSale($store, [
            ['name' => 'Open Cohort Item', 'sku' => 'FI-O', 'unit_minor' => 10_000],
        ], status: 'packing');

        $earnings = app(SellerEarningsService::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = CarbonImmutable::now()->endOfMonth();

        $result = $earnings->fulfillmentRateFor($profile, $from, $to);
        $this->assertSame(75.0, $result['rate']);
        $this->assertSame(3, $result['completed']);
        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(1, $result['open']);
        $this->assertNull($result['previous_rate']);

        $this->actingAs($seller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('Order Fulfillment Rate')
            ->assertSee('75.0%')
            ->assertSee('Cancellation Rate')
            ->assertSee('25.0%')
            ->assertSee('Good Standing');

        // A cohort with no finalized orders reports no rate instead of 0%.
        [$idleSeller, $idleProfile] = $this->sellerFixture('Idle Seller', 'idle-seller');
        $idle = $earnings->fulfillmentRateFor($idleProfile, $from, $to);
        $this->assertNull($idle['rate']);
        $this->assertSame(0, $idle['completed'] + $idle['cancelled']);

        $this->actingAs($idleSeller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('Order Fulfillment Rate')
            ->assertSee('—');
    }

    public function test_sales_metrics_follow_completion_date_and_finance_metrics_follow_posted_date(): void
    {
        [, $profile, $store] = $this->sellerFixture('Basis Seller', 'basis-seller');

        $firstSale = $this->createSale($store, [
            ['name' => 'July Order One', 'sku' => 'JO-001', 'unit_minor' => 100_000],
        ], completedAt: CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->createLedgerSale(
            $profile,
            $firstSale[1],
            $firstSale[2],
            baseMinor: 100_000,
            commissionMinor: 10_000,
            amountMinor: 90_000,
            postedAt: CarbonImmutable::parse('2026-08-03 09:00:00'),
        );

        $secondSale = $this->createSale($store, [
            ['name' => 'July Order Two', 'sku' => 'JO-002', 'unit_minor' => 50_000],
        ], completedAt: CarbonImmutable::parse('2026-07-20 12:00:00'));
        $this->createLedgerSale(
            $profile,
            $secondSale[1],
            $secondSale[2],
            baseMinor: 50_000,
            commissionMinor: 5_000,
            amountMinor: 45_000,
            postedAt: CarbonImmutable::parse('2026-08-31 23:59:00'),
        );

        $earnings = app(SellerEarningsService::class);
        $august = $earnings->overviewFor($profile, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));

        // Sales are attributed by completion date: these July orders stay out
        // of August, while the Finance ledger posted inside August is included.
        $this->assertSame(0, $august['totals']['orders']);
        $this->assertSame(0, $august['totals']['gross_minor']);
        $this->assertSame(150_000, $august['ledger']['gross_sales_minor']);
        $this->assertSame(15_000, $august['ledger']['net_commission_minor']);
        $this->assertSame(135_000, $august['ledger']['net_earnings_minor']);
        $this->assertNull($august['refund_rate']);

        $july = $earnings->overviewFor($profile, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));
        $this->assertSame(2, $july['totals']['orders']);
        $this->assertSame(150_000, $july['totals']['gross_minor']);
        $this->assertSame(0, $july['ledger']['net_earnings_minor']);
    }

    public function test_dashboard_period_filter_selects_real_ranges(): void
    {
        [$seller, , $store] = $this->sellerFixture('Period Seller', 'period-seller');

        $this->createSale($store, [
            ['name' => 'Current Month Item', 'sku' => 'CM-001', 'unit_minor' => 100_000],
        ], completedAt: CarbonImmutable::now()->startOfMonth()->addHours(5));

        $this->createSale($store, [
            ['name' => 'Previous Month Item', 'sku' => 'PM-001', 'unit_minor' => 50_000],
        ], completedAt: CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addHours(5));

        $this->actingAs($seller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('value="this_month" selected', false)
            ->assertSee('₱1,000.00')
            ->assertDontSee('₱500.00')
            ->assertSee('↑ 100.0%')
            // Tooltip points carry the real bucket values.
            ->assertSee('data-chart-point', false)
            ->assertSee('data-value="₱1,000.00"', false);

        $this->actingAs($seller)->get(route('seller.dashboard', ['period' => 'last_month']))
            ->assertOk()
            ->assertSee('value="last_month" selected', false)
            ->assertSee('₱500.00')
            ->assertDontSee('₱1,000.00')
            ->assertSee('No prior-period data');

        $this->actingAs($seller)->get(route('seller.dashboard', ['period' => 'yolo']))
            ->assertOk()
            ->assertSee('value="this_month" selected', false)
            ->assertSee('₱1,000.00');

        $this->actingAs($seller)->get(route('seller.dashboard', ['interval' => 'weekly']))
            ->assertOk()
            ->assertSee('value="weekly" selected', false);
    }

    public function test_dashboard_and_overview_series_bucket_counts_follow_the_interval(): void
    {
        [, $profile, $store] = $this->sellerFixture('Series Seller', 'series-seller');

        $this->createSale($store, [
            ['name' => 'First Bucket Item', 'sku' => 'FB-001', 'unit_minor' => 30_000],
        ], completedAt: CarbonImmutable::parse('2026-08-03 12:00:00'));
        $this->createSale($store, [
            ['name' => 'Last Bucket Item', 'sku' => 'LB-001', 'unit_minor' => 70_000],
        ], completedAt: CarbonImmutable::parse('2026-08-31 12:00:00'));

        $earnings = app(SellerEarningsService::class);
        $from = CarbonImmutable::parse('2026-08-01');
        $to = CarbonImmutable::parse('2026-08-31');

        $daily = $earnings->dashboardFinanceFor($profile, $from, $to, 'daily');
        $this->assertCount(31, $daily['series']);
        $this->assertSame(30_000, $daily['series'][2]['value_minor']);
        $this->assertSame(70_000, $daily['series'][30]['value_minor']);

        $weekly = $earnings->dashboardFinanceFor($profile, $from, $to, 'weekly');
        $this->assertCount(5, $weekly['series']);
        $this->assertSame('2026-08-29', $weekly['series'][4]['start']);
        $this->assertSame('2026-08-31', $weekly['series'][4]['end']);
        $this->assertSame(70_000, $weekly['series'][4]['value_minor']);

        $overview = $earnings->overviewFor($profile, $from, $to);
        $this->assertCount(7, $overview['trend']);
        $this->assertSame('2026-08-01', $overview['trend'][0]['start']);
        $this->assertSame('2026-08-31', $overview['trend'][6]['end']);
        $this->assertSame(1, $overview['trend'][0]['orders']);
        $this->assertSame(30_000, $overview['trend'][0]['gross_minor']);
        $this->assertSame(1, $overview['trend'][6]['orders']);
        $this->assertSame(70_000, $overview['trend'][6]['gross_minor']);

        $sales = $earnings->salesReportFor($profile, $from, $to);
        $this->assertCount(4, $sales['momentum']);
        $this->assertSame(100_000, $sales['totals']['gross_minor']);
        $this->assertSame('2026-08-01', $sales['momentum'][0]['start']);
        $this->assertSame('2026-08-31', $sales['momentum'][3]['end']);
    }

    public function test_reports_overview_chart_renders_real_bucket_gross_as_server_side_svg(): void
    {
        [$seller, , $store] = $this->sellerFixture('Chart Seller', 'chart-seller');

        $this->createSale($store, [
            ['name' => 'Chart Basket', 'sku' => 'CH-001', 'unit_minor' => 100_000],
        ], completedAt: CarbonImmutable::parse('2026-08-02 10:00:00'));
        $this->createSale($store, [
            ['name' => 'Chart Lamp', 'sku' => 'CH-002', 'unit_minor' => 30_000],
        ], completedAt: CarbonImmutable::parse('2026-08-27 10:00:00'));

        $response = $this->actingAs($seller)->get(route('seller.reports', ['from' => '2026-08-01', 'to' => '2026-08-31']));
        $response->assertOk();

        // The trend is a server-rendered SVG: the line, area, gridlines,
        // points, axes, and date labels render before any JavaScript runs.
        // Regression: the overview trend buckets carry `gross_minor`, so the
        // geometry must plot those values instead of silently zeroing every
        // point through a missing `value_minor` key.
        $response->assertSee('class="report-chart-line" points="30,26 140,234 250,234 360,234 470,234 580,172 690,234"', false)
            ->assertSee('class="report-chart-area"', false)
            ->assertSee('data-chart-hover="nearest"', false)
            ->assertSee('data-chart-crosshair', false)
            ->assertSee('<span style="top:10%">₱1,000</span>', false)
            ->assertSee('<span style="top:90%">₱0</span>', false)
            ->assertDontSee('data-report-trend', false);

        $this->assertSame(7, substr_count($response->getContent(), 'data-chart-point'));

        // Tooltip metadata and the screen-reader table carry the exact
        // bucket labels and centavo-exact amounts, not chart coordinates.
        $response->assertSee('data-label="Aug 1–Aug 5"', false)
            ->assertSee('aria-label="Aug 1–Aug 5: ₱1,000.00"', false)
            ->assertSee('data-label="Aug 24–Aug 27"', false)
            ->assertSee('data-value="₱300.00"', false)
            ->assertSee('<span style="left:95.833%">Aug 28–Aug 31</span>', false)
            ->assertSee('Gross completed sales by period');

        // A single-day range keeps its one real bucket (two anchors, one
        // label) and the screen-reader table lists it once.
        $singleDay = $this->actingAs($seller)->get(route('seller.reports', ['from' => '2026-08-02', 'to' => '2026-08-02']));
        $singleDay->assertOk();
        $singleDay->assertSee('class="report-chart-line" points="30,26 690,26"', false)
            ->assertSee('data-label="Aug 2"', false)
            ->assertSee('aria-label="Aug 2: ₱1,000.00"', false);
        $this->assertSame(1, substr_count($singleDay->getContent(), '<th scope="row">Aug 2</th>'));

        // A period without completed sales shows an honest empty state
        // instead of a flat zero line on an arbitrary axis.
        $this->actingAs($seller)->get(route('seller.reports', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->assertSee('No completed sales in this period')
            ->assertDontSee('report-chart-line', false)
            ->assertDontSee('data-chart-hover', false);
    }

    public function test_dashboard_chart_renders_real_buckets_with_shared_tooltip_markup(): void
    {
        [$seller, , $store] = $this->sellerFixture('Dashboard Chart Seller', 'dashboard-chart-seller');

        $this->createSale($store, [
            ['name' => 'Dashboard Chart Bowl', 'sku' => 'DCH-001', 'unit_minor' => 125_000],
        ], completedAt: CarbonImmutable::now()->startOfMonth()->addDays(2)->addHours(4));

        $response = $this->actingAs($seller)->get(route('seller.dashboard'));
        $response->assertOk();

        // The dashboard line chart mirrors the redesigned reports overview
        // treatment: server-computed gridlines, a hover and focus crosshair,
        // and points revealed by the shared tooltip module.
        $content = $response->getContent();
        $this->assertSame(4, substr_count($content, '<line x1="0" x2="720"'));
        $this->assertMatchesRegularExpression('/<line class="dashboard-ref-chart-crosshair" data-chart-crosshair aria-hidden="true"[^>]*y1="26" y2="234"/', $content);
        $this->assertStringContainsString('data-chart-hover="nearest"', $content);

        // Five real axis labels sit on the data-derived scale: the single
        // ₱1,250.00 bucket yields a ₱2,000 top label in ₱500 steps.
        $this->assertStringContainsString('<span style="top:10%">₱2,000</span>', $content);
        $this->assertStringContainsString('<span style="top:90%">₱0</span>', $content);

        // Tooltip points and sampled day labels keep real values and their
        // server-computed x coordinates.
        $this->assertMatchesRegularExpression('/<circle\s[^>]*data-chart-point[^>]*data-value="₱1,250\.00"/', $content);
        preg_match('/<div class="dashboard-ref-chart-days"[^>]*>(.*?)<\/div>/s', $content, $daysBlock);
        preg_match_all('/<span style="left:([\d.]+)%">/u', $daysBlock[1] ?? '', $dayLefts);
        $this->assertCount(7, $dayLefts[1] ?? []);
        $this->assertSame('4.167', $dayLefts[1][0]);

        // A period without completed sales keeps the honest empty state.
        [$idleSeller] = $this->sellerFixture('Dashboard Idle Seller', 'dashboard-idle-seller');
        $this->actingAs($idleSeller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('No completed sales in this period')
            ->assertDontSee('dashboard-ref-chart-line', false)
            ->assertDontSee('dashboard-ref-chart-crosshair', false);
    }

    public function test_sales_gross_excludes_shipping_across_totals_buckets_and_charts(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Shipping Seller', 'shipping-seller');

        $completedAt = CarbonImmutable::now()->startOfMonth()->addDays(9)->addHours(5);
        $sale = $this->createSale($store, [
            ['name' => 'Shipped Basket', 'sku' => 'SH-001', 'unit_minor' => 100_000],
        ], completedAt: $completedAt, discountMinor: 10_000, shippingFeeMinor: 30_000);
        $this->createLedgerSale(
            $profile,
            $sale[1],
            $sale[2],
            baseMinor: 120_000,
            commissionMinor: 12_000,
            amountMinor: 108_000,
            postedAt: $completedAt->addHour(),
        );

        $earnings = app(SellerEarningsService::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = CarbonImmutable::now()->endOfMonth();

        // Gross/Net Sales, averages, buckets, and chart values all use the
        // product subtotal snapshot (₱1,000) — never subtotal + shipping
        // (₱1,200) or the old total + discount formula (₱1,300).
        $report = $earnings->salesReportFor($profile, $from, $to);
        $this->assertSame(100_000, $report['totals']['gross_minor']);
        $this->assertSame(10_000, $report['totals']['discounts_minor']);
        $this->assertSame(90_000, $report['totals']['net_minor']);
        $this->assertSame(100_000, $report['totals']['average_order_minor']);
        $this->assertSame(100_000, array_sum(array_column($report['momentum'], 'gross_minor')));

        $overview = $earnings->overviewFor($profile, $from, $to);
        $this->assertSame(100_000, $overview['totals']['gross_minor']);
        $this->assertSame(100_000, array_sum(array_column($overview['trend'], 'gross_minor')));

        $dashboard = $earnings->dashboardFinanceFor($profile, $from, $to, 'daily');
        $this->assertSame(100_000, array_sum(array_column($dashboard['series'], 'value_minor')));

        // The Finance ledger keeps its documented commission basis: the
        // finalized commission was charged on the completion-time order
        // total (which includes shipping), so the ledger gross is unchanged.
        $financial = $earnings->financialReportFor($profile, $from, $to);
        $this->assertSame(130_000, $financial['summary']['gross_sales_minor']);

        $this->actingAs($seller)->get(route('seller.reports.sales'))
            ->assertOk()
            ->assertSee('₱1,000.00')
            ->assertDontSee('₱1,300.00')
            // Momentum bars expose the same gross values as tooltip points.
            ->assertSee('data-chart-point', false)
            ->assertSee('data-value="₱1,000.00"', false);

        $this->actingAs($seller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('₱1,000.00');
    }

    public function test_product_ranking_uses_snapshots_subcategories_and_keeps_inactive_items(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Ranking Seller', 'ranking-seller');

        $baskets = $this->createSubcategory($store, 'Baskets');
        $lighting = $this->createSubcategory($store, 'Lighting');
        $bags = $this->createSubcategory($store, 'Bags');

        $basket = $this->createProduct($store, 'Woven Basket', $baskets);
        $lamp = $this->createProduct($store, 'Lamp Shade', $lighting, status: 'inactive');
        $tote = $this->createProduct($store, 'Canvas Tote', $bags);
        $bare = $this->createProduct($store, 'Bare Product', null);

        $sale = $this->createSale($store, [
            ['product' => $basket, 'quantity' => 2, 'unit_minor' => 20_000],
            ['product' => $lamp, 'unit_minor' => 30_000],
            ['product' => $tote, 'unit_minor' => 15_000],
            ['product' => $bare, 'unit_minor' => 10_000],
            ['name' => 'Vintage Vase', 'sku' => 'VV-001', 'quantity' => 3, 'unit_minor' => 5_000],
        ]);

        // Deleted products must keep their order-item snapshot instead of vanishing.
        $tote->delete();

        $lastMonthSale = $this->createSale($store, [
            ['product' => $this->createProduct($store, 'Rattan Chair', $bags), 'unit_minor' => 70_000],
        ], completedAt: CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addHours(4));
        $this->createRefund($lastMonthSale, 7_000);

        $earnings = app(SellerEarningsService::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = CarbonImmutable::now()->endOfMonth();

        $report = $earnings->salesReportFor($profile, $from, $to, 'net');
        $this->assertSame(
            ['Woven Basket', 'Lamp Shade', 'Vintage Vase', 'Canvas Tote', 'Bare Product', 'Rattan Chair'],
            array_column($report['products'], 'name'),
        );

        $byName = collect($report['products'])->keyBy('name');
        $this->assertSame(1, $byName['Woven Basket']['rank']);
        $this->assertSame('Baskets', $byName['Woven Basket']['subcategory']);
        $this->assertSame(2, $byName['Woven Basket']['units']);
        $this->assertSame(36.4, $byName['Woven Basket']['share']);
        $this->assertSame('Lighting', $byName['Lamp Shade']['subcategory']);
        $this->assertSame('Bags', $byName['Canvas Tote']['subcategory']);
        $this->assertSame('No subcategory assigned', $byName['Bare Product']['subcategory']);
        $this->assertSame('Subcategory unavailable', $byName['Vintage Vase']['subcategory']);

        // Refund-only product rows keep their refund even when the completed
        // sale happened outside the selected period.
        $this->assertSame(0, $byName['Rattan Chair']['units']);
        $this->assertSame(0, $byName['Rattan Chair']['gross_minor']);
        $this->assertSame(7_000, $byName['Rattan Chair']['refunds_minor']);
        $this->assertSame(-7_000, $byName['Rattan Chair']['net_minor']);
        $this->assertSame(0.0, $byName['Rattan Chair']['share']);

        $unitsReport = $earnings->salesReportFor($profile, $from, $to, 'units');
        $this->assertSame('Vintage Vase', $unitsReport['products'][0]['name']);
        $this->assertSame(3, $unitsReport['products'][0]['units']);

        $this->actingAs($seller)->get(route('seller.reports.sales'))
            ->assertOk()
            ->assertSee('Woven Basket')
            ->assertSee('Subcategory unavailable')
            ->assertSee('Rattan Chair')
            ->assertSee('Rank by')
            ->assertDontSee('Frontend preview');
    }

    public function test_posted_available_and_withdrawn_are_distinguished_without_changing_wallet_eligibility(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Wallet Status Seller', 'wallet-status-seller');

        $eligibleSale = $this->createSale($store, [
            ['name' => 'Eligible Item', 'sku' => 'EL-001', 'unit_minor' => 100_000],
        ], completedAt: CarbonImmutable::now()->subDays(10));
        $eligibleTx = $this->createLedgerSale($profile, $eligibleSale[1], $eligibleSale[2], baseMinor: 100_000, commissionMinor: 10_000, amountMinor: 90_000);

        $recentSale = $this->createSale($store, [
            ['name' => 'Recent Item', 'sku' => 'RC-001', 'unit_minor' => 50_000],
        ], completedAt: CarbonImmutable::now()->subDay());
        $recentTx = $this->createLedgerSale($profile, $recentSale[1], $recentSale[2], baseMinor: 50_000, commissionMinor: 5_000, amountMinor: 45_000);

        $legacySale = $this->createSale($store, [
            ['name' => 'Legacy Item', 'sku' => 'LG-001', 'unit_minor' => 30_000],
        ], completedAt: CarbonImmutable::now()->subDays(20));
        $legacyTx = $this->createLedgerSale($profile, $legacySale[1], $legacySale[2], baseMinor: 30_000, commissionMinor: 3_000, amountMinor: 27_000);
        $payout = $this->markWithdrawnViaLegacyPayout($profile, $legacyTx);

        $wallets = app(SellerWalletService::class);
        $wallets->recordLegacyPayoutCompletion($payout);
        $wallet = $wallets->refreshForSeller($profile);
        $wallet = $wallets->refreshForSeller($profile);

        // Idempotent refresh: eligibility release never duplicates movements.
        $movementCount = SellerWalletTransaction::query()->where('seller_wallet_id', $wallet->id)->count();
        $wallets->refreshForSeller($profile);
        $this->assertSame($movementCount, SellerWalletTransaction::query()->where('seller_wallet_id', $wallet->id)->count());

        $this->assertSame(90_000, (int) $wallet->available_balance_minor);
        $this->assertSame(45_000, (int) $wallet->pending_balance_minor);
        $this->assertSame(27_000, (int) $wallet->total_withdrawn_minor);

        $earnings = app(SellerEarningsService::class);
        $rows = $earnings->transactionsFor($profile)->keyBy('id');

        $this->assertSame('Available', $rows[$eligibleTx->transaction_no]['status']);
        $this->assertSame('Posted', $rows[$recentTx->transaction_no]['status']);
        $this->assertSame('Withdrawn', $rows[$legacyTx->transaction_no]['status']);
        $this->assertSame(162_000, $earnings->ledgerSummaryFor(
            $profile,
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        )['net_earnings_minor']);

        $this->actingAs($seller)->get(route('seller.finance.transactions'))
            ->assertOk()
            ->assertSee('finance-status is-available', false)
            ->assertSee('finance-status is-posted', false)
            ->assertSee('finance-status is-withdrawn', false);

        $this->actingAs($seller)->get(route('seller.finance.wallet'))
            ->assertOk()
            ->assertSee('Wallet Activity');

        $this->actingAs($seller)->get(route('seller.reports.financial'))
            ->assertOk()
            ->assertSee('report-status is-posted', false);
    }

    public function test_csv_exports_are_seller_scoped_and_match_the_selected_period(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Export Seller', 'export-seller');
        [, $otherProfile, $otherStore] = $this->sellerFixture('Other Export Seller', 'other-export-seller');

        $sale = $this->createSale($store, [
            ['name' => 'Export Basket', 'sku' => 'EX-001', 'unit_minor' => 100_000],
        ], discountMinor: 10_000, completedAt: CarbonImmutable::parse('2026-08-10 10:00:00'));
        $this->createLedgerSale(
            $profile,
            $sale[1],
            $sale[2],
            baseMinor: 90_000,
            commissionMinor: 9_000,
            amountMinor: 81_000,
            postedAt: CarbonImmutable::parse('2026-08-10 11:00:00'),
        );

        $otherSale = $this->createSale($otherStore, [
            ['name' => 'Other Export Lamp', 'sku' => 'OX-001', 'unit_minor' => 55_000],
        ], completedAt: CarbonImmutable::parse('2026-08-12 10:00:00'));
        $this->createLedgerSale(
            $otherProfile,
            $otherSale[1],
            $otherSale[2],
            baseMinor: 55_000,
            commissionMinor: 5_500,
            amountMinor: 49_500,
            postedAt: CarbonImmutable::parse('2026-08-12 11:00:00'),
        );

        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];

        $salesExport = $this->actingAs($seller)->get(route('seller.reports.sales.export', $range));
        $salesExport->assertOk();
        $salesExport->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $salesExport->assertHeader('content-disposition', 'attachment; filename=bearly-sales-performance-2026-08-01-to-2026-08-31.csv');
        $salesCsv = $salesExport->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $salesCsv);
        $this->assertStringContainsString('Bearly Sales & Performance', $salesCsv);
        $this->assertStringContainsString('Export Basket', $salesCsv);
        $this->assertStringContainsString('1000.00', $salesCsv);
        $this->assertStringContainsString('-100.00', $salesCsv);
        $this->assertStringNotContainsString('Other Export Lamp', $salesCsv);

        $unitsCsv = $this->actingAs($seller)
            ->get(route('seller.reports.sales.export', $range + ['sort' => 'units']))
            ->streamedContent();
        $this->assertStringContainsString('Sorted by units sold', $unitsCsv);

        $financialExport = $this->actingAs($seller)->get(route('seller.reports.financial.export', $range));
        $financialExport->assertOk();
        $financialExport->assertHeader('content-disposition', 'attachment; filename=bearly-financial-report-2026-08-01-to-2026-08-31.csv');
        $financialCsv = $financialExport->streamedContent();
        $this->assertStringContainsString('Bearly Financial Report', $financialCsv);
        $this->assertStringContainsString('Net Earnings', $financialCsv);
        $this->assertStringContainsString('810.00', $financialCsv);
        $this->assertStringNotContainsString('495.00', $financialCsv);

        $overviewExport = $this->actingAs($seller)->get(route('seller.reports.export', $range));
        $overviewExport->assertOk();
        $overviewExport->assertHeader('content-disposition', 'attachment; filename=bearly-reports-overview-2026-08-01-to-2026-08-31.csv');
        $overviewCsv = $overviewExport->streamedContent();
        $this->assertStringContainsString('Bearly Reports Overview', $overviewCsv);
        $this->assertStringContainsString('Top products', $overviewCsv);
        $this->assertStringContainsString('Export Basket', $overviewCsv);
        $this->assertStringNotContainsString('Other Export Lamp', $overviewCsv);
    }

    public function test_report_views_render_live_labels_and_honest_empty_states(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('View Seller', 'view-seller');

        $sale = $this->createSale($store, [
            ['name' => 'View Item', 'sku' => 'VW-001', 'unit_minor' => 100_000],
        ], discountMinor: 10_000);
        $this->createLedgerSale($profile, $sale[1], $sale[2], baseMinor: 90_000, commissionMinor: 9_000, amountMinor: 81_000);

        $this->actingAs($seller)->get(route('seller.reports'))
            ->assertOk()
            ->assertSee('Business Statement')
            ->assertSee('From sales to earnings')
            ->assertSee('Net Revenue')
            ->assertSee('reports/export', false);

        $this->actingAs($seller)->get(route('seller.reports.sales'))
            ->assertOk()
            ->assertSee('View Item')
            ->assertSee('reports/sales/export', false)
            ->assertDontSee('Frontend preview');

        $this->actingAs($seller)->get(route('seller.reports.financial'))
            ->assertOk()
            ->assertSee('Selected-period earnings')
            ->assertSee('Where deductions went')
            ->assertSee('reports/financial/export', false)
            ->assertDontSee('>Processing<', false);

        $this->actingAs($seller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('No new notifications.')
            ->assertSee('Sales Overview')
            ->assertSee('Gross completed sales by completion date')
            ->assertSee('No pickup scheduled')
            ->assertSee('View Item');
    }

    /**
     * @return array{0: User, 1: SellerProfile, 2: Store}
     */
    private function sellerFixture(string $name, string $slug): array
    {
        $seller = User::factory()->create([
            'name' => $name,
            'role' => 'seller',
            'status' => 'active',
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => $name,
            'approved_category_id' => Category::query()->firstOrFail()->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $name,
            'slug' => $slug,
            'publication_status' => 'published',
        ]);

        return [$seller, $profile, $store];
    }

    private function createSubcategory(Store $store, string $name): StoreSubcategory
    {
        return StoreSubcategory::query()->create([
            'store_id' => $store->id,
            'parent_category_id' => Category::query()->firstOrFail()->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_active' => true,
        ]);
    }

    private function createProduct(Store $store, string $name, ?StoreSubcategory $subcategory, string $status = 'active'): Product
    {
        return Product::query()->create([
            'store_id' => $store->id,
            'category_id' => Category::query()->firstOrFail()->id,
            'subcategory_id' => $subcategory?->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'product_status' => $status,
            'compliance_status' => 'clear',
        ]);
    }

    /**
     * @param  array<int, array{product?: ?Product, name?: string, sku?: string, variant?: string, unit_minor: int, quantity?: int}>  $items
     * @return array{0: Order, 1: SellerOrder, 2: Payment, 3: Collection<int, OrderItem>}
     */
    private function createSale(
        Store $store,
        array $items,
        ?CarbonImmutable $completedAt = null,
        string $status = 'completed',
        string $paymentStatus = 'paid',
        string $paymentMethod = 'gcash',
        int $discountMinor = 0,
        int $shippingFeeMinor = 0,
    ): array {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $completedAt ??= CarbonImmutable::now();

        $subtotalMinor = 0;
        foreach ($items as $item) {
            $subtotalMinor += (int) $item['unit_minor'] * (int) ($item['quantity'] ?? 1);
        }

        $order = Order::query()->create([
            'order_no' => 'ORD-'.Str::upper(Str::random(10)),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '09171234567',
            'address_line' => '1 Report Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => $subtotalMinor,
            'shipping_fee_minor' => $shippingFeeMinor,
            'discount_minor' => $discountMinor,
            'total_minor' => $subtotalMinor - $discountMinor + $shippingFeeMinor,
            'status' => match ($status) {
                'completed' => 'completed',
                'cancelled' => 'cancelled',
                default => 'processing',
            },
            'payment_status' => $paymentStatus,
            'placed_at' => $completedAt->subDay(),
            'completed_at' => $status === 'completed' ? $completedAt : null,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SORD-'.Str::upper(Str::random(10)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => $subtotalMinor,
            'discount_minor' => $discountMinor,
            'shipping_fee_minor' => $shippingFeeMinor,
            'total_minor' => $subtotalMinor - $discountMinor + $shippingFeeMinor,
            'completed_at' => $status === 'completed' ? $completedAt : null,
        ]);

        $orderItems = collect();

        foreach ($items as $item) {
            $product = $item['product'] ?? null;
            $quantity = (int) ($item['quantity'] ?? 1);

            $orderItems->push(OrderItem::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'product_id' => $product?->id,
                'product_name' => $product?->name ?? ($item['name'] ?? 'Order item'),
                'variant_name' => $item['variant'] ?? 'Default',
                'sku' => $item['sku'] ?? ($product?->slug ?? 'NO-SKU'),
                'unit_price_minor' => $item['unit_minor'],
                'quantity' => $quantity,
                'discount_minor' => 0,
                'subtotal_minor' => $item['unit_minor'] * $quantity,
            ]));
        }

        $payment = Payment::query()->create([
            'payment_no' => 'PMT-'.Str::upper(Str::random(10)),
            'order_id' => $order->id,
            'method' => $paymentMethod,
            'amount_minor' => $subtotalMinor,
            'status' => $paymentStatus,
            'initiated_at' => $completedAt->subDay(),
            'paid_at' => $paymentStatus === 'paid' ? $completedAt->subDay() : null,
        ]);

        return [$order, $sellerOrder, $payment, $orderItems];
    }

    private function createLedgerSale(
        SellerProfile $profile,
        SellerOrder $sellerOrder,
        Payment $payment,
        int $baseMinor,
        int $commissionMinor,
        ?int $amountMinor = null,
        ?CarbonImmutable $postedAt = null,
        bool $withCommission = true,
    ): SellerTransaction {
        $postedAt ??= CarbonImmutable::now();
        $commissionId = null;

        if ($withCommission) {
            $commissionId = PlatformCommission::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'type' => 'sale',
                'base_amount_minor' => $baseMinor,
                'rate_bps' => 1000,
                'amount_minor' => $commissionMinor,
                'status' => 'finalized',
                'calculated_at' => $postedAt,
                'finalized_at' => $postedAt,
            ])->id;
        }

        return SellerTransaction::query()->create([
            'transaction_no' => 'STX-'.Str::upper(Str::random(10)),
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'commission_id' => $commissionId,
            'type' => 'sale',
            'amount_minor' => $amountMinor ?? ($baseMinor - $commissionMinor),
            'status' => 'posted',
            'posted_at' => $postedAt,
        ]);
    }

    /**
     * @param  array{0: Order, 1: SellerOrder, 2: Payment, 3: Collection<int, OrderItem>}  $sale
     * @return array{0: ReturnRequest, 1: ReturnItem, 2: Refund}
     */
    private function createRefund(array $sale, int $amountMinor, ?CarbonImmutable $completedAt = null): array
    {
        [$order, $sellerOrder, $payment, $items] = $sale;
        $completedAt ??= CarbonImmutable::now();

        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper(Str::random(10)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $sellerOrder->store_id,
            'request_type' => 'refund_only',
            'reason_code' => 'not_as_described',
            'status' => 'resolved',
            'requested_amount_minor' => $amountMinor,
            'submitted_at' => $completedAt->subDay(),
        ]);

        $returnItem = ReturnItem::query()->create([
            'return_request_id' => $returnRequest->id,
            'order_item_id' => $items->first()->id,
            'quantity' => 1,
            'refund_amount_minor' => $amountMinor,
        ]);

        $refund = Refund::query()->create([
            'refund_no' => 'REF-'.Str::upper(Str::random(10)),
            'return_request_id' => $returnRequest->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_minor' => $amountMinor,
            'status' => 'completed',
            'method' => 'gcash',
            'approved_at' => $completedAt,
            'processed_at' => $completedAt,
            'completed_at' => $completedAt,
        ]);

        return [$returnRequest, $returnItem, $refund];
    }

    /**
     * @param  array{0: ReturnRequest, 1: ReturnItem, 2: Refund}  $refundPieces
     */
    private function createRefundLedger(
        SellerProfile $profile,
        array $refundPieces,
        int $reversalMinor,
        int $transactionMinor,
        ?CarbonImmutable $postedAt = null,
    ): SellerTransaction {
        [, , $refund] = $refundPieces;
        $postedAt ??= CarbonImmutable::now();
        $sellerOrderId = (int) $refund->returnRequest?->seller_order_id;

        $reversal = PlatformCommission::query()->create([
            'seller_order_id' => $sellerOrderId,
            'refund_id' => $refund->id,
            'type' => 'refund_reversal',
            'base_amount_minor' => (int) $refund->amount_minor,
            'rate_bps' => 1000,
            'amount_minor' => $reversalMinor,
            'status' => 'finalized',
            'calculated_at' => $postedAt,
            'finalized_at' => $postedAt,
            'reversed_at' => $postedAt,
        ]);

        return SellerTransaction::query()->create([
            'transaction_no' => 'STX-'.Str::upper(Str::random(10)),
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrderId,
            'payment_id' => $refund->payment_id,
            'refund_id' => $refund->id,
            'commission_id' => $reversal->id,
            'type' => 'refund_reversal',
            'amount_minor' => $transactionMinor,
            'status' => 'posted',
            'posted_at' => $postedAt,
        ]);
    }

    private function markWithdrawnViaLegacyPayout(SellerProfile $profile, SellerTransaction $transaction): SellerPayout
    {
        $amountMinor = abs((int) $transaction->amount_minor);

        $payout = SellerPayout::query()->create([
            'payout_no' => 'SPY-'.Str::upper(Str::random(10)),
            'seller_profile_id' => $profile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_minor' => $amountMinor,
            'commission_minor' => 0,
            'adjustment_minor' => 0,
            'net_minor' => $amountMinor,
            'status' => 'paid',
            'reference' => 'LEGACY-REF-'.Str::upper(Str::random(4)),
            'external_method' => 'bank_transfer',
            'due_at' => now(),
            'paid_at' => now(),
        ]);

        SellerPayoutItem::query()->create([
            'seller_payout_id' => $payout->id,
            'seller_transaction_id' => $transaction->id,
            'gross_minor' => $amountMinor,
            'commission_minor' => 0,
            'net_minor' => $amountMinor,
        ]);

        return $payout;
    }
}
