<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeProductImageCategoryCheck;
use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageCategoryCheck;
use App\Models\ProductViolation;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\ComplianceScanner;
use App\Services\ProductImageCategoryCheckRetryException;
use App\Services\ProductImageCategoryCheckService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductImageCategoryCheckTest extends TestCase
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
            'name' => 'Image Check Seller',
            'first_name' => 'Image Check',
            'last_name' => 'Seller',
            'email' => 'image-check-'.Str::uuid().'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => bcrypt('Password123'),
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $this->seller->id,
            'legal_business_name' => 'Image Check Business',
            'approved_category_id' => $this->businessCategory->id,
            'approved_at' => now(),
        ]);
        $this->store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Image Check Store',
            'slug' => 'image-check-store-'.$profile->id,
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

        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'queue.default' => 'database',
            'services.gemini.api_key' => 'fake-test-credential-not-a-real-key',
            'services.gemini.product_image_check.enabled' => true,
            'services.gemini.product_image_check.model' => 'gemini-3.5-flash-lite',
            'services.gemini.product_image_check.timeout' => 10,
        ]);
    }

    public function test_verdicts_are_persisted_as_advisory_history_and_duplicate_jobs_do_not_call_gemini_twice(): void
    {
        $verdicts = [
            'match',
            'possible_mismatch',
            'uncertain',
        ];
        $sequence = Http::sequence();
        foreach ($verdicts as $verdict) {
            $sequence->push($this->interactionResponse([
                'verdict' => $verdict,
                'visible_item_description' => 'A compact wireless speaker.',
                'reason' => 'The visible speaker is consistent with the listing context.',
            ]));
        }
        Http::fake(['generativelanguage.googleapis.com/*' => $sequence]);

        foreach ($verdicts as $index => $verdict) {
            $product = $this->product('Speaker listing '.$index);
            $this->addPrimaryImage($product);
            $check = $this->queueCheck($product);

            $job = new AnalyzeProductImageCategoryCheck((int) $check->id);
            $job->handle(app(ProductImageCategoryCheckService::class));
            $job->handle(app(ProductImageCategoryCheckService::class));

            $check->refresh();
            $this->assertSame($verdict, $check->status);
            $this->assertSame('A compact wireless speaker.', $check->visible_item_description);
            $this->assertSame('The visible speaker is consistent with the listing context.', $check->reason);
            $this->assertSame(64, strlen((string) $check->image_sha256));
            $this->assertSame('google_gemini', $check->provider);
            $this->assertSame('gemini-3.5-flash-lite', $check->model);
            $this->assertSame('product-category-image-v1', $check->prompt_version);
            $this->assertSame(1, ProductImageCategoryCheck::query()->where('product_id', $product->id)->count());
            $this->assertSame('active', $product->fresh()->product_status);
            $this->assertSame('clear', $product->fresh()->compliance_status);
            $this->assertSame(0, $product->violations()->count());
            $this->assertStringNotContainsString($this->pngBytes(), json_encode($check->fresh()->toArray()));
        }

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
            && $request->hasHeader('x-goog-api-key')
            && $request->data()['store'] === false
            && isset($request->data()['response_format']['schema']['properties']['verdict']['enum'])
            && str_contains($request->data()['system_instruction'], 'untrusted data')
            && ! str_contains($request->url(), 'key='));
    }

    public function test_dispatch_is_deferred_until_product_save_transaction_commits(): void
    {
        $product = $this->product('Commit boundary product');
        $this->addPrimaryImage($product);

        DB::beginTransaction();
        app(ProductImageCategoryCheckService::class)->queueAfterProductSave($product);

        Queue::assertNothingPushed();
        DB::commit();

        $check = ProductImageCategoryCheck::query()->where('product_id', $product->id)->sole();
        Queue::assertPushedOn(
            'product-image-checks',
            AnalyzeProductImageCategoryCheck::class,
            fn (AnalyzeProductImageCategoryCheck $job): bool => $job->checkId === (int) $check->id,
        );
        $this->assertSame('pending', $check->status);
    }

    public function test_malformed_provider_output_retries_a_bounded_number_of_times_then_becomes_unavailable(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'status' => 'completed',
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [['type' => 'text', 'text' => 'not-json']],
                ]],
            ]),
        ]);

        $product = $this->product('Malformed response product');
        $this->addPrimaryImage($product);
        $check = $this->queueCheck($product);
        $job = new AnalyzeProductImageCategoryCheck((int) $check->id);
        $lastFailure = null;

        for ($attempt = 0; $attempt < $job->tries; $attempt++) {
            try {
                $job->handle(app(ProductImageCategoryCheckService::class));
            } catch (ProductImageCategoryCheckRetryException $exception) {
                $lastFailure = $exception;
            }
        }

        $this->assertInstanceOf(ProductImageCategoryCheckRetryException::class, $lastFailure);
        $job->failed($lastFailure);

        $this->assertSame('unavailable', $check->fresh()->status);
        $this->assertSame('invalid_response', $check->fresh()->failure_code);
        $this->assertSame($job->tries, (int) $check->fresh()->attempts);
        Http::assertSentCount(3);
    }

    public function test_rate_limit_is_retried_and_eventually_becomes_unavailable(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $product = $this->product('Rate limited product');
        $this->addPrimaryImage($product);
        $check = $this->queueCheck($product);
        $job = new AnalyzeProductImageCategoryCheck((int) $check->id);

        try {
            $job->handle(app(ProductImageCategoryCheckService::class));
            $this->fail('A rate-limited check should be retried.');
        } catch (ProductImageCategoryCheckRetryException $exception) {
            $this->assertSame('provider_temporary_failure', $exception->failureCode);
        }

        $this->assertSame('pending', $check->fresh()->status);
        $job->failed(new ProductImageCategoryCheckRetryException('provider_temporary_failure'));
        $this->assertSame('unavailable', $check->fresh()->status);

        Http::assertSentCount(1);
    }

    public function test_missing_api_key_marks_check_unavailable_without_sending_a_request(): void
    {
        config(['services.gemini.api_key' => null]);

        $product = $this->product('Missing credential product');
        $this->addPrimaryImage($product);
        $check = $this->queueCheck($product);

        (new AnalyzeProductImageCategoryCheck((int) $check->id))->handle(app(ProductImageCategoryCheckService::class));

        $this->assertSame('unavailable', $check->fresh()->status);
        $this->assertSame('missing_api_key', $check->fresh()->failure_code);
        $this->assertSame(64, strlen((string) $check->fresh()->image_sha256));
        Http::assertNothingSent();
    }

    public function test_connection_timeout_is_retried_then_safely_recorded_as_unavailable(): void
    {
        Http::fake(['*' => function () {
            throw new ConnectionException('Mocked timeout.');
        }]);

        $product = $this->product('Timed out product');
        $this->addPrimaryImage($product);
        $check = $this->queueCheck($product);
        $job = new AnalyzeProductImageCategoryCheck((int) $check->id);

        try {
            $job->handle(app(ProductImageCategoryCheckService::class));
            $this->fail('A timeout should be retried.');
        } catch (ProductImageCategoryCheckRetryException $exception) {
            $this->assertSame('network_failure', $exception->failureCode);
        }

        $this->assertSame('pending', $check->fresh()->status);
        $this->assertSame(64, strlen((string) $check->fresh()->image_sha256));

        $job->failed(new ProductImageCategoryCheckRetryException('network_failure'));
        $this->assertSame('unavailable', $check->fresh()->status);
        $this->assertSame('network_failure', $check->fresh()->failure_code);
    }

    public function test_nonretryable_api_errors_become_unavailable_without_becoming_match(): void
    {
        Http::fake(['*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);

        $otherProduct = $this->product('Rejected API product');
        $this->addPrimaryImage($otherProduct);
        $otherCheck = $this->queueCheck($otherProduct);
        (new AnalyzeProductImageCategoryCheck((int) $otherCheck->id))->handle(app(ProductImageCategoryCheckService::class));

        $this->assertSame('unavailable', $otherCheck->fresh()->status);
        $this->assertSame('provider_rejected', $otherCheck->fresh()->failure_code);
        $this->assertNotSame('match', $otherCheck->fresh()->status);
    }

    public function test_changed_image_or_listing_during_provider_call_supersedes_the_old_result(): void
    {
        $product = $this->product('Image that will change');
        $image = $this->addPrimaryImage($product);
        $imagePath = $image->path;
        $check = $this->queueCheck($product);

        $listingProduct = $this->product('Title before request');
        $this->addPrimaryImage($listingProduct);
        $listingCheck = $this->queueCheck($listingProduct);

        Http::fake(function ($request) use ($imagePath, $listingProduct) {
            $snapshot = json_decode((string) ($request->data()['input'][0]['text'] ?? ''), true);
            if (($snapshot['title'] ?? null) === 'Image that will change') {
                Storage::disk('public')->put($imagePath, $this->pngBytes().'changed');
            } else {
                $listingProduct->update(['name' => 'Title changed during request']);
            }

            return Http::response($this->interactionResponse([
                'verdict' => 'possible_mismatch',
                'visible_item_description' => 'A handbag.',
                'reason' => 'The image changed during this request.',
            ]));
        });

        (new AnalyzeProductImageCategoryCheck((int) $check->id))->handle(app(ProductImageCategoryCheckService::class));

        $this->assertFalse($check->fresh()->is_current);
        $this->assertSame('unavailable', $check->fresh()->status);
        $this->assertNull($check->fresh()->visible_item_description);
        $this->assertNull($product->fresh('currentImageCategoryCheck')->currentImageCategoryCheck);

        (new AnalyzeProductImageCategoryCheck((int) $listingCheck->id))->handle(app(ProductImageCategoryCheckService::class));

        $this->assertFalse($listingCheck->fresh()->is_current);
        $this->assertNull($listingCheck->fresh()->visible_item_description);
        $this->assertNull($listingProduct->fresh('currentImageCategoryCheck')->currentImageCategoryCheck);
    }

    public function test_admin_queue_displays_advisory_statuses_and_remains_admin_only(): void
    {
        config(['services.gemini.product_image_check.enabled' => true]);

        foreach ([
            'pending' => null,
            'match' => 'A speaker.',
            'possible_mismatch' => 'A handbag.',
            'uncertain' => 'An unclear object.',
            'unavailable' => null,
        ] as $index => $description) {
            $product = $this->product('Admin status '.$index);
            $this->addPrimaryImage($product);
            $check = $this->queueCheck($product);
            $check->update([
                'status' => $index,
                'visible_item_description' => $description,
                'reason' => $index === 'unavailable' ? 'Image check is temporarily unavailable.' : 'A short advisory reason.',
                'failure_code' => $index === 'unavailable' ? 'provider_temporary_failure' : null,
                'completed_at' => $index === 'pending' ? null : now(),
            ]);
        }

        config(['services.gemini.product_image_check.enabled' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews'))
            ->assertOk()
            ->assertSee('data-image-advisory-poll', false)
            ->assertSee('Image check (advisory)')
            ->assertSee('Gemini image checks are disabled. No product photos are sent to Google.')
            ->assertSee('Pending')
            ->assertSee('Match')
            ->assertSee('Possible mismatch')
            ->assertSee('Uncertain')
            ->assertSee('Unavailable')
            ->assertSee('A handbag.')
            ->assertSee('Flag for correction');

        $this->actingAs($this->seller)
            ->get(route('admin.compliance.category-reviews'))
            ->assertForbidden();
    }

    public function test_admin_queue_distinguishes_disabled_and_not_queued_checks_from_pending(): void
    {
        $product = $this->product('Not yet checked product');
        $this->addPrimaryImage($product);

        config(['services.gemini.product_image_check.enabled' => true]);
        $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews'))
            ->assertOk()
            ->assertSee('Not checked')
            ->assertSee('No image check is recorded for this listing yet.');

        config(['services.gemini.product_image_check.enabled' => false]);
        $this->actingAs($this->admin)
            ->get(route('admin.compliance.category-reviews'))
            ->assertOk()
            ->assertSee('Disabled')
            ->assertSee('Image checks are disabled; no product photo is sent to Google.')
            ->assertDontSee('Awaiting an advisory check; this does not affect publishing or visibility.');
    }

    public function test_product_violation_details_show_image_advisory_without_changing_enforcement_state(): void
    {
        $product = $this->product('Rice face mask');
        $this->addPrimaryImage($product);
        $imageCheck = $this->queueCheck($product);
        $imageCheck->update([
            'status' => ProductImageCategoryCheck::STATUS_POSSIBLE_MISMATCH,
            'visible_item_description' => 'An anime character doll.',
            'reason' => 'The visible doll does not appear to match a skincare face mask.',
            'completed_at' => now(),
        ]);

        $violation = ProductViolation::query()->create([
            'violation_no' => 'VIO-'.Str::upper(Str::random(10)),
            'product_id' => $product->id,
            'seller_profile_id' => $this->store->seller_profile_id,
            'source' => 'admin_manual_review',
            'violation_type' => 'category_correction',
            'severity' => 'medium',
            'reason' => 'The category needs human review.',
            'status' => 'flagged',
        ]);
        $product->update([
            'product_status' => 'flagged',
            'compliance_status' => 'flagged',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.compliance.violations'))
            ->assertOk()
            ->assertSee('Image check (advisory)')
            ->assertSee('data-image-advisory-product-id="'.$product->id.'"', false)
            ->assertSee('Possible mismatch')
            ->assertSee('An anime character doll.')
            ->assertSee('The visible doll does not appear to match a skincare face mask.')
            ->assertSee('AI advisory only; Admin review is still required.');

        $this->assertSame('flagged', $violation->fresh()->status);
        $this->assertSame('flagged', $product->fresh()->product_status);
        $this->assertSame(0, $violation->actions()->count());
    }

    public function test_admin_image_advisory_poll_endpoint_returns_current_results_and_is_admin_only(): void
    {
        $product = $this->product('Poll endpoint product');
        $this->addPrimaryImage($product);
        $check = $this->queueCheck($product);
        $check->update([
            'status' => ProductImageCategoryCheck::STATUS_POSSIBLE_MISMATCH,
            'visible_item_description' => 'A ceramic mug.',
            'reason' => 'The visible mug may not match the product title.',
            'completed_at' => now(),
        ]);
        $route = route('admin.compliance.image-advisories', ['product_ids' => [$product->id]]);

        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk()
            ->assertJsonPath('data.advisories.0.product_id', $product->id)
            ->assertJsonPath('data.advisories.0.image_category_code', 'possible_mismatch')
            ->assertJsonPath('data.advisories.0.image_category_status', 'Possible Mismatch');

        $this->actingAs($this->seller)
            ->get($route)
            ->assertForbidden();
    }

    public function test_compliance_overview_is_read_only_and_links_to_the_single_violation_queue(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.compliance'))
            ->assertOk()
            ->assertSee('Compliance Overview')
            ->assertSee('Recent product checks')
            ->assertSee('Open Product Violations')
            ->assertSee(route('admin.compliance.violations'), false)
            ->assertDontSee('Image advisory')
            ->assertDontSee('data-compliance-decision-form', false);
    }

    public function test_disabled_gemini_does_not_change_existing_text_compliance_or_seller_publish_decisions(): void
    {
        config([
            'services.gemini.product_image_check.enabled' => false,
            'services.gemini.api_key' => null,
        ]);
        $payload = [
            'product_mode' => 'single',
            'name' => 'Gun listing',
            'category' => $this->businessCategory->name,
            'description' => 'A product description for moderation coverage.',
            'sku' => 'IMAGE-CHECK-'.Str::upper(Str::random(8)),
            'price' => '100.00',
            'stock' => 4,
            'low_stock_threshold' => 1,
            'package_weight_kg' => '0.250',
            'package_length_cm' => '24',
            'package_width_cm' => '18',
            'package_height_cm' => '8',
            'payment_methods' => ['cod'],
            'intent' => 'publish',
            'image' => UploadedFile::fake()->createWithContent('product.png', $this->pngBytes()),
        ];

        $this->actingAs($this->seller)
            ->post('/seller/products', $payload)
            ->assertRedirect(route('seller.products', ['status' => 'Blocked']))
            ->assertSessionHas('compliance_warning');

        $product = Product::query()->where('store_id', $this->store->id)->where('name', 'Gun listing')->firstOrFail();
        $this->assertSame('blocked', $product->product_status);
        $this->assertSame('blocked', $product->compliance_status);
        $this->assertSame(1, $product->violations()->count());

        $imageCheck = $product->fresh('currentImageCategoryCheck')->currentImageCategoryCheck;
        $this->assertNull($imageCheck);
        Queue::assertNotPushed(AnalyzeProductImageCategoryCheck::class);
        $this->assertSame('blocked', $product->fresh()->product_status);
        $this->assertSame(1, $product->violations()->count());
        Http::assertNothingSent();

        // The existing text scanner remains authoritative when it finds no issue either.
        $clear = $this->product('Bluetooth Headphones');
        $result = app(ComplianceScanner::class)->scan($clear);
        $this->assertSame('clear', $result->result);
        $this->assertSame('active', $clear->fresh()->product_status);
        Http::assertNothingSent();
    }

    private function queueCheck(Product $product): ProductImageCategoryCheck
    {
        DB::transaction(fn () => app(ProductImageCategoryCheckService::class)->queueAfterProductSave($product));

        return ProductImageCategoryCheck::query()
            ->where('product_id', $product->id)
            ->where('is_current', true)
            ->latest('id')
            ->firstOrFail();
    }

    private function product(string $name): Product
    {
        $product = Product::query()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->businessCategory->id,
            'marketplace_subcategory_id' => $this->marketplaceSubcategory->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => 'Fixture description for '.$name,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'IMAGE-CHECK-'.Str::upper(Str::random(10)),
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => true,
        ]);

        return $product;
    }

    private function addPrimaryImage(Product $product): ProductImage
    {
        $path = 'seller-products/category-check-'.$product->id.'-'.Str::random(8).'.png';
        $bytes = $this->pngBytes();
        Storage::disk('public')->put($path, $bytes);

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'disk' => 'public',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'purpose' => 'primary',
            'alt_text' => $product->name,
            'position' => 0,
            'is_primary' => true,
        ]);
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        if (! is_string($bytes)) {
            throw new \RuntimeException('Could not create the in-memory test image.');
        }

        return $bytes;
    }

    /** @param array{verdict: string, visible_item_description: string, reason: string} $output
     * @return array<string, mixed>
     */
    private function interactionResponse(array $output): array
    {
        return [
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($output, JSON_THROW_ON_ERROR),
                ]],
            ]],
        ];
    }
}
