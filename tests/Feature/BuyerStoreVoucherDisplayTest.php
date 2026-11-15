<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromotionClaim;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BuyerStoreVoucherDisplayTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_store_page_shows_only_its_active_public_claimable_store_vouchers(): void
    {
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'storefront-vouchers');
        [, $otherStore] = $this->sellerAndStore($category, 'other-storefront-vouchers');
        $buyer = $this->buyer();
        $product = $this->product($store, $category, 'Voucher Item');

        $this->storeVoucher($store, $product, [
            'name' => 'Welcome Store Voucher',
            'voucher_amount_minor' => 5000,
            'minimum_spend_minor' => 20000,
        ]);

        $this->storeVoucher($otherStore, $product, [
            'name' => 'Other Store Voucher',
        ]);
        $this->storeVoucher($store, $product, [
            'name' => 'Private Store Voucher',
            'is_public' => false,
        ]);
        $this->storeVoucher($store, $product, [
            'name' => 'Code Only Store Voucher',
            'is_claimable' => false,
        ]);
        $this->storeVoucher($store, $product, [
            'name' => 'Paused Store Voucher',
            'status' => StorePromotion::STATUS_PAUSED,
        ]);
        $this->storeVoucher($store, $product, [
            'name' => 'Scheduled Store Voucher',
            'starts_on' => now('Asia/Manila')->addDay()->toDateString(),
        ]);
        $this->storeVoucher($store, $product, [
            'name' => 'Expired Store Voucher',
            'ends_on' => now('Asia/Manila')->subDay()->toDateString(),
        ]);
        $this->storeVoucher($store, $product, [
            'name' => 'Exhausted Store Voucher',
            'redemption_limit' => 0,
        ]);

        $this->actingAs($buyer)
            ->get(route('stores.show', ['store' => $store->slug]))
            ->assertOk()
            ->assertSee('Store vouchers')
            ->assertSee('Welcome Store Voucher')
            ->assertSee('₱50.00')
            ->assertSee('Min. Spend ₱200.00')
            ->assertSee('data-store-voucher-claim', false)
            ->assertSee(route('buyer.vouchers.claim'), false)
            ->assertDontSee('Other Store Voucher')
            ->assertDontSee('Private Store Voucher')
            ->assertDontSee('Code Only Store Voucher')
            ->assertDontSee('Paused Store Voucher')
            ->assertDontSee('Scheduled Store Voucher')
            ->assertDontSee('Expired Store Voucher')
            ->assertDontSee('Exhausted Store Voucher');
    }

    public function test_buyer_claims_a_store_voucher_from_the_store_page_and_sees_the_claimed_state(): void
    {
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'claim-storefront-voucher');
        $buyer = $this->buyer();
        $product = $this->product($store, $category, 'Claimable Voucher Item');
        $voucher = $this->storeVoucher($store, $product, [
            'name' => 'Claim On Storefront Voucher',
            'voucher_amount_minor' => 2500,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.vouchers.claim'), ['store_promotion_id' => $voucher->id])
            ->assertCreated()
            ->assertJsonPath('data.source', 'store')
            ->assertJsonPath('data.id', $voucher->id)
            ->assertJsonPath('data.claimed', true);

        $this->assertDatabaseHas('promotion_claims', [
            'buyer_id' => $buyer->id,
            'store_promotion_id' => $voucher->id,
            'status' => PromotionClaim::STATUS_CLAIMED,
        ]);

        $this->actingAs($buyer)
            ->get(route('stores.show', ['store' => $store->slug]))
            ->assertOk()
            ->assertSee('Claim On Storefront Voucher')
            ->assertSee('data-claimed="true"', false)
            ->assertSee('Claimed')
            ->assertDontSee('data-store-voucher-claim data-store-promotion-id', false);
    }

    public function test_store_page_omits_the_voucher_section_when_no_claimable_voucher_is_available(): void
    {
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'no-storefront-vouchers');
        $buyer = $this->buyer();
        $product = $this->product($store, $category, 'No Voucher Item');

        $this->storeVoucher($store, $product, [
            'name' => 'Not Claimable Here',
            'is_claimable' => false,
        ]);

        $this->actingAs($buyer)
            ->get(route('stores.show', ['store' => $store->slug]))
            ->assertOk()
            ->assertDontSee('Store vouchers')
            ->assertDontSee('Not Claimable Here');
    }

    private function category(): Category
    {
        return Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(Category $category, string $slug): array
    {
        $sequence = ++$this->sequence;
        $seller = User::query()->create([
            'name' => 'Voucher Seller '.$sequence,
            'first_name' => 'Voucher',
            'last_name' => 'Seller '.$sequence,
            'email' => "voucher-seller-{$sequence}@example.test",
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Voucher Seller Business '.$sequence,
            'approved_category_id' => $category->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);

        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Voucher Store '.$sequence,
            'slug' => $slug,
            'publication_status' => 'published',
        ]);

        return [$seller, $store];
    }

    private function buyer(): User
    {
        $sequence = ++$this->sequence;

        return User::query()->create([
            'name' => 'Voucher Buyer '.$sequence,
            'first_name' => 'Voucher',
            'last_name' => 'Buyer '.$sequence,
            'email' => "voucher-buyer-{$sequence}@example.test",
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function product(Store $store, Category $category, string $name): Product
    {
        $sequence = ++$this->sequence;
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => 'voucher-item-'.$sequence,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'VOUCHER-ITEM-'.$sequence,
            'name' => 'Standard',
            'options' => ['Color' => 'Cream'],
            'price_minor' => 120_000,
            'stock_on_hand' => 10,
            'is_active' => true,
            'position' => 0,
        ]);

        return $product;
    }

    private function storeVoucher(Store $store, Product $product, array $overrides = []): StorePromotion
    {
        $sequence = ++$this->sequence;
        $promotion = StorePromotion::query()->create(array_merge([
            'store_id' => $store->id,
            'name' => 'Store Voucher '.$sequence,
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
}
