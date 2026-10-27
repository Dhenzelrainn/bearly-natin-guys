<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformCampaignParticipationItem;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromotionClaim;
use App\Models\PromotionRedemption;
use App\Models\PromotionRedemptionAllocation;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PostalCodeLookup;
use App\Services\ProductPricingService;
use App\Services\PromotionService;
use App\Services\SellerFulfillmentService;
use App\Services\SellerOrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_platform_code_and_store_voucher_stack_and_keep_seller_order_basis(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-stack');
        $product = $this->product($store, $category, 'Stacked Item');
        $variant = $this->variant($product, 'STACK-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 2);

        $storeVoucher = $this->storeVoucher($store, $product, [
            'name' => 'Store ₱10 off',
            'code' => 'STORE10',
            'voucher_amount_minor' => 1000,
        ]);
        $platformVoucher = $this->platformVoucher([
            'name' => 'Bearly ₱5 off',
            'code' => 'PLAT50',
            'value_minor' => 500,
        ]);

        app(PromotionService::class)->claim($buyer, ['store_promotion_id' => $storeVoucher->id]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'store_voucher_id' => $storeVoucher->id,
            'voucher_code' => 'PLAT50',
        ]);

        $this->assertSame(20000, (int) $quote['subtotal_minor']);
        $this->assertSame(5000, (int) $quote['shipping_fee_minor']);
        $this->assertSame(1000, (int) $quote['seller_discount_minor']);
        $this->assertSame(500, (int) $quote['platform_discount_minor']);
        $this->assertSame(23500, (int) $quote['total_minor']);
        $this->assertSame(24000, (int) $quote['seller_orders'][0]['total_minor']);
        $this->assertSame(500, (int) $quote['seller_orders'][0]['platform_discount_minor']);
        $this->assertCount(2, $quote['applied_promotions']);

        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);

        $this->assertSame(1500, (int) $order->discount_minor);
        $this->assertSame(500, (int) $order->platform_discount_minor);
        $this->assertSame(0, (int) $order->platform_shipping_discount_minor);
        $this->assertSame(23500, (int) $order->total_minor);
        $this->assertSame(23500, (int) $order->payments->first()->amount_minor);

        $sellerOrder = $order->sellerOrders->first();
        $this->assertSame(1000, (int) $sellerOrder->discount_minor);
        $this->assertSame(500, (int) $sellerOrder->platform_discount_minor);
        $this->assertSame(24000, (int) $sellerOrder->total_minor);

        $item = $sellerOrder->items->first();
        $this->assertSame(1000, (int) $item->discount_minor);
        $this->assertSame(500, (int) $item->platform_discount_minor);

        $this->assertSame(2, PromotionRedemption::query()->count());
        $this->assertDatabaseHas('promotion_redemptions', [
            'order_id' => $order->id,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'status' => PromotionRedemption::STATUS_RESERVED,
        ]);
        $this->assertDatabaseHas('promotion_redemptions', [
            'order_id' => $order->id,
            'funding_type' => StorePromotion::FUNDING_SELLER,
            'status' => PromotionRedemption::STATUS_RESERVED,
        ]);
        $this->assertDatabaseHas('promotion_claims', [
            'buyer_id' => $buyer->id,
            'store_promotion_id' => $storeVoucher->id,
            'status' => PromotionClaim::STATUS_USED,
        ]);
    }

    public function test_store_voucher_stacks_with_bearly_shipping_voucher_without_mixing_funding(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-store-and-shipping');
        $product = $this->product($store, $category, 'Store and Shipping Voucher Item');
        $variant = $this->variant($product, 'STACK-SHIP-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 2);
        $this->setShippingFeeMinor(5000);

        $storeVoucher = $this->storeVoucher($store, $product, [
            'name' => 'Seller ₱10 merchandise voucher',
            'voucher_amount_minor' => 1000,
            'minimum_spend_minor' => 15000,
        ]);
        $shippingVoucher = $this->platformVoucher([
            'name' => 'Bearly shipping voucher',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'max_discount_minor' => 3000,
            'minimum_spend_minor' => 15000,
            'is_claimable' => true,
        ]);
        $shippingVoucher->stores()->attach($store->id);

        app(PromotionService::class)->claim($buyer, ['store_promotion_id' => $storeVoucher->id]);
        app(PromotionService::class)->claim($buyer, ['platform_promotion_id' => $shippingVoucher->id]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'store_voucher_id' => $storeVoucher->id,
            'platform_voucher_id' => $shippingVoucher->id,
        ]);

        $this->assertSame(20000, (int) $quote['subtotal_minor']);
        $this->assertSame(1000, (int) $quote['seller_discount_minor']);
        $this->assertSame(0, (int) $quote['platform_discount_minor']);
        $this->assertSame(5000, (int) $quote['shipping_fee_minor']);
        $this->assertSame(3000, (int) $quote['shipping_discount_minor']);
        $this->assertSame(2000, (int) $quote['buyer_paid_shipping_fee_minor']);
        $this->assertSame(3000, (int) $quote['platform_shipping_subsidy_minor']);
        $this->assertSame('buyer_and_platform', $quote['shipping_funding_source']);
        $this->assertSame([
            'original_fee_minor' => 5000,
            'buyer_paid_minor' => 2000,
            'platform_subsidy_minor' => 3000,
            'funding_source' => 'buyer_and_platform',
            'funding_sources' => [
                ['source' => 'buyer', 'amount_minor' => 2000],
                ['source' => 'platform', 'amount_minor' => 3000],
            ],
        ], $quote['seller_orders'][0]['quote']['shipping_fee_breakdown']);
        $this->assertSame(21000, (int) $quote['total_minor']);
        $this->assertSame(24000, (int) $quote['seller_orders'][0]['total_minor']);
        $this->assertSame(21000, (int) $quote['seller_orders'][0]['buyer_total_minor']);

        $order = $this->place(
            $buyer,
            [$cartItem->id],
            $destination->id,
            $quote['quote_token'],
            $quote['promotion_selection'],
        );
        $order->load(['sellerOrders.items', 'payments']);
        $sellerOrder = $order->sellerOrders->firstOrFail();
        $item = $sellerOrder->items->firstOrFail();

        $this->assertSame(21000, (int) $order->total_minor);
        $this->assertSame(1000, (int) $order->discount_minor);
        $this->assertSame(0, (int) $order->platform_discount_minor);
        $this->assertSame(3000, (int) $order->platform_shipping_discount_minor);
        $this->assertSame(24000, (int) $sellerOrder->total_minor);
        $this->assertSame(1000, (int) $sellerOrder->discount_minor);
        $this->assertSame(5000, (int) $sellerOrder->shipping_fee_minor);
        $this->assertSame(3000, (int) $sellerOrder->platform_shipping_discount_minor);
        $this->assertSame(2000, (int) $order->buyer_paid_shipping_fee_minor);
        $this->assertSame(2000, (int) $sellerOrder->buyer_paid_shipping_fee_minor);
        $this->assertSame('buyer_and_platform', $order->shipping_funding_source);
        $this->assertSame('buyer_and_platform', $sellerOrder->shipping_funding_source);
        $this->assertSame(2000, (int) $sellerOrder->shipping_quote['shipping_fee_breakdown']['buyer_paid_minor']);
        $this->assertSame(3000, (int) $sellerOrder->shipping_quote['shipping_fee_breakdown']['platform_subsidy_minor']);
        $this->assertSame(1000, (int) $item->discount_minor);
        $this->assertSame(0, (int) $item->platform_discount_minor);
        $this->assertSame(21000, (int) $order->payments->first()->amount_minor);

        $storeRedemption = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->where('store_promotion_id', $storeVoucher->id)
            ->firstOrFail();
        $shippingRedemption = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->where('platform_promotion_id', $shippingVoucher->id)
            ->firstOrFail();

        $this->assertSame(StorePromotion::FUNDING_SELLER, $storeRedemption->funding_type);
        $this->assertSame(1000, (int) $storeRedemption->discount_minor);
        $this->assertSame(0, (int) $storeRedemption->shipping_discount_minor);
        $this->assertSame(PlatformPromotion::FUNDING_PLATFORM, $shippingRedemption->funding_type);
        $this->assertSame(0, (int) $shippingRedemption->discount_minor);
        $this->assertSame(3000, (int) $shippingRedemption->shipping_discount_minor);
        $this->assertSame(PromotionRedemption::STATUS_RESERVED, $storeRedemption->status);
        $this->assertSame(PromotionRedemption::STATUS_RESERVED, $shippingRedemption->status);
        $this->assertDatabaseHas('promotion_claims', [
            'buyer_id' => $buyer->id,
            'store_promotion_id' => $storeVoucher->id,
            'status' => PromotionClaim::STATUS_USED,
        ]);
        $this->assertDatabaseHas('promotion_claims', [
            'buyer_id' => $buyer->id,
            'platform_promotion_id' => $shippingVoucher->id,
            'status' => PromotionClaim::STATUS_USED,
        ]);

        $storeAllocation = PromotionRedemptionAllocation::query()
            ->where('promotion_redemption_id', $storeRedemption->id)
            ->firstOrFail();
        $shippingAllocation = PromotionRedemptionAllocation::query()
            ->where('promotion_redemption_id', $shippingRedemption->id)
            ->firstOrFail();
        $this->assertSame((int) $item->id, (int) $storeAllocation->order_item_id);
        $this->assertSame(1000, (int) $storeAllocation->amount_minor);
        $this->assertNull($shippingAllocation->order_item_id);
        $this->assertSame(3000, (int) $shippingAllocation->amount_minor);
    }

    public function test_one_platform_merchandise_and_shipping_voucher_can_stack_with_one_store_voucher(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'three-way-voucher-stack');
        $product = $this->product($store, $category, 'Three Way Voucher Stack Item');
        $variant = $this->variant($product, 'STACK-THREE-WAY-001', 20000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $storeVoucher = $this->storeVoucher($store, $product, [
            'name' => 'Seller ₱5 off',
            'voucher_amount_minor' => 500,
        ]);
        $platformMerchandiseVoucher = $this->platformVoucher([
            'name' => 'Bearly ₱10 merchandise voucher',
            'code' => 'MERCH10',
            'value_minor' => 1000,
            'is_claimable' => true,
        ]);
        $platformShippingVoucher = $this->platformPromotion([
            'name' => 'Bearly ₱5 shipping voucher',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'max_discount_minor' => 500,
            'is_claimable' => true,
        ]);

        app(PromotionService::class)->claim($buyer, ['store_promotion_id' => $storeVoucher->id]);
        app(PromotionService::class)->claim($buyer, ['platform_promotion_id' => $platformMerchandiseVoucher->id]);
        app(PromotionService::class)->claim($buyer, ['platform_promotion_id' => $platformShippingVoucher->id]);

        $conflictingMerchandiseVoucher = $this->platformVoucher([
            'name' => 'Second Bearly merchandise voucher',
            'code' => 'MERCH20',
            'value_minor' => 2000,
        ]);
        $this->actingAs($buyer)
            ->postJson(route('checkout.quote'), [
                'address_id' => $destination->id,
                'cart_item_ids' => [$cartItem->id],
                'platform_voucher_id' => $platformMerchandiseVoucher->id,
                'platform_shipping_voucher_id' => $platformShippingVoucher->id,
                'store_voucher_id' => $storeVoucher->id,
                'voucher_code' => $conflictingMerchandiseVoucher->code,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voucher_code');

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'platform_voucher_id' => $platformMerchandiseVoucher->id,
            'platform_shipping_voucher_id' => $platformShippingVoucher->id,
            'store_voucher_id' => $storeVoucher->id,
        ]);

        $this->assertSame(1000, (int) $quote['platform_discount_minor']);
        $this->assertSame(500, (int) $quote['shipping_discount_minor']);
        $this->assertSame(500, (int) $quote['seller_discount_minor']);
        $this->assertSame(23000, (int) $quote['total_minor']);
        $this->assertCount(3, $quote['applied_promotions']);
        $this->assertSame((int) $platformMerchandiseVoucher->id, (int) $quote['promotion_selection']['platform_voucher_id']);
        $this->assertSame((int) $platformShippingVoucher->id, (int) $quote['promotion_selection']['platform_shipping_voucher_id']);

        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);
        $sellerOrder = $order->sellerOrders->sole();
        $this->assertSame(23000, (int) $order->total_minor);
        $this->assertSame(1500, (int) $order->discount_minor);
        $this->assertSame(500, (int) $order->platform_shipping_discount_minor);
        $this->assertSame(24500, (int) $sellerOrder->total_minor);
        $this->assertSame(500, (int) $sellerOrder->discount_minor);
        $this->assertSame(1000, (int) $sellerOrder->platform_discount_minor);
        $this->assertSame(500, (int) $sellerOrder->platform_shipping_discount_minor);
        $this->assertSame(3, PromotionRedemption::query()->where('order_id', $order->id)->count());
        $this->assertSame(3, PromotionRedemptionAllocation::query()
            ->whereIn('promotion_redemption_id', PromotionRedemption::query()->where('order_id', $order->id)->select('id'))
            ->count());
    }

    public function test_platform_voucher_suppresses_automatic_merchandise_discount(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-automatic');
        $product = $this->product($store, $category, 'Automatic Item');
        $variant = $this->variant($product, 'AUTO-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $this->platformPromotion([
            'name' => 'Bearly Automatic ₱20 off',
            'code' => null,
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'value_minor' => 2000,
        ]);

        $withoutVoucher = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(2000, (int) $withoutVoucher['platform_discount_minor']);
        $this->assertSame(13000, (int) $withoutVoucher['total_minor']);

        $this->platformVoucher([
            'name' => 'Bearly ₱3 off',
            'code' => 'V300',
            'value_minor' => 300,
        ]);

        $withVoucher = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'voucher_code' => 'V300',
        ]);
        $this->assertSame(300, (int) $withVoucher['platform_discount_minor']);
        $this->assertSame(14700, (int) $withVoucher['total_minor']);
        $this->assertCount(1, $withVoucher['applied_promotions']);
        $this->assertSame('platform', $withVoucher['applied_promotions'][0]['source']);
    }

    public function test_free_shipping_voucher_stacks_with_automatic_merchandise_discount(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-shipping');
        $product = $this->product($store, $category, 'Shipping Item');
        $variant = $this->variant($product, 'SHIP-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $this->platformPromotion([
            'name' => 'Bearly Automatic ₱10 off',
            'code' => null,
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'value_minor' => 1000,
        ]);

        $this->platformVoucher([
            'name' => 'Bearly Free Shipping',
            'code' => 'FREESHIP',
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'voucher_code' => 'FREESHIP',
        ]);

        $this->assertSame(5000, (int) $quote['shipping_fee_minor']);
        $this->assertSame(1000, (int) $quote['platform_discount_minor']);
        $this->assertSame(5000, (int) $quote['shipping_discount_minor']);
        $this->assertSame(9000, (int) $quote['total_minor']);
        $this->assertSame(9000, (int) $quote['seller_orders'][0]['buyer_total_minor']);

        $sources = array_column($quote['applied_promotions'], 'source');
        $this->assertContains('platform', $sources);
        $this->assertContains('automatic', $sources);
    }

    public function test_free_shipping_is_shown_in_buyer_shipping_vouchers_and_claimable_offers_require_selection(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'free-shipping-wallet');
        $variant = $this->variant($this->product($store, $category, 'Wallet Free Shipping Item'), 'SHIP-WALLET-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $this->setShippingFeeMinor(4500);

        $claimable = $this->platformPromotion([
            'name' => 'Claimable Free Shipping',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'max_discount_minor' => 1500,
            'is_claimable' => true,
        ]);
        $automatic = $this->platformPromotion([
            'name' => 'Automatic Free Shipping',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'is_claimable' => false,
        ]);

        $wallet = app(PromotionService::class)->wallet($buyer);
        $claimableCard = $wallet['platform']->firstWhere('id', (int) $claimable->id);
        $automaticCard = $wallet['platform']->firstWhere('id', (int) $automatic->id);
        $this->assertNotNull($claimableCard);
        $this->assertSame('shipping', $claimableCard['section']);
        $this->assertTrue($claimableCard['can_claim']);
        $this->assertFalse($claimableCard['auto_applies']);
        $this->assertNotNull($automaticCard);
        $this->assertTrue($automaticCard['auto_applies']);
        $this->assertFalse($automaticCard['can_claim']);

        $this->actingAs($buyer)
            ->postJson(route('buyer.vouchers.claim'), ['platform_promotion_id' => $claimable->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $claimable->id)
            ->assertJsonPath('data.section', 'shipping');

        $automatic->update(['status' => PlatformPromotion::STATUS_PAUSED]);
        $unselectedQuote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(4500, (int) $unselectedQuote['shipping_fee_minor']);
        $this->assertSame(0, (int) $unselectedQuote['shipping_discount_minor']);
        $this->assertSame(14500, (int) $unselectedQuote['total_minor']);

        $selectedQuote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'platform_voucher_id' => $claimable->id,
        ]);
        $this->assertSame(1500, (int) $selectedQuote['shipping_discount_minor']);
        $this->assertSame(13000, (int) $selectedQuote['total_minor']);
        $this->assertNull($selectedQuote['promotion_selection']['platform_voucher_id']);
        $this->assertSame((int) $claimable->id, (int) $selectedQuote['promotion_selection']['platform_shipping_voucher_id']);
    }

    public function test_free_shipping_without_a_cap_covers_actual_quoted_shipping_and_records_allocation(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'free-shipping-no-cap');
        $variant = $this->variant($this->product($store, $category, 'Uncapped Shipping Item'), 'SHIP-NOCAP-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $shippingFeeMinor = 6375;
        $this->setShippingFeeMinor($shippingFeeMinor);

        $promotion = $this->platformPromotion([
            'name' => 'Uncapped Free Shipping',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'max_discount_minor' => null,
            'budget_minor' => 20000,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame($shippingFeeMinor, (int) $quote['shipping_fee_minor']);
        $this->assertSame($shippingFeeMinor, (int) $quote['shipping_discount_minor']);
        $this->assertSame(0, (int) $quote['buyer_paid_shipping_fee_minor']);
        $this->assertSame($shippingFeeMinor, (int) $quote['platform_shipping_subsidy_minor']);
        $this->assertSame('platform', $quote['shipping_funding_source']);
        $this->assertSame(10000, (int) $quote['total_minor']);

        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);
        $sellerOrder = $order->sellerOrders->first();
        $redemption = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->where('platform_promotion_id', $promotion->id)
            ->firstOrFail();
        $allocation = PromotionRedemptionAllocation::query()
            ->where('promotion_redemption_id', $redemption->id)
            ->firstOrFail();

        $this->assertSame($shippingFeeMinor, (int) $order->shipping_fee_minor);
        $this->assertSame(0, (int) $order->buyer_paid_shipping_fee_minor);
        $this->assertSame('platform', $order->shipping_funding_source);
        $this->assertSame($shippingFeeMinor, (int) $order->platform_shipping_discount_minor);
        $this->assertSame(10000, (int) $order->total_minor);
        $this->assertSame($shippingFeeMinor, (int) $sellerOrder->shipping_fee_minor);
        $this->assertSame(0, (int) $sellerOrder->buyer_paid_shipping_fee_minor);
        $this->assertSame('platform', $sellerOrder->shipping_funding_source);
        $this->assertSame($shippingFeeMinor, (int) $redemption->shipping_discount_minor);
        $this->assertSame(0, (int) $redemption->discount_minor);
        $this->assertNull($allocation->order_item_id);
        $this->assertSame($shippingFeeMinor, (int) $allocation->amount_minor);
        $this->assertSame((int) $sellerOrder->id, (int) $allocation->seller_order_id);
        $this->assertSame(10000, (int) $order->payments->first()->amount_minor);
        $this->assertSame(20000, (int) $promotion->budget_minor);
    }

    public function test_free_shipping_cap_applies_once_to_actual_shipping_total_across_seller_orders(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $firstStore] = $this->sellerAndStore($category, 'free-shipping-multi-first');
        [, $secondStore] = $this->sellerAndStore($category, 'free-shipping-multi-second');
        $firstVariant = $this->variant($this->product($firstStore, $category, 'First Shipping Item'), 'SHIP-MULTI-001', 5000, 5);
        $secondVariant = $this->variant($this->product($secondStore, $category, 'Second Shipping Item'), 'SHIP-MULTI-002', 5000, 5);
        $firstCartItem = $this->cartItem($buyer, $firstVariant, 1);
        $secondCartItem = $this->cartItem($buyer, $secondVariant, 1);
        $sellerOrderFeeMinor = 3100;
        $shippingTotalMinor = $sellerOrderFeeMinor * 2;
        $capMinor = 4300;
        $this->setShippingFeeMinor($sellerOrderFeeMinor);

        $promotion = $this->platformPromotion([
            'name' => 'Multi-store Capped Free Shipping',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'max_discount_minor' => $capMinor,
            'budget_minor' => 10000,
        ]);

        $quote = $this->quote($buyer, [$firstCartItem->id, $secondCartItem->id], $destination->id);
        $this->assertSame($shippingTotalMinor, (int) $quote['shipping_fee_minor']);
        $this->assertSame($capMinor, (int) $quote['shipping_discount_minor']);
        $this->assertSame(11900, (int) $quote['total_minor']);
        $this->assertCount(2, $quote['seller_orders']);
        foreach ($quote['seller_orders'] as $sellerOrderQuote) {
            $this->assertSame($sellerOrderFeeMinor, (int) $sellerOrderQuote['shipping_fee_minor']);
        }

        $order = $this->place(
            $buyer,
            [$firstCartItem->id, $secondCartItem->id],
            $destination->id,
            $quote['quote_token'],
            $quote['promotion_selection'],
        );
        $redemption = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->where('platform_promotion_id', $promotion->id)
            ->firstOrFail();
        $allocations = PromotionRedemptionAllocation::query()
            ->where('promotion_redemption_id', $redemption->id)
            ->orderBy('seller_order_id')
            ->get();

        $this->assertSame($shippingTotalMinor, (int) $order->shipping_fee_minor);
        $this->assertSame($capMinor, (int) $order->platform_shipping_discount_minor);
        $this->assertSame(11900, (int) $order->total_minor);
        $this->assertSame($capMinor, (int) $redemption->shipping_discount_minor);
        $this->assertCount(2, $allocations);
        $this->assertSame($capMinor, (int) $allocations->sum('amount_minor'));
        $this->assertSame([2150, 2150], $allocations->pluck('amount_minor')->map(fn ($amount): int => (int) $amount)->all());
        $this->assertSame(
            $order->sellerOrders->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            $allocations->pluck('seller_order_id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
        );
        $this->assertSame(11900, (int) $order->payments->first()->amount_minor);
    }

    public function test_free_shipping_cap_above_actual_quoted_shipping_is_limited_to_that_shipping_total(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'free-shipping-high-cap');
        $variant = $this->variant($this->product($store, $category, 'High Cap Shipping Item'), 'SHIP-HIGH-CAP-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $shippingFeeMinor = 2875;
        $this->setShippingFeeMinor($shippingFeeMinor);
        $this->platformPromotion([
            'name' => 'Free Shipping Cap Above Quote',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'max_discount_minor' => 5000,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id);

        $this->assertSame($shippingFeeMinor, (int) $quote['shipping_fee_minor']);
        $this->assertSame($shippingFeeMinor, (int) $quote['shipping_discount_minor']);
        $this->assertSame(10000, (int) $quote['total_minor']);
    }

    public function test_shipping_discount_is_not_applied_when_store_target_misses_or_shipping_fee_is_zero(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $cartStore] = $this->sellerAndStore($category, 'shipping-target-cart');
        [, $targetStore] = $this->sellerAndStore($category, 'shipping-target-other');
        $variant = $this->variant($this->product($cartStore, $category, 'Untargeted Shipping Item'), 'SHIP-UNTARGETED-001', 5000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $this->setShippingFeeMinor(3400);

        $targetedPromotion = $this->platformPromotion([
            'name' => 'Free Shipping For Another Store',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
        ]);
        $targetedPromotion->stores()->attach($targetStore->id);

        $untargetedQuote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(3400, (int) $untargetedQuote['shipping_fee_minor']);
        $this->assertSame(0, (int) $untargetedQuote['shipping_discount_minor']);
        $this->assertSame(8400, (int) $untargetedQuote['total_minor']);

        $this->setShippingFeeMinor(0);
        $zeroFeePromotion = $this->platformPromotion([
            'name' => 'Free Shipping With Zero Quote',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
        ]);
        $zeroFeePromotion->stores()->attach($cartStore->id);

        $zeroFeeQuote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(0, (int) $zeroFeeQuote['shipping_fee_minor']);
        $this->assertSame(0, (int) $zeroFeeQuote['shipping_discount_minor']);
        $this->assertSame(5000, (int) $zeroFeeQuote['total_minor']);
        $this->assertSame([], $zeroFeeQuote['applied_promotions']);
    }

    public function test_fixed_and_percentage_shipping_discount_semantics_remain_intact(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'shipping-types');
        $variant = $this->variant($this->product($store, $category, 'Shipping Type Item'), 'SHIP-TYPES-001', 5000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $this->setShippingFeeMinor(5000);

        $fixed = $this->platformPromotion([
            'name' => 'Fixed Shipping Discount',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
            'value_minor' => 1800,
            'max_discount_minor' => 500,
        ]);
        $fixedQuote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(1800, (int) $fixedQuote['shipping_discount_minor']);

        $fixed->update(['status' => PlatformPromotion::STATUS_PAUSED]);
        $percentage = $this->platformPromotion([
            'name' => 'Percentage Shipping Discount',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
            'value_minor' => null,
            'discount_percent' => 40,
            'max_discount_minor' => null,
        ]);
        $uncappedPercentageQuote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(2000, (int) $uncappedPercentageQuote['shipping_discount_minor']);

        $percentage->update(['max_discount_minor' => 1200]);
        $cappedPercentageQuote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(1200, (int) $cappedPercentageQuote['shipping_discount_minor']);
    }

    public function test_private_code_voucher_works_at_checkout_but_cannot_be_claimed(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-private');
        $product = $this->product($store, $category, 'Private Code Item');
        $variant = $this->variant($product, 'PRIV-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $this->platformVoucher([
            'name' => 'Private Bearly Code',
            'code' => 'PRIVATE10',
            'value_minor' => 400,
            'is_claimable' => false,
            'is_public' => false,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'voucher_code' => 'PRIVATE10',
        ]);
        $this->assertSame(400, (int) $quote['platform_discount_minor']);
        $this->assertSame(14600, (int) $quote['total_minor']);

        $this->actingAs($buyer)
            ->postJson(route('buyer.vouchers.claim'), ['code' => 'PRIVATE10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform_promotion_id');
    }

    public function test_store_voucher_code_applies_to_its_store_products(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-store-code');
        $product = $this->product($store, $category, 'Store Code Item');
        $variant = $this->variant($product, 'STORE-CODE-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $this->storeVoucher($store, $product, [
            'name' => 'Store Code Voucher',
            'code' => 'STORE700',
            'voucher_amount_minor' => 700,
            'is_claimable' => false,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'voucher_code' => 'STORE700',
        ]);
        $this->assertSame(700, (int) $quote['seller_discount_minor']);
        $this->assertSame(0, (int) $quote['platform_discount_minor']);
        $this->assertSame(14300, (int) $quote['total_minor']);

        $product->update(['voucher_eligible' => false]);
        $this->actingAs($buyer)
            ->postJson(route('checkout.quote'), [
                'address_id' => $destination->id,
                'cart_item_ids' => [$cartItem->id],
                'voucher_code' => 'STORE700',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_voucher_id');
    }

    public function test_buyer_can_claim_platform_and_store_vouchers_into_wallet(): void
    {
        $buyer = $this->user('buyer');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-wallet');
        $product = $this->product($store, $category, 'Wallet Item');
        $storeVoucher = $this->storeVoucher($store, $product, [
            'name' => 'Wallet Store Voucher',
            'code' => 'WALLETSTORE',
            'voucher_amount_minor' => 300,
        ]);
        $platformVoucher = $this->platformVoucher([
            'name' => 'Wallet Bearly Voucher',
            'code' => 'WALLETPLAT',
            'value_minor' => 200,
            'is_claimable' => true,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.vouchers.claim'), ['code' => 'WALLETPLAT'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'platform')
            ->assertJsonPath('data.id', $platformVoucher->id)
            ->assertJsonPath('data.expires', $platformVoucher->endsAt()->format('M j, Y g:i A').' PHT');

        $this->actingAs($buyer)
            ->postJson(route('buyer.vouchers.claim'), ['store_promotion_id' => $storeVoucher->id])
            ->assertCreated()
            ->assertJsonPath('data.source', 'store')
            ->assertJsonPath('data.id', $storeVoucher->id);

        $this->actingAs($buyer)
            ->getJson(route('buyer.vouchers.data'))
            ->assertOk()
            ->assertJsonPath('data.claimed_count', 2)
            ->assertJsonCount(1, 'data.platform')
            ->assertJsonCount(1, 'data.store')
            ->assertJsonPath('data.store.0.expires_at', $storeVoucher->endsAt()->toIso8601String());

        $this->assertDatabaseHas('promotion_claims', [
            'buyer_id' => $buyer->id,
            'platform_promotion_id' => $platformVoucher->id,
            'status' => PromotionClaim::STATUS_CLAIMED,
        ]);
        $this->assertDatabaseHas('promotion_claims', [
            'buyer_id' => $buyer->id,
            'store_promotion_id' => $storeVoucher->id,
            'status' => PromotionClaim::STATUS_CLAIMED,
        ]);
    }

    public function test_cancellation_releases_redemption_and_restores_claim(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promo-cancel');
        $product = $this->product($store, $category, 'Cancelled Item');
        $variant = $this->variant($product, 'CANCEL-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $platformVoucher = $this->platformVoucher([
            'name' => 'Cancellable Voucher',
            'code' => 'CANCEL10',
            'value_minor' => 1000,
            'is_claimable' => true,
            'budget_minor' => 1000,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.vouchers.claim'), ['code' => 'CANCEL10'])
            ->assertCreated();

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'platform_voucher_id' => $platformVoucher->id,
        ]);
        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);

        $claim = PromotionClaim::query()
            ->where('buyer_id', $buyer->id)
            ->where('platform_promotion_id', $platformVoucher->id)
            ->firstOrFail();
        $this->assertSame(PromotionClaim::STATUS_USED, $claim->status);

        app(CheckoutService::class)->cancel($buyer, $order->id, 'Changed my mind');

        $redemption = PromotionRedemption::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PromotionRedemption::STATUS_RELEASED, $redemption->status);
        $this->assertNotNull($redemption->released_at);

        $claim->refresh();
        $this->assertSame(PromotionClaim::STATUS_CLAIMED, $claim->status);
        $this->assertNull($claim->used_at);

        $newCartItem = $this->cartItem($buyer, $variant, 1);
        $requote = $this->quote($buyer, [$newCartItem->id], $destination->id, [
            'platform_voucher_id' => $platformVoucher->id,
        ]);
        $this->assertSame(1000, (int) $requote['platform_discount_minor']);
    }

    public function test_payment_confirmation_redeems_reservations_once(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promotion-payment-confirmation');
        $product = $this->product($store, $category, 'Promotion Payment Confirmation Item');
        $variant = $this->variant($product, 'PROMO-PAYMENT-001', 10000, 3);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $voucher = $this->platformVoucher([
            'name' => 'Payment Confirmation Voucher',
            'code' => 'PAYMENT-CONFIRM-500',
            'value_minor' => 500,
        ]);
        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, ['voucher_code' => $voucher->code]);
        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);
        $redemption = PromotionRedemption::query()->where('order_id', $order->id)->sole();
        $this->assertSame(PromotionRedemption::STATUS_RESERVED, $redemption->status);
        $this->assertNull($redemption->redeemed_at);

        $payment = $order->payments()->firstOrFail();
        $payment->update(['method' => 'gcash']);
        $admin = $this->user('admin');
        $this->actingAs($admin)
            ->post(route('admin.orders.payments.confirm', $payment), ['provider_reference' => 'PROMO-PAYMENT-REF-1'])
            ->assertRedirect();

        $redemption->refresh();
        $this->assertSame(PromotionRedemption::STATUS_REDEEMED, $redemption->status);
        $this->assertNotNull($redemption->redeemed_at);

        $this->actingAs($buyer)
            ->get(route('checkout.confirmation', $order->order_no))
            ->assertOk()
            ->assertSee('Offers applied to this order')
            ->assertSee('Payment Confirmation Voucher')
            ->assertSee('Applied');

        $this->actingAs($admin)
            ->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('Bearly offer: Payment Confirmation Voucher')
            ->assertSee('Merchandise subsidy ₱5.00')
            ->assertSee('Shipping subsidy ₱0.00');

        $this->actingAs($admin)
            ->post(route('admin.orders.payments.confirm', $payment), ['provider_reference' => 'PROMO-PAYMENT-REF-1'])
            ->assertRedirect();
        $this->assertSame(1, PromotionRedemption::query()->where('order_id', $order->id)->count());
    }

    public function test_platform_promotion_computed_lifecycle_states_use_manila_schedule_and_usage(): void
    {
        $now = now('Asia/Manila');
        $draft = $this->platformVoucher([
            'name' => 'Draft Promotion Lifecycle',
            'status' => PlatformPromotion::STATUS_DRAFT,
            'starts_on' => $now->copy()->subDay()->toDateString(),
            'ends_on' => $now->copy()->subDay()->toDateString(),
        ]);
        $scheduled = $this->platformVoucher([
            'name' => 'Scheduled Promotion Lifecycle',
            'starts_on' => $now->copy()->addDay()->toDateString(),
            'starts_time' => '00:00:00',
            'ends_on' => $now->copy()->addDays(2)->toDateString(),
            'ends_time' => '23:59:59',
        ]);
        $paused = $this->platformVoucher([
            'name' => 'Paused Promotion Lifecycle',
            'status' => PlatformPromotion::STATUS_PAUSED,
        ]);
        $expired = $this->platformVoucher([
            'name' => 'Expired Promotion Lifecycle',
            'ends_on' => $now->copy()->subDay()->toDateString(),
            'ends_time' => '23:59:59',
        ]);
        $exhausted = $this->platformVoucher([
            'name' => 'Exhausted Promotion Lifecycle',
            'redemption_limit' => 1,
        ]);

        $this->assertSame(PlatformPromotion::STATUS_DRAFT, $draft->statusKey($now));
        $this->assertSame(PlatformPromotion::STATUS_SCHEDULED, $scheduled->statusKey($now));
        $this->assertSame(PlatformPromotion::STATUS_PAUSED, $paused->statusKey($now));
        $this->assertSame(PlatformPromotion::STATUS_EXPIRED, $expired->statusKey($now));
        $this->assertTrue($exhausted->isExhausted(1, 0));
        $this->assertFalse($exhausted->isExhausted(0, 0));

        $expired->update(['status' => PlatformPromotion::STATUS_PAUSED]);
        $admin = $this->user('admin');
        $this->actingAs($admin)
            ->patch(route('admin.promotions.status', $expired), ['status' => PlatformPromotion::STATUS_ACTIVE])
            ->assertSessionHasErrors('status');
    }

    public function test_automatic_offers_enforce_per_buyer_total_redemption_and_full_budget_limits(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'automatic-usage-limits');
        $product = $this->product($store, $category, 'Automatic Usage Limit Item');
        $variant = $this->variant($product, 'AUTO-LIMIT-001', 10000, 10);
        $promotion = $this->platformPromotion([
            'name' => 'One Use Per Buyer, Two Total',
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'value_minor' => 500,
            'per_buyer_limit' => 1,
            'redemption_limit' => 2,
        ]);

        $firstItem = $this->cartItem($buyer, $variant, 1);
        $firstQuote = $this->quote($buyer, [$firstItem->id], $destination->id);
        $this->assertSame(500, (int) $firstQuote['platform_discount_minor']);
        $firstOrder = $this->place($buyer, [$firstItem->id], $destination->id, $firstQuote['quote_token'], $firstQuote['promotion_selection']);
        $this->assertSame(PromotionRedemption::STATUS_RESERVED, $firstOrder->promotionRedemptions()->sole()->status);

        $secondItem = $this->cartItem($buyer, $variant, 1);
        $secondQuote = $this->quote($buyer, [$secondItem->id], $destination->id);
        $this->assertSame(0, (int) $secondQuote['platform_discount_minor'], 'A reserved order counts against the buyer limit.');

        $secondBuyer = $this->user('buyer');
        $secondDestination = $this->address($secondBuyer, 'Santa Rosa');
        $thirdItem = $this->cartItem($secondBuyer, $variant, 1);
        $thirdQuote = $this->quote($secondBuyer, [$thirdItem->id], $secondDestination->id);
        $this->assertSame(500, (int) $thirdQuote['platform_discount_minor']);
        $this->place($secondBuyer, [$thirdItem->id], $secondDestination->id, $thirdQuote['quote_token'], $thirdQuote['promotion_selection']);

        $thirdBuyer = $this->user('buyer');
        $thirdDestination = $this->address($thirdBuyer, 'Santa Rosa');
        $fourthItem = $this->cartItem($thirdBuyer, $variant, 1);
        $fourthQuote = $this->quote($thirdBuyer, [$fourthItem->id], $thirdDestination->id);
        $this->assertSame(0, (int) $fourthQuote['platform_discount_minor'], 'Reserved redemptions count against the total limit.');
        $this->assertSame(2, PromotionRedemption::query()->where('platform_promotion_id', $promotion->id)->count());

        $budgeted = $this->platformPromotion([
            'name' => 'Automatic Hard Budget',
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'value_minor' => 500,
            'per_buyer_limit' => 5,
            'budget_minor' => 800,
        ]);
        $budgetBuyer = $this->user('buyer');
        $budgetAddress = $this->address($budgetBuyer, 'Santa Rosa');
        $budgetItem = $this->cartItem($budgetBuyer, $variant, 1);
        $budgetQuote = $this->quote($budgetBuyer, [$budgetItem->id], $budgetAddress->id);
        $this->assertSame(500, (int) $budgetQuote['platform_discount_minor']);
        $this->place($budgetBuyer, [$budgetItem->id], $budgetAddress->id, $budgetQuote['quote_token'], $budgetQuote['promotion_selection']);

        $remainingBudgetBuyer = $this->user('buyer');
        $remainingBudgetAddress = $this->address($remainingBudgetBuyer, 'Santa Rosa');
        $remainingBudgetItem = $this->cartItem($remainingBudgetBuyer, $variant, 1);
        $remainingBudgetQuote = $this->quote($remainingBudgetBuyer, [$remainingBudgetItem->id], $remainingBudgetAddress->id);
        $this->assertSame(0, (int) $remainingBudgetQuote['platform_discount_minor'], 'An automatic offer is skipped when its full benefit exceeds the remaining budget.');
        $budgetOffer = collect($remainingBudgetQuote['promotions']['automatic'])->firstWhere('id', (int) $budgeted->id);
        $this->assertNotNull($budgetOffer);
        $this->assertFalse($budgetOffer['eligible']);
        $this->assertStringContainsString('enough budget', $budgetOffer['reason']);
        $this->assertArrayNotHasKey('budget_minor', $budgetOffer);
        $this->assertSame(500, (int) PromotionRedemption::query()
            ->where('platform_promotion_id', $budgeted->id)
            ->whereIn('status', [PromotionRedemption::STATUS_RESERVED, PromotionRedemption::STATUS_REDEEMED])
            ->sum('discount_minor'));
    }

    public function test_checkout_rechecks_redemption_limits_after_a_quote_before_reserving_a_voucher(): void
    {
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'promotion-stale-quote-limit');
        $product = $this->product($store, $category, 'Limited Voucher Item');
        $variant = $this->variant($product, 'LIMITED-VOUCHER-001', 10000, 5);
        $promotion = $this->platformVoucher([
            'name' => 'One Redemption Voucher',
            'code' => 'ONE-REDEMPTION',
            'value_minor' => 500,
            'redemption_limit' => 1,
        ]);

        $firstBuyer = $this->user('buyer');
        $firstAddress = $this->address($firstBuyer, 'Santa Rosa');
        $firstCartItem = $this->cartItem($firstBuyer, $variant, 1);
        $firstQuote = $this->quote($firstBuyer, [$firstCartItem->id], $firstAddress->id, [
            'voucher_code' => $promotion->code,
        ]);

        $secondBuyer = $this->user('buyer');
        $secondAddress = $this->address($secondBuyer, 'Santa Rosa');
        $secondCartItem = $this->cartItem($secondBuyer, $variant, 1);
        $secondQuote = $this->quote($secondBuyer, [$secondCartItem->id], $secondAddress->id, [
            'voucher_code' => $promotion->code,
        ]);
        $this->assertSame(500, (int) $firstQuote['platform_discount_minor']);
        $this->assertSame(500, (int) $secondQuote['platform_discount_minor']);

        $firstOrder = $this->place(
            $firstBuyer,
            [$firstCartItem->id],
            $firstAddress->id,
            $firstQuote['quote_token'],
            $firstQuote['promotion_selection'],
        );
        $this->assertSame(PromotionRedemption::STATUS_RESERVED, $firstOrder->promotionRedemptions()->sole()->status);

        $this->actingAs($secondBuyer)
            ->postJson(route('checkout.place'), [
                'checkout_request_key' => (string) Str::uuid(),
                'address_id' => $secondAddress->id,
                'cart_item_ids' => [$secondCartItem->id],
                'quote_token' => $secondQuote['quote_token'],
                'payment_method' => 'cod',
                'voucher_code' => $promotion->code,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform_voucher_id');

        $this->assertSame(1, PromotionRedemption::query()->where('platform_promotion_id', $promotion->id)->count());
    }

    public function test_partial_seller_cancellation_releases_only_that_seller_orders_promotion_allocations(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$sellerA, $storeA] = $this->sellerAndStore($category, 'partial-promo-cancel-a');
        [$sellerB, $storeB] = $this->sellerAndStore($category, 'partial-promo-cancel-b');
        $productA = $this->product($storeA, $category, 'Partial Cancellation Item A');
        $productB = $this->product($storeB, $category, 'Partial Cancellation Item B');
        $variantA = $this->variant($productA, 'PARTIAL-PROMO-A-001', 10000, 5);
        $variantB = $this->variant($productB, 'PARTIAL-PROMO-B-001', 10000, 5);
        $cartItemA = $this->cartItem($buyer, $variantA, 1);
        $cartItemB = $this->cartItem($buyer, $variantB, 1);

        $merchandiseVoucher = $this->platformVoucher([
            'name' => 'Partially Released Merchandise Voucher',
            'code' => 'PARTIAL-MERCH',
            'value_minor' => 1000,
            'per_buyer_limit' => 2,
            'budget_minor' => 1500,
        ]);
        $shippingVoucher = $this->platformPromotion([
            'name' => 'Partially Released Shipping Voucher',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
            'per_buyer_limit' => 2,
            'budget_minor' => 19999,
            'is_claimable' => true,
        ]);
        app(PromotionService::class)->claim($buyer, ['platform_promotion_id' => $shippingVoucher->id]);

        $quote = $this->quote($buyer, [$cartItemA->id, $cartItemB->id], $destination->id, [
            'platform_shipping_voucher_id' => $shippingVoucher->id,
            'voucher_code' => $merchandiseVoucher->code,
        ]);
        $this->assertSame(1000, (int) $quote['platform_discount_minor']);
        $this->assertSame(10000, (int) $quote['shipping_discount_minor']);
        $order = $this->place(
            $buyer,
            [$cartItemA->id, $cartItemB->id],
            $destination->id,
            $quote['quote_token'],
            $quote['promotion_selection'],
        );
        $sellerOrderA = $order->sellerOrders->firstWhere('store_id', (int) $storeA->id);
        $sellerOrderB = $order->sellerOrders->firstWhere('store_id', (int) $storeB->id);
        $this->assertNotNull($sellerOrderA);
        $this->assertNotNull($sellerOrderB);

        $merchandiseRedemption = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->where('platform_promotion_id', $merchandiseVoucher->id)
            ->firstOrFail();
        $shippingRedemption = PromotionRedemption::query()
            ->where('order_id', $order->id)
            ->where('platform_promotion_id', $shippingVoucher->id)
            ->firstOrFail();
        $allocationsForStoreA = PromotionRedemptionAllocation::query()
            ->where('seller_order_id', $sellerOrderA->id)
            ->whereIn('promotion_redemption_id', [$merchandiseRedemption->id, $shippingRedemption->id])
            ->get();
        $allocationsForStoreB = PromotionRedemptionAllocation::query()
            ->where('seller_order_id', $sellerOrderB->id)
            ->whereIn('promotion_redemption_id', [$merchandiseRedemption->id, $shippingRedemption->id])
            ->get();
        $this->assertCount(2, $allocationsForStoreA);
        $this->assertCount(2, $allocationsForStoreB);

        app(SellerOrderWorkflowService::class)->transition(
            $sellerA,
            (int) $sellerOrderA->id,
            'cancel',
            'Seller cannot fulfill this order',
        );

        $merchandiseRedemption->refresh();
        $shippingRedemption->refresh();
        $this->assertSame(PromotionRedemption::STATUS_RESERVED, $merchandiseRedemption->status);
        $this->assertSame(PromotionRedemption::STATUS_RESERVED, $shippingRedemption->status);
        $this->assertSame(500, (int) $merchandiseRedemption->discount_minor);
        $this->assertSame(5000, (int) $shippingRedemption->shipping_discount_minor);
        $this->assertSame(500, (int) $allocationsForStoreA->where('promotion_redemption_id', $merchandiseRedemption->id)->sole()->fresh()->released_amount_minor);
        $this->assertSame(5000, (int) $allocationsForStoreA->where('promotion_redemption_id', $shippingRedemption->id)->sole()->fresh()->released_amount_minor);
        $this->assertSame(0, (int) $allocationsForStoreB->sum('released_amount_minor'));

        $nextBuyer = $this->user('buyer');
        $nextDestination = $this->address($nextBuyer, 'Santa Rosa');
        app(PromotionService::class)->claim($nextBuyer, ['platform_promotion_id' => $shippingVoucher->id]);
        $nextItemA = $this->cartItem($nextBuyer, $variantA, 1);
        $nextItemB = $this->cartItem($nextBuyer, $variantB, 1);
        $nextQuote = $this->quote($nextBuyer, [$nextItemA->id, $nextItemB->id], $nextDestination->id, [
            'platform_shipping_voucher_id' => $shippingVoucher->id,
            'voucher_code' => $merchandiseVoucher->code,
        ]);
        $this->assertSame(1000, (int) $nextQuote['platform_discount_minor'], 'Released merchandise allocation restores the corresponding budget.');
        $this->assertSame(10000, (int) $nextQuote['shipping_discount_minor'], 'Only the cancelled Seller Order share is released from the shipping budget.');
    }

    public function test_cod_amount_subtracts_platform_merchandise_and_shipping_discounts(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'promo-cod');
        $product = $this->product($store, $category, 'COD Item');
        $variant = $this->variant($product, 'COD-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $this->platformVoucher([
            'name' => 'COD Bearly ₱5 off',
            'code' => 'COD50',
            'value_minor' => 500,
        ]);
        $this->platformPromotion([
            'name' => 'Bearly Free Shipping',
            'code' => null,
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'value_minor' => null,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id, [
            'voucher_code' => 'COD50',
        ]);
        $this->assertSame(500, (int) $quote['platform_discount_minor']);
        $this->assertSame(5000, (int) $quote['shipping_discount_minor']);
        $this->assertSame(9500, (int) $quote['total_minor']);

        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);
        $sellerOrder = $order->sellerOrders->first();
        $this->assertSame(15000, (int) $sellerOrder->total_minor);
        $this->assertSame(500, (int) $sellerOrder->platform_discount_minor);
        $this->assertSame(5000, (int) $sellerOrder->platform_shipping_discount_minor);

        $pickup = Address::query()->whereKey($seller->sellerProfile->pickup_address_id)->firstOrFail();
        $cityCode = app(PostalCodeLookup::class)
            ->canonicalCityCode(null, (string) $pickup->province, (string) $pickup->city_municipality);
        $provider = LogisticsProfile::query()->create([
            'user_id' => $this->user('logistics')->id,
            'legal_name' => 'Bearly COD Logistics Inc.',
            'display_name' => 'Bearly COD Logistics',
            'contact_phone' => '+639171234567',
            'psgc_city_code' => $cityCode,
            'status' => 'active',
        ]);

        $shipment = app(SellerFulfillmentService::class)->createShipmentFor(
            $sellerOrder->fresh('order'),
            $seller,
            $provider,
            $pickup,
        );

        $this->assertSame(9500, (int) $shipment->cod_amount_minor);
    }

    public function test_admin_can_create_platform_voucher_and_pause_it(): void
    {
        $admin = $this->user('admin');
        Storage::fake('public');
        $startsOn = now('Asia/Manila')->toDateString();
        $endsOn = now('Asia/Manila')->addDays(14)->toDateString();

        $this->actingAs($admin)->post(route('admin.promotions.store'), [
            'name' => 'Bearly Payday ₱15.50 off',
            'code' => 'admin1550',
            'kind' => PlatformPromotion::KIND_VOUCHER,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'value' => '15.50',
            'minimum_spend' => '100',
            'starts_on' => $startsOn,
            'start_time' => '08:15',
            'ends_on' => $endsOn,
            'end_time' => '23:59',
            'per_buyer_limit' => 2,
            'is_claimable' => 1,
            'is_public' => 1,
            'is_featured' => 1,
            'scope_type' => 'all',
            'banner' => UploadedFile::fake()->create('offer-banner.jpg', 10, 'image/jpeg'),
            'banner_title' => 'A platform voucher banner',
            'banner_link' => 'https://example.com/platform-voucher',
        ])->assertRedirect(route('admin.promotions'))
            ->assertSessionHas('success_title', 'Promotion created')
            ->assertSessionHas('success', 'Platform voucher created successfully: Bearly Payday ₱15.50 off.');

        $promotion = PlatformPromotion::query()->where('code', 'ADMIN1550')->firstOrFail();
        $this->assertSame(1550, (int) $promotion->value_minor);
        $this->assertSame(10000, (int) $promotion->minimum_spend_minor);
        $this->assertSame('08:15:00', $promotion->starts_time);
        $this->assertSame('23:59:59', $promotion->ends_time);
        $this->assertTrue($promotion->is_claimable);
        $this->assertFalse($promotion->is_featured);
        $this->assertNull($promotion->banner_path);
        $this->assertNull($promotion->banner_disk);
        $this->assertNull($promotion->banner_title);
        $this->assertNull($promotion->banner_link);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'promotion.created',
            'module' => 'marketing',
            'auditable_type' => $promotion->getMorphClass(),
            'auditable_id' => $promotion->id,
            'actor_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.promotions'))
            ->assertOk()
            ->assertSee('Platform Vouchers &amp; Automatic Offers', false)
            ->assertDontSee('Platform Vouchers &amp;amp; Automatic Offers', false)
            ->assertSee('Bearly Payday ₱15.50 off')
            ->assertSee('data-admin-server-toast', false)
            ->assertSee('class="toast-stack admin-server-toast-stack"', false)
            ->assertSeeText('Platform voucher created successfully: Bearly Payday ₱15.50 off.')
            ->assertDontSee('Banner image')
            ->assertDontSee('Banner title')
            ->assertDontSee('Banner link')
            ->assertDontSee('Feature on the homepage');

        $this->actingAs($admin)->patch(route('admin.promotions.status', $promotion), [
            'status' => PlatformPromotion::STATUS_PAUSED,
        ])->assertRedirect(route('admin.promotions'));

        $this->assertSame(PlatformPromotion::STATUS_PAUSED, $promotion->fresh()->status);
        $this->assertFalse($promotion->fresh()->isActiveAt());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'promotion.status_updated',
            'module' => 'marketing',
            'auditable_type' => $promotion->getMorphClass(),
            'auditable_id' => $promotion->id,
            'actor_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('admin.promotions.store'), [
            'name' => 'Invalid automatic voucher',
            'code' => 'NOPE',
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'value' => '5',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'per_buyer_limit' => 1,
            'is_public' => 1,
            'scope_type' => 'all',
        ])->assertSessionHasErrors('code');
    }

    public function test_campaign_banners_are_only_available_on_the_campaign_form(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->get(route('admin.promotions'))
            ->assertOk()
            ->assertDontSee('name="banner"', false)
            ->assertDontSee('name="banner_title"', false)
            ->assertDontSee('name="banner_link"', false)
            ->assertDontSee('name="is_featured"', false)
            ->assertDontSee('Feature on the homepage');

        $this->actingAs($admin)
            ->get(route('admin.campaigns.create'))
            ->assertOk()
            ->assertSee('name="banner"', false)
            ->assertSee('name="banner_title"', false)
            ->assertDontSee('name="banner_link"', false)
            ->assertSee('name="is_featured"', false)
            ->assertSee('Feature on the homepage');
    }

    public function test_public_offer_payload_only_exposes_banner_creative_for_campaigns(): void
    {
        $voucher = $this->platformVoucher([
            'banner_path' => 'promotions/legacy-voucher.jpg',
            'banner_disk' => 'public',
            'banner_title' => 'Legacy voucher banner',
            'banner_link' => 'https://example.com/legacy-voucher',
            'is_featured' => true,
        ]);
        $campaign = $this->platformPromotion([
            'name' => 'Payday Campaign Banner',
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
            'banner_path' => 'campaigns/payday-banner.jpg',
            'banner_disk' => 'public',
            'banner_title' => 'Payday campaign creative',
            'banner_link' => 'https://example.com/payday-campaign',
            'is_featured' => true,
        ]);

        $offers = app(PromotionService::class)
            ->publicOffers([PlatformPromotion::KIND_VOUCHER, PlatformPromotion::KIND_SELLER_CAMPAIGN])
            ->keyBy('id');

        $this->assertNull($offers[$voucher->id]['banner_url']);
        $this->assertNull($offers[$voucher->id]['banner_title']);
        $this->assertNull($offers[$voucher->id]['banner_link']);
        $this->assertSame(Storage::disk('public')->url('campaigns/payday-banner.jpg'), $offers[$campaign->id]['banner_url']);
        $this->assertSame('Payday campaign creative', $offers[$campaign->id]['banner_title']);
        $this->assertSame('https://example.com/payday-campaign', $offers[$campaign->id]['banner_link']);

        $featuredOffers = app(PromotionService::class)
            ->publicOffers([PlatformPromotion::KIND_VOUCHER, PlatformPromotion::KIND_SELLER_CAMPAIGN], featuredOnly: true)
            ->keyBy('id');
        $this->assertArrayNotHasKey($voucher->id, $featuredOffers);
        $this->assertArrayHasKey($campaign->id, $featuredOffers);
        $this->assertFalse($offers[$voucher->id]['is_featured']);
        $this->assertTrue($offers[$campaign->id]['is_featured']);
    }

    public function test_admin_free_shipping_maximum_is_per_checkout_and_disabled_caps_are_not_stored(): void
    {
        $admin = $this->user('admin');
        $startsOn = now('Asia/Manila')->toDateString();
        $endsOn = now('Asia/Manila')->addDays(14)->toDateString();

        $form = $this->actingAs($admin)
            ->get(route('admin.promotions'))
            ->assertOk()
            ->assertSee('Maximum shipping discount (₱)', false)
            ->assertSee('Optional cap per checkout. Blank covers the full eligible shipping fee, within the offer budget.', false)
            ->assertSee('Optional cap per checkout on the shipping discount.', false)
            ->assertSee('Checked: buyers claim it in My Vouchers. Unchecked: it applies automatically when eligible.', false);
        $this->assertSame(1, preg_match(
            '/<label\b(?=[^>]*data-promotion-max-discount-field)(?=[^>]*\bhidden\b)[^>]*>.*?<input\b(?=[^>]*name="max_discount")(?=[^>]*\bdisabled\b)[^>]*>/s',
            $form->getContent(),
        ));

        $this->actingAs($admin)->post(route('admin.promotions.store'), [
            'name' => 'Capped Free Shipping',
            'kind' => PlatformPromotion::KIND_FREE_SHIPPING,
            'discount_type' => PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            'max_discount' => '12.34',
            'budget' => '100.00',
            'is_claimable' => 1,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'starts_on' => $startsOn,
            'start_time' => '00:00',
            'ends_on' => $endsOn,
            'end_time' => '23:59',
            'per_buyer_limit' => 1,
            'scope_type' => 'all',
        ])->assertRedirect(route('admin.promotions'))
            ->assertSessionHas('success_title', 'Promotion created')
            ->assertSessionHas('success', 'Free shipping voucher created successfully: Capped Free Shipping.');

        $freeShipping = PlatformPromotion::query()->where('name', 'Capped Free Shipping')->firstOrFail();
        $this->assertSame(1234, (int) $freeShipping->max_discount_minor);
        $this->assertSame(10000, (int) $freeShipping->budget_minor);
        $this->assertTrue((bool) $freeShipping->is_claimable);
        $this->assertNull($freeShipping->value_minor);

        $this->actingAs($admin)->post(route('admin.promotions.store'), [
            'name' => 'Fixed Discount With Tampered Cap',
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'value' => '5.00',
            'max_discount' => '9.99',
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'starts_on' => $startsOn,
            'start_time' => '00:00',
            'ends_on' => $endsOn,
            'end_time' => '23:59',
            'per_buyer_limit' => 1,
            'scope_type' => 'all',
        ])->assertRedirect(route('admin.promotions'))
            ->assertSessionHas('success_title', 'Promotion created')
            ->assertSessionHas('success', 'Automatic discount created successfully: Fixed Discount With Tampered Cap.');

        $fixed = PlatformPromotion::query()->where('name', 'Fixed Discount With Tampered Cap')->firstOrFail();
        $this->assertNull($fixed->max_discount_minor);

        $this->actingAs($admin)->post(route('admin.promotions.store'), [
            'name' => 'Disabled Zero Budget Offer',
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'value' => '5.00',
            'budget' => '0.00',
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'starts_on' => $startsOn,
            'start_time' => '00:00',
            'ends_on' => $endsOn,
            'end_time' => '23:59',
            'per_buyer_limit' => 1,
            'scope_type' => 'all',
        ])->assertRedirect(route('admin.promotions'));
        $zeroBudget = PlatformPromotion::query()->where('name', 'Disabled Zero Budget Offer')->firstOrFail();
        $this->assertSame(0, (int) $zeroBudget->budget_minor);
        $this->assertTrue($zeroBudget->isExhausted(0, 0));

        $this->actingAs($admin)->post(route('admin.promotions.store'), [
            'name' => 'Zero Fixed Discount',
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'value' => '0.00',
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'starts_on' => $startsOn,
            'start_time' => '00:00',
            'ends_on' => $endsOn,
            'end_time' => '23:59',
            'per_buyer_limit' => 1,
            'scope_type' => 'all',
        ])->assertSessionHasErrors('value');

        $this->actingAs($admin)->post(route('admin.promotions.store'), [
            'name' => 'Percentage With Stale Fixed Value',
            'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            'discount_type' => PlatformPromotion::DISCOUNT_PERCENT,
            'value' => '999.00',
            'discount_percent' => 10,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'starts_on' => $startsOn,
            'start_time' => '00:00',
            'ends_on' => $endsOn,
            'end_time' => '23:59',
            'per_buyer_limit' => 1,
            'scope_type' => 'all',
        ])->assertRedirect(route('admin.promotions'));

        $percentage = PlatformPromotion::query()->where('name', 'Percentage With Stale Fixed Value')->firstOrFail();
        $this->assertNull($percentage->value_minor);
        $this->assertSame(10, (int) $percentage->discount_percent);
    }

    public function test_admin_promotion_form_recovers_when_target_lists_are_not_arrays(): void
    {
        $admin = $this->user('admin');
        $startsOn = now('Asia/Manila')->toDateString();
        $endsOn = now('Asia/Manila')->addDays(2)->toDateString();

        $this->actingAs($admin)
            ->from(route('admin.promotions'))
            ->followingRedirects()
            ->post(route('admin.promotions.store'), [
                'name' => 'Malformed Target Input',
                'kind' => PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
                'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
                'value' => '5.00',
                'status' => PlatformPromotion::STATUS_ACTIVE,
                'starts_on' => $startsOn,
                'start_time' => '00:00',
                'ends_on' => $endsOn,
                'end_time' => '23:59',
                'per_buyer_limit' => 1,
                'scope_type' => 'category',
                'category_ids' => 'not-an-array',
            ])
            ->assertOk()
            ->assertSee('Check the promotion details.')
            ->assertSee('name="category_ids[]"', false);
    }

    public function test_platform_funded_campaign_keeps_seller_basis_and_order_price_snapshots_immutable(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-platform');
        $product = $this->product($store, $category, 'Platform Campaign Item');
        $variant = $this->variant($product, 'CAMPAIGN-PLATFORM-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $campaign = $this->campaign($store, $seller, $product, $variant, [
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'campaign_price_minor' => 8000,
            'budget_minor' => 5000,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id);

        $this->assertSame(8000, (int) $quote['subtotal_minor']);
        $this->assertSame(10000, (int) $quote['seller_subtotal_minor']);
        $this->assertSame(2000, (int) $quote['campaign_platform_discount_minor']);
        $this->assertSame(0, (int) $quote['platform_discount_minor']);
        $this->assertSame($campaign['campaign']->name, $quote['items'][0]['campaign_name']);
        $this->assertSame(PlatformPromotion::FUNDING_PLATFORM, $quote['items'][0]['campaign_funding']);
        $this->assertSame(15000, (int) $quote['seller_orders'][0]['total_minor']);
        $this->assertSame(13000, (int) $quote['seller_orders'][0]['buyer_total_minor']);
        $this->assertSame(13000, (int) $quote['total_minor']);

        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);
        $order->load(['sellerOrders.items', 'payments']);
        $sellerOrder = $order->sellerOrders->firstOrFail();
        $item = $sellerOrder->items->firstOrFail();

        $this->assertSame(13000, (int) $order->total_minor);
        $this->assertSame(0, (int) $order->platform_discount_minor);
        $this->assertSame(2000, (int) $order->campaign_platform_discount_minor);
        $this->assertSame(15000, (int) $sellerOrder->total_minor);
        $this->assertSame(0, (int) $sellerOrder->platform_discount_minor);
        $this->assertSame(2000, (int) $sellerOrder->campaign_platform_discount_minor);
        $this->assertSame(10000, (int) $item->unit_price_minor);
        $this->assertSame(10000, (int) $item->subtotal_minor);
        $this->assertSame(0, (int) $item->platform_discount_minor);
        $this->assertSame(2000, (int) $item->campaign_platform_discount_minor);
        $this->assertSame(13000, (int) $order->payments->first()->amount_minor);
        $this->assertSame((int) $campaign['item']->id, (int) $item->platform_campaign_participation_item_id);

        $this->actingAs($buyer)
            ->get(route('checkout.confirmation', $order->order_no))
            ->assertOk()
            ->assertSee('₱80.00 each')
            ->assertSee('Bearly campaign savings (included in item prices)');
        $this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->assertJsonPath('data.0.items.0.price_minor', 8000);

        $variant->update(['price_minor' => 12000]);
        $campaign['item']->update(['campaign_price_minor' => 6000]);
        $this->assertSame(10000, (int) $item->fresh()->unit_price_minor);
        $this->assertSame(10000, (int) $item->fresh()->subtotal_minor);
        $this->assertSame(2000, (int) $item->fresh()->campaign_platform_discount_minor);
    }

    public function test_seller_funded_campaign_reduces_seller_proceeds_without_platform_discount(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-seller');
        $product = $this->product($store, $category, 'Seller Campaign Item');
        $variant = $this->variant($product, 'CAMPAIGN-SELLER-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $this->campaign($store, $seller, $product, $variant, [
            'funding_type' => PlatformPromotion::FUNDING_SELLER,
            'campaign_price_minor' => 7000,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id);

        $this->assertSame(7000, (int) $quote['subtotal_minor']);
        $this->assertSame(7000, (int) $quote['seller_subtotal_minor']);
        $this->assertSame(0, (int) $quote['campaign_platform_discount_minor']);
        $this->assertSame(0, (int) $quote['platform_discount_minor']);
        $this->assertSame(12000, (int) $quote['seller_orders'][0]['total_minor']);
        $this->assertSame(12000, (int) $quote['total_minor']);
    }

    public function test_configured_campaign_minimum_and_bearly_subsidy_guard_follow_seller_price_changes(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-price-change-guard');
        $product = $this->product($store, $category, 'Campaign Price Change Guard Item');
        $variant = $this->variant($product, 'CAMPAIGN-PRICE-GUARD-001', 10000, 5);
        $campaign = $this->campaign($store, $seller, $product, $variant, [
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'campaign_price_minor' => 8000,
            'minimum_discount_percent' => 20,
        ]);

        $approvedPrice = (new ProductPricingService)->priceForVariant($product->fresh(), $variant->fresh());
        $this->assertSame('campaign', $approvedPrice['price_source']);
        $this->assertSame(8000, $approvedPrice['unit_price_minor']);
        $this->assertSame(2000, $approvedPrice['platform_unit_discount_minor']);

        $variant->update(['price_minor' => 9000]);
        $belowMinimum = (new ProductPricingService)->priceForVariant($product->fresh(), $variant->fresh());
        $this->assertSame('regular', $belowMinimum['price_source']);
        $this->assertSame(9000, $belowMinimum['unit_price_minor']);
        $this->assertSame(0, $belowMinimum['platform_unit_discount_minor']);
        $this->assertNull($belowMinimum['campaign_participation_item_id']);

        $variant->update(['price_minor' => 11000]);
        $expandedSubsidy = (new ProductPricingService)->priceForVariant($product->fresh(), $variant->fresh());
        $this->assertSame('regular', $expandedSubsidy['price_source']);
        $this->assertSame(11000, $expandedSubsidy['unit_price_minor']);
        $this->assertSame(0, $expandedSubsidy['platform_unit_discount_minor']);
        $this->assertNull($expandedSubsidy['campaign_participation_item_id']);
        $this->assertSame(10000, (int) $campaign['item']->fresh()->regular_price_minor);
    }

    public function test_open_draft_paused_ended_private_or_out_of_schedule_campaigns_do_not_reach_buyer_pricing(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'inactive-campaign-pricing');
        $product = $this->product($store, $category, 'Inactive Campaign Product');
        $variant = $this->variant($product, 'INACTIVE-CAMPAIGN-001', 10000, 5);
        $manila = now('Asia/Manila');
        $cases = [
            'open' => ['status' => PlatformPromotion::STATUS_OPEN],
            'draft' => ['status' => PlatformPromotion::STATUS_DRAFT],
            'paused' => ['status' => PlatformPromotion::STATUS_PAUSED],
            'ended' => ['status' => PlatformPromotion::STATUS_ENDED],
            'private' => ['is_public' => false],
            'future schedule' => ['starts_on' => $manila->copy()->addDay()->toDateString()],
            'expired schedule' => ['ends_on' => $manila->copy()->subDay()->toDateString()],
        ];

        foreach ($cases as $label => $overrides) {
            $this->campaign($store, $seller, $product, $variant, array_merge([
                'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
                'campaign_price_minor' => 5000,
            ], $overrides));

            $price = (new ProductPricingService)->priceForVariant($product->fresh(), $variant->fresh());

            $this->assertSame('regular', $price['price_source'], $label);
            $this->assertSame(10000, $price['unit_price_minor'], $label);
            $this->assertSame(0, $price['platform_unit_discount_minor'], $label);
            $this->assertNull($price['campaign_participation_item_id'], $label);
            $this->assertNull($price['campaign_name'], $label);
        }
    }

    public function test_admin_budget_estimate_separates_actual_approved_and_pending_exposure_and_does_not_block_approval(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-budget-approved');
        $limitedProduct = $this->product($store, $category, 'Campaign Budget Limited Item');
        $limitedVariant = $this->variant($limitedProduct, 'CAMPAIGN-BUDGET-LIMIT-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $limitedVariant, 1);
        $campaignData = $this->campaign($store, $seller, $limitedProduct, $limitedVariant, [
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'campaign_price_minor' => 8000,
            'stock_limit' => 5,
            'budget_minor' => 3999,
        ]);

        $quote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $this->assertSame(2000, (int) $quote['campaign_platform_discount_minor']);
        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);
        $order->load(['sellerOrders.items']);
        $this->assertSame(2000, (int) $order->sellerOrders->first()->items->first()->campaign_platform_discount_minor);

        $secondBuyer = $this->user('buyer');
        $secondDestination = $this->address($secondBuyer, 'Santa Rosa');
        $secondCartItem = $this->cartItem($secondBuyer, $limitedVariant, 1);
        $secondQuote = $this->quote($secondBuyer, [$secondCartItem->id], $secondDestination->id);
        $this->assertSame(0, (int) $secondQuote['campaign_platform_discount_minor']);
        $this->assertSame(10000, (int) $secondQuote['items'][0]['unit_price_minor']);

        $inventoryProduct = $this->product($store, $category, 'Campaign Budget Inventory Item');
        $inventoryVariant = $this->variant($inventoryProduct, 'CAMPAIGN-BUDGET-INVENTORY-001', 5000, 3);
        $inventoryCampaignItem = $campaignData['participation']->items()->create([
            'product_id' => $inventoryProduct->id,
            'product_variant_id' => $inventoryVariant->id,
            'regular_price_minor' => 5000,
            'campaign_price_minor' => 4000,
            'discount_percent' => 20,
            'stock_limit' => null,
            'per_buyer_limit' => null,
        ]);

        [$pendingSeller, $pendingStore] = $this->sellerAndStore($category, 'campaign-budget-pending');
        $pendingProduct = $this->product($pendingStore, $category, 'Campaign Budget Pending Item');
        $pendingVariant = $this->variant($pendingProduct, 'CAMPAIGN-BUDGET-PENDING-001', 10000, 10);
        $pendingParticipation = PlatformCampaignParticipation::query()->create([
            'platform_promotion_id' => $campaignData['campaign']->id,
            'store_id' => $pendingStore->id,
            'submitted_by' => $pendingSeller->id,
            'status' => PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
        ]);
        $pendingParticipation->items()->create([
            'product_id' => $pendingProduct->id,
            'product_variant_id' => $pendingVariant->id,
            'regular_price_minor' => 10000,
            'campaign_price_minor' => 7000,
            'discount_percent' => 30,
            'stock_limit' => 5,
            'per_buyer_limit' => null,
        ]);

        $campaigns = app(\App\Services\CampaignService::class);
        $estimate = $campaigns->adminBudgetEstimate($campaigns->adminDetail($campaignData['campaign']));
        $this->assertSame(3999, $estimate['budget_minor']);
        $this->assertSame(2000, $estimate['actual_spend_minor']);
        $this->assertSame(11000, $estimate['approved_remaining_exposure_minor']);
        $this->assertSame(13000, $estimate['projected_total_minor']);
        $this->assertSame(-9001, $estimate['remaining_headroom_minor']);
        $this->assertFalse($estimate['actual_at_or_over_budget']);
        $this->assertTrue($estimate['projected_exceeds_budget']);
        $this->assertSame(1, $estimate['item_estimates'][$campaignData['item']->id]['sold_units']);
        $this->assertSame(4, $estimate['item_estimates'][$campaignData['item']->id]['estimated_units']);
        $this->assertSame(2000, $estimate['item_estimates'][$campaignData['item']->id]['unit_subsidy_minor']);
        $this->assertTrue($estimate['item_estimates'][$inventoryCampaignItem->id]['inventory_based']);
        $this->assertSame(3, $estimate['item_estimates'][$inventoryCampaignItem->id]['estimated_units']);
        $this->assertSame(3000, $estimate['item_estimates'][$inventoryCampaignItem->id]['exposure_minor']);

        $pendingEstimate = $estimate['pending_participations'][$pendingParticipation->id];
        $this->assertSame(15000, $pendingEstimate['additional_exposure_minor']);
        $this->assertSame(28000, $pendingEstimate['projected_total_if_approved_minor']);
        $this->assertSame(-24001, $pendingEstimate['remaining_headroom_if_approved_minor']);
        $this->assertTrue($pendingEstimate['would_exceed_budget']);
        $this->assertSame(13000, $estimate['projected_total_minor'], 'Pending participation is not included in approved projection.');

        $admin = $this->user('admin');
        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $campaignData['campaign']))
            ->assertOk()
            ->assertSee('Actual Bearly spend to date')
            ->assertSee('₱39.99')
            ->assertSee('₱20.00')
            ->assertSee('₱110.00')
            ->assertSee('₱130.00')
            ->assertSee('₱90.01 over budget')
            ->assertSee('Projected exposure exceeds cap')
            ->assertSee('If approved: additional estimated exposure ₱150.00')
            ->assertSee('Projected total if approved: ₱280.00')
            ->assertSee('Would exceed the configured budget by ₱240.01 if approved. Estimate only; approval remains available.')
            ->assertSee('Current available inventory; changeable')
            ->assertSee('Approve');

        $campaignData['campaign']->update(['budget_minor' => 2000]);
        $atBudgetEstimate = $campaigns->adminBudgetEstimate($campaigns->adminDetail($campaignData['campaign']->fresh()));
        $this->assertTrue($atBudgetEstimate['actual_at_or_over_budget']);
        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $campaignData['campaign']))
            ->assertOk()
            ->assertSee('Actual Bearly spend has reached or exceeded the configured budget.')
            ->assertSee('Approved remaining exposure puts the projected total');

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.participations.review', $pendingParticipation), [
                'decision' => 'approve',
                'reason' => 'Approval remains an Admin decision despite the estimate.',
            ])
            ->assertRedirect();
        $this->assertSame(PlatformCampaignParticipation::STATUS_APPROVED, $pendingParticipation->fresh()->status);

        $approvedEstimate = $campaigns->adminBudgetEstimate($campaigns->adminDetail($campaignData['campaign']->fresh()));
        $this->assertSame(26000, $approvedEstimate['approved_remaining_exposure_minor']);
        $this->assertSame(28000, $approvedEstimate['projected_total_minor']);

        $buyerPage = $this->actingAs($buyer)->get(route('campaigns.show', $campaignData['campaign']))->assertOk();
        $buyerPage->assertDontSee('Actual Bearly spend to date')
            ->assertDontSee('Approved remaining exposure')
            ->assertDontSee('Projected total if approved');
        $sellerPage = $this->actingAs($seller)->get(route('seller.promotions.campaigns.show', $campaignData['campaign']))->assertOk();
        $sellerPage->assertDontSee('Actual Bearly spend to date')
            ->assertDontSee('Approved remaining exposure')
            ->assertDontSee('Projected total if approved');
    }

    public function test_campaign_budget_spend_and_sold_units_share_checkout_cancellation_and_refund_filters(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-budget-accounting');
        $product = $this->product($store, $category, 'Campaign Budget Accounting Item');
        $variant = $this->variant($product, 'CAMPAIGN-BUDGET-ACCOUNTING-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $campaign = $this->campaign($store, $seller, $product, $variant, [
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'campaign_price_minor' => 8000,
            'stock_limit' => 5,
            'budget_minor' => 10000,
        ]);
        $quote = $this->quote($buyer, [$cartItem->id], $destination->id);
        $order = $this->place($buyer, [$cartItem->id], $destination->id, $quote['quote_token'], $quote['promotion_selection']);
        $sellerOrder = $order->sellerOrders->firstOrFail();
        $itemId = (int) $sellerOrder->items->firstOrFail()->platform_campaign_participation_item_id;

        $this->assertSame(2000, app(ProductPricingService::class)->campaignActualSpendMinor($campaign['campaign']));
        $this->assertSame(1, app(ProductPricingService::class)->campaignSoldUnitsForItems([$itemId])[$itemId]);

        $order->update(['payment_status' => 'partially_refunded']);
        $this->assertSame(2000, app(ProductPricingService::class)->campaignActualSpendMinor($campaign['campaign']));
        $this->assertSame(1, app(ProductPricingService::class)->campaignSoldUnitsForItems([$itemId])[$itemId]);

        $order->update(['payment_status' => 'refunded']);
        $this->assertSame(0, app(ProductPricingService::class)->campaignActualSpendMinor($campaign['campaign']));
        $this->assertSame(0, app(ProductPricingService::class)->campaignSoldUnitsForItems([$itemId])[$itemId]);

        $order->update(['payment_status' => 'unpaid']);
        $sellerOrder->update(['status' => 'cancelled']);
        $this->assertSame(0, app(ProductPricingService::class)->campaignActualSpendMinor($campaign['campaign']));
        $this->assertSame(0, app(ProductPricingService::class)->campaignSoldUnitsForItems([$itemId])[$itemId]);
    }

    public function test_admin_budget_display_handles_uncapped_and_seller_funded_campaigns_without_buyer_or_seller_leaks(): void
    {
        $category = $this->category();
        [$platformSeller, $platformStore] = $this->sellerAndStore($category, 'campaign-budget-no-cap');
        $platformProduct = $this->product($platformStore, $category, 'Uncapped Campaign Item');
        $platformVariant = $this->variant($platformProduct, 'CAMPAIGN-BUDGET-NOCAP-001', 10000, 4);
        $uncapped = $this->campaign($platformStore, $platformSeller, $platformProduct, $platformVariant, [
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'campaign_price_minor' => 9000,
            'budget_minor' => null,
        ]);

        [$sellerFundedSeller, $sellerFundedStore] = $this->sellerAndStore($category, 'campaign-budget-seller-funded');
        $sellerFundedProduct = $this->product($sellerFundedStore, $category, 'Seller Funded Campaign Item');
        $sellerFundedVariant = $this->variant($sellerFundedProduct, 'CAMPAIGN-BUDGET-SELLER-001', 10000, 4);
        $sellerFunded = $this->campaign($sellerFundedStore, $sellerFundedSeller, $sellerFundedProduct, $sellerFundedVariant, [
            'funding_type' => PlatformPromotion::FUNDING_SELLER,
            'campaign_price_minor' => 8000,
            'budget_minor' => null,
        ]);

        $admin = $this->user('admin');
        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $uncapped['campaign']))
            ->assertOk()
            ->assertSee('No budget cap')
            ->assertSee('Actual Bearly spend to date')
            ->assertSee('Current available inventory; changeable')
            ->assertDontSee('Projected exposure exceeds cap');

        $uncapped['campaign']->update(['budget_minor' => 10000]);
        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $uncapped['campaign']))
            ->assertOk()
            ->assertSee('Projected within budget')
            ->assertSee('₱60.00 projected headroom');
        $uncapped['campaign']->update(['budget_minor' => null]);

        $sellerFundedEstimate = app(\App\Services\CampaignService::class)
            ->adminBudgetEstimate($sellerFunded['campaign']);
        $this->assertFalse($sellerFundedEstimate['applies']);
        $this->assertSame([], $sellerFundedEstimate['item_estimates']);
        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $sellerFunded['campaign']))
            ->assertOk()
            ->assertSee('No Bearly budget applies')
            ->assertDontSee('Actual Bearly spend to date');

        $buyer = $this->user('buyer');
        $buyerPage = $this->actingAs($buyer)->get(route('campaigns.show', $uncapped['campaign']))->assertOk();
        $buyerPage->assertDontSee('No budget cap')
            ->assertDontSee('Actual Bearly spend to date')
            ->assertDontSee('Projected total');
        $sellerPage = $this->actingAs($platformSeller)
            ->get(route('seller.promotions.campaigns.show', $uncapped['campaign']))
            ->assertOk();
        $sellerPage->assertDontSee('No budget cap')
            ->assertDontSee('Actual Bearly spend to date')
            ->assertDontSee('Projected total');
    }

    public function test_locked_campaign_pricing_accounts_for_projected_stock_and_budget_within_checkout(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-lock');
        $product = $this->product($store, $category, 'Campaign Limit Item');
        $variant = $this->variant($product, 'CAMPAIGN-LIMIT-001', 10000, 5);
        $campaign = $this->campaign($store, $seller, $product, $variant, [
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'campaign_price_minor' => 8000,
            'stock_limit' => 5,
            'budget_minor' => 2000,
        ]);
        $pricing = app(ProductPricingService::class);

        $first = $pricing->priceForVariant($product, $variant, 1, true);
        $second = $pricing->priceForVariant($product, $variant, 1, true);

        $this->assertSame((int) $campaign['item']->id, (int) $first['campaign_participation_item_id']);
        $this->assertSame(8000, $first['unit_price_minor']);
        $this->assertNull($second['campaign_participation_item_id']);
        $this->assertSame(10000, $second['unit_price_minor']);
    }

    public function test_campaign_per_buyer_limit_is_enforced_when_pricing_checkout_items(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-buyer-limit');
        $product = $this->product($store, $category, 'Campaign Per Buyer Item');
        $variant = $this->variant($product, 'CAMPAIGN-BUYER-LIMIT-001', 10000, 5);
        $secondVariant = $this->variant($product, 'CAMPAIGN-BUYER-LIMIT-002', 10000, 5);
        $firstCartItem = $this->cartItem($buyer, $variant, 1);
        $secondCartItem = $this->cartItem($buyer, $secondVariant, 1);
        $campaign = $this->campaign($store, $seller, $product, $variant, [
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'campaign_price_minor' => 8000,
            'stock_limit' => 5,
            'per_buyer_limit' => 1,
            'budget_minor' => 10000,
        ]);
        $campaign['item']->update(['product_variant_id' => null]);

        $quote = $this->quote($buyer, [$firstCartItem->id, $secondCartItem->id], $destination->id);
        $campaignLines = collect($quote['items'])->filter(fn (array $item): bool => $item['campaign_platform_discount_minor'] > 0);
        $this->assertCount(1, $campaignLines);
        $this->assertSame(18000, (int) $quote['subtotal_minor']);
        $this->assertSame(20000, (int) $quote['seller_subtotal_minor']);
        $this->assertSame(2000, (int) $quote['campaign_platform_discount_minor']);

        $this->place(
            $buyer,
            [$firstCartItem->id, $secondCartItem->id],
            $destination->id,
            $quote['quote_token'],
            $quote['promotion_selection'],
        );

        $thirdCartItem = $this->cartItem($buyer, $variant, 1);
        $afterPurchase = $this->quote($buyer, [$thirdCartItem->id], $destination->id);
        $this->assertSame(10000, (int) $afterPurchase['items'][0]['unit_price_minor']);
        $this->assertSame(0, (int) $afterPurchase['campaign_platform_discount_minor']);
        $this->assertSame(0, (int) $afterPurchase['items'][0]['campaign_platform_discount_minor']);
    }

    /** @return array{campaign: PlatformPromotion, participation: PlatformCampaignParticipation, item: PlatformCampaignParticipationItem} */
    private function campaign(Store $store, User $seller, Product $product, ProductVariant $variant, array $overrides = []): array
    {
        $campaign = $this->platformPromotion(array_merge([
            'name' => 'Bearly Campaign '.$this->sequence,
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(10)->toDateString(),
            'join_deadline' => now('Asia/Manila')->subDay()->toDateString(),
            'requires_approval' => true,
            'budget_minor' => null,
        ], array_diff_key($overrides, array_flip([
            'campaign_price_minor',
            'stock_limit',
            'per_buyer_limit',
            'regular_price_minor',
        ]))));
        $participation = PlatformCampaignParticipation::query()->create([
            'platform_promotion_id' => $campaign->id,
            'store_id' => $store->id,
            'submitted_by' => $seller->id,
            'status' => PlatformCampaignParticipation::STATUS_APPROVED,
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
        ]);
        $item = PlatformCampaignParticipationItem::query()->create([
            'platform_campaign_participation_id' => $participation->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'regular_price_minor' => (int) ($overrides['regular_price_minor'] ?? $variant->price_minor),
            'campaign_price_minor' => (int) ($overrides['campaign_price_minor'] ?? 8000),
            'discount_percent' => null,
            'stock_limit' => $overrides['stock_limit'] ?? null,
            'per_buyer_limit' => $overrides['per_buyer_limit'] ?? null,
        ]);

        return ['campaign' => $campaign, 'participation' => $participation, 'item' => $item];
    }

    private function platformVoucher(array $overrides = []): PlatformPromotion
    {
        return $this->platformPromotion(array_merge([
            'kind' => PlatformPromotion::KIND_VOUCHER,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
        ], $overrides));
    }

    private function platformPromotion(array $overrides = []): PlatformPromotion
    {
        return PlatformPromotion::query()->create(array_merge([
            'name' => 'Bearly Promotion '.$this->sequence,
            'code' => null,
            'kind' => PlatformPromotion::KIND_VOUCHER,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'value_minor' => 500,
            'discount_percent' => null,
            'max_discount_minor' => null,
            'minimum_spend_minor' => 0,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(30)->toDateString(),
            'redemption_limit' => null,
            'per_buyer_limit' => 1,
            'budget_minor' => null,
            'is_claimable' => false,
            'is_public' => true,
            'is_featured' => false,
            'position' => 0,
        ], $overrides));
    }

    private function storeVoucher(Store $store, Product $product, array $overrides = []): StorePromotion
    {
        $promotion = StorePromotion::query()->create(array_merge([
            'store_id' => $store->id,
            'name' => 'Store Voucher '.$this->sequence,
            'code' => null,
            'type' => StorePromotion::TYPE_STORE_VOUCHER,
            'status' => StorePromotion::STATUS_ACTIVE,
            'funding_type' => StorePromotion::FUNDING_SELLER,
            'discount_type' => StorePromotion::DISCOUNT_FIXED,
            'discount_percent' => null,
            'voucher_amount_minor' => 500,
            'max_discount_minor' => null,
            'minimum_spend_minor' => 0,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(30)->toDateString(),
            'redemption_limit' => null,
            'per_buyer_limit' => 1,
            'is_claimable' => true,
            'is_public' => true,
        ], $overrides));

        $promotion->products()->attach($product->id);

        return $promotion;
    }

    /** @return array<string, mixed> */
    private function quote(User $buyer, array $cartItemIds, int $addressId, array $selection = []): array
    {
        $response = $this->actingAs($buyer)->postJson(route('checkout.quote'), array_merge([
            'address_id' => $addressId,
            'cart_item_ids' => $cartItemIds,
        ], $selection))->assertOk();

        return $response->json('data');
    }

    private function place(User $buyer, array $cartItemIds, int $addressId, string $quoteToken, array $selection = []): Order
    {
        $this->actingAs($buyer)->postJson(route('checkout.place'), array_merge([
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $addressId,
            'cart_item_ids' => $cartItemIds,
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ], $selection))->assertRedirect();

        return Order::query()
            ->with(['sellerOrders.items', 'payments'])
            ->where('buyer_id', $buyer->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function setShippingFeeMinor(int $amountMinor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'shipping.seller_order_fee_minor'],
            ['value' => $amountMinor, 'value_type' => 'integer', 'is_public' => false],
        );
    }

    private function user(string $role): User
    {
        $sequence = ++$this->sequence;

        return User::query()->create([
            'name' => Str::headline($role).' '.$sequence,
            'first_name' => ucfirst($role),
            'last_name' => 'Promotions '.$sequence,
            'email' => "{$role}-promo-{$sequence}@example.test",
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function category(): Category
    {
        $slug = 'promo-category-'.(++$this->sequence);

        return Category::query()->create(['name' => Str::headline($slug), 'slug' => $slug]);
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(Category $category, string $slug): array
    {
        $seller = $this->user('seller');
        $pickup = $this->address($seller, 'Santa Rosa');
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Promotions Seller '.$this->sequence,
            'approved_category_id' => $category->id,
            'pickup_address_id' => $pickup->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => Str::headline($slug),
            'slug' => $slug.'-'.$this->sequence,
            'publication_status' => 'published',
        ]);

        return [$seller->fresh('sellerProfile.store'), $store];
    }

    private function address(
        User $user,
        string $city,
        string $province = 'Laguna',
        ?string $cityCode = null,
        ?string $provinceCode = null,
    ): Address {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'street' => '10 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => $city,
            'province' => $province,
            'postal_code' => '4026',
            'psgc_province_code' => $provinceCode ?? '0434',
            'psgc_city_code' => $cityCode ?? app(PostalCodeLookup::class)
                ->canonicalCityCode(null, $province, $city),
            'is_default_shipping' => true,
        ]);
    }

    private function product(Store $store, Category $category, string $name): Product
    {
        $slug = Str::slug($name).'-'.(++$this->sequence);

        return Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'payment_methods' => ['cod', 'online'],
            'voucher_eligible' => true,
        ]);
    }

    private function variant(Product $product, string $sku, int $priceMinor, int $stock, array $dimensions = [10, 10, 10]): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'stock_on_hand' => $stock,
            'stock_reserved' => 0,
            'low_stock_threshold' => 5,
            'package_weight_kg' => 0.25,
            'package_length_cm' => $dimensions[0],
            'package_width_cm' => $dimensions[1],
            'package_height_cm' => $dimensions[2],
            'is_active' => true,
        ]);
    }

    private function cartItem(User $buyer, ProductVariant $variant, int $quantity): CartItem
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $buyer->id, 'status' => 'active']);

        return CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'selected' => true,
        ]);
    }
}
