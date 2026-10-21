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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            'status' => PromotionRedemption::STATUS_REDEEMED,
        ]);
        $this->assertDatabaseHas('promotion_redemptions', [
            'order_id' => $order->id,
            'funding_type' => StorePromotion::FUNDING_SELLER,
            'status' => PromotionRedemption::STATUS_REDEEMED,
        ]);
        $this->assertDatabaseHas('promotion_claims', [
            'buyer_id' => $buyer->id,
            'store_promotion_id' => $storeVoucher->id,
            'status' => PromotionClaim::STATUS_USED,
        ]);
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
        $this->assertSame((int) $claimable->id, (int) $selectedQuote['promotion_selection']['platform_voucher_id']);
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
        $this->assertSame($shippingFeeMinor, (int) $order->platform_shipping_discount_minor);
        $this->assertSame(10000, (int) $order->total_minor);
        $this->assertSame($shippingFeeMinor, (int) $sellerOrder->shipping_fee_minor);
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
        ])->assertRedirect(route('admin.promotions'));

        $promotion = PlatformPromotion::query()->where('code', 'ADMIN1550')->firstOrFail();
        $this->assertSame(1550, (int) $promotion->value_minor);
        $this->assertSame(10000, (int) $promotion->minimum_spend_minor);
        $this->assertSame('08:15:00', $promotion->starts_time);
        $this->assertSame('23:59:59', $promotion->ends_time);
        $this->assertTrue($promotion->is_claimable);
        $this->assertTrue($promotion->is_featured);
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
            ->assertSee('Bearly Payday ₱15.50 off');

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

    public function test_admin_free_shipping_maximum_is_per_checkout_and_disabled_caps_are_not_stored(): void
    {
        $admin = $this->user('admin');
        $startsOn = now('Asia/Manila')->toDateString();
        $endsOn = now('Asia/Manila')->addDays(14)->toDateString();

        $form = $this->actingAs($admin)
            ->get(route('admin.promotions'))
            ->assertOk()
            ->assertSee('Maximum shipping discount (₱)', false)
            ->assertSee('Optional cap per checkout; blank covers the full eligible shipping cost.', false)
            ->assertSee('Optional cap per checkout; limits the percentage-based shipping discount.', false)
            ->assertSee('Checked: buyers claim it in My Vouchers and select it at checkout. Unchecked: it applies automatically when eligible.', false);
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
        ])->assertRedirect(route('admin.promotions'));

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
        ])->assertRedirect(route('admin.promotions'));

        $fixed = PlatformPromotion::query()->where('name', 'Fixed Discount With Tampered Cap')->firstOrFail();
        $this->assertNull($fixed->max_discount_minor);
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
