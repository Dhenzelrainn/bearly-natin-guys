<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductComplianceCheck;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductViolation;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminComplianceProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function product(): Product
    {
        $category = Category::query()->create(['name' => 'Image Review Category', 'slug' => 'image-review-'.Str::uuid()]);
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Image Review Seller',
            'approved_category_id' => $category->id,
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Image Review Store',
            'slug' => 'image-review-store-'.Str::uuid(),
            'publication_status' => 'published',
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Uploaded Review Product',
            'slug' => 'uploaded-review-'.Str::uuid(),
            'description' => 'A listing with uploaded product photos.',
            'product_status' => 'flagged',
            'compliance_status' => 'flagged',
        ]);
        $check = ProductComplianceCheck::query()->create([
            'product_id' => $product->id,
            'trigger' => 'create',
            'status' => 'passed',
            'result' => 'clear',
            'name_snapshot' => $product->name,
            'description_snapshot' => $product->description,
            'category_id_snapshot' => $category->id,
        ]);
        ProductViolation::query()->create([
            'violation_no' => 'VIO-'.Str::upper(Str::random(10)),
            'product_id' => $product->id,
            'seller_profile_id' => $profile->id,
            'check_id' => $check->id,
            'source' => 'admin_manual_review',
            'violation_type' => 'category_correction',
            'severity' => 'medium',
            'reason' => 'Manual review fixture.',
            'status' => 'flagged',
        ]);

        return $product;
    }

    private function image(Product $product, array $attributes = []): ProductImage
    {
        $path = 'products/'.$product->id.'/'.Str::uuid().'.png';
        Storage::disk('public')->put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='
        ));

        return $product->images()->create(array_merge([
            'disk' => 'public',
            'path' => $path,
            'alt_text' => 'Actual uploaded product photo',
            'position' => 0,
            'is_primary' => false,
        ], $attributes));
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_uploaded_primary_photo_appears_in_both_tables_and_the_review_modal_without_changing_moderation(): void
    {
        $product = $this->product();
        $this->image($product, ['position' => 0]);
        $primary = $this->image($product, ['is_primary' => true, 'position' => 5, 'alt_text' => 'Front & side view']);
        $url = Storage::disk('public')->url($primary->path);
        $before = $product->fresh()->getAttributes();

        $violations = $this->actingAs($this->admin)->get(route('admin.compliance.violations'))->assertOk();
        $xpath = $this->xpath($violations->getContent());
        $tableImage = $xpath->query('//table[@id="violations-table"]//img')->item(0);
        $modalImage = $xpath->query('//div[@data-violation-modal]//div[@class="review-profile"]//img')->item(0);

        $this->assertNotNull($tableImage);
        $this->assertNotNull($modalImage);
        foreach ([$tableImage, $modalImage] as $image) {
            $this->assertSame($url, $image->getAttribute('src'));
            $this->assertSame('Front & side view', $image->getAttribute('alt'));
            $this->assertSame('lazy', $image->getAttribute('loading'));
        }

        $overview = $this->get(route('admin.compliance'))->assertOk();
        $overviewImage = $this->xpath($overview->getContent())->query('//table[@id="compliance-table"]//img')->item(0);
        $this->assertNotNull($overviewImage);
        $this->assertSame($url, $overviewImage->getAttribute('src'));
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('violation_actions', 0);
        $this->assertDatabaseCount('seller_warnings', 0);
        $this->assertDatabaseCount('product_image_category_checks', 0);
    }

    public function test_first_gallery_photo_is_used_when_there_is_no_primary_and_alt_defaults_to_product_name(): void
    {
        $product = $this->product();
        $this->image($product, ['position' => 4]);
        $gallery = $this->image($product, ['position' => 1, 'alt_text' => null]);
        $url = Storage::disk('public')->url($gallery->path);

        $overview = $this->actingAs($this->admin)->get(route('admin.compliance'))->assertOk();
        $this->assertSame($url, $overview->viewData('audits')[0]['image_url']);
        $this->assertSame($product->name, $overview->viewData('audits')[0]['image_alt']);

        $violations = $this->get(route('admin.compliance.violations'))->assertOk();
        $this->assertSame($url, $violations->viewData('violations')->items()[0]['image_url']);
        $this->assertSame($product->name, $violations->viewData('violations')->items()[0]['image_alt']);
    }

    public function test_main_product_photo_takes_precedence_over_an_earlier_variant_photo(): void
    {
        $product = $this->product();
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'IMAGE-'.Str::uuid(),
            'name' => 'Blue',
            'price_minor' => 10000,
        ]);
        $this->image($product, ['variant_id' => $variant->id, 'position' => 0, 'is_primary' => true]);
        $main = $this->image($product, ['position' => 5]);
        $url = Storage::disk('public')->url($main->path);

        $overview = $this->actingAs($this->admin)->get(route('admin.compliance'))->assertOk();
        $this->assertSame($url, $overview->viewData('audits')[0]['image_url']);
        $violations = $this->get(route('admin.compliance.violations'))->assertOk();
        $this->assertSame($url, $violations->viewData('violations')->items()[0]['image_url']);
    }

    public static function unavailableImages(): array
    {
        return ['no image' => [null], 'empty path' => [['path' => '']], 'unknown disk' => [['disk' => 'missing-disk']]];
    }

    #[DataProvider('unavailableImages')]
    public function test_missing_image_metadata_has_an_accessible_fallback_in_every_placement(?array $attributes): void
    {
        $product = $this->product();
        if ($attributes !== null) {
            $this->image($product, $attributes);
        }

        $overview = $this->actingAs($this->admin)->get(route('admin.compliance'))->assertOk();
        $xpath = $this->xpath($overview->getContent());
        $this->assertSame(0, $xpath->query('//table[@id="compliance-table"]//img')->length);
        $this->assertSame(1, $xpath->query('//table[@id="compliance-table"]//*[@aria-label="Product image unavailable" and not(@hidden)]')->length);

        $violations = $this->get(route('admin.compliance.violations'))->assertOk();
        $xpath = $this->xpath($violations->getContent());
        $this->assertSame(0, $xpath->query('//table[@id="violations-table"]//img')->length);
        $this->assertSame(1, $xpath->query('//table[@id="violations-table"]//*[@aria-label="Product image unavailable" and not(@hidden)]')->length);
        $this->assertSame(1, $xpath->query('//div[@data-violation-modal]//div[@class="review-profile"]//*[@aria-label="Product image unavailable" and not(@hidden)]')->length);
    }

    public function test_soft_deleted_product_and_store_history_still_show_the_saved_photo(): void
    {
        $product = $this->product();
        $image = $this->image($product);
        $product->delete();
        $product->store->delete();
        $url = Storage::disk('public')->url($image->path);

        $overview = $this->actingAs($this->admin)->get(route('admin.compliance'))->assertOk();
        $this->assertSame($url, $overview->viewData('audits')[0]['image_url']);
        $violations = $this->get(route('admin.compliance.violations'))->assertOk();
        $this->assertSame($url, $violations->viewData('violations')->items()[0]['image_url']);
    }

    public function test_product_photos_are_eager_loaded_once_per_page_not_once_per_record(): void
    {
        for ($index = 0; $index < 4; $index++) {
            $this->image($this->product());
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (preg_match('/^select \* from ["`]?product_images["`]?\s/i', $query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($this->admin)->get(route('admin.compliance'))->assertOk();
        $this->assertCount(1, $queries);
        $queries = [];
        $this->get(route('admin.compliance.violations'))->assertOk();
        $this->assertCount(1, $queries);
    }

    public function test_image_backed_compliance_pages_remain_admin_only(): void
    {
        $this->get(route('admin.compliance'))->assertRedirect(route('login'));
        $this->get(route('admin.compliance.violations'))->assertRedirect(route('login'));
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $this->actingAs($buyer)->get(route('admin.compliance'))->assertForbidden();
        $this->get(route('admin.compliance.violations'))->assertForbidden();
    }
}
