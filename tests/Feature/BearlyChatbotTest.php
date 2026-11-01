<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BearlyChatbotTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrelated_questions_receive_a_refusal_without_calling_gemini_or_creating_a_ticket(): void
    {
        Http::fake();

        $response = $this->postJson(route('chatbot.message'), [
            'message' => 'Can you explain quantum entanglement?',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.answer', config('bearly_chatbot.refusal'))
            ->assertJsonPath('data.products', []);

        Http::assertNothingSent();
        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_product_answers_include_only_buyer_visible_cod_products_and_current_catalog_prices(): void
    {
        $this->configureGemini();
        $category = $this->category();
        $store = $this->store($category, 'Chatbot Catalog Store');
        $visibleProduct = $this->product($category, $store, 'Stainless Water Bottle', 159900);
        $this->product($category, $store, 'Stainless Water Bottle Draft', 99900, 'draft');
        $onlineOnlyProduct = $this->product($category, $store, 'Stainless Bottle Online Only', 89900);
        $onlineOnlyProduct->update(['payment_methods' => ['online']]);
        $visibleProduct->variants()->firstOrFail()->update(['price_minor' => 174900]);

        $this->fakeGeminiReply('Here is a currently listed bottle.');

        $response = $this->postJson(route('chatbot.message'), [
            'message' => 'Find me a stainless water bottle, email mia@example.test and ref BRLY123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.answer', 'Here is a currently listed bottle.')
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.id', $visibleProduct->id)
            ->assertJsonPath('data.products.0.price_minor', 174900)
            ->assertJsonPath('data.products.0.available', true)
            ->assertJsonPath('data.products.0.url', '/products/'.$visibleProduct->id);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $input = json_decode((string) data_get($data, 'input.0.text'), true);
            $products = $input['current_buyer_visible_products'] ?? [];
            $question = (string) ($input['question'] ?? '');

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
                && $request->hasHeader('x-goog-api-key', 'fake-chatbot-test-key')
                && ($data['store'] ?? null) === false
                && str_contains($question, '[redacted contact detail]')
                && str_contains($question, '[redacted reference]')
                && ! str_contains($question, 'mia@example.test')
                && ! str_contains($question, 'BRLY123456')
                && count($products) === 1
                && ($products[0]['name'] ?? null) === 'Stainless Water Bottle'
                && ! array_key_exists('image_url', $products[0])
                && ! array_key_exists('url', $products[0])
                && ($products[0]['price_minor'] ?? null) === 174900;
        });
    }

    public function test_english_and_taglish_product_count_questions_use_the_live_buyer_catalog_count(): void
    {
        config([
            'services.gemini.chatbot.api_key' => null,
            'services.gemini.chatbot.enabled' => false,
        ]);
        Http::fake();

        $category = $this->category();
        $visibleStore = $this->store($category, 'Chatbot Count Store');
        $this->product($category, $visibleStore, 'Visible Count Product One', 19900);
        $this->product($category, $visibleStore, 'Visible Count Product Two', 29900);

        $this->product($category, $visibleStore, 'Draft Count Product', 39900, 'draft');

        $onlineOnlyProduct = $this->product($category, $visibleStore, 'Online Only Count Product', 49900);
        $onlineOnlyProduct->update(['payment_methods' => ['online']]);

        $unclearedProduct = $this->product($category, $visibleStore, 'Uncleared Count Product', 59900);
        $unclearedProduct->update(['compliance_status' => 'pending']);

        $removedProduct = $this->product($category, $visibleStore, 'Removed Count Product', 69900);
        $removedProduct->update(['removed_at' => now()]);

        $hiddenStore = $this->store($category, 'Chatbot Hidden Count Store');
        $this->product($category, $hiddenStore, 'Hidden Store Count Product', 79900);
        $hiddenStore->update(['publication_status' => 'draft']);

        foreach ([
            [
                'question' => 'How many products are currently listed on Bearly?',
                'answer' => 'Bearly currently has 2 product listings visible in the Buyer catalog. This counts listings, not individual stock units; some listed products may be out of stock.',
            ],
            [
                'question' => 'Ilang products or items ang meron ngayon sa Bearly?',
                'answer' => 'May 2 product listings na visible ngayon sa Bearly Buyer catalog. Listings ang bilang na ito, hindi individual stock units; may ilang product na maaaring out of stock.',
            ],
        ] as $example) {
            $this->postJson(route('chatbot.message'), ['message' => $example['question']])
                ->assertOk()
                ->assertJsonPath('data.answer', $example['answer'])
                ->assertJsonPath('data.products', []);
        }

        Http::assertNothingSent();
    }

    public function test_chatbot_route_is_available_to_guests_and_authenticated_buyers(): void
    {
        Http::fake();

        $this->postJson(route('chatbot.message'), ['message' => 'Hi'])
            ->assertOk()
            ->assertJsonPath('data.answer', config('bearly_chatbot.welcome'));

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $this->actingAs($buyer)
            ->postJson(route('chatbot.message'), ['message' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('data.answer', config('bearly_chatbot.welcome'));

        Http::assertNothingSent();
    }

    public function test_widget_is_mounted_on_public_landing_and_buyer_pages(): void
    {
        $this->get(route('shop.home'))
            ->assertOk()
            ->assertSee('data-bearly-assistant', false)
            ->assertSee(route('chatbot.message', [], false), false)
            ->assertSee('images/chatbotbearly-head.png', false)
            ->assertSee('chatbotbearly-head.png?v=', false)
            ->assertSee('data-assistant-avatar=', false)
            ->assertSee('alt="Bearly assistant"', false)
            ->assertSee('bearly-assistant-launcher-icon', false)
            ->assertDontSee('bearly-assistant-icon-disc', false)
            ->assertSee('Bearly Assistant')
            ->assertSee('Shopping help · AI assistant')
            ->assertSee('data-assistant-disclosure', false)
            ->assertSee('Quick Help')
            ->assertSee('Track my order')
            ->assertSee('Shipping info')
            ->assertSee('Vouchers & deals')
            ->assertSee('Return & Refund')
            ->assertSee('data-assistant-minimize', false)
            ->assertSee('Messages and relevant public product details may be sent to Google Gemini');

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        $this->actingAs($buyer)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-bearly-assistant', false);

        $this->actingAs($buyer)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('data-bearly-assistant', false);
    }

    public function test_provider_failures_return_a_generic_error_without_exposing_provider_details(): void
    {
        $this->configureGemini();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'private provider detail']], 500),
        ]);

        $this->postJson(route('chatbot.message'), ['message' => 'How does Bearly work?'])
            ->assertStatus(503)
            ->assertJsonPath('data.answer', 'The Bearly assistant is temporarily unavailable. Please try again or contact Bearly Support.')
            ->assertDontSee('private provider detail');
    }

    public function test_order_tracking_questions_do_not_send_personal_order_references_to_gemini(): void
    {
        $this->configureGemini();
        Http::fake();
        $orderTrackingFact = collect(config('bearly_chatbot.knowledge'))
            ->firstWhere('topic', 'order_tracking');

        $this->postJson(route('chatbot.message'), [
            'message' => 'Where is my order BRLY123456?',
        ])
            ->assertOk()
            ->assertJsonPath('data.products', [])
            ->assertJsonPath('data.answer', $orderTrackingFact['facts']);

        Http::assertNothingSent();
    }

    public function test_message_validation_and_rate_limiting_are_enforced(): void
    {
        $this->postJson(route('chatbot.message'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $this->postJson(route('chatbot.message'), ['message' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
                ->postJson(route('chatbot.message'), ['message' => 'What is quantum entanglement?']);
            $response->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
            ->postJson(route('chatbot.message'), ['message' => 'What is quantum entanglement?'])
            ->assertStatus(429);
    }

    private function configureGemini(): void
    {
        config([
            'services.gemini.chatbot.api_key' => 'fake-chatbot-test-key',
            'services.gemini.chatbot.enabled' => true,
            'services.gemini.chatbot.model' => 'gemini-test-model',
            'services.gemini.chatbot.timeout' => 10,
        ]);
    }

    private function fakeGeminiReply(string $answer): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'status' => 'completed',
                'output_text' => json_encode([
                    'scope' => 'bearly',
                    'answer' => $answer,
                ], JSON_THROW_ON_ERROR),
            ]),
        ]);
    }

    private function category(): Category
    {
        return Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
    }

    private function store(Category $category, string $name): Store
    {
        $seller = User::query()->create([
            'name' => $name.' Seller',
            'first_name' => $name,
            'last_name' => 'Seller',
            'email' => Str::uuid().'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => $name.' Business',
            'approved_category_id' => $category->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);

        return Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'publication_status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function product(
        Category $category,
        Store $store,
        string $name,
        int $priceMinor,
        string $status = 'active',
    ): Product {
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => 'A reliable everyday drink bottle.',
            'product_status' => $status,
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => Str::upper(Str::random(14)),
            'name' => 'Standard',
            'price_minor' => $priceMinor,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        return $product;
    }
}
