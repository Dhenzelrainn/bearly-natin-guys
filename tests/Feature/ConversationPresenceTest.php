<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        /*
         * Channel closures register on the default broadcaster during boot.
         * PHPUnit boots with the null broadcaster, so reload the same
         * production channel definitions after selecting the reverb driver.
         */
        require base_path('routes/channels.php');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_presence_authorization_returns_only_minimal_identity_data(): void
    {
        $buyer = $this->buyerFixture('Presence Buyer');
        [$seller, $store] = $this->storeFixture('Presence Store', 'presence-store');
        $conversation = $this->conversationFixture(
            $buyer,
            $seller,
            $this->productFixture($store, 'Presence Product'),
        );

        $channelData = $this->authorizePresence($buyer, $conversation);

        $this->assertSame($buyer->id, $channelData['user_id']);
        $this->assertSame([
            'id' => $buyer->id,
            'name' => 'Presence Buyer',
            'avatar_url' => null,
        ], $channelData['user_info']);
        $this->assertSame(
            ['id', 'name', 'avatar_url'],
            array_keys($channelData['user_info']),
        );

        $encoded = json_encode($channelData, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $buyer->email, $encoded);
        $this->assertStringNotContainsString((string) $buyer->contact_number, $encoded);
    }

    public function test_non_participant_cannot_authorize_a_conversation_presence_channel(): void
    {
        $buyer = $this->buyerFixture('Presence Buyer');
        $outsider = $this->buyerFixture('Outside Buyer');
        [$seller, $store] = $this->storeFixture('Presence Store', 'presence-store');
        $conversation = $this->conversationFixture(
            $buyer,
            $seller,
            $this->productFixture($store, 'Presence Product'),
        );

        $this->authorizePresenceRequest($outsider, $conversation)
            ->assertForbidden();
    }

    public function test_seller_presence_uses_the_public_store_identity(): void
    {
        $buyer = $this->buyerFixture('Presence Buyer');
        [$seller, $store] = $this->storeFixture('Presence Store', 'presence-store');
        $conversation = $this->conversationFixture(
            $buyer,
            $seller,
            $this->productFixture($store, 'Presence Product'),
        );

        $channelData = $this->authorizePresence($seller, $conversation);

        $this->assertSame($seller->id, $channelData['user_id']);
        $this->assertSame([
            'id' => $seller->id,
            'name' => 'Presence Store',
            'avatar_url' => null,
        ], $channelData['user_info']);
        $this->assertStringNotContainsString(
            (string) $seller->email,
            json_encode($channelData, JSON_THROW_ON_ERROR),
        );
    }

    public function test_seller_presence_rejects_a_conversation_for_another_store(): void
    {
        $buyer = $this->buyerFixture('Presence Buyer');
        [$seller] = $this->storeFixture('Seller Store', 'seller-store');
        [, $otherStore] = $this->storeFixture('Other Store', 'other-store');
        $conversation = $this->conversationFixture(
            $buyer,
            $seller,
            $this->productFixture($otherStore, 'Other Product'),
        );

        $this->authorizePresenceRequest($seller, $conversation)
            ->assertForbidden();
    }

    public function test_staff_presence_does_not_expose_the_account_name(): void
    {
        $buyer = $this->buyerFixture('Presence Buyer');
        $admin = User::factory()->create([
            'name' => 'Private Staff Name',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $conversation = Conversation::query()->create([
            'subject' => 'Support thread',
            'type' => 'direct',
            'status' => 'open',
            'created_by' => $buyer->id,
            'last_message_at' => now(),
        ]);

        $conversation->participants()->attach($buyer->id, [
            'participant_role' => 'buyer',
            'last_read_at' => null,
            'joined_at' => now(),
        ]);
        $conversation->participants()->attach($admin->id, [
            'participant_role' => 'admin',
            'last_read_at' => null,
            'joined_at' => now(),
        ]);

        $channelData = $this->authorizePresence($admin, $conversation);

        $this->assertSame('Bearly Support', $channelData['user_info']['name']);
        $this->assertStringNotContainsString(
            'Private Staff Name',
            json_encode($channelData, JSON_THROW_ON_ERROR),
        );
    }

    public function test_thread_payload_lists_only_the_other_presence_participants(): void
    {
        $buyer = $this->buyerFixture('Presence Buyer');
        [$seller, $store] = $this->storeFixture('Presence Store', 'presence-store');
        $conversation = $this->conversationFixture(
            $buyer,
            $seller,
            $this->productFixture($store, 'Presence Product'),
        );

        $this->actingAs($buyer)
            ->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath(
                'data.conversation.presence_user_ids',
                [$seller->id],
            );
    }

    private function authorizePresenceRequest(User $user, Conversation $conversation)
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-conversation-presence.'.$conversation->id,
        ]);
    }

    /**
     * @return array{user_id: int, user_info: array<string, mixed>}
     */
    private function authorizePresence(User $user, Conversation $conversation): array
    {
        $response = $this->authorizePresenceRequest($user, $conversation)
            ->assertOk();

        $channelData = json_decode(
            (string) $response->json('channel_data'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($channelData);
        $this->assertArrayHasKey('user_id', $channelData);
        $this->assertArrayHasKey('user_info', $channelData);

        $channelData['user_id'] = (int) $channelData['user_id'];

        return $channelData;
    }

    private function buyerFixture(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'buyer',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function storeFixture(string $name, string $slug): array
    {
        $seller = User::factory()->create([
            'name' => $name.' Owner',
            'role' => 'seller',
            'status' => 'active',
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => $name,
            'approved_category_id' => Category::query()->firstOrFail()->id,
            'approved_at' => now(),
        ]);

        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $name,
            'slug' => $slug,
            'publication_status' => 'published',
        ]);

        return [$seller, $store];
    }

    private function productFixture(Store $store, string $name): Product
    {
        return Product::query()->create([
            'store_id' => $store->id,
            'category_id' => Category::query()->firstOrFail()->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
    }

    private function conversationFixture(User $buyer, User $seller, Product $product): Conversation
    {
        $conversation = Conversation::query()->create([
            'subject' => $product->name,
            'product_id' => $product->id,
            'type' => 'direct',
            'status' => 'open',
            'created_by' => $buyer->id,
            'last_message_at' => now(),
        ]);

        $conversation->participants()->attach($buyer->id, [
            'participant_role' => 'buyer',
            'last_read_at' => null,
            'joined_at' => now(),
        ]);

        $conversation->participants()->attach($seller->id, [
            'participant_role' => 'seller',
            'last_read_at' => null,
            'joined_at' => now(),
        ]);

        return $conversation;
    }
}
