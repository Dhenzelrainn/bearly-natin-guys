<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerProductVariantPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_detail_renders_color_images_and_arbitrary_option_groups(): void
    {
        Storage::fake('public');

        $category = Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
        $seller = User::create([
            'name' => 'Variant Seller',
            'first_name' => 'Variant',
            'last_name' => 'Seller',
            'email' => 'variant-seller@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Variant Seller Business',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Gadget Store',
            'slug' => 'gadget-store',
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Wireless Headphones',
            'slug' => 'wireless-headphones',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);

        $blackVariant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'HEADPHONES-BLK-128',
            'name' => 'Black / 128 GB',
            'options' => ['Color' => 'Black', 'Size' => 'S', 'Capacity' => '128 GB'],
            'price_minor' => 250000,
            'stock_on_hand' => 4,
            'is_active' => true,
        ]);
        $whiteVariant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'HEADPHONES-WHT-256',
            'name' => 'White / 256 GB',
            'options' => ['Color' => 'White', 'Size' => 'XL', 'Capacity' => '256 GB'],
            'price_minor' => 275000,
            'stock_on_hand' => 3,
            'is_active' => true,
        ]);

        foreach ([[$blackVariant, 'black'], [$whiteVariant, 'white']] as [$variant, $color]) {
            $path = "products/headphones-{$color}.jpg";
            Storage::disk('public')->put($path, 'image-placeholder');
            ProductImage::create([
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'path' => $path,
                'disk' => 'public',
                'purpose' => 'variant_color',
                'alt_text' => ucfirst($color).' headphones',
            ]);
        }

        $buyer = User::create([
            'name' => 'Variant Buyer',
            'first_name' => 'Variant',
            'last_name' => 'Buyer',
            'email' => 'variant-buyer@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->actingAs($buyer)->get(route('products.show', $product));

        $response->assertOk()
            ->assertSee('Product Specifications')
            ->assertSee('Product Description')
            ->assertSee('data-option-group="color"', false)
            ->assertSee('data-option-group="size"', false)
            ->assertSee('data-option-group="capacity"', false)
            ->assertSee('aria-label="Select Color: Black"', false)
            ->assertSee('aria-label="Select Size: S"', false)
            ->assertSee('aria-label="Select Capacity: 128 GB"', false)
            ->assertSee('data-option-image', false)
            ->assertSee(Storage::disk('public')->url('products/headphones-black.jpg'), false)
            ->assertDontSee('Choose variation');
    }
}
