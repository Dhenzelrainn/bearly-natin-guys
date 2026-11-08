<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SellerCategoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SellerCategoryDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_PASSWORDS = [
        'jewelry-and-watches' => 'BearlyDemo-Jewelry26!',
        'food-and-gourmet' => 'BearlyDemo-Food26!',
        'sports-and-outdoors' => 'BearlyDemo-Sports26!',
        'pet-supplies' => 'BearlyDemo-Pet26!',
        'women-s-apparel' => 'BearlyDemo-Women26!',
        'kids-and-baby' => 'BearlyDemo-Kids26!',
        'home-and-garden' => 'BearlyDemo-Home26!',
        'furniture-and-office-equipment' => 'BearlyDemo-Furniture26!',
    ];

    public function test_it_creates_one_active_seller_and_draft_store_per_missing_target_category_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->seed(SellerCategoryDemoSeeder::class);
        $this->seed(SellerCategoryDemoSeeder::class);

        $categories = Category::query()
            ->whereIn('slug', array_keys(self::DEMO_PASSWORDS))
            ->get()
            ->keyBy('slug');

        $profiles = SellerProfile::query()
            ->whereIn('approved_category_id', $categories->pluck('id'))
            ->with(['approvedCategory', 'store', 'user.roles'])
            ->get();

        $this->assertCount(count(self::DEMO_PASSWORDS), $profiles);
        $this->assertSame(count(self::DEMO_PASSWORDS), Store::query()
            ->whereIn('seller_profile_id', $profiles->pluck('id'))
            ->count());

        foreach (self::DEMO_PASSWORDS as $slug => $password) {
            $category = $categories->get($slug);
            $profile = $profiles->firstWhere('approved_category_id', $category->id);

            $this->assertNotNull($profile);
            $this->assertSame($category->name, $profile->approvedCategory->name);
            $this->assertNotNull($profile->approved_at);
            $this->assertSame('draft', $profile->store->publication_status);
            $this->assertSame('active', $profile->user->status);
            $this->assertNotNull($profile->user->email_verified_at);
            $this->assertTrue($profile->user->hasRole('seller'));
            $this->assertTrue(Hash::check($password, $profile->user->password));
            $this->assertSame('seller.demo.'.$slug.'@bearly.test', $profile->user->email);
        }
    }

    public function test_it_skips_categories_that_already_have_a_seller(): void
    {
        $this->seed(DatabaseSeeder::class);

        $category = Category::query()->where('slug', 'jewelry-and-watches')->firstOrFail();
        $existingSeller = User::factory()->create([
            'email' => 'existing-jewelry-seller@example.test',
            'role' => 'seller',
            'status' => 'active',
        ]);
        SellerProfile::query()->create([
            'user_id' => $existingSeller->id,
            'legal_business_name' => 'Existing Jewelry Business',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
        ]);

        $this->seed(SellerCategoryDemoSeeder::class);

        $this->assertDatabaseMissing('users', [
            'email' => 'seller.demo.jewelry-and-watches@bearly.test',
        ]);
        $this->assertSame(1, SellerProfile::query()
            ->whereApprovedCategoryId($category->id)
            ->count());
    }

    public function test_it_refuses_to_run_outside_local_or_testing(): void
    {
        $this->app['env'] = 'production';

        try {
            app(SellerCategoryDemoSeeder::class)->run();
            $this->fail('The demo seeder should reject non-local environments.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('may only run in local or testing environments', $exception->getMessage());
        }
    }
}
