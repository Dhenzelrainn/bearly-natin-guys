<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_can_submit_review_with_photo_for_completed_item(): void
    {
        Storage::fake('public');
        $buyer = $this->buyer('Test Buyer', 'submit');
        [, $store] = $this->seller();
        [$product] = $this->product($store, 'submit');
        $order = $this->marketplaceOrder($buyer, 'submit');
        $sellerOrder = $this->sellerOrder($order, $store, 'submit', 'completed');
        $item = $this->item($sellerOrder, $product, 'submit');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 5,
                'comment' => 'Ang ganda, exactly as described.',
                'photo' => $this->fakePhoto('review.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5);

        $review = ProductReview::query()->sole();
        $this->assertSame($buyer->id, (int) $review->buyer_id);
        $this->assertSame($product->id, (int) $review->product_id);
        $this->assertSame($store->id, (int) $review->store_id);
        $this->assertNotNull($review->photo_path);
        Storage::disk('public')->assertExists($review->photo_path);

        $this->actingAs($buyer)
            ->getJson(route('products.reviews.data', $product->id))
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.average', 5)
            ->assertJsonPath('data.reviews.0.text', 'Ang ganda, exactly as described.')
            ->assertJsonPath('data.reviews.0.name', 't******r');
    }

    public function test_updating_a_review_keeps_one_row_and_replaces_the_photo(): void
    {
        Storage::fake('public');
        $buyer = $this->buyer('Test Buyer', 'update');
        [, $store] = $this->seller();
        [$product] = $this->product($store, 'update');
        $order = $this->marketplaceOrder($buyer, 'update');
        $sellerOrder = $this->sellerOrder($order, $store, 'update', 'completed');
        $item = $this->item($sellerOrder, $product, 'update');

        $payload = [
            'order_item_id' => $item->id,
            'rating' => 5,
            'comment' => 'First take.',
            'photo' => $this->fakePhoto('first.jpg'),
        ];
        $this->actingAs($buyer)->postJson(route('buyer.reviews.store'), $payload)->assertCreated();
        $firstPhoto = ProductReview::query()->sole()->photo_path;

        $payload['rating'] = 4;
        $payload['comment'] = 'Second take.';
        $payload['photo'] = $this->fakePhoto('second.jpg');
        $this->actingAs($buyer)->postJson(route('buyer.reviews.store'), $payload)->assertCreated();

        $this->assertSame(1, ProductReview::query()->count());
        $review = ProductReview::query()->sole();
        $this->assertSame(4, (int) $review->rating);
        $this->assertSame('Second take.', $review->comment);
        $this->assertNotSame($firstPhoto, $review->photo_path);
        Storage::disk('public')->assertMissing($firstPhoto);
        Storage::disk('public')->assertExists($review->photo_path);
    }

    public function test_review_is_rejected_until_the_seller_order_is_completed(): void
    {
        $buyer = $this->buyer('Test Buyer', 'early');
        [, $store] = $this->seller();
        [$product] = $this->product($store, 'early');
        $order = $this->marketplaceOrder($buyer, 'early');
        $sellerOrder = $this->sellerOrder($order, $store, 'early', 'handed_to_logistics');
        $item = $this->item($sellerOrder, $product, 'early');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 5,
                'comment' => 'Too early.',
            ])
            ->assertConflict();

        $this->assertSame(0, ProductReview::query()->count());
    }

    public function test_buyer_cannot_review_or_list_another_buyers_items(): void
    {
        $buyer = $this->buyer('Test Buyer', 'owner');
        $intruder = $this->buyer('Intruder Buyer', 'intruder');
        [, $store] = $this->seller();
        [$product] = $this->product($store, 'foreign');
        $order = $this->marketplaceOrder($buyer, 'foreign');
        $sellerOrder = $this->sellerOrder($order, $store, 'foreign', 'completed');
        $item = $this->item($sellerOrder, $product, 'foreign');

        $this->actingAs($intruder)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 1,
                'comment' => 'Not mine.',
            ])
            ->assertNotFound();

        $this->actingAs($intruder)
            ->getJson(route('buyer.reviews.data'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, ProductReview::query()->count());
    }

    public function test_review_input_is_validated(): void
    {
        $buyer = $this->buyer('Test Buyer', 'validation');
        [, $store] = $this->seller();
        [$product] = $this->product($store, 'validation');
        $order = $this->marketplaceOrder($buyer, 'validation');
        $sellerOrder = $this->sellerOrder($order, $store, 'validation', 'completed');
        $item = $this->item($sellerOrder, $product, 'validation');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 6,
                'comment' => 'Bad rating.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 5,
                'comment' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');

        $this->assertSame(0, ProductReview::query()->count());
    }

    public function test_hidden_reviews_are_excluded_from_public_summary(): void
    {
        $buyer = $this->buyer('Test Buyer', 'hidden');
        [, $store] = $this->seller();
        [$product] = $this->product($store, 'hidden');
        $order = $this->marketplaceOrder($buyer, 'hidden');
        $sellerOrder = $this->sellerOrder($order, $store, 'hidden', 'completed');
        $item = $this->item($sellerOrder, $product, 'hidden');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 2,
                'comment' => 'Hidden take.',
            ])
            ->assertCreated();

        ProductReview::query()->sole()->update(['is_visible' => false]);

        $this->actingAs($buyer)
            ->getJson(route('products.reviews.data', $product->id))
            ->assertOk()
            ->assertJsonPath('data.summary.count', 0)
            ->assertJsonCount(0, 'data.reviews');
    }

    public function test_seller_can_reply_to_own_store_review_but_not_foreign_ones(): void
    {
        $buyer = $this->buyer('Test Buyer', 'reply');
        [$seller, $store] = $this->seller();
        $this->seller();
        [$product] = $this->product($store, 'reply');
        $order = $this->marketplaceOrder($buyer, 'reply');
        $sellerOrder = $this->sellerOrder($order, $store, 'reply', 'completed');
        $item = $this->item($sellerOrder, $product, 'reply');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 5,
                'comment' => 'Please reply.',
            ])
            ->assertCreated();

        $review = ProductReview::query()->sole();

        $this->actingAs($seller)
            ->postJson(route('seller.support.feedback.reply', $review->id), [
                'response' => 'Salamat sa pagbili!',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'replied');

        $this->assertSame('Salamat sa pagbili!', $review->fresh()->seller_response);
        $this->assertSame($seller->id, (int) $review->fresh()->seller_response_by);

        $this->actingAs($buyer)
            ->getJson(route('products.reviews.data', $product->id))
            ->assertOk()
            ->assertJsonPath('data.reviews.0.response', 'Salamat sa pagbili!');

        $foreignSeller = User::query()->where('id', '!=', $seller->id)
            ->where('role', UserRole::Seller->value)
            ->firstOrFail();

        $this->actingAs($foreignSeller)
            ->postJson(route('seller.support.feedback.reply', $review->id), [
                'response' => 'Hijacked reply.',
            ])
            ->assertForbidden();

        $this->actingAs($seller)
            ->postJson(route('seller.support.feedback.reply', $review->id), [
                'response' => '',
            ])
            ->assertUnprocessable();

        $this->assertSame('Salamat sa pagbili!', $review->fresh()->seller_response);
    }

    public function test_seller_feedback_page_and_product_page_show_real_reviews(): void
    {
        $buyer = $this->buyer('Test Buyer', 'surfaces');
        [$seller, $store] = $this->seller();
        [$product] = $this->product($store, 'surfaces');
        $order = $this->marketplaceOrder($buyer, 'surfaces');
        $sellerOrder = $this->sellerOrder($order, $store, 'surfaces', 'completed');
        $item = $this->item($sellerOrder, $product, 'surfaces');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 5,
                'comment' => 'Verified quality.',
            ])
            ->assertCreated();

        $this->actingAs($seller)
            ->get(route('seller.support.feedback'))
            ->assertOk()
            ->assertSee('Test Buyer')
            ->assertSee('Verified quality.')
            ->assertSee($order->order_no);

        $this->actingAs($buyer)
            ->get(route('products.show', $product->id))
            ->assertOk()
            ->assertSee('Customer Reviews')
            ->assertSee('Buy now')
            ->assertSee('data-detail-buy-now', false)
            ->assertSee('Specifications')
            ->assertSee('data-review-filter="all"', false)
            ->assertSee('Verified quality.')
            ->assertSee('t******r')
            ->assertDontSee('No reviews yet');
    }

    public function test_buyer_profile_renders_review_status_as_exclusive_tab_panels(): void
    {
        $buyer = $this->buyer('Tab Buyer', 'review-tabs');

        $this->actingAs($buyer)
            ->get(route('buyer.profile'))
            ->assertOk()
            ->assertSee('id="review-tab-to-review" type="button" role="tab" tabindex="0"', false)
            ->assertSee('id="review-tab-reviewed" type="button" role="tab" tabindex="-1"', false)
            ->assertSee('id="review-section-to-review" role="tabpanel"', false)
            ->assertSee('id="review-section-reviewed" role="tabpanel" tabindex="0" aria-labelledby="review-tab-reviewed" data-review-section="reviewed" hidden', false)
            ->assertSee('data-review-section-empty="reviewed"', false);
    }

    public function test_buy_now_prepares_the_requested_cart_line_without_changing_other_items(): void
    {
        $buyer = $this->buyer('Buy Now Buyer', 'buy-now');
        [, $store] = $this->seller();
        [$product, $variant] = $this->product($store, 'buy-now');
        $otherVariant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'BUY-NOW-'.Str::upper(Str::random(8)),
            'name' => 'Large',
            'options' => ['size' => 'Large'],
            'price_minor' => 18900,
            'stock_on_hand' => 6,
            'is_active' => true,
            'position' => 1,
        ]);
        $cart = Cart::query()->create(['user_id' => $buyer->id, 'status' => 'active']);
        $existingTarget = CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 3,
            'selected' => false,
        ]);
        $unrelatedItem = CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $otherVariant->id,
            'quantity' => 4,
            'selected' => true,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('cart.buy-now'), [
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.id', $existingTarget->id)
            ->assertJsonPath('data.item.quantity', 2)
            ->assertJsonPath('data.cart_count', 6);

        $this->assertDatabaseHas('cart_items', [
            'id' => $existingTarget->id,
            'quantity' => 2,
            'selected' => true,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'id' => $unrelatedItem->id,
            'quantity' => 4,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('cart.buy-now'), [
                'product_variant_id' => $variant->id,
                'quantity' => 5,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('cart_items', [
            'id' => $existingTarget->id,
            'quantity' => 2,
        ]);
    }

    public function test_storefront_uses_real_review_aggregates_and_adds_a_product_to_cart(): void
    {
        $buyer = $this->buyer('Storefront Buyer', 'storefront');
        [, $store] = $this->seller();
        [$product, $variant] = $this->product($store, 'storefront');
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'REV-STORE-SECOND',
            'name' => 'Large / Red',
            'options' => ['size' => 'Large', 'color' => 'Red'],
            'price_minor' => 18000,
            'stock_on_hand' => 2,
            'is_active' => true,
            'position' => 1,
        ]);
        $order = $this->marketplaceOrder($buyer, 'storefront');
        $sellerOrder = $this->sellerOrder($order, $store, 'storefront', 'completed');
        $item = $this->item($sellerOrder, $product, 'storefront');

        $this->actingAs($buyer)
            ->postJson(route('buyer.reviews.store'), [
                'order_item_id' => $item->id,
                'rating' => 4,
                'comment' => 'Real storefront review.',
            ])
            ->assertCreated();

        $this->actingAs($buyer)
            ->get(route('stores.show', $store->slug))
            ->assertOk()
            ->assertSee('Buyer rating')
            ->assertSee('Verified product reviews')
            ->assertSee('4.0')
            ->assertSee('4.0 out of 5, 1 reviews', false)
            ->assertSee('Reviewable Product storefront', false)
            ->assertSee('data-store-add', false)
            ->assertSee('data-store-variant', false)
            ->assertSee('data-variant-id="'.$variant->id.'"', false);

        $this->actingAs($buyer)
            ->postJson(route('cart.add'), [
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.cart_count', 2);
    }

    /** 1x1 PNG built without GD, which is unavailable in this environment. */
    private function fakePhoto(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'review').'.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function buyer(string $name, string $suffix): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => 'review-buyer-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => UserRole::Buyer->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Buyer->value)->value('id'),
        ]);

        return $user;
    }

    /** @return array{0: User, 1: Store} */
    private function seller(): array
    {
        $suffix = ++$this->sequence;
        $user = User::factory()->create([
            'name' => "Seller {$suffix}",
            'email' => "review-seller-{$suffix}@example.test",
            'role' => UserRole::Seller->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Seller->value)->value('id'),
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'house_number' => '12',
            'street' => 'Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'is_default_pickup' => true,
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'legal_business_name' => "Seller Business {$suffix}",
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => "Seller Store {$suffix}",
            'slug' => "review-seller-store-{$suffix}",
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return [$user, $store];
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function product(Store $store, string $suffix): array
    {
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => Category::query()->firstOrFail()->id,
            'name' => "Reviewable Product {$suffix}",
            'slug' => 'reviewable-'.Str::slug($suffix).'-'.Str::lower(Str::random(6)),
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'REV-'.Str::upper(Str::random(8)),
            'name' => 'Standard',
            'price_minor' => 16600,
            'stock_on_hand' => 4,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function marketplaceOrder(User $buyer, string $suffix): Order
    {
        $destination = Address::query()->create([
            'user_id' => $buyer->id,
            'label' => 'Home',
            'recipient_name' => $buyer->name,
            'phone' => '+639171234568',
            'house_number' => '20',
            'street' => 'Buyer Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $order = Order::query()->create([
            'order_no' => 'BRY-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $destination->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $destination->phone,
            'address_line' => '20 Buyer Street',
            'barangay' => $destination->barangay,
            'city_municipality' => $destination->city_municipality,
            'province' => $destination->province,
            'postal_code' => $destination->postal_code,
            'currency' => 'PHP',
            'subtotal_minor' => 16600,
            'shipping_fee_minor' => 3400,
            'discount_minor' => 0,
            'total_minor' => 20000,
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);
        Payment::query()->create([
            'payment_no' => 'PAY-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'method' => 'cod',
            'amount_minor' => 20000,
            'status' => 'pending',
            'initiated_at' => now(),
        ]);

        return $order;
    }

    private function sellerOrder(Order $order, Store $store, string $suffix, string $status): SellerOrder
    {
        return SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => 16600,
            'discount_minor' => 0,
            'shipping_fee_minor' => 3400,
            'total_minor' => 20000,
            'ready_at' => now(),
        ]);
    }

    private function item(SellerOrder $sellerOrder, Product $product, string $suffix): OrderItem
    {
        return $sellerOrder->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'variant_name' => 'Standard',
            'sku' => 'SNAPSHOT-'.Str::upper(Str::random(8)),
            'unit_price_minor' => 16600,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 16600,
        ]);
    }
}
