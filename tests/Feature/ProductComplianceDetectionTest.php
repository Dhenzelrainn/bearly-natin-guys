<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ComplianceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\SellerWarning;
use App\Models\Store;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\ComplianceScanner;
use App\Services\ProductViolationDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductComplianceDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_seller_publish_blocks_gun_listing_warns_seller_and_hides_product_from_buyers(): void
    {
        $category = $this->category('electronics-and-gadgets');
        [$seller, $store] = $this->sellerAndStore($category);

        $response = $this->actingAs($seller)->post('/seller/products', $this->sellerProductPayload(
            $category,
            'Gun listing',
            'A product description for moderation coverage.',
        ));

        $response->assertRedirect('/seller/products')
            ->assertSessionHas('compliance_warning');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame('blocked', $product->product_status);
        $this->assertSame('blocked', $product->compliance_status);
        $this->assertSame(1, $product->violations()->count());
        $this->assertSame(1, $product->violations()->firstOrFail()->warnings()->where('status', 'active')->count());
        $this->assertSame('PROHIBITED_FIREARM_GUN_ALIAS', $product->violations()->firstOrFail()->violation_type);
        $this->assertFalse(Product::visibleToBuyers()->whereKey($product->id)->exists());

        app(ComplianceScanner::class)->scan($product->fresh());

        $this->assertSame(1, $product->violations()->count(), 'Repeated scans must reuse the open violation.');
        $this->assertSame(1, $product->violations()->firstOrFail()->warnings()->where('status', 'active')->count());

        $this->actingAs($seller)
            ->get('/seller/products?status=Blocked')
            ->assertOk()
            ->assertSee('Compliance warning: this product is blocked and hidden from Buyers. Review the reason beside the product before making changes.')
            ->assertSee('Critical compliance warning')
            ->assertSee('Prohibited firearm listing (gun)');

        $buyer = $this->user('buyer');

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertNotFound();

        $this->actingAs($seller)
            ->put('/seller/products/'.$product->id, $this->sellerProductPayload(
                $category,
                'Bluetooth Headphones',
                'A product description without prohibited content.',
            ))
            ->assertRedirect('/seller/products');

        $product->refresh();
        $this->assertSame('active', $product->product_status);
        $this->assertSame('clear', $product->compliance_status);
        $this->assertSame(0, $product->violations()->whereIn('status', ['flagged', 'under_review', 'correction_requested'])->count());
        $this->assertSame(0, $product->violations()->firstOrFail()->warnings()->where('status', 'active')->count());

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Bluetooth Headphones');
    }

    public function test_category_rules_hold_apparel_in_electronics_but_allow_legitimate_electronics(): void
    {
        $category = $this->category('electronics-and-gadgets');
        [$seller, $store] = $this->sellerAndStore($category);
        $scanner = app(ComplianceScanner::class);
        $apparel = $this->product($store, $category, 'T-Shirt', 'Cotton short-sleeve shirt.');

        $apparelCheck = $scanner->scan($apparel);

        $this->assertSame('review_required', $apparelCheck->result);
        $this->assertSame('flagged', $apparel->fresh()->product_status);
        $this->assertSame('CATEGORY_MISMATCH_ELECTRONICS_APPAREL', $apparelCheck->matches->firstOrFail()->rule->code);
        $this->assertDatabaseHas('seller_warnings', [
            'violation_id' => $apparel->violations()->value('id'),
            'seller_profile_id' => $seller->sellerProfile->id,
            'status' => 'active',
        ]);
        $this->assertFalse(Product::visibleToBuyers()->whereKey($apparel->id)->exists());

        $shorts = $this->product($store, $category, 'Cargo Shorts', 'Casual clothing with an adjustable waist.');
        $shortsCheck = $scanner->scan($shorts);

        $this->assertSame('review_required', $shortsCheck->result);
        $this->assertSame('CATEGORY_MISMATCH_ELECTRONICS_APPAREL', $shortsCheck->matches->firstOrFail()->rule->code);
        $this->assertFalse(Product::visibleToBuyers()->whereKey($shorts->id)->exists());

        $electronics = $this->product($store, $category, 'Bluetooth Headphones', 'Wireless stereo headset with USB-C charging.');
        $electronicsCheck = $scanner->scan($electronics);

        $this->assertSame('clear', $electronicsCheck->result);
        $this->assertSame('clear', $electronics->fresh()->compliance_status);
        $this->assertSame('active', $electronics->fresh()->product_status);
        $this->assertSame(0, $electronics->violations()->count());
    }

    public function test_electronics_apparel_terms_are_specific_and_category_mismatches_only_hold(): void
    {
        $category = $this->category('electronics-and-gadgets');
        [, $store] = $this->sellerAndStore($category);
        $rule = ComplianceRule::query()
            ->where('code', 'CATEGORY_MISMATCH_ELECTRONICS_APPAREL')
            ->firstOrFail();

        // Simulate a deployed database whose persisted rule still has its original terms.
        $rule->update([
            'default_action' => 'block',
            'configuration' => [
                'mismatched_terms_by_category_slug' => [
                    'electronics-and-gadgets' => ['shirt', 'shirts', 'shorts'],
                ],
            ],
        ]);

        $scanner = app(ComplianceScanner::class);
        $apparelListings = [
            'Fleece Hoodie' => 'A warm pullover with a front pocket.',
            'Cotton Tank Top' => 'Sleeveless everyday clothing.',
            'Everyday Pajamas' => 'A matching sleepwear set.',
        ];

        foreach ($apparelListings as $name => $description) {
            $product = $this->product($store, $category, $name, $description);
            $check = $scanner->scan($product);

            $this->assertSame('review_required', $check->result, "[{$name}] should be held for Admin review.");
            $this->assertSame('flagged', $product->fresh()->product_status);
            $this->assertSame('CATEGORY_MISMATCH_ELECTRONICS_APPAREL', $check->matches->firstOrFail()->rule->code);
            $this->assertSame('hold', $check->matches->firstOrFail()->recommended_action);
            $this->assertSame('flagged', $product->violations()->firstOrFail()->status);
        }

        $ambiguousListings = [
            'Short USB-C Cable' => 'A short charging cable for compatible devices.',
            'Short-Throw Projector' => 'Projector with a short-throw lens and adjustable focus.',
            'Short testing' => 'A short sample description for an electronics listing.',
        ];

        foreach ($ambiguousListings as $name => $description) {
            $product = $this->product($store, $category, $name, $description);
            $check = $scanner->scan($product);

            $this->assertSame('clear', $check->result, "[{$name}] must remain clear for manual judgment.");
            $this->assertSame('active', $product->fresh()->product_status);
            $this->assertSame(0, $product->violations()->count());
            $this->assertTrue(Product::visibleToBuyers()->whereKey($product->id)->exists());
        }
    }

    public function test_rescan_command_is_dry_run_by_default_and_restores_corrected_published_products(): void
    {
        $category = $this->category('electronics-and-gadgets');
        [, $store] = $this->sellerAndStore($category);
        $product = $this->product($store, $category, 'Firearm listing', 'An item that must remain unavailable.');

        $this->artisan('products:compliance-rescan', ['--product' => [(string) $product->id]])
            ->expectsOutput('1 eligible products found. No records changed; pass --apply to persist scans.')
            ->assertExitCode(0);

        $this->assertSame('active', $product->fresh()->product_status);
        $this->assertSame(0, $product->complianceChecks()->count());

        $this->artisan('products:compliance-rescan', [
            '--apply' => true,
            '--product' => [(string) $product->id],
        ])->assertExitCode(0);

        $product->refresh();
        $this->assertSame('blocked', $product->product_status);
        $this->assertSame('blocked', $product->compliance_status);
        $this->assertSame(1, $product->violations()->count());

        $product->update([
            'name' => 'Bluetooth Headphones',
            'description' => 'A safe electronics product.',
        ]);
        app(ComplianceScanner::class)->scan($product->fresh(), 'manual');

        $this->assertSame('active', $product->fresh()->product_status);
        $this->assertSame('clear', $product->fresh()->compliance_status);
        $this->assertSame(0, $product->violations()->whereIn('status', ['flagged', 'under_review', 'correction_requested'])->count());
        $this->assertSame(0, $product->violations()->firstOrFail()->warnings()->where('status', 'active')->count());
        $this->assertTrue(Product::visibleToBuyers()->whereKey($product->id)->exists());
    }

    public function test_gun_keyword_uses_word_boundaries_to_avoid_unrelated_gunmetal_listings(): void
    {
        $category = $this->category('electronics-and-gadgets');
        [, $store] = $this->sellerAndStore($category);
        $product = $this->product($store, $category, 'Gunmetal Phone Case', 'Protective case with a metallic finish.');

        $check = app(ComplianceScanner::class)->scan($product);

        $this->assertSame('clear', $check->result);
        $this->assertDatabaseHas('compliance_rules', [
            'code' => 'PROHIBITED_FIREARM_GUN_ALIAS',
            'is_active' => true,
        ]);
        $this->assertSame(0, $product->violations()->count());
    }

    public function test_unchanged_admin_dismissed_match_is_not_reopened_by_rescan(): void
    {
        $category = $this->category('electronics-and-gadgets');
        [$seller, $store] = $this->sellerAndStore($category);
        $product = $this->product($store, $category, 'Gun listing', 'A product description for moderation coverage.');
        $scanner = app(ComplianceScanner::class);

        $scanner->scan($product);
        $violation = $product->violations()->firstOrFail();
        $warning = $violation->warnings()->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $product->update(['name' => 'Gun sale listing']);
        $latestOpenCheck = $scanner->scan($product->fresh(), 'update', $seller);

        $this->assertSame('blocked', $latestOpenCheck->result);
        $this->assertSame($latestOpenCheck->id, $violation->fresh()->check_id);

        app(ProductViolationDecisionService::class)->decide(
            $violation,
            $admin,
            'dismiss',
            'Manual review confirmed this was a false positive.',
        );

        $this->assertSame('revoked', $warning->fresh()->status);
        $secondCheck = $scanner->scan($product->fresh(), 'update', $seller);

        $this->assertSame('clear', $secondCheck->result);
        $this->assertSame('dismissed', $violation->fresh()->status);
        $this->assertSame(1, $product->violations()->count());
        $this->assertSame('clear', $product->fresh()->compliance_status);
        $this->assertSame('active', $product->fresh()->product_status);
        $this->assertSame(0, SellerWarning::query()->where('violation_id', $violation->id)->where('status', 'active')->count());
    }

    public function test_seller_product_submission_is_restricted_to_the_registration_approved_category(): void
    {
        $fashion = $this->category('fashion-and-apparel');
        [$seller, $store] = $this->sellerAndStore($fashion);

        $wrongCategoryPayload = $this->sellerProductPayload($fashion, 'Wool Overcoat', 'A tailored wool overcoat.');
        $wrongCategoryPayload['category'] = 'Electronics and Gadgets';

        $this->actingAs($seller)
            ->post('/seller/products', $wrongCategoryPayload)
            ->assertSessionHasErrors('category');

        $this->assertSame(0, Product::query()->where('store_id', $store->id)->count());

        $this->actingAs($seller)
            ->post('/seller/products', $this->sellerProductPayload($fashion, 'Wool Overcoat', 'A tailored wool overcoat.'))
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame($fashion->id, (int) $product->category_id);
        $this->assertSame('active', $product->product_status);
        $this->assertSame('clear', $product->compliance_status);
    }

    public function test_fashion_seller_electronics_content_is_held_with_excerpt_warnings_and_single_notifications(): void
    {
        Notification::fake();

        $fashion = $this->category('fashion-and-apparel');
        [$seller, $store] = $this->sellerAndStore($fashion);
        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $this->actingAs($seller)
            ->post('/seller/products', $this->sellerProductPayload(
                $fashion,
                'Flagship Smartphone 5G',
                'Unlocked 5G smartphone with 128 GB storage and a bright display.',
            ))
            ->assertRedirect('/seller/products')
            ->assertSessionHas('compliance_warning');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame('flagged', $product->product_status);
        $this->assertSame('flagged', $product->compliance_status);
        $this->assertFalse(Product::visibleToBuyers()->whereKey($product->id)->exists());

        $violation = $product->violations()->firstOrFail();
        $this->assertSame('CATEGORY_MISMATCH_FASHION_ELECTRONICS', $violation->violation_type);
        $this->assertSame('Electronics product listed under Fashion and Apparel', $violation->reason);
        $this->assertSame('flagged', $violation->status);
        $this->assertSame('smartphone', $violation->detected_excerpt);

        $match = $violation->check->matches()->where('rule_id', $violation->rule_id)->firstOrFail();
        $this->assertSame('smartphone', $match->matched_excerpt);
        $this->assertSame('hold', $match->recommended_action);

        $this->assertDatabaseHas('seller_warnings', [
            'violation_id' => $violation->id,
            'seller_profile_id' => $violation->seller_profile_id,
            'reason' => 'Electronics product listed under Fashion and Apparel',
            'status' => 'active',
        ]);

        Notification::assertSentTo(
            $seller,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification, array $channels): bool => $notification->eventKey() === 'seller_compliance_flagged'
                && in_array('database', $channels, true)
                && $notification->meta()['product_id'] === $product->id,
        );
        Notification::assertSentToTimes($seller, WorkflowNotification::class, 1);
        Notification::assertSentTo(
            $admin,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'admin_compliance_flagged'
                && $notification->meta()['product_id'] === $product->id,
        );

        app(ComplianceScanner::class)->scan($product->fresh());

        $this->assertSame(1, $product->violations()->count(), 'Repeated scans must reuse the open violation.');
        $this->assertSame(1, $product->violations()->firstOrFail()->warnings()->where('status', 'active')->count());
        Notification::assertSentToTimes($seller, WorkflowNotification::class, 1);
        Notification::assertSentToTimes($admin, WorkflowNotification::class, 1);

        $buyer = $this->user('buyer');

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertNotFound();

        $this->actingAs($seller)
            ->put('/seller/products/'.$product->id, $this->sellerProductPayload(
                $fashion,
                'Handwoven Cotton Shirt',
                'Relaxed-fit handwoven cotton shirt with a classic collar.',
            ))
            ->assertRedirect('/seller/products');

        $product->refresh();
        $this->assertSame('active', $product->product_status);
        $this->assertSame('clear', $product->compliance_status);
        $this->assertSame('resolved', $violation->fresh()->status);
        $this->assertSame('no_active_rule_match', $violation->fresh()->resolution);
        $this->assertSame(1, $product->violations()->count(), 'Resolving a hold must preserve violation history.');
        $this->assertSame(0, $violation->warnings()->where('status', 'active')->count());
        $this->assertTrue(Product::visibleToBuyers()->whereKey($product->id)->exists());
        Notification::assertSentToTimes($seller, WorkflowNotification::class, 1);

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Handwoven Cotton Shirt');
    }

    public function test_fashion_mismatch_terms_skip_harmless_wording_but_hold_electronics_phrases(): void
    {
        $fashion = $this->category('fashion-and-apparel');
        [, $store] = $this->sellerAndStore($fashion);
        $scanner = app(ComplianceScanner::class);

        $harmlessListings = [
            'Mickey Mouse Print Hoodie' => 'Cotton hoodie with an all-over cartoon mouse print.',
            'Cable Knit Sweater' => 'Chunky cable knit sweater in soft wool.',
            'USB-C Compatible Heated Vest' => 'Rechargeable heated vest with a USB-C power port.',
            'Pleated Midi Dress' => 'Flowing pleated dress with a fitted waist.',
        ];

        foreach ($harmlessListings as $name => $description) {
            $harmless = $this->product($store, $fashion, $name, $description);
            $check = $scanner->scan($harmless);

            $this->assertSame('clear', $check->result, "[{$name}] must not be flagged.");
            $this->assertSame(0, $harmless->violations()->count(), "[{$name}] must not create a violation.");
            $this->assertTrue(Product::visibleToBuyers()->whereKey($harmless->id)->exists());
        }

        $heldListings = [
            'Wireless Earbuds Pro' => 'Noise-cancelling earbuds with a long battery life.',
            'Action Camera 4K' => 'Waterproof action camera for outdoor trips.',
        ];

        foreach ($heldListings as $name => $description) {
            $held = $this->product($store, $fashion, $name, $description);
            $check = $scanner->scan($held);

            $this->assertSame('review_required', $check->result, "[{$name}] must be held for review.");
            $this->assertSame('flagged', $held->fresh()->product_status);
            $this->assertSame(
                'CATEGORY_MISMATCH_FASHION_ELECTRONICS',
                $held->violations()->firstOrFail()->violation_type,
            );
            $this->assertFalse(Product::visibleToBuyers()->whereKey($held->id)->exists());
        }
    }

    private function category(string $slug): Category
    {
        return Category::query()->where('slug', $slug)->firstOrFail();
    }

    /** @return array{User, Store} */
    private function sellerAndStore(Category $category): array
    {
        $seller = $this->user('seller');
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Compliance Test Seller',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Compliance Test Store '.$profile->id,
            'slug' => 'compliance-test-store-'.$profile->id,
            'publication_status' => 'published',
        ]);

        return [$seller->fresh('sellerProfile'), $store];
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => 'Compliance '.$role,
            'first_name' => 'Compliance',
            'last_name' => Str::title($role),
            'email' => 'compliance-'.$role.'-'.Str::uuid().'@example.test',
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function product(Store $store, Category $category, string $name, string $description): Product
    {
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => $description,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'COMPLIANCE-'.Str::upper(Str::random(12)),
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => true,
        ]);

        return $product;
    }

    private function sellerProductPayload(Category $category, string $name, string $description): array
    {
        return [
            'product_mode' => 'single',
            'name' => $name,
            'category' => $category->name,
            'description' => $description,
            'sku' => 'COMPLIANCE-'.Str::upper(Str::random(10)),
            'price' => '100.00',
            'stock' => 4,
            'low_stock_threshold' => 1,
            'package_weight_kg' => '0.250',
            'package_length_cm' => '24',
            'package_width_cm' => '18',
            'package_height_cm' => '8',
            'payment_methods' => ['cod'],
            'intent' => 'publish',
        ];
    }
}
