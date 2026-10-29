<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerAccountIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_pages_are_protected_and_render_authenticated_identity(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->get('/addresses')->assertRedirect('/login');
        $this->get('/vouchers')->assertRedirect('/login');
        $this->get('/chat')->assertRedirect('/login');

        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');

        $this->actingAs($buyer)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Ava Buyer')
            ->assertSee('buyer@example.test')
            ->assertSee('Personal Information')
            ->assertSee('Profile Photo')
            ->assertSee('My Likes')
            ->assertSee('id="likes-category-tabs"', false)
            ->assertSee('id="likes-sort"', false)
            ->assertSee('No liked products yet')
            ->assertDontSee('You may also like')
            ->assertSee('My Vouchers')
            ->assertSee('images/vouchers/Bearly-voucher-icon.png', false)
            ->assertSee('data-notification-trigger', false)
            ->assertSee('id="buyer-notification-popover"', false)
            ->assertSee('View all notifications')
            ->assertSee('Edit Profile')
            ->assertSee('Save Changes')
            ->assertSee('select id="address-province"', false)
            ->assertSee('select id="address-city"', false)
            ->assertSee('address-city-code', false)
            ->assertSee('Legacy addresses need city re-verification before checkout.')
            ->assertDontSee('Mia Santos');

        $this->actingAs($buyer)
            ->get('/home')
            ->assertOk()
            ->assertDontSee('class="flash-deals-preview home-flash-deals', false)
            ->assertDontSee('data-home-ranking-section', false)
            ->assertDontSee('No live product rankings are available yet')
            ->assertDontSee('No live Flash Deal products right now')
            ->assertDontSee('Wireless Mouse')
            ->assertDontSee('data-top-like', false)
            ->assertDontSee('data-flash-like', false)
            ->assertSee('data-notification-trigger', false)
            ->assertSee('id="buyer-notification-popover"', false)
            ->assertDontSee('SELLING FAST')
            ->assertDontSee('data-flash-countdown', false)
            ->assertSee('href="'.url('/profile#vouchers').'"', false)
            ->assertSee('href="'.url('/profile?voucher=shipping#vouchers').'"', false);

        $this->actingAs($buyer)
            ->get('/vouchers')
            ->assertRedirect('/profile#vouchers');

        $this->actingAs($buyer)
            ->get('/cart')
            ->assertOk()
            ->assertDontSee('View Wishlist');

        $this->actingAs($buyer)
            ->get('/chat')
            ->assertOk()
            ->assertSee('Talk with your sellers about products and orders.')
            ->assertSee('data-chat-filter', false)
            ->assertSee('data-chat-attachment-button', false)
            ->assertSee('data-chat-emoji', false);
    }

    public function test_ranking_and_flash_deal_pages_do_not_render_sample_product_cards(): void
    {
        $buyer = $this->makeBuyer('buyer-preview@example.test', 'Preview Buyer');

        $this->actingAs($buyer)
            ->get('/top-products')
            ->assertOk()
            ->assertSee('No live product rankings are available yet')
            ->assertDontSee('data-top-product', false)
            ->assertDontSee('featured-product-data', false)
            ->assertDontSee('Wireless Mouse');

        $this->actingAs($buyer)
            ->get('/top-sales')
            ->assertOk()
            ->assertSee('No live sales rankings are available yet')
            ->assertDontSee('data-top-product', false)
            ->assertDontSee('Resistance Band Set');

        $this->actingAs($buyer)
            ->get('/flash-deals')
            ->assertOk()
            ->assertSee('No live Flash Deal products right now')
            ->assertDontSee('data-flash-product', false)
            ->assertDontSee('Wireless Earbuds')
            ->assertDontSee('% claimed')
            ->assertDontSee('Deals refresh in');
    }

    public function test_profile_likes_and_wishlist_use_only_the_authenticated_buyers_saved_products(): void
    {
        $buyer = $this->makeBuyer('likes-owner@example.test', 'Likes Owner');
        $otherBuyer = $this->makeBuyer('likes-other@example.test', 'Other Buyer');
        ['product' => $product, 'variant' => $variant] = $this->makeProduct();

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);

        $this->actingAs($buyer)
            ->get('/profile')
            ->assertOk()
            ->assertSee('window.bearlyLiveWishlist', false)
            ->assertSee('Private Integration Product')
            ->assertSee('"product_id":'.$product->id, false);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($buyer)
            ->get('/wishlist')
            ->assertOk()
            ->assertSee('Private Integration Product')
            ->assertSee('data-wishlist-remove', false)
            ->assertSee('data-variant-id="'.$variant->id.'"', false)
            ->assertSee('Add to Cart');

        $this->actingAs($otherBuyer)
            ->get('/wishlist')
            ->assertOk()
            ->assertDontSee('Private Integration Product')
            ->assertSee('Your wishlist is empty');
    }

    public function test_home_live_product_card_data_is_hydrated_from_the_buyer_wishlist(): void
    {
        $buyer = $this->makeBuyer('home-likes@example.test', 'Home Likes Buyer');
        ['product' => $product] = $this->makeProduct();

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);

        $this->actingAs($buyer)
            ->get('/home')
            ->assertOk()
            ->assertSee('Private Integration Product')
            ->assertSee('"id":'.$product->id, false)
            ->assertSee('"is_wishlisted":true', false)
            ->assertDontSee('Wireless Mouse');
    }

    public function test_profile_updates_are_persisted_for_the_authenticated_buyer(): void
    {
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');

        $response = $this->actingAs($buyer)->patchJson('/profile', [
            'full_name' => 'Ava Updated',
            'phone' => '+63 917 123 4567',
            'gender' => 'Female',
            'birthday' => '1995-05-20',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.fullName', 'Ava Updated')
            ->assertJsonPath('data.phone', '+639171234567');

        $updatedBuyer = $buyer->fresh();

        $this->assertSame('Ava Updated', $updatedBuyer->name);
        $this->assertSame('+639171234567', $updatedBuyer->phone);
        $this->assertSame('female', $updatedBuyer->sex);
        $this->assertSame('1995-05-20', $updatedBuyer->birthday->format('Y-m-d'));
    }

    public function test_checkout_uses_canonical_contact_when_legacy_phone_is_empty(): void
    {
        $buyer = $this->makeBuyer('checkout-phone@example.test', 'Ava Buyer');
        $buyer->forceFill([
            'phone' => null,
            'contact_number' => '+639171234567',
        ])->save();

        $this->actingAs($buyer)
            ->get(route('checkout'))
            ->assertOk()
            ->assertSee('+639171234567')
            ->assertSee('Shipping is charged once per combined Seller Order shipment.')
            ->assertDontSee('using the Store pickup address, route tier, packaged SKU weight');
    }

    public function test_profile_update_rejects_duplicate_phone_and_underage_birthday(): void
    {
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');

        User::factory()->create([
            'email' => 'phone-owner@example.test',
            'contact_number' => '+639177777777',
            'phone' => '+639177777777',
        ]);

        Carbon::setTestNow('2026-10-08 12:00:00');

        try {
            $this->actingAs($buyer)
                ->patchJson('/profile', [
                    'full_name' => 'Ava Buyer',
                    'phone' => '0917 777 7777',
                    'gender' => 'Female',
                    'birthday' => '2008-10-09',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone', 'birthday']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_profile_photo_upload_and_removal_are_server_backed(): void
    {
        Storage::fake('public');
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');

        $this->actingAs($buyer)
            ->post('/profile', [
                '_method' => 'PATCH',
                'full_name' => 'Ava Buyer',
                'phone' => '',
                'gender' => '',
                'birthday' => '',
                'remove_photo' => '0',
                'photo' => UploadedFile::fake()->create('avatar.png', 6000, 'image/png'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $uploadedPath = $buyer->fresh()->profile_photo_path;

        $this->assertNotEmpty($uploadedPath);
        Storage::disk('public')->assertExists($uploadedPath);

        $this->actingAs($buyer)
            ->postJson('/profile', [
                '_method' => 'PATCH',
                'full_name' => 'Ava Buyer',
                'phone' => '',
                'gender' => '',
                'birthday' => '',
                'remove_photo' => true,
            ])
            ->assertOk();

        $this->assertNull($buyer->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($uploadedPath);
    }

    public function test_cart_data_and_mutations_cannot_cross_buyer_ownership(): void
    {
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');
        $otherBuyer = $this->makeBuyer('other@example.test', 'Other Buyer');
        ['variant' => $variant] = $this->makeProduct();

        $otherCart = Cart::create(['user_id' => $otherBuyer->id, 'status' => 'active']);
        $otherItem = CartItem::create([
            'cart_id' => $otherCart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'selected' => true,
        ]);

        $this->actingAs($buyer)
            ->getJson('/cart/data')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.cart_count', 0);

        $this->actingAs($buyer)
            ->patchJson('/cart/'.$otherItem->id, ['quantity' => 1])
            ->assertNotFound();

        $this->actingAs($buyer)
            ->deleteJson('/cart/'.$otherItem->id)
            ->assertNotFound();

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.cart_count', 1);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => Cart::where('user_id', $buyer->id)->value('id'),
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'id' => $otherItem->id,
            'cart_id' => $otherCart->id,
            'quantity' => 2,
        ]);

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $variant->id,
                'quantity' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.cart_count', 1);

        $this->actingAs($buyer)
            ->getJson('/cart/data')
            ->assertOk()
            ->assertJsonPath('data.cart_count', 1)
            ->assertJsonPath('data.items.0.quantity', 5);

        $this->actingAs($buyer)
            ->get('/profile')
            ->assertOk()
            ->assertSee('"cartCount":1', false);

        ['variant' => $unpublishedVariant] = $this->makeProduct();
        $unpublishedVariant->product->store->update(['publication_status' => 'draft']);

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $unpublishedVariant->id,
                'quantity' => 1,
            ])
            ->assertNotFound();
    }

    public function test_cart_uses_selected_variant_color_image_and_falls_back_to_primary(): void
    {
        $buyer = $this->makeBuyer('cart-image-buyer@example.test', 'Cart Image Buyer');
        ['product' => $product, 'variant' => $smallRed] = $this->makeProduct();
        $smallRed->update([
            'name' => 'Small / Red',
            'options' => ['Size' => 'Small', 'Color' => 'Red'],
            'position' => 1,
        ]);
        $largeRed = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CART-LARGE-RED-'.uniqid(),
            'name' => 'Large / Red',
            'options' => ['Size' => 'Large', 'Color' => 'Red'],
            'price_minor' => 52900,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => true,
            'position' => 2,
        ]);
        $blue = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CART-SMALL-BLUE-'.uniqid(),
            'name' => 'Small / Blue',
            'options' => ['Size' => 'Small', 'Color' => 'Blue'],
            'price_minor' => 49900,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => true,
            'position' => 3,
        ]);
        $primaryPath = 'seller-products/cart-primary.jpg';
        $redVariantPath = 'seller-products/cart-red.jpg';

        ProductImage::create([
            'product_id' => $product->id,
            'path' => $primaryPath,
            'disk' => 'public',
            'purpose' => 'primary',
            'alt_text' => 'Primary product image',
            'position' => 0,
            'is_primary' => true,
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'variant_id' => $smallRed->id,
            'path' => $redVariantPath,
            'disk' => 'public',
            'purpose' => 'variant',
            'alt_text' => 'Red variant image',
            'position' => 1,
            'is_primary' => false,
        ]);

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $largeRed->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.variant_name', 'Large / Red')
            ->assertJsonPath('data.item.image', Storage::disk('public')->url($redVariantPath));

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $blue->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.variant_name', 'Small / Blue')
            ->assertJsonPath('data.item.image', Storage::disk('public')->url($primaryPath));

        $this->actingAs($buyer)
            ->getJson('/cart/data')
            ->assertOk()
            ->assertJsonPath('data.items.0.image', Storage::disk('public')->url($redVariantPath))
            ->assertJsonPath('data.items.1.image', Storage::disk('public')->url($primaryPath));
    }

    public function test_web_cart_payload_uses_an_approved_local_http_image_url(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config([
            'app.url' => 'http://127.0.0.1:8011',
            'filesystems.disks.public.url' => 'https://bearlymarket.com/storage',
            'image_urls.local_http_hosts' => ['localhost', '127.0.0.1'],
        ]);

        $buyer = $this->makeBuyer('local-cart-image@example.test', 'Local Cart Buyer');
        ['product' => $product, 'variant' => $variant] = $this->makeProduct();
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/local-cart-bear.jpg',
            'disk' => 'public',
            'purpose' => 'primary',
            'is_primary' => true,
            'position' => 0,
        ]);
        $cart = Cart::create(['user_id' => $buyer->id, 'status' => 'active']);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'selected' => true,
        ]);

        $this->actingAs($buyer)
            ->withServerVariables([
                'HTTP_HOST' => '127.0.0.1:8011',
                'SERVER_NAME' => '127.0.0.1',
                'SERVER_PORT' => 8011,
                'HTTPS' => 'off',
            ])
            ->getJson('http://127.0.0.1:8011/cart/data')
            ->assertOk()
            ->assertJsonPath('data.items.0.image', 'http://127.0.0.1:8011/storage/products/local-cart-bear.jpg');
    }

    public function test_wishlist_keeps_online_only_legacy_items_removable_but_not_purchasable(): void
    {
        $buyer = $this->makeBuyer('online-wishlist@example.test', 'Wishlist Buyer');
        ['product' => $product] = $this->makeProduct();
        $product->update(['payment_methods' => ['online']]);

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);

        $this->actingAs($buyer)
            ->get('/wishlist')
            ->assertOk()
            ->assertSee('Saved · Unavailable with Cash on Delivery checkout')
            ->assertSee('Unavailable with Cash on Delivery checkout')
            ->assertSee('data-wishlist-remove', false)
            ->assertDontSee('Add to Cart');
    }

    public function test_wishlist_and_addresses_are_scoped_to_the_authenticated_buyer(): void
    {
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');
        $otherBuyer = $this->makeBuyer('other@example.test', 'Other Buyer');
        ['product' => $product, 'variant' => $variant] = $this->makeProduct();

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertOk();

        $this->actingAs($buyer)
            ->get('/cart')
            ->assertOk()
            ->assertSee('window.bearlyWishlistProductIds = ['.$product->id.'];', false)
            ->assertSee('cart-preview-notice', false);

        $this->actingAs($otherBuyer)
            ->get('/wishlist')
            ->assertOk()
            ->assertDontSee('Private Integration Product')
            ->assertSee('Your wishlist is empty')
            ->assertDontSee('preview-wishlist');

        $otherAddress = Address::create([
            'user_id' => $otherBuyer->id,
            'label' => 'Home',
            'recipient_name' => 'Other Buyer',
            'phone' => '+639171234568',
            'street' => 'Other Street',
            'barangay' => 'Other Barangay',
            'city_municipality' => 'Manila',
            'province' => 'Metro Manila',
            'postal_code' => '1000',
            'is_default_shipping' => true,
        ]);

        $this->actingAs($buyer)
            ->patchJson('/addresses/'.$otherAddress->id, [
                'name' => 'Ava Buyer',
                'phone' => '+63 917 123 4567',
                'province' => 'Metro Manila',
                'city' => 'Manila',
                'barangay' => 'Barangay 1',
                'postal' => '1000',
                'street' => 'Buyer Street',
                'label' => 'Home',
                'is_default' => true,
            ])
            ->assertNotFound();

        $this->actingAs($buyer)
            ->postJson('/addresses', [
                'name' => 'Ava Buyer',
                'phone' => '+63 917 123 4567',
                'province' => 'Laguna',
                'city' => 'City of Santa Rosa',
                'city_code' => '0403428000',
                'barangay' => 'Barangay 1',
                'postal' => '4026',
                'street' => 'Buyer Street',
                'label' => 'Home',
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+639171234567')
            ->assertJsonPath('data.isDefault', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $buyer->id,
            'phone' => '+639171234567',
            'is_default_shipping' => 1,
        ]);
        $this->assertDatabaseHas('addresses', ['id' => $otherAddress->id, 'user_id' => $otherBuyer->id]);
    }

    public function test_buyer_address_coordinates_are_optional_paired_and_range_validated(): void
    {
        $buyer = $this->makeBuyer('coordinates@example.test', 'Coordinate Buyer');

        $response = $this->actingAs($buyer)->postJson('/addresses', [
            'name' => 'Coordinate Buyer',
            'phone' => '+63 917 123 4567',
            'province' => 'Laguna',
            'city' => 'Santa Rosa',
            'city_code' => '0403428000',
            'barangay' => 'Balibago',
            'postal' => '4026',
            'street' => 'Maple Street',
            'label' => 'Home',
            'is_default' => true,
            'latitude' => 14.3121,
            'longitude' => 121.1112,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.latitude', 14.3121)
            ->assertJsonPath('data.longitude', 121.1112);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $buyer->id,
            'latitude' => 14.3121,
            'longitude' => 121.1112,
        ]);

        $this->actingAs($buyer)
            ->postJson('/addresses', [
                'name' => 'Coordinate Buyer',
                'phone' => '+63 917 123 4567',
                'province' => 'Laguna',
                'city' => 'Santa Rosa',
                'city_code' => '0403428000',
                'barangay' => 'Balibago',
                'postal' => '4026',
                'street' => 'Maple Street',
                'label' => 'Work',
                'latitude' => 14.3121,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');

        $this->actingAs($buyer)
            ->postJson('/addresses', [
                'name' => 'Coordinate Buyer',
                'phone' => '+63 917 123 4567',
                'province' => 'Laguna',
                'city' => 'Santa Rosa',
                'city_code' => '0403428000',
                'barangay' => 'Balibago',
                'postal' => '4026',
                'street' => 'Maple Street',
                'label' => 'Work',
                'latitude' => 91,
                'longitude' => 181,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_address_cannot_be_saved_when_its_city_does_not_resolve_to_a_canonical_psgc_code(): void
    {
        $buyer = $this->makeBuyer('unresolved-address@example.test', 'Unresolved Buyer');

        $this->actingAs($buyer)
            ->postJson('/addresses', [
                'name' => 'Unresolved Buyer',
                'phone' => '+63 917 123 4567',
                'province' => 'Unknown Province',
                'city' => 'Unknown City',
                'city_code' => '0000000000',
                'barangay' => 'Unknown Barangay',
                'postal' => '9999',
                'street' => 'Unknown Street',
                'label' => 'Home',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('city')
            ->assertJsonPath('errors.city.0', 'Please select your city again; the PSGC city code does not match the address.');

        $this->assertDatabaseMissing('addresses', [
            'user_id' => $buyer->id,
            'street' => 'Unknown Street',
        ]);
    }

    public function test_kids_and_baby_category_uses_the_authenticated_buyer_view(): void
    {
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');

        $this->actingAs($buyer)
            ->get('/products?category=kids-and-baby')
            ->assertOk()
            ->assertSee('Kids & Baby', false)
            ->assertSee('Ava Buyer')
            ->assertDontSee('Floral Baby Dress');
    }

    private function makeBuyer(string $email, string $name): User
    {
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, 'Buyer');

        return User::create([
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function makeProduct(): array
    {
        $seller = $this->makeBuyer('seller-'.uniqid().'@example.test', 'Test Seller');
        $category = Category::create([
            'name' => 'Private Integration Category',
            'slug' => 'private-integration-'.uniqid(),
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Private Integration Seller',
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Private Integration Store',
            'slug' => 'private-integration-store-'.uniqid(),
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Private Integration Product',
            'slug' => 'private-integration-product-'.uniqid(),
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PRIVATE-'.uniqid(),
            'name' => 'Default',
            'price_minor' => 49900,
            'stock_on_hand' => 10,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        return compact('product', 'variant');
    }
}
