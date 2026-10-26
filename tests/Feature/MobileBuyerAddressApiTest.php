<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileBuyerAddressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_retrieves_an_empty_list_and_web_saved_addresses(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        $token = $this->token($buyer);
        $otherAddress = $this->address($otherBuyer, 'Other Buyer Address');

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/addresses')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'message' => 'Addresses retrieved successfully.',
            ]);

        $webAddress = $this->address($buyer, 'Web Saved Address', isDefault: true);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/addresses')
            ->assertOk()
            ->assertJsonPath('data.0.id', $webAddress->id)
            ->assertJsonPath('data.0.label', 'Home')
            ->assertJsonPath('data.0.name', 'Web Saved Address')
            ->assertJsonPath('data.0.phone', '+639171234567')
            ->assertJsonPath('data.0.province', 'Laguna')
            ->assertJsonPath('data.0.city', 'Santa Rosa')
            ->assertJsonPath('data.0.barangay', 'Balibago')
            ->assertJsonPath('data.0.postal', '4026')
            ->assertJsonPath('data.0.street', 'Maple Street')
            ->assertJsonPath('data.0.isDefault', true)
            ->assertJsonMissing(['id' => $otherAddress->id]);
    }

    public function test_mobile_create_reuses_web_validation_first_default_and_authenticated_ownership(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        $token = $this->token($buyer);

        $response = $this->withToken($token)
            ->postJson('/api/mobile/buyer/addresses', $this->addressInput([
                'name' => 'Mobile Recipient',
                'phone' => '+63 917 123 4567',
                'user_id' => $otherBuyer->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mobile Recipient')
            ->assertJsonPath('data.phone', '+639171234567')
            ->assertJsonPath('data.cityCode', '0403428000')
            ->assertJsonPath('data.cityVerified', true)
            ->assertJsonPath('data.isDefault', true)
            ->assertJsonPath('message', 'Address added successfully.');

        $addressId = (int) $response->json('data.id');
        $this->assertDatabaseHas('addresses', [
            'id' => $addressId,
            'user_id' => $buyer->id,
            'recipient_name' => 'Mobile Recipient',
            'phone' => '+639171234567',
            'psgc_city_code' => '0403428000',
            'is_default_shipping' => 1,
        ]);
        $this->assertDatabaseMissing('addresses', [
            'id' => $addressId,
            'user_id' => $otherBuyer->id,
        ]);

        $this->actingAs($buyer)
            ->getJson('/addresses/data')
            ->assertOk()
            ->assertJsonPath('data.0.id', $addressId)
            ->assertJsonPath('data.0.isDefault', true);
    }

    public function test_mobile_created_address_can_be_selected_by_existing_web_checkout(): void
    {
        $buyer = $this->buyer();
        $addressResponse = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/addresses', $this->addressInput())
            ->assertCreated();
        $addressId = (int) $addressResponse->json('data.id');

        $category = Category::create([
            'name' => 'Address Checkout Category',
            'slug' => 'address-checkout-category-'.uniqid(),
            'is_active' => true,
        ]);
        $seller = $this->buyer(['role' => 'seller']);
        $pickupAddress = $this->address($seller, 'Seller Pickup Recipient', isDefault: true);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Address Checkout Seller',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
            'standing_status' => 'good_standing',
            'pickup_address_id' => $pickupAddress->id,
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Address Checkout Store',
            'slug' => 'address-checkout-store-'.uniqid(),
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Address Checkout Product',
            'slug' => 'address-checkout-product-'.uniqid(),
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'ADDRESS-CHECKOUT-'.uniqid(),
            'name' => 'Default',
            'price_minor' => 500,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'low_stock_threshold' => 1,
            'package_weight_kg' => 0.25,
            'package_length_cm' => 10,
            'package_width_cm' => 10,
            'package_height_cm' => 10,
            'is_active' => true,
        ]);
        $cart = Cart::create(['user_id' => $buyer->id, 'status' => 'active']);
        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'selected' => true,
        ]);

        $this->actingAs($buyer)
            ->postJson('/checkout/quote', [
                'address_id' => $addressId,
                'cart_item_ids' => [$cartItem->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.delivery_address.id', $addressId)
            ->assertJsonPath('data.delivery_address.name', 'Test Recipient')
            ->assertJsonPath('data.delivery_address.city', 'Santa Rosa');
    }

    public function test_mobile_patch_updates_only_supplied_fields_and_web_changes_appear_on_mobile(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        $address = $this->address($buyer, 'Original Recipient', isDefault: true);
        $token = $this->token($buyer);

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$address->id, [
                'street' => 'Mobile Updated Street',
                'user_id' => $otherBuyer->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $address->id)
            ->assertJsonPath('data.name', 'Original Recipient')
            ->assertJsonPath('data.street', 'Mobile Updated Street')
            ->assertJsonPath('data.phone', '+639171234567')
            ->assertJsonPath('data.isDefault', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'user_id' => $buyer->id,
            'street' => 'Mobile Updated Street',
        ]);

        $this->actingAs($buyer)
            ->getJson('/addresses/data')
            ->assertOk()
            ->assertJsonPath('data.0.street', 'Mobile Updated Street');

        $this->actingAs($buyer)
            ->patchJson('/addresses/'.$address->id, $this->addressInput([
                'name' => 'Web Updated Recipient',
                'phone' => '+63 917 123 4567',
                'province' => 'Laguna',
                'city' => 'Santa Rosa',
                'barangay' => 'Balibago',
                'postal' => '4026',
                'street' => 'Web Updated Street',
                'label' => 'Work',
                'is_default' => true,
            ]))
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/addresses')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Web Updated Recipient')
            ->assertJsonPath('data.0.label', 'Work')
            ->assertJsonPath('data.0.street', 'Web Updated Street');

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$address->id, [
                'city' => 'Biñan',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('city_code');

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$address->id, [
                'city' => 'Biñan',
                'city_code' => '0403428000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('city');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'city_municipality' => 'Santa Rosa',
            'psgc_city_code' => '0403428000',
        ]);
    }

    public function test_mobile_address_validation_matches_web_phone_location_and_coordinate_rules(): void
    {
        $buyer = $this->buyer();
        $token = $this->token($buyer);

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/addresses', ['name' => 'Missing fields'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'phone',
                'province',
                'city',
                'city_code',
                'barangay',
                'postal',
                'street',
                'label',
            ]);

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/addresses', $this->addressInput([
                'phone' => '+12025550123',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_number');

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/addresses', $this->addressInput([
                'city_code' => '0403427000',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('city');

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/addresses', $this->addressInput([
                'latitude' => 14.3121,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/addresses', $this->addressInput([
                'latitude' => 91,
                'longitude' => 181,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->assertDatabaseCount('addresses', 0);
    }

    public function test_legacy_address_allows_non_location_patch_without_becoming_city_verified(): void
    {
        $buyer = $this->buyer();
        $address = $this->address($buyer, 'Legacy Recipient', verified: false);
        $token = $this->token($buyer);

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$address->id, [
                'name' => 'Updated Legacy Recipient',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Legacy Recipient')
            ->assertJsonPath('data.cityCode', null)
            ->assertJsonPath('data.cityVerified', false);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'recipient_name' => 'Updated Legacy Recipient',
            'city_municipality' => 'Santa Rosa',
            'psgc_city_code' => null,
        ]);

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$address->id, [
                'province' => 'Metro Manila',
                'city' => 'Manila',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('city_code');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'province' => 'Laguna',
            'city_municipality' => 'Santa Rosa',
            'psgc_city_code' => null,
        ]);
    }

    public function test_mobile_ownership_checks_hide_foreign_addresses_for_update_delete_and_default(): void
    {
        $buyer = $this->buyer();
        $owner = $this->buyer();
        $foreignAddress = $this->address($owner, 'Private Recipient', isDefault: true);
        $token = $this->token($buyer);

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$foreignAddress->id, ['street' => 'Stolen Update'])
            ->assertNotFound();
        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/addresses/'.$foreignAddress->id)
            ->assertNotFound();
        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$foreignAddress->id.'/default', [])
            ->assertNotFound();

        $this->assertDatabaseHas('addresses', [
            'id' => $foreignAddress->id,
            'user_id' => $owner->id,
            'street' => 'Maple Street',
            'is_default_shipping' => 1,
            'deleted_at' => null,
        ]);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/addresses')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mobile_can_change_default_repeat_safely_and_default_deletion_promotes_oldest(): void
    {
        $buyer = $this->buyer();
        $first = $this->address($buyer, 'First Recipient', isDefault: true);
        $second = $this->address($buyer, 'Second Recipient');
        $third = $this->address($buyer, 'Third Recipient');
        $token = $this->token($buyer);

        foreach ([1, 2] as $_attempt) {
            $this->withToken($token)
                ->patchJson('/api/mobile/buyer/addresses/'.$third->id.'/default', [])
                ->assertOk()
                ->assertJsonPath('data.id', $third->id)
                ->assertJsonPath('data.isDefault', true);
        }

        $this->assertDatabaseHas('addresses', ['id' => $first->id, 'is_default_shipping' => 0]);
        $this->assertDatabaseHas('addresses', ['id' => $second->id, 'is_default_shipping' => 0]);
        $this->assertDatabaseHas('addresses', ['id' => $third->id, 'is_default_shipping' => 1]);
        $this->assertSame(1, (int) DB::table('addresses')->where('user_id', $buyer->id)->where('is_default_shipping', true)->count());

        $webAddresses = $this->actingAs($buyer)->getJson('/addresses/data')->assertOk();
        $this->assertSame($third->id, (int) $webAddresses->json('data.0.id'));
        $this->assertTrue((bool) $webAddresses->json('data.0.isDefault'));

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/addresses/'.$third->id)
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('addresses', ['id' => $third->id]);
        $this->assertDatabaseHas('addresses', ['id' => $first->id, 'is_default_shipping' => 1]);
        $this->assertDatabaseHas('addresses', ['id' => $second->id, 'is_default_shipping' => 0]);
        $this->assertSame(1, (int) DB::table('addresses')->where('user_id', $buyer->id)->whereNull('deleted_at')->where('is_default_shipping', true)->count());

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/addresses/'.$first->id)
            ->assertOk();
        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/addresses/'.$second->id)
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/addresses')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_address_edits_and_soft_deletes_do_not_change_order_shipping_snapshots(): void
    {
        $buyer = $this->buyer();
        $address = $this->address($buyer, 'Original Order Recipient', isDefault: true);
        $order = Order::create([
            'order_no' => 'ADDRESS-SNAPSHOT-'.strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->phone,
            'address_line' => $address->street,
            'barangay' => $address->barangay,
            'city_municipality' => $address->city_municipality,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
            'currency' => 'PHP',
            'subtotal_minor' => 500,
            'total_minor' => 500,
            'status' => 'processing',
            'payment_status' => 'unpaid',
        ]);
        $token = $this->token($buyer);

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/addresses/'.$address->id, [
                'name' => 'Updated Saved Recipient',
                'street' => 'Updated Saved Street',
            ])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => 'Original Order Recipient',
            'address_line' => 'Maple Street',
            'city_municipality' => 'Santa Rosa',
        ]);

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/addresses/'.$address->id)
            ->assertOk();

        $this->assertSoftDeleted('addresses', ['id' => $address->id]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => 'Original Order Recipient',
            'recipient_phone' => '+639171234567',
            'address_line' => 'Maple Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
    }

    public function test_mobile_address_routes_require_active_buyer_tokens(): void
    {
        $this->getJson('/api/mobile/buyer/addresses')->assertUnauthorized();
        $this->postJson('/api/mobile/buyer/addresses', [])->assertUnauthorized();
        $this->patchJson('/api/mobile/buyer/addresses/1', [])->assertUnauthorized();
        $this->deleteJson('/api/mobile/buyer/addresses/1')->assertUnauthorized();
        $this->patchJson('/api/mobile/buyer/addresses/1/default', [])->assertUnauthorized();

        foreach (['rider', 'seller', 'admin', 'logistics'] as $role) {
            $token = $this->token($this->buyer(['role' => $role]));
            $this->withToken($token)
                ->getJson('/api/mobile/buyer/addresses')
                ->assertForbidden();
            $this->withToken($token)
                ->postJson('/api/mobile/buyer/addresses', $this->addressInput())
                ->assertForbidden();
        }

        $suspendedToken = $this->token($this->buyer(['status' => 'suspended']));
        $this->withToken($suspendedToken)
            ->getJson('/api/mobile/buyer/addresses')
            ->assertForbidden();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => hash('sha256', $suspendedToken),
        ]);
    }

    private function address(
        User $user,
        string $name,
        bool $isDefault = false,
        bool $verified = true,
    ): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $name,
            'phone' => '+639171234567',
            'street' => 'Maple Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'psgc_city_code' => $verified ? '0403428000' : null,
            'is_default_shipping' => $isDefault,
        ]);
    }

    private function addressInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Recipient',
            'phone' => '+63 917 123 4567',
            'province' => 'Laguna',
            'city' => 'Santa Rosa',
            'city_code' => '0403428000',
            'barangay' => 'Balibago',
            'postal' => '4026',
            'street' => 'Maple Street',
            'label' => 'Home',
        ], $overrides);
    }

    private function buyer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'buyer',
            'status' => 'active',
        ], $attributes));
    }

    private function token(User $user): string
    {
        return $user->createToken('mobile-address-test')->plainTextToken;
    }
}
