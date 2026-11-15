<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ComplianceRule;
use App\Models\Product;
use App\Models\ProductViolation;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminProductViolationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()
            ->where('email', 'admin@bearly.test')
            ->firstOrFail();
    }

    public function test_admin_violation_queue_uses_persisted_records_and_decision_history(): void
    {
        $violation = $this->createViolation([
            'status' => 'under_review',
            'reason' => 'The product description contains an unverified health claim.',
        ]);

        $violation->actions()->create([
            'action_type' => 'request_correction',
            'from_status' => 'flagged',
            'to_status' => 'under_review',
            'actor_user_id' => $this->admin->id,
            'reason' => 'Please remove the unsupported claim.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.violations'))
            ->assertOk()
            ->assertSee($violation->violation_no)
            ->assertSee('Test Prohibited Listing')
            ->assertSee('Test Seller Store')
            ->assertSee('Decision history')
            ->assertSee('Please remove the unsupported claim.')
            ->assertSee('name="decision" value="remove_product"', false)
            ->assertSee('data-admin-violation-decision-form', false)
            ->assertSee('data-decision-reason-guide', false)
            ->assertSee('data-decision-submit', false)
            ->assertSee('data-reason-guidance="Tell us what rule the Seller broke."', false)
            ->assertSee('data-reason-placeholder="Remove this product because [what is not allowed]."', false)
            ->assertSee('name="note"', false)
            ->assertDontSee('Escalate Seller')
            ->assertDontSee('VIO-4108')
            ->assertSee('data-status="under_review"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance'))
            ->assertOk()
            ->assertSee('Product Compliance')
            ->assertSee('CUSTOMER CASES')
            ->assertSee('Compliance Overview')
            ->assertSee('Complaints & Disputes')
            ->assertSee('Open Product Violations')
            ->assertDontSee('Test Prohibited Listing');
    }

    public function test_violation_search_and_status_filters_use_persisted_values(): void
    {
        $matchingViolation = $this->createViolation(['status' => 'under_review']);
        $otherViolation = $this->createViolation();
        $otherViolation->product->update(['name' => 'Different Product']);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.violations', [
                'search' => 'Test Prohibited Listing',
                'status' => 'under_review',
            ]))
            ->assertOk()
            ->assertSee($matchingViolation->violation_no)
            ->assertDontSee($otherViolation->violation_no)
            ->assertSee('name="status"', false);
    }

    public function test_admin_queue_explains_content_based_category_mismatch_with_rule_and_excerpt(): void
    {
        $violation = $this->createViolation([
            'rule_id' => ComplianceRule::query()->where('code', 'CATEGORY_MISMATCH_FASHION_ELECTRONICS')->value('id'),
            'violation_type' => 'CATEGORY_MISMATCH_FASHION_ELECTRONICS',
            'reason' => 'Electronics product listed under Fashion and Apparel',
            'severity' => 'high',
            'detected_excerpt' => 'smartphone',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.violations'))
            ->assertOk()
            ->assertSee($violation->violation_no)
            ->assertSee('Electronics product listed under Fashion and Apparel')
            ->assertSee('Detected text')
            ->assertSee('smartphone')
            ->assertSee("Both categories are the Seller's registered category", false);
    }

    public function test_admin_can_issue_a_reasoned_warning_and_it_is_audited_once(): void
    {
        Notification::fake();

        $violation = $this->createViolation(['severity' => 'high']);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.violations'))
            ->post(route('admin.compliance.decide', $violation), [
                'violation_id' => $violation->id,
                'decision' => 'warn_seller',
                'note' => 'The health claim is unsupported and must not be repeated.',
            ])
            ->assertRedirect(route('admin.compliance.violations'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('product_violations', [
            'id' => $violation->id,
            'status' => 'confirmed',
            'resolution' => 'warn_seller',
            'admin_note' => 'The health claim is unsupported and must not be repeated.',
            'assigned_to' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('seller_warnings', [
            'seller_profile_id' => $violation->seller_profile_id,
            'violation_id' => $violation->id,
            'level' => 'high',
            'points' => 5,
            'reason' => 'The health claim is unsupported and must not be repeated.',
            'issued_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('violation_actions', [
            'violation_id' => $violation->id,
            'action_type' => 'warn_seller',
            'from_status' => 'flagged',
            'to_status' => 'confirmed',
            'actor_user_id' => $this->admin->id,
            'reason' => 'The health claim is unsupported and must not be repeated.',
        ]);

        $seller = $violation->sellerProfile->user;

        Notification::assertSentTo(
            $seller,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification, array $channels): bool => $notification->eventKey() === 'seller_compliance_flagged'
                && in_array('database', $channels, true)
                && $notification->meta()['violation_id'] === $violation->id,
        );
        Notification::assertSentToTimes($seller, WorkflowNotification::class, 1);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.violations'))
            ->post(route('admin.compliance.decide', $violation), [
                'violation_id' => $violation->id,
                'decision' => 'warn_seller',
                'note' => 'Retry should not issue another warning.',
            ])
            ->assertRedirect(route('admin.compliance.violations'))
            ->assertSessionHasErrors('decision');

        $this->assertDatabaseCount('seller_warnings', 1);
        $this->assertDatabaseCount('violation_actions', 1);
        Notification::assertSentToTimes($seller, WorkflowNotification::class, 1);
    }

    public function test_warn_seller_decision_survives_notification_delivery_failure(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('SMTP transport unavailable.'));
        Log::spy();

        $violation = $this->createViolation(['severity' => 'high']);

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.violations'))
            ->post(route('admin.compliance.decide', $violation), [
                'violation_id' => $violation->id,
                'decision' => 'warn_seller',
                'note' => 'Delivery failure must not roll back this warning.',
            ])
            ->assertRedirect(route('admin.compliance.violations'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('product_violations', [
            'id' => $violation->id,
            'status' => 'confirmed',
            'resolution' => 'warn_seller',
        ]);
        $this->assertDatabaseHas('seller_warnings', [
            'violation_id' => $violation->id,
            'reason' => 'Delivery failure must not roll back this warning.',
            'issued_by' => $this->admin->id,
            'status' => 'active',
        ]);

        // The in-app notification still lands even though the mail transport failed.
        $seller = $violation->sellerProfile->user;
        $this->assertSame(1, $seller->notifications()->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Bearly notification delivery failed'))
            ->once();
    }

    public function test_require_removal_blocks_the_listing_and_keeps_product_and_violation_records(): void
    {
        $violation = $this->createViolation();

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.decide', $violation), [
                'violation_id' => $violation->id,
                'decision' => 'remove_product',
                'note' => 'The listing offers prohibited ammunition.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', [
            'id' => $violation->product_id,
            'product_status' => 'blocked',
            'compliance_status' => 'blocked',
            'removed_reason' => 'The listing offers prohibited ammunition.',
        ]);
        $this->assertNotNull($violation->product->fresh()->removed_at);
        $this->assertDatabaseHas('product_violations', [
            'id' => $violation->id,
            'status' => 'confirmed',
            'resolution' => 'remove_product',
        ]);
        $this->assertDatabaseHas('violation_actions', [
            'violation_id' => $violation->id,
            'action_type' => 'remove_product',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_dismissal_preserves_history_and_only_clears_compliance_without_other_open_violations(): void
    {
        $violation = $this->createViolation();
        $otherViolation = $this->createViolationForProduct($violation->product, [
            'status' => 'under_review',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.decide', $violation), [
                'violation_id' => $violation->id,
                'decision' => 'dismiss',
                'note' => 'Manual review confirmed this was a false positive.',
            ])
            ->assertRedirect();

        $this->assertSame('dismissed', $violation->fresh()->status);
        $this->assertSame('flagged', $violation->product->fresh()->compliance_status);
        $this->assertDatabaseHas('violation_actions', [
            'violation_id' => $violation->id,
            'action_type' => 'dismiss',
            'reason' => 'Manual review confirmed this was a false positive.',
        ]);
        $this->assertDatabaseHas('product_violations', [
            'id' => $otherViolation->id,
            'status' => 'under_review',
        ]);

        $otherViolation->update(['status' => 'dismissed']);

        $lastOpenViolation = $this->createViolationForProduct($violation->product, [
            'status' => 'flagged',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.decide', $lastOpenViolation), [
                'violation_id' => $lastOpenViolation->id,
                'decision' => 'dismiss',
                'note' => 'The final remaining flag was also a false positive.',
            ])
            ->assertRedirect();

        $this->assertSame('clear', $violation->product->fresh()->compliance_status);
        $this->assertSame('active', $violation->product->fresh()->product_status);
        $this->assertDatabaseHas('product_violations', [
            'id' => $lastOpenViolation->id,
            'status' => 'dismissed',
        ]);
    }

    public function test_request_correction_keeps_violation_open_and_requires_a_reason(): void
    {
        $violation = $this->createViolation();

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.violations'))
            ->post(route('admin.compliance.decide', $violation), [
                'violation_id' => $violation->id,
                'decision' => 'request_correction',
                'note' => 'Remove the unsupported claim and submit the corrected listing.',
            ])
            ->assertRedirect(route('admin.compliance.violations'));

        $this->assertSame('under_review', $violation->fresh()->status);
        $this->assertNull($violation->fresh()->resolved_at);
        $this->assertSame('flagged', $violation->product->fresh()->compliance_status);
        $this->assertDatabaseHas('violation_actions', [
            'violation_id' => $violation->id,
            'action_type' => 'request_correction',
            'to_status' => 'under_review',
        ]);

        $anotherViolation = $this->createViolation();

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.violations'))
            ->post(route('admin.compliance.decide', $anotherViolation), [
                'violation_id' => $anotherViolation->id,
                'decision' => 'dismiss',
                'note' => '',
            ])
            ->assertRedirect(route('admin.compliance.violations'))
            ->assertSessionHasErrors('note');

        $this->assertSame('flagged', $anotherViolation->fresh()->status);
        $this->assertDatabaseMissing('violation_actions', [
            'violation_id' => $anotherViolation->id,
        ]);
    }

    public function test_only_admins_can_view_or_decide_product_violations(): void
    {
        $violation = $this->createViolation();
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer)
            ->get(route('admin.compliance.violations'))
            ->assertForbidden();

        $this->actingAs($buyer)
            ->post(route('admin.compliance.decide', $violation), [
                'decision' => 'remove_product',
                'note' => 'Unauthorized attempt.',
            ])
            ->assertForbidden();

        $this->assertSame('flagged', $violation->fresh()->status);
        $this->assertDatabaseCount('violation_actions', 0);
    }

    private function createViolation(array $overrides = []): ProductViolation
    {
        $category = Category::query()->create([
            'name' => 'Violation Test Category '.Str::random(6),
            'slug' => 'violation-test-'.Str::lower(Str::random(8)),
        ]);
        $seller = User::factory()->create(['role' => 'seller']);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Test Seller Business',
            'approved_category_id' => $category->id,
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Test Seller Store',
            'slug' => 'test-seller-store-'.$profile->id,
            'publication_status' => 'published',
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Test Prohibited Listing',
            'slug' => 'test-prohibited-listing-'.$profile->id,
            'description' => 'Fixture product for moderation tests.',
            'product_status' => 'active',
            'compliance_status' => 'flagged',
        ]);

        return $this->createViolationForProduct($product, $overrides);
    }

    private function createViolationForProduct(Product $product, array $overrides = []): ProductViolation
    {
        return ProductViolation::query()->create(array_merge([
            'violation_no' => 'VIO-'.Str::upper(Str::random(10)),
            'product_id' => $product->id,
            'seller_profile_id' => $product->store->seller_profile_id,
            'rule_id' => ComplianceRule::query()->where('code', 'PROHIBITED_FIREARMS')->value('id'),
            'source' => 'automated',
            'violation_type' => 'PROHIBITED_FIREARMS',
            'severity' => 'critical',
            'reason' => 'Firearms and ammunition',
            'detected_excerpt' => 'ammunition',
            'status' => 'flagged',
        ], $overrides));
    }
}
