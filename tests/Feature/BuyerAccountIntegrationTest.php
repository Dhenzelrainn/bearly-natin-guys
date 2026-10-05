<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertSee('You may also like')
            ->assertSee('href="'.url('/home#results').'"', false)
             ->assertSee('My Vouchers')
             ->assertSee('images/vouchers/Bearly-voucher-icon.png', false)
             ->assertSee('data-notification-trigger', false)
             ->assertSee('id="buyer-notification-popover"', false)
             ->assertSee('View all notifications')
             ->assertSee('Edit Profile')
            ->assertSee('Save Changes')
            ->assertDontSee('Mia Santos');

        $this->actingAs($buyer)
            ->get('/home')
            ->assertOk()
             ->assertSee('"wishlist_key":"pet-supplies:1"', false)
             ->assertSee('data-notification-trigger', false)
             ->assertSee('id="buyer-notification-popover"', false)
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
            ->assertSee('Message your sellers here.')
            ->assertSee('data-chat-filter', false)
            ->assertSee('data-chat-attachment-button', false)
            ->assertSee('data-chat-emoji', false);
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
                'photo' => UploadedFile::fake()->create('avatar.png', 100, 'image/png'),
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

        ['variant' => $unpublishedVariant] = $this->makeProduct();
        $unpublishedVariant->product->store->update(['publication_status' => 'draft']);

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $unpublishedVariant->id,
                'quantity' => 1,
            ])
            ->assertNotFound();
    }

    public function test_wishlist_and_addresses_are_scoped_to_the_authenticated_buyer(): void
    {
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');
        $otherBuyer = $this->makeBuyer('other@example.test', 'Other Buyer');
        ['product' => $product] = $this->makeProduct();

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);

        $this->actingAs($otherBuyer)
            ->get('/wishlist')
            ->assertOk()
            ->assertDontSee('Private Integration Product');

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
                'province' => 'Metro Manila',
                'city' => 'Manila',
                'barangay' => 'Barangay 1',
                'postal' => '1000',
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

    public function test_kids_and_baby_category_uses_the_authenticated_buyer_view(): void
    {
        $buyer = $this->makeBuyer('buyer@example.test', 'Ava Buyer');

        $this->actingAs($buyer)
            ->get('/products?category=kids-and-baby')
            ->assertOk()
            ->assertSee('Kids & Baby', false)
            ->assertSee('Ava Buyer');
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
