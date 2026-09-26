<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReturnRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $buyer;
    private ReturnRequest $returnRequest;

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

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);

        $sellerProfile = SellerProfile::create([
            'user_id' => $seller->id,
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

        Payment::create([
            'payment_no' => 'PAY-RETURN-001',
            'order_id' => $order->id,
            'method' => 'gcash',
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
            'request_type' => 'return_refund',
            'reason_code' => 'item_arrived_damaged',
            'buyer_note' => 'Item arrived damaged.',
            'status' => 'escalated',
            'requested_amount_minor' => 129900,
            'response_due_at' => now()->addDay(),
            'submitted_at' => now(),
        ]);
    }

    public function test_admin_can_approve_return_and_create_pending_refund(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->post(route(
                'admin.compliance.returns-refunds.approve',
                $this->returnRequest
            ));

        $response->assertRedirect();

        $this->returnRequest->refresh();

        $this->assertSame(
            'approved',
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
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->admin->id,
            'action' => 'return.refund_approved',
            'module' => 'Returns & Refunds',
            'auditable_id' => $this->returnRequest->id,
        ]);
    }

    public function test_admin_can_reject_return_without_creating_refund(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->post(route(
                'admin.compliance.returns-refunds.reject',
                $this->returnRequest
            ));

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
            ));

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
            ))
            ->assertRedirect();

        $response = $this
            ->actingAs($this->admin)
            ->from(route('admin.compliance.returns-refunds'))
            ->post(route(
                'admin.compliance.returns-refunds.reject',
                $this->returnRequest
            ));

        $response->assertRedirect(
            route('admin.compliance.returns-refunds')
        );

        $response->assertSessionHasErrors('return');

        $this->returnRequest->refresh();

        $this->assertSame(
            'approved',
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
}