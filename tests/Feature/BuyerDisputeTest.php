<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Dispute;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerDisputeTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_buyer_can_create_a_transactional_dispute_with_derived_participants_and_evidence(): void
    {
        Storage::fake('local');

        $buyer = $this->buyer('owner');
        [$seller, $store] = $this->seller('one');
        $logistics = $this->logistics('one');
        $sellerOrder = $this->sellerOrder($this->order($buyer, 'owned'), $store, 'processing');
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-DISPUTE-'.Str::upper(Str::random(8)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $logistics->id,
            'purpose' => 'outbound',
            'status' => 'out_for_delivery',
        ]);

        $response = $this->actingAs($buyer)->post(
            route('buyer.disputes.store', $sellerOrder),
            [
                'subject' => 'Parcel arrived damaged',
                'description' => 'The outer packaging was damaged and the item inside is not usable.',
                'evidence' => [UploadedFile::fake()->create('damage.jpg', 20, 'image/jpeg')],
            ],
        );

        $dispute = Dispute::query()->firstOrFail();

        $response
            ->assertRedirect(route('buyer.disputes.show', $dispute))
            ->assertSessionHas('success');

        $this->assertSame($sellerOrder->id, $dispute->seller_order_id);
        $this->assertSame($shipment->id, $dispute->shipment_id);
        $this->assertSame($buyer->id, $dispute->opened_by);
        $this->assertSame('open', $dispute->status);
        $this->assertSame('normal', $dispute->priority);
        $this->assertSame((int) $sellerOrder->total_minor, (int) $dispute->amount_minor);

        $participants = $dispute->participants()->get()->mapWithKeys(
            fn (User $participant): array => [$participant->pivot->participant_role => $participant->id],
        );

        $this->assertSame($buyer->id, $participants['buyer']);
        $this->assertSame($seller->id, $participants['seller']);
        $this->assertSame($logistics->user_id, $participants['logistics']);
        $this->assertDatabaseHas('dispute_events', [
            'dispute_id' => $dispute->id,
            'event_type' => 'opened',
            'actor_user_id' => $buyer->id,
            'to_status' => 'open',
        ]);

        $evidence = $dispute->evidence()->firstOrFail();
        Storage::disk('local')->assertExists($evidence->file_path);
        $this->assertSame($buyer->id, $evidence->uploaded_by);

        $this->actingAs($buyer)
            ->get(route('buyer.disputes.show', $dispute))
            ->assertOk()
            ->assertSee($dispute->dispute_no)
            ->assertSee('Parcel arrived damaged')
            ->assertSee('damage.jpg');

        $admin = $this->admin('queue');

        $this->actingAs($admin)
            ->get(route('admin.disputes'))
            ->assertOk()
            ->assertSee($dispute->dispute_no)
            ->assertSee($buyer->name)
            ->assertSee($store->name)
            ->assertSee($logistics->user->name);
    }

    public function test_foreign_orders_and_client_supplied_context_or_participants_are_rejected(): void
    {
        $buyer = $this->buyer('owner');
        $foreignBuyer = $this->buyer('foreign');
        [, $store] = $this->seller('foreign-order');

        $foreignOrder = $this->sellerOrder($this->order($foreignBuyer, 'foreign'), $store, 'completed');

        $this->actingAs($buyer)
            ->post(route('buyer.disputes.store', $foreignOrder), [
                'subject' => 'Foreign order',
                'description' => 'This order belongs to another Buyer and must not be accepted.',
            ])
            ->assertNotFound();

        $ownedOrder = $this->sellerOrder($this->order($buyer, 'owned'), $store, 'pending');

        $this->actingAs($buyer)
            ->post(route('buyer.disputes.store', $ownedOrder), [
                'subject' => 'Forged context',
                'description' => 'The server must reject all client supplied ownership context.',
                'shipment_id' => 999999,
                'return_request_id' => 999999,
                'participant_ids' => [$foreignBuyer->id],
                'seller_id' => $foreignBuyer->id,
                'amount_minor' => 1,
                'priority' => 'urgent',
            ])
            ->assertSessionHasErrors([
                'shipment_id',
                'return_request_id',
                'participant_ids',
                'seller_id',
                'amount_minor',
                'priority',
            ]);

        $this->assertSame(0, Dispute::query()->count());
    }

    public function test_logistics_is_derived_from_another_related_shipment_when_the_return_context_provider_is_unavailable(): void
    {
        $buyer = $this->buyer('shipment-fallback');
        [, $store] = $this->seller('shipment-fallback');
        $outboundProvider = $this->logistics('outbound-provider');
        $returnProvider = $this->logistics('return-provider');
        $order = $this->order($buyer, 'shipment-fallback');
        $sellerOrder = $this->sellerOrder($order, $store, 'processing');

        Shipment::query()->create([
            'shipment_no' => 'SHP-DISPUTE-'.Str::upper(Str::random(8)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $outboundProvider->id,
            'purpose' => 'outbound',
            'status' => 'delivered',
        ]);

        $returnShipment = Shipment::query()->create([
            'shipment_no' => 'SHP-DISPUTE-'.Str::upper(Str::random(8)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $returnProvider->id,
            'purpose' => 'return',
            'status' => 'ready_for_pickup',
        ]);

        ReturnRequest::query()->create([
            'return_no' => 'RET-DISPUTE-'.Str::upper(Str::random(8)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $buyer->id,
            'store_id' => $store->id,
            'request_type' => 'refund_only',
            'reason_code' => 'damaged',
            'status' => 'submitted',
            'requested_amount_minor' => $sellerOrder->total_minor,
            'submitted_at' => now(),
            'return_shipment_id' => $returnShipment->id,
        ]);

        // A soft-deleted provider must not hide the active provider attached to
        // another real shipment for the same Seller Order.
        $returnProvider->delete();

        $this->actingAs($buyer)
            ->post(route('buyer.disputes.store', $sellerOrder), [
                'subject' => 'Shipment provider fallback',
                'description' => 'Use the related outbound shipment provider when the return provider is unavailable.',
            ])
            ->assertRedirect();

        $dispute = Dispute::query()->firstOrFail();

        $this->assertSame($returnShipment->id, $dispute->shipment_id);
        $this->assertSame(
            $outboundProvider->user_id,
            $dispute->participants()
                ->wherePivot('participant_role', 'logistics')
                ->value('users.id'),
        );
    }

    public function test_only_one_active_case_is_allowed_and_finalized_history_can_be_followed_by_a_new_case(): void
    {
        $buyer = $this->buyer('duplicate');
        [$seller, $store] = $this->seller('duplicate');
        $sellerOrder = $this->sellerOrder($this->order($buyer, 'duplicate'), $store, 'processing');

        $payload = [
            'subject' => 'Repeated dispute submission',
            'description' => 'The same form may be submitted twice by accident.',
        ];

        $this->actingAs($buyer)
            ->post(route('buyer.disputes.store', $sellerOrder), $payload)
            ->assertRedirect();

        $this->actingAs($buyer)
            ->post(route('buyer.disputes.store', $sellerOrder), $payload)
            ->assertSessionHasErrors('seller_order');

        $first = Dispute::query()->firstOrFail();
        $this->assertSame(1, Dispute::query()->count());

        $admin = $this->admin('resolve');

        $this->actingAs($admin)
            ->postJson(route('admin.disputes.resolve', $first), [
                'outcome' => 'seller_favored',
                'note' => 'The first case was reviewed and finalized.',
            ])
            ->assertOk();

        $this->assertSame('resolved', $first->fresh()->status);

        $this->actingAs($buyer)
            ->post(route('buyer.disputes.store', $sellerOrder), [
                'subject' => 'A later dispute',
                'description' => 'A new case is allowed after the prior case was finalized.',
            ])
            ->assertRedirect();

        $this->assertSame(2, Dispute::query()->count());
        $this->assertSame($seller->id, $first->participants()->wherePivot('participant_role', 'seller')->value('users.id'));
    }

    public function test_evidence_download_is_private_to_case_participants_and_internal_notes_stay_hidden(): void
    {
        Storage::fake('local');

        $buyer = $this->buyer('evidence-owner');
        [$seller, $store] = $this->seller('evidence-seller');
        $sellerOrder = $this->sellerOrder($this->order($buyer, 'evidence'), $store, 'processing');

        $this->actingAs($buyer)->post(route('buyer.disputes.store', $sellerOrder), [
            'subject' => 'Evidence access test',
            'description' => 'Evidence must only be available to actual case participants.',
            'evidence' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')],
        ])->assertRedirect();

        $dispute = Dispute::query()->firstOrFail();
        $evidence = $dispute->evidence()->firstOrFail();
        $outsider = $this->buyer('outsider');
        $admin = $this->admin('notes');

        $this->actingAs($outsider)
            ->get(route('disputes.evidence.download', $evidence))
            ->assertNotFound();

        $this->actingAs($seller)
            ->get(route('disputes.evidence.download', $evidence))
            ->assertOk()
            ->assertDownload('proof.pdf');

        $this->actingAs($admin)
            ->postJson(route('admin.disputes.notes.store', $dispute), [
                'note' => 'Private Admin note.',
            ])
            ->assertOk();

        $this->actingAs($buyer)
            ->get(route('buyer.disputes.show', $dispute))
            ->assertOk()
            ->assertDontSee('Private Admin note.');

        $this->assertDatabaseHas('dispute_events', [
            'dispute_id' => $dispute->id,
            'event_type' => 'internal_note',
            'note' => 'Private Admin note.',
        ]);
    }

    private function buyer(string $suffix): User
    {
        return User::factory()->create([
            'name' => 'Buyer '.ucfirst($suffix),
            'email' => 'dispute-buyer-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => 'buyer',
            'status' => 'active',
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function seller(string $suffix): array
    {
        $seller = User::factory()->create([
            'name' => 'Seller '.ucfirst($suffix),
            'email' => 'dispute-seller-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => 'seller',
            'status' => 'active',
        ]);
        $address = Address::query()->create([
            'user_id' => $seller->id,
            'label' => 'Pickup',
            'recipient_name' => $seller->name,
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
            'user_id' => $seller->id,
            'legal_business_name' => 'Seller Business '.ucfirst($suffix),
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Seller Store '.ucfirst($suffix),
            'slug' => 'dispute-store-'.Str::slug($suffix).'-'.(++$this->sequence),
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return [$seller, $store];
    }

    private function logistics(string $suffix): LogisticsProfile
    {
        $user = User::factory()->create([
            'name' => 'Logistics '.ucfirst($suffix),
            'email' => 'dispute-logistics-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => 'logistics',
            'status' => 'active',
        ]);

        return LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Logistics '.ucfirst($suffix).' Inc.',
            'display_name' => 'Logistics '.ucfirst($suffix),
            'contact_phone' => '+639171234567',
            'status' => 'active',
        ]);
    }

    private function admin(string $suffix): User
    {
        return User::factory()->create([
            'name' => 'Admin '.ucfirst($suffix),
            'email' => 'dispute-admin-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function order(User $buyer, string $suffix): Order
    {
        $address = Address::query()->create([
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

        return Order::query()->create([
            'order_no' => 'BRY-DISPUTE-'.Str::upper(Str::random(8)),
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $address->phone,
            'address_line' => '20 Buyer Street',
            'barangay' => $address->barangay,
            'city_municipality' => $address->city_municipality,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
            'currency' => 'PHP',
            'subtotal_minor' => 16600,
            'shipping_fee_minor' => 3400,
            'discount_minor' => 0,
            'total_minor' => 20000,
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);
    }

    private function sellerOrder(Order $order, Store $store, string $status): SellerOrder
    {
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-DISPUTE-'.Str::upper(Str::random(8)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => 16600,
            'discount_minor' => 0,
            'shipping_fee_minor' => 3400,
            'total_minor' => 20000,
        ]);
        $sellerOrder->items()->create([
            'product_name' => 'Dispute Test Product',
            'variant_name' => 'Standard',
            'sku' => 'DISPUTE-'.Str::upper(Str::random(8)),
            'unit_price_minor' => 16600,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 16600,
        ]);

        return $sellerOrder;
    }
}
