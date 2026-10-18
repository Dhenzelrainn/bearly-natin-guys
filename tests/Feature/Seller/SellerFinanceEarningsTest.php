<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PlatformCommission;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerFinanceEarningsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_earnings_and_settlements_use_the_admin_finance_ledger_and_are_seller_scoped(): void
    {
        [$seller, $profile, $store] = $this->sellerFixture('Earnings Seller', 'earnings-seller');
        [, $otherProfile] = $this->sellerFixture('Other Seller', 'other-seller');
        [$order, $sellerOrder, $payment, $product] = $this->saleFixture($store);
        SellerTransaction::query()->create([
            'transaction_no' => 'STX-OTHER-SELLER-001',
            'seller_profile_id' => $otherProfile->id,
            'type' => 'sale',
            'amount_minor' => 12_345,
            'status' => 'pending',
        ]);

        $saleCommission = PlatformCommission::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'type' => 'sale',
            'base_amount_minor' => 90_000,
            'rate_bps' => 1000,
            'amount_minor' => 9000,
            'status' => 'finalized',
            'calculated_at' => now(),
            'finalized_at' => now(),
        ]);
        SellerTransaction::query()->create([
            'transaction_no' => 'STX-EARNINGS-SALE-001',
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'commission_id' => $saleCommission->id,
            'type' => 'sale',
            'amount_minor' => 81_000,
            'status' => 'posted',
            'posted_at' => now()->subMinute(),
        ]);

        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RET-EARNINGS-001',
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $store->id,
            'request_type' => 'refund_only',
            'reason_code' => 'not_as_described',
            'status' => 'approved',
            'requested_amount_minor' => 10_000,
            'submitted_at' => now()->subDay(),
        ]);
        $refund = Refund::query()->create([
            'refund_no' => 'REF-EARNINGS-001',
            'return_request_id' => $returnRequest->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_minor' => 10_000,
            'status' => 'completed',
            'method' => 'gcash',
            'approved_at' => now()->subHour(),
            'processed_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);
        $reversal = PlatformCommission::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'refund_id' => $refund->id,
            'type' => 'refund_reversal',
            'base_amount_minor' => 10_000,
            'rate_bps' => 1000,
            'amount_minor' => 1000,
            'status' => 'finalized',
            'calculated_at' => now()->subHour(),
            'finalized_at' => now()->subHour(),
            'reversed_at' => now()->subHour(),
        ]);
        SellerTransaction::query()->create([
            'transaction_no' => 'STX-EARNINGS-REFUND-001',
            'seller_profile_id' => $profile->id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'refund_id' => $refund->id,
            'commission_id' => $reversal->id,
            'type' => 'refund_reversal',
            'amount_minor' => -9000,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $payout = SellerPayout::query()->create([
            'payout_no' => 'SPY-EARNINGS-PENDING-001',
            'seller_profile_id' => $profile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_minor' => 100_000,
            'commission_minor' => 8000,
            'adjustment_minor' => 0,
            'net_minor' => 72_000,
            'status' => 'pending',
            'due_at' => now(),
        ]);
        SellerPayout::query()->create([
            'payout_no' => 'SPY-OTHER-SELLER-001',
            'seller_profile_id' => $otherProfile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_minor' => 50_000,
            'commission_minor' => 5000,
            'adjustment_minor' => 0,
            'net_minor' => 45_000,
            'status' => 'pending',
            'due_at' => now(),
        ]);

        $earningsResponse = $this->actingAs($seller)->get(route('seller.finance.earnings'));
        $earningsResponse->assertOk()
            ->assertSee('₱1,000.00')
            ->assertSee('−₱200.00')
            ->assertSee('−₱80.00')
            ->assertSee('₱720.00')
            ->assertSee('Handwoven Basket')
            ->assertSee('Handwoven Home')
            ->assertSee('SPY-EARNINGS-PENDING-001')
            ->assertSee('Available after Finance records payment.')
            ->assertSee('Admin Finance still needs to pay you. You do not need to do anything yet.')
            ->assertDontSee('SPY-OTHER-SELLER-001')
            ->assertDontSee('Other Seller');
        $earningsResponse->assertSee(Storage::disk('public')->url('seller-finance/basket.jpg'), false);

        $this->actingAs($seller)
            ->get(route('seller.finance.transactions'))
            ->assertOk()
            ->assertSee('STX-EARNINGS-SALE-001')
            ->assertSee('STX-EARNINGS-REFUND-001')
            ->assertSee(Storage::disk('public')->url('seller-finance/basket.jpg'), false)
            ->assertDontSee('STX-OTHER-SELLER-001');

        $this->actingAs($this->admin)
            ->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('STX-EARNINGS-SALE-001')
            ->assertSee('STX-EARNINGS-REFUND-001')
            ->assertSee(Storage::disk('public')->url('seller-finance/basket.jpg'), false);

        $this->actingAs($this->admin)
            ->get(route('admin.orders'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url('seller-finance/basket.jpg'), false);

        $this->actingAs($seller)
            ->get(route('seller.finance.wallet'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url('seller-finance/basket.jpg'), false);
        $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('SPY-EARNINGS-PENDING-001');
        $this->actingAs($this->admin)
            ->get(route('admin.reports', ['type' => 'settlement']))
            ->assertOk()
            ->assertSee('Earnings Seller')
            ->assertSee('₱720.00');

        $this->assertSame($product->id, $sellerOrder->items()->value('product_id'));
        $this->assertSame($payout->id, SellerPayout::query()->where('seller_profile_id', $profile->id)->sole()->id);
    }

    public function test_only_the_owning_seller_can_download_a_paid_payout_receipt(): void
    {
        Storage::fake('local');
        [$seller, $profile] = $this->sellerFixture('Receipt Seller', 'receipt-seller');
        [$otherSeller] = $this->sellerFixture('Different Receipt Seller', 'different-receipt-seller');
        $path = 'seller-payout-receipts/SPY-RECEIPT-001/transfer-proof.pdf';
        Storage::disk('local')->put($path, 'verified payout evidence');

        $paidPayout = SellerPayout::query()->create([
            'payout_no' => 'SPY-RECEIPT-001',
            'seller_profile_id' => $profile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_minor' => 100_000,
            'commission_minor' => 10_000,
            'adjustment_minor' => 0,
            'net_minor' => 90_000,
            'status' => 'paid',
            'due_at' => now()->subDay(),
            'paid_at' => now(),
            'reference' => 'BANK-TRANSFER-001',
            'external_method' => 'bank_transfer',
            'receipt_disk' => 'local',
            'receipt_path' => $path,
            'receipt_mime_type' => 'application/pdf',
            'receipt_original_name' => 'seller-payout-proof.pdf',
            'receipt_size_bytes' => strlen('verified payout evidence'),
            'paid_by' => $this->admin->id,
        ]);
        $pendingPayout = SellerPayout::query()->create([
            'payout_no' => 'SPY-RECEIPT-PENDING-001',
            'seller_profile_id' => $profile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_minor' => 100_000,
            'commission_minor' => 10_000,
            'adjustment_minor' => 0,
            'net_minor' => 90_000,
            'status' => 'pending',
            'due_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.finance.earnings'))
            ->assertOk()
            ->assertSee('Paid')
            ->assertSee(route('seller.finance.settlements.receipt', $paidPayout), false)
            ->assertDontSee(route('seller.finance.settlements.receipt', $pendingPayout), false);

        $this->actingAs($seller)
            ->get(route('seller.finance.settlements.receipt', $paidPayout))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seller_payout.receipt_downloaded_by_seller',
            'actor_user_id' => $seller->id,
            'auditable_id' => $paidPayout->id,
        ]);

        $this->actingAs($otherSeller)
            ->get(route('seller.finance.settlements.receipt', $paidPayout))
            ->assertNotFound();
        $this->actingAs($seller)
            ->get(route('seller.finance.settlements.receipt', $pendingPayout))
            ->assertNotFound();
    }

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

    private function saleFixture(Store $store): array
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $category = Category::query()->firstOrFail();
        $subcategory = StoreSubcategory::query()->create([
            'store_id' => $store->id,
            'parent_category_id' => $category->id,
            'name' => 'Handwoven Home',
            'slug' => 'handwoven-home',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'name' => 'Handwoven Basket',
            'slug' => 'handwoven-basket',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'seller-finance/basket.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'purpose' => 'product_image',
            'position' => 0,
            'is_primary' => true,
        ]);
        $order = Order::query()->create([
            'order_no' => 'ORD-EARNINGS-001',
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '09171234567',
            'address_line' => '1 Finance Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => 100_000,
            'total_minor' => 100_000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'placed_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SORD-EARNINGS-001',
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => 100_000,
            'discount_minor' => 10_000,
            'shipping_fee_minor' => 0,
            'total_minor' => 90_000,
            'completed_at' => now(),
        ]);
        $item = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'variant_name' => 'Default',
            'sku' => 'HB-DEFAULT',
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-finance/basket.jpg',
            'unit_price_minor' => 100_000,
            'quantity' => 1,
            'discount_minor' => 10_000,
            'subtotal_minor' => 100_000,
        ]);
        $payment = Payment::query()->create([
            'payment_no' => 'PMT-EARNINGS-001',
            'order_id' => $order->id,
            'method' => 'gcash',
            'amount_minor' => 100_000,
            'status' => 'paid',
            'initiated_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
        ]);

        return [$order, $sellerOrder, $payment, $product, $item];
    }
}
