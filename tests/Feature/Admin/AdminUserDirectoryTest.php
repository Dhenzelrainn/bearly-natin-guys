<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_seller_directory_populates_category_options_from_seller_data(): void
    {
        $approvedCategory = Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();

        $approvedSeller = User::factory()->create([
            'name' => 'Approved Category Seller',
            'role' => 'seller',
            'status' => 'active',
            'business_category' => 'Legacy Category',
        ]);

        SellerProfile::query()->create([
            'user_id' => $approvedSeller->id,
            'legal_business_name' => 'Approved Category Seller Business',
            'approved_category_id' => $approvedCategory->id,
        ]);

        User::factory()->create([
            'name' => 'Legacy Category Seller',
            'role' => 'seller',
            'status' => 'active',
            'business_category' => 'Books and Media',
        ]);

        $unusedCategory = Category::query()->where('slug', 'fashion-and-apparel')->firstOrFail();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.users.sellers'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="'.$approvedCategory->name.'"', $html);
        $this->assertStringContainsString('value="Books and Media"', $html);
        $this->assertStringContainsString('data-category="'.$approvedCategory->name.'"', $html);
        $this->assertStringNotContainsString('value="Legacy Category"', $html);
        $this->assertStringNotContainsString('value="'.$unusedCategory->name.'"', $html);
    }

    public function test_all_users_directory_keeps_older_accounts_available_to_client_side_role_filters(): void
    {
        User::factory()->create([
            'name' => 'Dhenzel Gadgets',
            'role' => 'seller',
            'status' => 'active',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        foreach (range(1, 10) as $index) {
            User::factory()->create([
                'name' => 'New Buyer '.$index,
                'role' => 'buyer',
                'status' => 'active',
                'created_at' => now()->addMinutes($index),
                'updated_at' => now()->addMinutes($index),
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Dhenzel Gadgets');
    }
}
