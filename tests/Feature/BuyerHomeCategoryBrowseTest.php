<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerHomeCategoryBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_category_browse_links_follow_the_buyer_taxonomy(): void
    {
        $categories = json_decode(
            file_get_contents(resource_path('data/buyer-categories.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $response = $this->actingAs($this->buyer())->get(route('home'));

        $response->assertOk()
            ->assertSee('id="home-category-title"', false)
            ->assertSee('Shop by Category');

        $html = $response->getContent();

        $this->assertStringNotContainsString('class="home-category-all"', $html);
        $this->assertCount(12, $categories);
        $this->assertSame(12, substr_count($html, 'class="home-category-photo"'));
        $this->assertSame(
            12,
            preg_match_all('/<a class="home-category-card" href="([^"]+)">(.*?)<\/a>/s', $html, $matches)
        );

        $this->assertSame(
            array_map(
                fn (array $category): string => route('products.index', ['category' => $category['slug']]),
                $categories
            ),
            $matches[1]
        );

        foreach ($categories as $index => $category) {
            $this->assertArrayHasKey('icon', $category);
            $this->assertNotEmpty($category['icon']);
            $this->assertArrayHasKey('image', $category);
            $this->assertMatchesRegularExpression(
                '#^/images/categories/[A-Za-z0-9 _-]+\.(?:jpg|png)$#',
                $category['image']
            );
            $this->assertFileExists(public_path(ltrim($category['image'], '/')));

            $markup = $matches[2][$index];

            $this->assertStringContainsString(e($category['name']), $markup);
            $this->assertStringContainsString('class="home-category-photo"', $markup);
            $this->assertStringContainsString('src="'.asset($category['image']).'"', $markup);
            $this->assertStringContainsString('alt=""', $markup);
            $this->assertStringContainsString('loading="lazy"', $markup);
            $this->assertStringContainsString('decoding="async"', $markup);
            $this->assertStringContainsString('home-category-arrow', $markup);
            $this->assertStringContainsString('aria-hidden="true"', $markup);
            $this->assertStringNotContainsString('data-category', $markup);
        }

        $this->assertStringNotContainsString('home-category-icon', $html);

        $heroPosition = strpos($html, 'id="bearly-promo-slider"');
        $categoryPosition = strpos($html, 'class="home-category-browse"');

        $this->assertNotFalse($heroPosition);
        $this->assertNotFalse($categoryPosition);
        $this->assertGreaterThan($heroPosition, $categoryPosition);
    }

    public function test_buyer_home_footer_hides_social_and_newsletter_sections_but_keeps_copyright(): void
    {
        $response = $this->actingAs($this->buyer())->get(route('home'));

        $response->assertOk()
            ->assertSee('aria-label="Shop"', false)
            ->assertSee('aria-label="Help"', false)
            ->assertSee('aria-label="About"', false)
            ->assertSee('All rights reserved.')
            ->assertDontSee('Quality products. Trusted sellers. A better everyday.')
            ->assertDontSee('Get the latest deals')
            ->assertDontSee('footer-socials')
            ->assertDontSee('footer-newsletter-form');
    }

    private function buyer(): User
    {
        return User::query()->create([
            'name' => 'Category Browser Buyer',
            'first_name' => 'Category',
            'last_name' => 'Browser',
            'email' => Str::uuid().'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }
}
