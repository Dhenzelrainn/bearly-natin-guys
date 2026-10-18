<?php

namespace Tests\Feature\Admin;

use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnEvidence;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\SellerRefundRecovery;
use App\Models\SellerTransaction;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminReturnRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $buyer;

    private User $seller;

    private ReturnRequest $returnRequest;

    private OrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            [
                'display_name' => 'Admin',
                'description' => 'Platform administrator',
            ]
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->admin->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
        ]);

        $this->buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);

        $sellerProfile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'legal_business_name' => 'Test Seller Store',
            'commission_rate_bps' => 1000,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);

        $store = Store::create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => 'Test Seller Store',
            'slug' => 'test-seller-store',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        $order = Order::create([
            'order_no' => 'ORD-RETURN-001',
            'buyer_id' => $this->buyer->id,
            'recipient_name' => $this->buyer->name,
            'recipient_phone' => '09171234567',
            'address_line' => '123 Test Street',
            'barangay' => 'Real',
            'city_municipality' => 'Calamba',
            'province' => 'Laguna',
            'postal_code' => '4027',
            'psgc_city_code' => '0403405000',
            'currency' => 'PHP',
            'subtotal_minor' => 129900,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 129900,
            'status' => 'completed',
            'payment_status' => 'paid',
            'placed_at' => now()->subDays(3),
            'completed_at' => now()->subDay(),
        ]);

        $sellerOrder = SellerOrder::create([
            'seller_order_no' => 'SOR-RETURN-001',
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => 129900,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => 129900,
            'completed_at' => now()->subDay(),
        ]);

        $this->orderItem = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_name' => 'Return Test Item',
            'variant_name' => 'Standard',
            'sku' => 'RETURN-TEST-001',
            'unit_price_minor' => 129900,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 129900,
        ]);

        Payment::create([
            'payment_no' => 'PAY-RETURN-001',
            'order_id' => $order->id,
            'method' => 'cod',
            'provider' => 'test',
            'provider_reference' => 'TEST-PAYMENT-001',
            'amount_minor' => 129900,
            'status' => 'paid',
            'initiated_at' => now()->subDays(3),
            'paid_at' => now()->subDays(3),
        ]);

        $this->returnRequest = ReturnRequest::create([
            'return_no' => 'REF-TEST-001',
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $this->buyer->id,
            'store_id' => $store->id,
            'request_type' => 'refund_only',
            'reason_code' => 'item_arrived_damaged',
            'buyer_note' => 'Item arrived damaged.',
            'status' => 'under_review',
            'requested_amount_minor' => 129900,
            'response_due_at' => now()->addDay(),
            'submitted_at' => now(),
        ]);
    }

    public function test_admin_can_approve_refund_only_and_create_pending_refund(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->post(route(
                'admin.compliance.returns-refunds.approve',
                $this->returnRequest
            ), $this->responsibilityData('platform'));

        $response->assertRedirect();

        $this->returnRequest->refresh();

        $this->assertSame(
            'refund_pending',
            $this->returnRequest->status
        );

        $this->assertSame(
            $this->admin->id,
            $this->returnRequest->reviewed_by
        );

        $this->assertNotNull(
            $this->returnRequest->reviewed_at
        );

        $this->assertDatabaseHas('refunds', [
            'return_request_id' => $this->returnRequest->id,
            'order_id' => $this->returnRequest->order_id,
            'amount_minor' => 129900,
            'status' => 'pending',
            'approved_by' => $this->admin->id,
            'financially_responsible_party' => 'platform',
            'financial_responsibility_reason' => 'Evidence supports this responsibility decision.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->admin->id,
            'action' => 'return.refund_approved',
            'module' => 'Returns & Refunds',
            'auditable_id' => $this->returnRequest->id,
        ]);
    }

    public function test_admin_return_review_page_shows_case_context_and_available_actions(): void
    {
        ReturnItem::query()->create([
            'return_request_id' => $this->returnRequest->id,
            'order_item_id' => $this->orderItem->id,
            'quantity' => 1,
            'refund_amount_minor' => 129900,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds'))
            ->assertOk()
            ->assertSee('Return and refund cases')
            ->assertSee('Review case REF-TEST-001')
            ->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('Review return request')
            ->assertSee('Buyer’s request')
            ->assertSee('Seller’s response')
            ->assertSee('Quantity requested')
            ->assertSee('Refund requested')
            ->assertSee('Admin decision')
            ->assertSee('Who is financially responsible?')
            ->assertSee('Admin sends the Buyer’s external payout either way')
            ->assertSee('Reason for rejection')
            ->assertSee('What evidence is needed?')
            ->assertSee(route('admin.compliance.returns-refunds.approve', $this->returnRequest->id), false)
            ->assertSee(route('admin.compliance.returns-refunds.reject', $this->returnRequest->id), false)
            ->assertSee(route('admin.compliance.returns-refunds.request-evidence', $this->returnRequest->id), false);
    }

    public function test_admin_approval_creates_a_zero_fee_reverse_shipment(): void
    {
        // The Buyer-city provider handles pickup; the Seller-city provider is
        // resolved later as the reverse shipment's destination custodian.
        $this->createLogisticsProvider('Alternate Sorting Center');
        $logistics = $this->createLogisticsProvider('PB Sorting Center');
        ['seller_address' => $sellerAddress, 'outbound' => $outbound] = $this->preparePhysicalReturn($logistics);

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('platform'))
            ->assertRedirect();

        $this->assertSame('return_pickup_requested', $this->returnRequest->fresh()->status);
        $this->assertDatabaseHas('pickup_requests', [
            'return_request_id' => $this->returnRequest->id,
            'logistics_profile_id' => $logistics->id,
            'status' => 'requested',
        ]);
        $returnShipment = Shipment::query()
            ->where('seller_order_id', $this->returnRequest->seller_order_id)
            ->where('purpose', 'return')
            ->firstOrFail();
        $this->assertSame((int) $outbound->logistics_profile_id, (int) $returnShipment->logistics_profile_id);
        $this->assertSame('0403422000', $returnShipment->destination_city_code);
        $this->assertSame('Pila', $returnShipment->destination_address_snapshot['city_municipality']);
        $this->assertSame('0403422000', $returnShipment->destination_address_snapshot['psgc_city_code']);
        $this->assertSame(0, (int) $returnShipment->shipping_fee_minor);
        $this->assertSame($sellerAddress->id, (int) $returnShipment->destination_address_id);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $returnShipment->id,
            'event_type' => 'return_shipment_ready_for_pickup',
            'to_status' => 'ready_for_pickup',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_physical_return_approval_is_blocked_if_buyer_city_logistics_provider_is_inactive(): void
    {
        $provider = $this->createLogisticsProvider('PB Sorting Center', 'inactive');
        $this->preparePhysicalReturn($provider);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('platform'))
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('return');

        $this->assertSame('under_review', $this->returnRequest->fresh()->status);
        $this->assertDatabaseMissing('refunds', [
            'return_request_id' => $this->returnRequest->id,
        ]);
        $this->assertDatabaseMissing('pickup_requests', [
            'return_request_id' => $this->returnRequest->id,
        ]);
        $this->assertDatabaseMissing('shipments', [
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'purpose' => 'return',
        ]);
    }

    public function test_admin_can_reject_return_without_creating_refund(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->post(route(
                'admin.compliance.returns-refunds.reject',
                $this->returnRequest
            ), [
                'reason' => 'The available evidence does not support this request.',
            ]);

        $response->assertRedirect();

        $this->returnRequest->refresh();

        $this->assertSame(
            'rejected',
            $this->returnRequest->status
        );

        $this->assertSame(
            $this->admin->id,
            $this->returnRequest->reviewed_by
        );

        $this->assertNotNull(
            $this->returnRequest->reviewed_at
        );

        $this->assertNotNull(
            $this->returnRequest->resolved_at
        );

        $this->assertDatabaseMissing('refunds', [
            'return_request_id' => $this->returnRequest->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->admin->id,
            'action' => 'return.request_rejected',
            'module' => 'Returns & Refunds',
            'auditable_id' => $this->returnRequest->id,
        ]);
    }

    public function test_admin_can_request_additional_evidence(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->post(route(
                'admin.compliance.returns-refunds.request-evidence',
                $this->returnRequest
            ), [
                'note' => 'Please provide more information to support this case.',
                'request_from' => 'both',
            ]);

        $response->assertRedirect();

        $this->returnRequest->refresh();

        $this->assertSame(
            'awaiting_evidence',
            $this->returnRequest->status
        );

        $this->assertSame(
            $this->admin->id,
            $this->returnRequest->reviewed_by
        );

        $this->assertDatabaseMissing('refunds', [
            'return_request_id' => $this->returnRequest->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->admin->id,
            'action' => 'return.evidence_requested',
            'module' => 'Returns & Refunds',
            'auditable_id' => $this->returnRequest->id,
        ]);
    }

    public function test_approved_return_cannot_be_decided_again(): void
    {
        $this
            ->actingAs($this->admin)
            ->post(route(
                'admin.compliance.returns-refunds.approve',
                $this->returnRequest
            ), $this->responsibilityData('platform'))
            ->assertRedirect();

        $response = $this
            ->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route(
                'admin.compliance.returns-refunds.reject',
                $this->returnRequest
            ), [
                'reason' => 'The case has already been decided.',
            ]);

        $response->assertRedirect(
            route('admin.compliance.returns-refunds')
        );

        $response->assertSessionHasErrors('return');

        $this->returnRequest->refresh();

        $this->assertSame(
            'refund_pending',
            $this->returnRequest->status
        );

        $this->assertSame(
            1,
            Refund::where(
                'return_request_id',
                $this->returnRequest->id
            )->count()
        );
    }

    public function test_admin_cannot_decide_before_seller_response(): void
    {
        $this->returnRequest->update(['status' => 'awaiting_seller']);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('platform'))
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('return');

        $this->assertDatabaseMissing('refunds', [
            'return_request_id' => $this->returnRequest->id,
        ]);
    }

    public function test_seller_response_requires_and_persists_supporting_evidence(): void
    {
        Storage::fake('local');
        $this->returnRequest->update(['status' => 'awaiting_seller']);

        $this->actingAs($this->seller)
            ->from(route('seller.orders.returns.show', $this->returnRequest->return_no))
            ->post(route('seller.orders.returns.respond', $this->returnRequest->id), [
                'response' => 'We reviewed the order and inspected our dispatch records.',
            ])
            ->assertRedirect(route('seller.orders.returns.show', $this->returnRequest->return_no))
            ->assertSessionHasErrors('evidence');

        $this->assertSame('awaiting_seller', $this->returnRequest->fresh()->status);

        $this->actingAs($this->seller)
            ->post(route('seller.orders.returns.respond', $this->returnRequest->id), [
                'response' => 'We reviewed the order and inspected our dispatch records.',
                'evidence' => [UploadedFile::fake()->create('dispatch-photo.jpg', 10, 'image/jpeg')],
            ])
            ->assertRedirect(route('seller.orders.returns.show', $this->returnRequest->return_no));

        $this->assertSame('under_review', $this->returnRequest->fresh()->status);
        $this->assertDatabaseHas('return_evidence', [
            'return_request_id' => $this->returnRequest->id,
            'uploaded_by' => $this->seller->id,
            'type' => 'seller',
        ]);
    }

    public function test_seller_case_details_show_the_required_response_and_evidence_form(): void
    {
        $this->returnRequest->update(['status' => 'awaiting_seller']);

        $this->actingAs($this->seller)
            ->get(route('seller.orders.returns.show', $this->returnRequest->return_no))
            ->assertOk()
            ->assertSee('Your reply is needed')
            ->assertSee('Add 1–5 files that support your reply')
            ->assertSee('item/packing photos or dispatch records')
            ->assertSee('name="response"', false)
            ->assertSee('name="evidence[]"', false)
            ->assertSee(route('seller.orders.returns.respond', $this->returnRequest->id), false)
            ->assertDontSee('Platform fee change')
            ->assertDontSee('<table', false)
            ->assertDontSee('Save as draft');
    }

    public function test_seller_returns_list_uses_the_aligned_case_row_layout(): void
    {
        $this->actingAs($this->seller)
            ->get(route('seller.orders.returns'))
            ->assertOk()
            ->assertSee('Request &amp; Reason', false)
            ->assertSee('return-case-id', false)
            ->assertSee('<wbr>', false)
            ->assertSee('return-case-submitted', false)
            ->assertSee('returns-table-product', false)
            ->assertSee('Within 7 days after Bearly marks the order complete')
            ->assertSee('attach at least one helpful file')
            ->assertSee('Logistics picks it up and brings it to you')
            ->assertSee('It will not be taken from your payout automatically.')
            ->assertSee('REF-TEST-001');
    }

    public function test_seller_return_case_displays_the_order_item_image_snapshot(): void
    {
        Storage::fake('public');
        $imagePath = 'products/return-case-item.jpg';
        Storage::disk('public')->put($imagePath, 'test image');
        $this->orderItem->update([
            'product_image_disk' => 'public',
            'product_image_path' => $imagePath,
        ]);
        ReturnItem::query()->create([
            'return_request_id' => $this->returnRequest->id,
            'order_item_id' => $this->orderItem->id,
            'quantity' => 1,
            'refund_amount_minor' => 129900,
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.orders.returns.show', $this->returnRequest->return_no))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($imagePath), false)
            ->assertSee('Return Test Item')
            ->assertSee('Quantity requested')
            ->assertSee('1 item')
            ->assertSee('Refund for this item');
    }

    public function test_seller_can_preview_private_return_evidence_inline(): void
    {
        Storage::fake('local');
        $path = "return-evidence/{$this->returnRequest->return_no}/buyer-proof.png";
        Storage::disk('local')->put($path, 'private image bytes');
        $evidence = ReturnEvidence::query()->create([
            'return_request_id' => $this->returnRequest->id,
            'uploaded_by' => $this->buyer->id,
            'type' => 'buyer',
            'mime_type' => 'image/png',
            'file_path' => $path,
            'original_name' => 'buyer-proof.png',
            'size_bytes' => strlen('private image bytes'),
            'created_at' => now(),
        ]);
        $pdfPath = "return-evidence/{$this->returnRequest->return_no}/seller-receipt.pdf";
        Storage::disk('local')->put($pdfPath, 'private pdf bytes');
        $pdfEvidence = ReturnEvidence::query()->create([
            'return_request_id' => $this->returnRequest->id,
            'uploaded_by' => $this->seller->id,
            'type' => 'seller',
            'mime_type' => 'application/pdf',
            'file_path' => $pdfPath,
            'original_name' => 'seller-receipt.pdf',
            'size_bytes' => strlen('private pdf bytes'),
            'created_at' => now(),
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.orders.returns.show', $this->returnRequest->return_no))
            ->assertOk()
            ->assertSee('case-evidence-preview', false)
            ->assertSee('data-case-image-dialog', false)
            ->assertSee('Preview buyer-proof.png')
            ->assertSee('data-preview-url="'.route('seller.orders.returns.evidence.view', $evidence).'"', false)
            ->assertDontSee('href="'.route('seller.orders.returns.evidence.view', $evidence).'" target="_blank"', false)
            ->assertSee('View file seller-receipt.pdf')
            ->assertDontSee('Download buyer-proof.png');

        $preview = $this->actingAs($this->seller)
            ->get(route('seller.orders.returns.evidence.view', $evidence))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = strtolower((string) $preview->baseResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringStartsWith(
            'inline;',
            (string) $preview->baseResponse->headers->get('Content-Disposition'),
        );

        $this->actingAs($this->seller)
            ->get(route('seller.orders.returns.evidence.view', $pdfEvidence))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->buyer)
            ->get(route('seller.orders.returns.evidence.view', $evidence))
            ->assertForbidden();

        $otherSeller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);
        $otherSellerProfile = SellerProfile::create([
            'user_id' => $otherSeller->id,
            'legal_business_name' => 'Another Seller Store',
            'commission_rate_bps' => 1000,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        Store::create([
            'seller_profile_id' => $otherSellerProfile->id,
            'name' => 'Another Seller Store',
            'slug' => 'another-seller-store',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($otherSeller)
            ->get(route('seller.orders.returns.evidence.view', $evidence))
            ->assertNotFound();
    }

    public function test_both_requested_parties_must_submit_evidence_before_admin_review(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.request-evidence', $this->returnRequest), [
                'note' => 'Please provide additional context and supporting documentation.',
                'request_from' => 'both',
            ])
            ->assertRedirect();

        $this->actingAs($this->buyer)
            ->post(route('buyer.returns.evidence.store', $this->returnRequest->id), [
                'note' => 'Here is more detail about the item I received.',
            ])
            ->assertRedirect();

        $this->assertSame('awaiting_evidence', $this->returnRequest->fresh()->status);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('platform'))
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('return');

        $this->actingAs($this->seller)
            ->post(route('seller.orders.returns.evidence.store', $this->returnRequest->id), [
                'note' => 'Our dispatch log confirms the packed item details.',
            ])
            ->assertRedirect();

        $this->assertSame('under_review', $this->returnRequest->fresh()->status);
    }

    public function test_manual_payout_is_external_and_cannot_be_recorded_twice(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('seller'))
            ->assertRedirect();

        $refund = Refund::query()
            ->where('return_request_id', $this->returnRequest->id)
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds'))
            ->assertOk()
            ->assertSee('External payout only')
            ->assertSee('Bearly does not transfer money')
            ->assertSee('Record completed external payout')
            ->assertSee('<details class="return-payout-disclosure">', false)
            ->assertDontSee('<details class="return-payout-disclosure" open', false)
            ->assertSee('I confirm this exact payout was already sent to the Buyer.')
            ->assertSee('name="external_payout_confirmed" value="1" required', false)
            ->assertSee('name="external_paid_on" type="date"', false)
            ->assertSee('name="receipt" type="file"', false)
            ->assertSee('Confirm & record payout')
            ->assertSee(route('admin.compliance.returns-refunds.record-payout', $refund->id), false);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.record-payout', $refund), [
                'method' => 'gcash',
                'provider_reference' => 'GCASH-RECEIPT-123',
                'external_paid_on' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->create('gcash-receipt.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('external_payout_confirmed');

        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertSame('refund_pending', $this->returnRequest->fresh()->status);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.record-payout', $refund), [
                'external_payout_confirmed' => '1',
                'method' => 'cash',
                'provider_reference' => 'BUYER-ACKNOWLEDGMENT-123',
                'external_paid_on' => now()->toDateString(),
            ])
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('receipt');

        $this->assertSame('pending', $refund->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.record-payout', $refund), [
                'external_payout_confirmed' => '1',
                'method' => 'gcash',
                'provider_reference' => 'GCASH-RECEIPT-123',
                'external_paid_on' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->create('gcash-receipt.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'status' => 'completed',
            'method' => 'gcash',
            'provider_reference' => 'GCASH-RECEIPT-123',
            'financially_responsible_party' => 'seller',
            'payout_amount_minor' => 129900,
        ]);
        $this->assertSame(now()->toDateString(), $refund->fresh()->external_paid_on->toDateString());
        $this->assertSame('resolved', $this->returnRequest->fresh()->status);
        $this->assertSame('refunded', Payment::query()->where('order_id', $this->returnRequest->order_id)->value('status'));
        $this->assertSame(0, SellerTransaction::query()->where('refund_id', $refund->id)->where('type', 'refund_reversal')->count());
        $this->assertDatabaseHas('seller_refund_recoveries', [
            'refund_id' => $refund->id,
            'seller_profile_id' => SellerProfile::query()->where('user_id', $this->seller->id)->value('id'),
            'amount_minor' => 116910,
            'recovered_amount_minor' => 0,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('platform_commissions', [
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'refund_id' => $refund->id,
            'type' => 'refund_reversal',
            'amount_minor' => 12990,
        ]);
        Storage::disk('local')->assertExists($refund->fresh()->payout_receipt_path);

        $this->actingAs($this->seller)
            ->get(route('seller.orders.returns.show', $this->returnRequest->return_no))
            ->assertOk()
            ->assertSee('Refund and financial responsibility')
            ->assertSee('Paid externally by Admin')
            ->assertSee('Recoverable Seller balance')
            ->assertSee('₱1,299.00 paid')
            ->assertSee('₱1,169.10')
            ->assertSee('not automatically deducted')
            ->assertDontSee('Download payout receipt');

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.record-payout', $refund), [
                'external_payout_confirmed' => '1',
                'method' => 'gcash',
                'provider_reference' => 'GCASH-RECEIPT-123',
                'external_paid_on' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->create('duplicate-receipt.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('refund');

        $this->assertSame(0, SellerTransaction::query()->where('refund_id', $refund->id)->where('type', 'refund_reversal')->count());
        $this->assertSame(1, SellerRefundRecovery::query()->where('refund_id', $refund->id)->count());
    }

    public function test_admin_cannot_approve_a_refund_without_recording_financial_responsibility(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), [])
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors(['financially_responsible_party', 'financial_responsibility_reason']);

        $this->assertDatabaseMissing('refunds', [
            'return_request_id' => $this->returnRequest->id,
        ]);
    }

    public function test_platform_responsibility_does_not_create_a_seller_recovery(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('platform'))
            ->assertRedirect();
        $refund = Refund::query()->where('return_request_id', $this->returnRequest->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.record-payout', $refund), $this->payoutData())
            ->assertRedirect();

        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertDatabaseMissing('seller_refund_recoveries', ['refund_id' => $refund->id]);
        $this->assertDatabaseMissing('platform_commissions', [
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'refund_id' => $refund->id,
            'type' => 'refund_reversal',
        ]);
        $this->assertSame(0, SellerTransaction::query()->where('refund_id', $refund->id)->count());
    }

    public function test_admin_must_record_missing_responsibility_for_a_legacy_pending_refund(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('platform'))
            ->assertRedirect();
        $refund = Refund::query()->where('return_request_id', $this->returnRequest->id)->firstOrFail();
        $refund->update([
            'financially_responsible_party' => null,
            'financial_responsibility_reason' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds'))
            ->assertOk()
            ->assertSee('Financial responsibility is missing')
            ->assertSee(route('admin.compliance.returns-refunds.responsibility', $refund->id), false);

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.responsibility', $refund), $this->responsibilityData('seller'))
            ->assertRedirect();

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'financially_responsible_party' => 'seller',
            'financial_responsibility_reason' => 'Evidence supports this responsibility decision.',
        ]);
        $this->assertDatabaseHas('return_events', [
            'return_request_id' => $this->returnRequest->id,
            'event_type' => 'refund_responsibility_recorded',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_seller_recovery_is_recorded_only_after_verified_collection_or_paid_payout_offset(): void
    {
        Storage::fake('local');
        $refund = $this->createCompletedSellerRefund();
        $recovery = SellerRefundRecovery::query()->where('refund_id', $refund->id)->firstOrFail();
        $payout = SellerPayout::query()->create([
            'payout_no' => 'PYO-RECOVERY-TEST-001',
            'seller_profile_id' => $recovery->seller_profile_id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->subDay()->toDateString(),
            'gross_minor' => 116910,
            'commission_minor' => 0,
            'adjustment_minor' => 0,
            'net_minor' => 116910,
            'status' => 'pending',
            'reference' => 'FUTURE-SETTLEMENT-001',
        ]);
        $offsetData = [
            'recovery_confirmed' => '1',
            'amount' => '500.5',
            'method' => 'seller_payout_offset',
            'external_reference' => 'OFFSET-REF-001',
            'recovered_on' => now()->toDateString(),
            'seller_payout_id' => $payout->id,
            'note' => 'Verified against the completed settlement record.',
        ];

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.seller-recovery', $refund), $offsetData)
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('seller_payout_id');
        $this->assertSame(0, (int) $recovery->fresh()->recovered_amount_minor);

        $payout->update(['status' => 'paid', 'paid_at' => now()]);
        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.seller-recovery', $refund), $offsetData)
            ->assertRedirect();

        $this->assertDatabaseHas('seller_refund_recoveries', [
            'id' => $recovery->id,
            'amount_minor' => 116910,
            'recovered_amount_minor' => 50050,
            'status' => 'partially_recovered',
        ]);
        $this->assertDatabaseHas('seller_refund_recovery_events', [
            'seller_refund_recovery_id' => $recovery->id,
            'seller_payout_id' => $payout->id,
            'method' => 'seller_payout_offset',
            'amount_minor' => 50050,
            'external_reference' => 'OFFSET-REF-001',
        ]);
        $this->assertSame(0, (int) $payout->fresh()->adjustment_minor);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.seller-recovery', $refund), $offsetData)
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('external_reference');
        $this->assertSame(50050, (int) $recovery->fresh()->recovered_amount_minor);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route('admin.compliance.returns-refunds.seller-recovery', $refund), [
                'recovery_confirmed' => '1',
                'amount' => '668.61',
                'method' => 'external_collection',
                'external_reference' => 'COLLECTION-OVER-LIMIT',
                'recovered_on' => now()->toDateString(),
                'note' => 'This amount exceeds the open Seller balance.',
            ])
            ->assertRedirect(route('admin.compliance.returns-refunds'))
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.seller-recovery', $refund), [
                'recovery_confirmed' => '1',
                'amount' => '668.60',
                'method' => 'external_collection',
                'external_reference' => 'COLLECTION-REF-002',
                'recovered_on' => now()->toDateString(),
                'note' => 'Seller external collection verified by Admin.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('seller_refund_recoveries', [
            'id' => $recovery->id,
            'recovered_amount_minor' => 116910,
            'status' => 'recovered',
        ]);
        $this->assertSame(2, $recovery->events()->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->admin->id,
            'action' => 'refund.seller_recovery_recorded',
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_buyer_can_view_only_the_completed_refund_receipt(): void
    {
        Storage::fake('local');
        $refund = $this->createCompletedSellerRefund();

        $this->actingAs($this->buyer)
            ->get(route('buyer.returns.show', $this->returnRequest->id))
            ->assertOk()
            ->assertSee('Refund transaction')
            ->assertSee('Paid externally')
            ->assertSee('Amount paid')
            ->assertSee('₱1,299.00')
            ->assertSee('GCASH-RECEIPT-123')
            ->assertSee('Download payout receipt');

        $this->actingAs($this->buyer)
            ->get(route('buyer.returns.refund-receipt', $refund))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $otherBuyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $this->actingAs($otherBuyer)
            ->get(route('buyer.returns.refund-receipt', $refund))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds'))
            ->assertOk()
            ->assertSee('Actual amount paid')
            ->assertSee('₱1,299.00');

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds.payout-receipt', $refund))
            ->assertOk();

        $this->actingAs($this->seller)
            ->get(route('admin.compliance.returns-refunds.payout-receipt', $refund))
            ->assertForbidden();
    }

    public function test_buyer_can_create_a_persisted_request_for_a_paid_completed_item(): void
    {
        $this->returnRequest->update(['status' => 'rejected']);

        $response = $this->actingAs($this->buyer)->post(
            route('buyer.returns.store', $this->returnRequest->seller_order_id),
            [
                'request_type' => 'refund_only',
                'reason_code' => 'item_not_as_described',
                'item_quantities' => [$this->orderItem->id => 1],
                'buyer_note' => 'The received item does not match the saved order description.',
            ],
        );

        $case = ReturnRequest::query()->where('buyer_id', $this->buyer->id)->where('id', '!=', $this->returnRequest->id)->firstOrFail();
        $response->assertRedirect(route('buyer.returns.show', $case->id));
        $this->assertSame('awaiting_seller', $case->status);
        $this->assertSame(129900, (int) $case->requested_amount_minor);
        $this->assertDatabaseHas('return_items', [
            'return_request_id' => $case->id,
            'order_item_id' => $this->orderItem->id,
            'quantity' => 1,
            'refund_amount_minor' => 129900,
        ]);

        $this->actingAs($this->buyer)
            ->from(route('buyer.returns.create', $this->returnRequest->seller_order_id))
            ->post(route('buyer.returns.store', $this->returnRequest->seller_order_id), [
                'request_type' => 'refund_only',
                'reason_code' => 'other',
                'item_quantities' => [$this->orderItem->id => 1],
            ])
            ->assertRedirect(route('buyer.returns.create', $this->returnRequest->seller_order_id))
            ->assertSessionHasErrors('item_quantities');

        $this->assertSame(
            1,
            ReturnRequest::query()
                ->where('order_id', $this->returnRequest->order_id)
                ->where('status', 'awaiting_seller')
                ->count(),
        );
    }

    public function test_creating_a_return_request_notifies_the_owning_seller_store(): void
    {
        $this->returnRequest->update(['status' => 'rejected']);

        $this->actingAs($this->buyer)
            ->post(route('buyer.returns.store', $this->returnRequest->seller_order_id), [
                'request_type' => 'refund_only',
                'reason_code' => 'item_not_as_described',
                'item_quantities' => [$this->orderItem->id => 1],
                'buyer_note' => 'The received item does not match the saved order description.',
            ])
            ->assertRedirect();

        $case = ReturnRequest::query()
            ->where('buyer_id', $this->buyer->id)
            ->where('id', '!=', $this->returnRequest->id)
            ->firstOrFail();

        $notification = $this->seller->notifications()
            ->where('data->event', 'seller_return_updates')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame((int) $case->id, (int) $notification->data['meta']['return_request_id']);
        $this->assertSame((int) $this->returnRequest->seller_order_id, (int) $notification->data['meta']['seller_order_id']);
        $this->assertSame(route('seller.orders.returns'), $notification->data['url']);
        $this->assertStringContainsString($case->return_no, $notification->data['body']);

        // A different Seller never receives this store's return alerts.
        $otherSeller = User::factory()->create(['role' => 'seller', 'status' => 'active']);

        $this->assertSame(0, $otherSeller->notifications()->count());
    }

    public function test_buyer_cannot_request_a_return_after_the_seven_day_window(): void
    {
        $this->returnRequest->sellerOrder()->update(['completed_at' => now()->subDays(8)]);

        $this->actingAs($this->buyer)
            ->from(route('buyer.returns.create', $this->returnRequest->seller_order_id))
            ->post(route('buyer.returns.store', $this->returnRequest->seller_order_id), [
                'request_type' => 'refund_only',
                'reason_code' => 'other',
                'item_quantities' => [$this->orderItem->id => 1],
            ])
            ->assertRedirect(route('buyer.returns.create', $this->returnRequest->seller_order_id))
            ->assertSessionHasErrors('return');
    }

    public function test_buyer_cannot_view_another_buyers_return_case(): void
    {
        $otherBuyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($otherBuyer)
            ->get(route('buyer.returns.show', $this->returnRequest->id))
            ->assertNotFound();
    }

    public function test_return_evidence_downloads_are_authenticated_and_role_scoped(): void
    {
        Storage::fake('local');
        $path = "return-evidence/{$this->returnRequest->return_no}/buyer-proof.txt";
        Storage::disk('local')->put($path, 'private return evidence');
        $evidence = ReturnEvidence::query()->create([
            'return_request_id' => $this->returnRequest->id,
            'uploaded_by' => $this->buyer->id,
            'type' => 'buyer',
            'mime_type' => 'text/plain',
            'file_path' => $path,
            'original_name' => 'buyer-proof.txt',
            'size_bytes' => strlen('private return evidence'),
            'created_at' => now(),
        ]);

        $this->actingAs($this->buyer)
            ->get(route('buyer.returns.evidence.download', $evidence))
            ->assertOk();

        $otherBuyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $this->actingAs($otherBuyer)
            ->get(route('buyer.returns.evidence.download', $evidence))
            ->assertNotFound();

        $this->actingAs($this->seller)
            ->get(route('seller.orders.returns.evidence.download', $evidence))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds.evidence.download', $evidence))
            ->assertOk();
    }

    public function test_admin_can_preview_private_image_evidence_inline(): void
    {
        Storage::fake('local');
        $imagePath = "return-evidence/{$this->returnRequest->return_no}/buyer-proof.png";
        Storage::disk('local')->put($imagePath, 'private image bytes');
        $imageEvidence = ReturnEvidence::query()->create([
            'return_request_id' => $this->returnRequest->id,
            'uploaded_by' => $this->buyer->id,
            'type' => 'buyer',
            'mime_type' => 'image/png',
            'file_path' => $imagePath,
            'original_name' => 'buyer-proof.png',
            'size_bytes' => strlen('private image bytes'),
            'created_at' => now(),
        ]);

        $pdfEvidence = ReturnEvidence::query()->create([
            'return_request_id' => $this->returnRequest->id,
            'uploaded_by' => $this->seller->id,
            'type' => 'seller',
            'mime_type' => 'application/pdf',
            'file_path' => 'return-evidence/seller-proof.pdf',
            'original_name' => 'seller-proof.pdf',
            'size_bytes' => 100,
            'created_at' => now(),
        ]);

        $preview = $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds.evidence.preview', $imageEvidence))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = strtolower((string) $preview->baseResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringStartsWith(
            'inline;',
            (string) $preview->baseResponse->headers->get('Content-Disposition'),
        );
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->admin->id,
            'action' => 'return.evidence_previewed',
            'module' => 'Returns & Refunds',
            'auditable_id' => $this->returnRequest->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds'))
            ->assertOk()
            ->assertSee('data-preview-url="'.route('admin.compliance.returns-refunds.evidence.preview', $imageEvidence).'"', false)
            ->assertSee('aria-label="Preview Buyer image buyer-proof.png"', false)
            ->assertSee('Download buyer-proof.png')
            ->assertDontSee('Preview Seller image seller-proof.pdf');

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.returns-refunds.evidence.preview', $pdfEvidence))
            ->assertNotFound();

        $this->actingAs($this->buyer)
            ->get(route('admin.compliance.returns-refunds.evidence.preview', $imageEvidence))
            ->assertForbidden();
    }

    public function test_admin_cannot_confirm_a_failed_or_cancelled_payment(): void
    {
        $payment = Payment::query()->where('order_id', $this->returnRequest->order_id)->firstOrFail();
        $payment->update(['status' => 'failed', 'paid_at' => null]);

        $this->actingAs($this->admin)
            ->post(route('admin.orders.payments.confirm', $payment), [
                'provider_reference' => 'SHOULD-NOT-CONFIRM',
            ])
            ->assertStatus(409);

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'payment.confirmed',
            'auditable_id' => $payment->id,
        ]);

        $payment->update(['status' => 'pending']);
        $this->returnRequest->order()->update(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->post(route('admin.orders.payments.confirm', $payment), [
                'provider_reference' => 'CANCELLED-ORDER-PAYMENT',
            ])
            ->assertStatus(409);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /** @return array{financially_responsible_party: string, financial_responsibility_reason: string} */
    private function responsibilityData(string $party): array
    {
        return [
            'financially_responsible_party' => $party,
            'financial_responsibility_reason' => 'Evidence supports this responsibility decision.',
        ];
    }

    /** @return array<string, mixed> */
    private function payoutData(): array
    {
        return [
            'external_payout_confirmed' => '1',
            'method' => 'gcash',
            'provider_reference' => 'GCASH-RECEIPT-123',
            'external_paid_on' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->create('gcash-receipt.pdf', 10, 'application/pdf'),
        ];
    }

    private function createCompletedSellerRefund(): Refund
    {
        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.approve', $this->returnRequest), $this->responsibilityData('seller'))
            ->assertRedirect();
        $refund = Refund::query()->where('return_request_id', $this->returnRequest->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.returns-refunds.record-payout', $refund), $this->payoutData())
            ->assertRedirect();

        return $refund->fresh();
    }

    private function createLogisticsProvider(string $displayName, string $profileStatus = 'active'): LogisticsProfile
    {
        $logisticsUser = User::factory()->create([
            'role' => 'logistics',
            'status' => 'active',
            'name' => $displayName,
        ]);

        return LogisticsProfile::query()->create([
            'user_id' => $logisticsUser->id,
            'legal_name' => $displayName,
            'display_name' => $displayName,
            'contact_phone' => fake()->unique()->numerify('09#########'),
            'psgc_city_code' => str_contains($displayName, 'Alternate')
                ? '0403422000'
                : '0403405000',
            'status' => $profileStatus,
        ]);
    }

    /** @return array{seller_address: Address, outbound: Shipment} */
    private function preparePhysicalReturn(LogisticsProfile $provider): array
    {
        $this->returnRequest->update(['request_type' => 'return_refund']);
        $sellerProfile = SellerProfile::query()->where('user_id', $this->seller->id)->firstOrFail();
        $sellerAddress = Address::query()->create([
            'user_id' => $this->seller->id,
            'label' => 'Seller Pickup',
            'recipient_name' => $this->seller->name,
            'phone' => '09171111111',
            'house_number' => '14',
            'street' => 'Store Road',
            'barangay' => 'Real',
            'city_municipality' => 'Pila',
            'province' => 'Laguna',
            'postal_code' => '4010',
            'psgc_city_code' => '0403422000',
        ]);
        $sellerProfile->update(['pickup_address_id' => $sellerAddress->id]);

        $outbound = Shipment::query()->create([
            'shipment_no' => 'SHP-RETURN-OUTBOUND-001',
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'logistics_profile_id' => $provider->id,
            'origin_logistics_profile_id' => $provider->id,
            'destination_city_code' => '0403405000',
            'purpose' => 'outbound',
            'status' => 'delivered',
            'shipping_fee_minor' => 0,
            'cod_amount_minor' => 0,
        ]);
        Waybill::query()->create([
            'shipment_id' => $outbound->id,
            'waybill_no' => 'BRLY-RETURN-OUTBOUND-001',
            'scan_token' => str_repeat('a', 64),
            'barcode_value' => 'BRLY-RETURN-OUTBOUND-BARCODE-001',
            'piece_count' => 1,
            'total_weight_kg' => 1.25,
            'length_cm' => 20,
            'width_cm' => 15,
            'height_cm' => 10,
            'generated_at' => now(),
        ]);

        return [
            'seller_address' => $sellerAddress,
            'outbound' => $outbound,
        ];
    }
}
