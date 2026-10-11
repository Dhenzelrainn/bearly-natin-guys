<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\CodCashCollectionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerOrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_can_confirm_fully_delivered_order_and_everything_completes(): void
    {
        $buyer = $this->buyer('confirm');
        [$seller, $store] = $this->seller();
        $provider = $this->provider('confirm');
        $order = $this->marketplaceOrder($buyer, 'confirm');
        $sellerOrder = $this->sellerOrder($order, $store, 'confirm', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'confirm', 'delivered');
        $this->collectCod($shipment, $provider, 'confirm');

        $this->actingAs($buyer)
            ->get(route('checkout.confirmation', $order->order_no))
            ->assertOk()
            ->assertSee('Confirm receipt');

        $this->actingAs($buyer)
            ->post(route('checkout.confirm-receipt', $order->id))
            ->assertRedirect(route('checkout.confirmation', $order->order_no))
            ->assertSessionHas('success');

        $this->assertSame('completed', $sellerOrder->fresh()->status);
        $this->assertNotNull($sellerOrder->fresh()->completed_at);
        $this->assertDatabaseHas('seller_order_status_histories', [
            'seller_order_id' => $sellerOrder->id,
            'from_status' => 'handed_to_logistics',
            'to_status' => 'completed',
            'actor_user_id' => $buyer->id,
        ]);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->completed_at);
        $this->assertSame('paid', $order->payments()->sole()->status);
        $this->assertNotNull($order->payments()->sole()->paid_at);

        $this->actingAs($buyer)
            ->get(route('checkout.confirmation', $order->order_no))
            ->assertOk()
            ->assertSee('Order completed')
            ->assertDontSee('Confirm receipt');

        $this->actingAs($seller)
            ->get(route('seller.orders'))
            ->assertOk()
            ->assertSee('Completed');
    }

    public function test_repeating_confirmation_is_idempotent_without_duplicate_history(): void
    {
        $buyer = $this->buyer('replay');
        [, $store] = $this->seller();
        $provider = $this->provider('replay');
        $order = $this->marketplaceOrder($buyer, 'replay');
        $sellerOrder = $this->sellerOrder($order, $store, 'replay', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'replay', 'delivered');
        $this->collectCod($shipment, $provider, 'replay');

        $this->actingAs($buyer)->post(route('checkout.confirm-receipt', $order->id))->assertRedirect();
        $this->actingAs($buyer)
            ->post(route('checkout.confirm-receipt', $order->id))
            ->assertRedirect(route('checkout.confirmation', $order->order_no))
            ->assertSessionHas('success');

        $this->assertSame('completed', $sellerOrder->fresh()->status);
        $this->assertSame(1, $sellerOrder->statusHistory()->count());
    }

    public function test_confirmation_is_rejected_until_every_parcel_is_delivered(): void
    {
        $buyer = $this->buyer('partial');
        [, $firstStore] = $this->seller();
        [, $secondStore] = $this->seller();
        $provider = $this->provider('partial');
        $order = $this->marketplaceOrder($buyer, 'partial');
        $deliveredOrder = $this->sellerOrder($order, $firstStore, 'partial-first', 'handed_to_logistics');
        $transitOrder = $this->sellerOrder($order, $secondStore, 'partial-second', 'handed_to_logistics');
        $this->shipment($deliveredOrder, $provider, 'partial-first', 'delivered');
        $this->shipment($transitOrder, $provider, 'partial-second', 'out_for_delivery');

        $this->actingAs($buyer)
            ->post(route('checkout.confirm-receipt', $order->id))
            ->assertConflict();

        $this->assertSame('handed_to_logistics', $deliveredOrder->fresh()->status);
        $this->assertSame('handed_to_logistics', $transitOrder->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('pending', $order->payments()->sole()->status);
        $this->assertDatabaseCount('seller_order_status_histories', 0);
    }

    public function test_confirmation_requires_a_delivered_shipment_and_rejects_cancelled_orders(): void
    {
        $buyer = $this->buyer('undelivered');
        [, $store] = $this->seller();
        $order = $this->marketplaceOrder($buyer, 'undelivered');
        $sellerOrder = $this->sellerOrder($order, $store, 'undelivered', 'ready_for_pickup');

        $this->actingAs($buyer)
            ->post(route('checkout.confirm-receipt', $order->id))
            ->assertConflict();
        $this->assertSame('ready_for_pickup', $sellerOrder->fresh()->status);

        $order->update(['status' => 'cancelled']);

        $this->actingAs($buyer)
            ->post(route('checkout.confirm-receipt', $order->id))
            ->assertConflict();
    }

    public function test_buyer_cannot_confirm_another_buyers_order(): void
    {
        $buyer = $this->buyer('owner');
        $intruder = $this->buyer('intruder');
        [, $store] = $this->seller();
        $provider = $this->provider('foreign');
        $order = $this->marketplaceOrder($buyer, 'foreign');
        $sellerOrder = $this->sellerOrder($order, $store, 'foreign', 'handed_to_logistics');
        $this->shipment($sellerOrder, $provider, 'foreign', 'delivered');

        $this->actingAs($intruder)
            ->post(route('checkout.confirm-receipt', $order->id))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('checkout.confirmation', $order->order_no))
            ->assertNotFound();

        $this->assertSame('handed_to_logistics', $sellerOrder->fresh()->status);
    }

    public function test_confirmation_page_shows_complete_product_details(): void
    {
        $buyer = $this->buyer('details');
        [, $store] = $this->seller();
        $provider = $this->provider('details');
        $order = $this->marketplaceOrder($buyer, 'details');
        $sellerOrder = $this->sellerOrder($order, $store, 'details', 'handed_to_logistics');
        $sellerOrder->items()->create([
            'product_name' => 'Snapshot Product',
            'variant_name' => 'Blue / Medium',
            'sku' => 'SNAPSHOT-DETAIL-001',
            'unit_price_minor' => 16600,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 16600,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/variants/detail-test.png',
        ]);
        $shipment = $this->shipment($sellerOrder, $provider, 'details', 'delivered');
        $this->collectCod($shipment, $provider, 'details');

        $this->actingAs($buyer)
            ->get(route('checkout.confirmation', $order->order_no))
            ->assertOk()
            ->assertSee('seller-products/variants/detail-test.png', false)
            ->assertSee('SNAPSHOT-DETAIL-001')
            ->assertSee('SKU: SNAPSHOT-DETAIL-001')
            ->assertSee('₱166.00 each')
            ->assertSee('Delivered · awaiting your confirmation')
            ->assertSee('20 Buyer Street')
            ->assertSee(now()->format('M j, Y'))
            ->assertSee('Confirm receipt');
    }

    private function buyer(string $suffix): User
    {
        $user = User::factory()->create([
            'name' => "Buyer {$suffix}",
            'email' => 'confirm-buyer-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
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
            'email' => "confirm-seller-{$suffix}@example.test",
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
            'slug' => "confirm-seller-store-{$suffix}",
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return [$user, $store];
    }

    /** @return array{user: User, profile: LogisticsProfile} */
    private function provider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'email' => 'confirm-logistics-'.Str::slug($suffix).'@example.test',
            'role' => UserRole::Logistics->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Logistics->value)->value('id'),
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Inc.",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '+639171234567',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
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

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function shipment(SellerOrder $sellerOrder, array $provider, string $suffix, string $status): Shipment
    {
        return Shipment::query()->create([
            'shipment_no' => 'CONFIRM-'.Str::slug($suffix).'-'.Str::upper(Str::random(8)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'status' => $status,
            'cod_amount_minor' => (int) $sellerOrder->total_minor,
        ]);
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function collectCod(Shipment $shipment, array $provider, string $suffix): void
    {
        $rider = User::factory()->create([
            'name' => 'COD Rider '.$suffix,
            'email' => 'confirm-cod-rider-'.Str::slug($suffix).'-'.Str::upper(Str::random(5)).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => 'active',
        ]);
        $rider->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Rider->value)->value('id'),
        ]);
        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        app(CodCashCollectionService::class)->recordRiderCollection(
            $shipment,
            $rider,
            (int) $shipment->cod_amount_minor,
        );
    }
}
