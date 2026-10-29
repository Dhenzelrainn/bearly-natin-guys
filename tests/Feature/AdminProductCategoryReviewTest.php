<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductViolation;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\ComplianceScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProductCategoryReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private Store $store;

    private Category $businessCategory;

    private MarketplaceSubcategory $marketplaceSubcategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@bearly.test')->firstOrFail();
        $this->businessCategory = Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
        $this->seller = User::query()->create([
            'name' => 'Category Review Seller',
            'first_name' => 'Category Review',
            'last_name' => 'Seller',
            'email' => 'category-review-'.Str::uuid().'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $this->seller->id,
            'legal_business_name' => 'Category Review Business',
            'approved_category_id' => $this->businessCategory->id,
            'approved_at' => now(),
        ]);
        $this->store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Category Review Store',
            'slug' => 'category-review-store-'.$profile->id,
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        $marketplaceCategory = MarketplaceCategory::query()
            ->where('slug', 'electronics-and-gadgets')
            ->firstOrFail();
        $this->marketplaceSubcategory = MarketplaceSubcategory::query()
            ->where('marketplace_category_id', $marketplaceCategory->id)
            ->orderBy('position')
            ->firstOrFail();
    }

    public function test_queue_searches_and_includes_clear_stale_and_unscanned_buyer_visible_products(): void
    {
        $clear = $this->product('Short testing', 'A short sample description for the Admin review queue.');
        app(ComplianceScanner::class)->scan($clear);
        $this->addImage($clear);

        $stale = $this->product('USB Wall Charger', 'A compact charger with two ports.');
        app(ComplianceScanner::class)->scan($stale);
        $stale->update(['description' => 'A compact wall charger with one updated detail.']);

        $unscanned = $this->product('Bluetooth Speaker', 'A portable speaker for home use.');

        $openViolationProduct = $this->product('Already in enforcement', 'This must not be duplicated in the review queue.');
        ProductViolation::query()->create([
            'violation_no' => 'VIO-'.Str::upper(Str::random(10)),
            'product_id' => $openViolationProduct->id,
            'seller_profile_id' => $this->store->seller_profile_id,
            'source' => 'admin_manual_review',
            'violation_type' => 'category_correction',
            'severity' => 'medium',
            'reason' => 'Existing actionable category violation.',
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews', ['search' => 'Short testing']))
            ->assertOk()
            ->assertSee('Short testing')
            ->assertSee('Category Review Store')
            ->assertSee('Category Review Business')
            ->assertSee('Electronics and Gadgets')
            ->assertSee($this->marketplaceSubcategory->name)
            ->assertSee('Clear')
            ->assertSee('Mark category appropriate')
            ->assertSee('Flag for correction');

        $this->assertStringContainsString('storage/', $response->getContent());

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews'))
            ->assertOk()
            ->assertSee('USB Wall Charger')
            ->assertSee('Stale text/category scan')
            ->assertSee('Bluetooth Speaker')
            ->assertSee('No scan recorded')
            ->assertDontSee('Already in enforcement');

        $this->assertTrue(Product::visibleToBuyers()->whereKey($clear->id)->exists());
        $this->assertTrue(Product::visibleToBuyers()->whereKey($stale->id)->exists());
        $this->assertTrue(Product::visibleToBuyers()->whereKey($unscanned->id)->exists());
    }

    public function test_category_review_thumbnails_use_the_shared_local_image_url_policy(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config([
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.url' => 'https://bearlymarket.com/storage',
            'image_urls.local_http_hosts' => ['localhost', '127.0.0.1'],
        ]);

        $firstProduct = $this->product('Category review thumbnail one', 'A sample listing for local image URL coverage.');
        $firstImage = $this->addImage($firstProduct);
        $firstImage->update(['path' => 'seller-products/category-review-policy-one.png']);

        $secondProduct = $this->product('Category review thumbnail two', 'Another sample listing for local image URL coverage.');
        $secondImage = $this->addImage($secondProduct);
        $secondImage->update(['path' => 'seller-products/category-review-policy-two.png']);

        $response = $this->actingAs($this->admin)
            ->withServerVariables([
                'HTTP_HOST' => '127.0.0.1:8000',
                'SERVER_NAME' => '127.0.0.1',
                'SERVER_PORT' => '8000',
                'HTTPS' => 'off',
            ])
            ->get('http://127.0.0.1:8000/admin/compliance/category-reviews')
            ->assertOk();

        $response->assertSee('src="http://127.0.0.1:8000/storage/'.$firstImage->path.'"', false);
        $response->assertSee('src="http://127.0.0.1:8000/storage/'.$secondImage->path.'"', false);
    }

    public function test_clear_review_records_actor_reason_snapshot_and_requeues_after_text_or_image_metadata_changes(): void
    {
        $product = $this->product('Short testing', 'The listing needs a manual category check.');
        $image = $this->addImage($product);
        app(ComplianceScanner::class)->scan($product);
        $reason = 'Reviewed the product photo and listing details; the electronics category is appropriate.';

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.category-reviews'))
            ->post(route('admin.compliance.category-reviews.reviewed', $product), ['reason' => $reason])
            ->assertRedirect(route('admin.compliance.category-reviews'))
            ->assertSessionHas('success');

        $review = AuditLog::query()
            ->where('action', 'category_review_marked_appropriate')
            ->where('auditable_type', $product->getMorphClass())
            ->where('auditable_id', $product->id)
            ->firstOrFail();

        $this->assertSame($this->admin->id, (int) $review->actor_user_id);
        $this->assertSame($reason, $review->description);
        $this->assertSame($reason, $review->new_values['reason']);
        $this->assertSame($this->admin->id, (int) $review->new_values['reviewed_by']);
        $this->assertNotEmpty($review->new_values['reviewed_at']);
        $this->assertSame($image->path, $review->new_values['snapshot']['images'][0]['path']);
        $this->assertSame(64, strlen($review->new_values['fingerprint']));

        $unchangedResponse = $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews', ['search' => 'Short testing']))
            ->assertOk();
        $this->assertSame(0, $unchangedResponse->viewData('products')->total());

        $product->update(['description' => 'An updated description returns this listing to review.']);

        $textChangedResponse = $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews', ['search' => 'Short testing']))
            ->assertOk()
            ->assertSee('Product #'.$product->id);
        $this->assertSame(1, $textChangedResponse->viewData('products')->total());

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.category-reviews.reviewed', $product), [
                'reason' => 'Rechecked the revised listing; category remains appropriate.',
            ])
            ->assertRedirect();

        $unchangedAfterSecondReview = $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews', ['search' => 'Short testing']))
            ->assertOk();
        $this->assertSame(0, $unchangedAfterSecondReview->viewData('products')->total());

        $image->update(['path' => 'products/category-review/replaced-product-photo.jpg']);

        $imageChangedResponse = $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews', ['search' => 'Short testing']))
            ->assertOk()
            ->assertSee('Product #'.$product->id);
        $this->assertSame(1, $imageChangedResponse->viewData('products')->total());

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.category-reviews.reviewed', $product), [
                'reason' => 'Rechecked the replacement photo; category remains appropriate.',
            ])
            ->assertRedirect();

        $product->update([
            'category_id' => Category::query()->where('slug', 'fashion-and-apparel')->value('id'),
        ]);

        $categoryChangedResponse = $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews', ['search' => 'Short testing']))
            ->assertOk()
            ->assertSee('Fashion and Apparel')
            ->assertSee('Product #'.$product->id);
        $this->assertSame(1, $categoryChangedResponse->viewData('products')->total());
    }

    public function test_manual_category_flag_creates_audited_violation_hides_product_and_never_warns_automatically(): void
    {
        Notification::fake();

        $product = $this->product('Unclear listing category', 'The photo and description do not support this category.');
        app(ComplianceScanner::class)->scan($product);
        $reason = 'The product shown is apparel and should not remain in Electronics and Gadgets.';

        $this->actingAs($this->admin)
            ->post(route('admin.compliance.category-reviews.flag', $product), ['reason' => $reason])
            ->assertRedirect(route('admin.compliance.violations'))
            ->assertSessionHas('success');

        $violation = ProductViolation::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('admin_manual_review', $violation->source);
        $this->assertSame('category_correction', $violation->violation_type);
        $this->assertSame($reason, $violation->reason);
        $this->assertSame('flagged', $violation->status);
        $this->assertSame($this->admin->id, (int) $violation->assigned_to);
        $this->assertSame('flagged', $product->fresh()->product_status);
        $this->assertSame('flagged', $product->fresh()->compliance_status);
        $this->assertFalse(Product::visibleToBuyers()->whereKey($product->id)->exists());
        $this->assertDatabaseHas('violation_actions', [
            'violation_id' => $violation->id,
            'action_type' => 'manual_category_flag',
            'to_status' => 'flagged',
            'actor_user_id' => $this->admin->id,
            'reason' => $reason,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->admin->id,
            'action' => 'category_correction_flagged',
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'description' => $reason,
        ]);
        $this->assertSame(0, $violation->warnings()->count());
        Notification::assertNothingSent();

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.violations'))
            ->assertOk()
            ->assertSee($violation->violation_no)
            ->assertSee('Category Correction')
            ->assertSee($reason);

        $nextScan = app(ComplianceScanner::class)->scan($product->fresh());
        $this->assertSame('review_required', $nextScan->result);
        $this->assertSame('flagged', $product->fresh()->compliance_status);
        $this->assertSame(0, $violation->warnings()->count());
        Notification::assertNothingSent();

        // Simulate a stale page/client retrying after the first hold was applied.
        $this->actingAs($this->admin)
            ->from(route('admin.compliance.category-reviews'))
            ->post(route('admin.compliance.category-reviews.flag', $product), [
                'reason' => 'A duplicate request must not create another open manual violation.',
            ])
            ->assertRedirect(route('admin.compliance.category-reviews'))
            ->assertSessionHasErrors('product');

        $this->assertSame(1, ProductViolation::query()
            ->where('product_id', $product->id)
            ->where('source', 'admin_manual_review')
            ->whereIn('status', ['flagged', 'under_review', 'correction_requested'])
            ->count());
        $this->assertSame(0, $violation->warnings()->count());

        $warningReason = 'Admin explicitly chose to issue a warning after reviewing the category violation.';
        $this->actingAs($this->admin)
            ->post(route('admin.compliance.decide', $violation), [
                'decision' => 'warn_seller',
                'note' => $warningReason,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('seller_warnings', [
            'violation_id' => $violation->id,
            'seller_profile_id' => $this->store->seller_profile_id,
            'issued_by' => $this->admin->id,
            'reason' => $warningReason,
            'status' => 'active',
        ]);
    }

    public function test_manual_category_actions_require_a_reason_and_remain_admin_only(): void
    {
        $product = $this->product('Review permissions listing', 'A simple item description.');

        $this->actingAs($this->admin)
            ->from(route('admin.compliance.category-reviews'))
            ->post(route('admin.compliance.category-reviews.reviewed', $product), ['reason' => ''])
            ->assertRedirect(route('admin.compliance.category-reviews'))
            ->assertSessionHasErrors('reason');

        $buyer = User::query()->create([
            'name' => 'Category Review Buyer',
            'first_name' => 'Category Review',
            'last_name' => 'Buyer',
            'email' => 'category-review-buyer-'.Str::uuid().'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($buyer)
            ->get(route('admin.compliance.category-reviews'))
            ->assertForbidden();

        $this->actingAs($buyer)
            ->post(route('admin.compliance.category-reviews.flag', $product), ['reason' => 'Unauthorized flag.'])
            ->assertForbidden();

        $this->assertSame('active', $product->fresh()->product_status);
        $this->assertSame(0, ProductViolation::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, AuditLog::query()->where('auditable_id', $product->id)->count());
    }

    private function product(string $name, string $description): Product
    {
        $product = Product::query()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->businessCategory->id,
            'marketplace_subcategory_id' => $this->marketplaceSubcategory->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => $description,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'CATEGORY-REVIEW-'.Str::upper(Str::random(10)),
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => true,
        ]);

        return $product;
    }

    private function addImage(Product $product): ProductImage
    {
        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/category-review/product-'.$product->id.'.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'purpose' => 'product_image',
            'alt_text' => 'Category review test photo',
            'position' => 0,
            'is_primary' => true,
        ]);
    }
}
