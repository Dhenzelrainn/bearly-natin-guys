<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_seller_messages_page_only_lists_conversations_linked_to_the_sellers_store(): void
    {
        [$sellerA, $storeA] = $this->storeFixture('Alpha Store', 'alpha-store');
        [$sellerB, $storeB] = $this->storeFixture('Beta Store', 'beta-store');

        [, $productA, $conversationA] = $this->buyerThread($sellerA, $storeA, 'Ana Cruz', 'Alpha Basket', 'Do you have this in brown?');
        [, , $conversationB] = $this->buyerThread($sellerB, $storeB, 'Ben Reyes', 'Beta Lamp', 'Is the lamp dimmable?');
        [, , $crossStore] = $this->buyerThread($sellerA, $storeB, 'Cara Mendoza', 'Beta Lamp', 'Cross-store question');

        $response = $this->actingAs($sellerA)->get(route('seller.support.messages'));
        $response->assertOk()
            ->assertSee('data-realtime-user-id="'.$sellerA->id.'"', false)
            ->assertSee('Ana Cruz')
            ->assertSee('Do you have this in brown?')
            ->assertSee('Alpha Basket')
            ->assertSee('data-inbox-unread-count>1</b>', false)
            ->assertDontSee('Ben Reyes')
            ->assertDontSee('Cara Mendoza')
            ->assertDontSee('Is the lamp dimmable?')
            ->assertDontSee('Maria Santos')
            ->assertDontSee('Seller availability');

        $this->actingAs($sellerA)->getJson(route('seller.support.messages.thread', ['conversation' => $conversationA->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.from', 'them')
            ->assertJsonPath('data.messages.0.text', 'Do you have this in brown?')
            ->assertJsonPath('data.conversation.title', 'Ana Cruz');

        // Seller-side product links must stay on seller surfaces: the
        // Buyer storefront route answers 403 for a signed-in Seller.
        $this->actingAs($sellerA)->getJson(route('seller.support.messages.thread', ['conversation' => $conversationA->id]))
            ->assertOk()
            ->assertJsonPath('data.conversation.product.url', route('seller.products.edit', ['product' => $productA->id]))
            ->assertJsonPath('data.conversation.product_url', route('seller.products.edit', ['product' => $productA->id]));

        $this->actingAs($sellerA)->get(route('seller.support.messages'))
            ->assertOk()
            ->assertSee('Manage product')
            ->assertDontSee('View product in storefront');

        // The quick action destination is really reachable for this Seller,
        // while the Buyer-only storefront route forbids them.
        $this->actingAs($sellerA)->get(route('seller.products.edit', ['product' => $productA->id]))
            ->assertOk();
        $this->actingAs($sellerA)->get(route('products.show', ['product' => $productA->id]))
            ->assertForbidden();

        // The same seller cannot open conversations owned by another store,
        // even when they participate in them.
        $this->actingAs($sellerA)->getJson(route('seller.support.messages.thread', ['conversation' => $conversationB->id]))
            ->assertNotFound();

        $this->actingAs($sellerA)->getJson(route('seller.support.messages.thread', ['conversation' => $crossStore->id]))
            ->assertNotFound();

        $buyer = $this->buyerFixture('Role Buyer');
        $this->actingAs($buyer)->get(route('seller.support.messages'))->assertForbidden();
        $this->actingAs($buyer)->getJson(route('seller.support.messages.thread', ['conversation' => $conversationA->id]))
            ->assertForbidden();
    }

    public function test_seller_replies_persist_and_mark_the_buyer_conversation_unread(): void
    {
        [$seller, $store] = $this->storeFixture('Alpha Store', 'alpha-store');
        [$buyer, , $conversation] = $this->buyerThread($seller, $store, 'Reply Buyer', 'Alpha Basket', 'Can I change the color?');

        $this->actingAs($seller)->postJson(route('seller.support.messages.send', ['conversation' => $conversation->id]), [
            'message' => '  Yes, beige is available.  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.from', 'me')
            ->assertJsonPath('data.message.text', 'Yes, beige is available.');

        $reply = Message::query()->where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->assertSame($seller->id, (int) $reply->sender_id);
        $this->assertSame('Yes, beige is available.', $reply->body);

        $conversation->refresh();
        $this->assertNotNull($conversation->last_message_at);
        $this->assertTrue($conversation->last_message_at->greaterThanOrEqualTo($reply->sent_at));

        // The seller's reply is the first message the buyer has not read: the
        // buyer's own opening message never counts toward their own badge.
        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unread', 1);

        // Reading the thread clears the buyer's unread badge.
        $this->actingAs($buyer)->postJson(route('buyer.chat.read', ['conversation' => $conversation->id]))
            ->assertOk();

        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unread', 0);
    }

    public function test_seller_message_endpoints_enforce_state_validation_and_access_rules(): void
    {
        [$seller, $store] = $this->storeFixture('Alpha Store', 'alpha-store');
        [, , $conversation] = $this->buyerThread($seller, $store, 'State Buyer', 'Alpha Basket', 'Are you open today?');

        $conversation->forceFill(['status' => 'closed'])->save();

        $this->actingAs($seller)->postJson(route('seller.support.messages.send', ['conversation' => $conversation->id]), [
            'message' => 'Late reply',
        ])->assertStatus(409);

        $conversation->forceFill(['status' => 'open'])->save();

        $this->actingAs($seller)->postJson(route('seller.support.messages.send', ['conversation' => $conversation->id]), [])
            ->assertStatus(422);

        $this->actingAs($seller)->getJson(route('seller.support.messages.thread', ['conversation' => 999_999]))
            ->assertNotFound();

        [$otherSeller] = $this->storeFixture('Beta Store', 'beta-store');

        $this->actingAs($otherSeller)->postJson(route('seller.support.messages.send', ['conversation' => $conversation->id]), [
            'message' => 'Not my conversation',
        ])->assertNotFound();

        $this->actingAs($otherSeller)->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertNotFound();

        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_store_level_buyer_conversations_reach_only_the_store_seller(): void
    {
        [$sellerA, $storeA] = $this->storeFixture('Alpha Store', 'alpha-store');
        [$sellerB] = $this->storeFixture('Beta Store', 'beta-store');
        $buyer = $this->buyerFixture('Store Chat Buyer');

        $created = $this->actingAs($buyer)->postJson(route('buyer.chat.store'), ['store' => $storeA->slug]);
        $created->assertCreated();
        $conversationId = (int) $created->json('data.conversation_id');

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversationId]), [
            'message' => 'Hi, do you have this in beige?',
        ])->assertOk();

        $this->actingAs($sellerA)->getJson(route('seller.support.messages.inbox'))
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.id', $conversationId)
            ->assertJsonPath('data.conversations.0.buyer', 'Store Chat Buyer')
            ->assertJsonPath('data.conversations.0.member', $buyer->created_at->copy()->setTimezone('Asia/Manila')->format('F j, Y'));

        $this->actingAs($sellerA)->getJson(route('seller.support.messages.thread', ['conversation' => $conversationId]))
            ->assertOk()
            ->assertJsonPath('data.conversation.title', 'Store Chat Buyer')
            ->assertJsonPath('data.conversation.counterpart.since_caption', 'Member since')
            ->assertJsonPath('data.conversation.product', null)
            ->assertJsonPath('data.conversation.order', null)
            ->assertJsonPath('data.messages.0.from', 'them')
            ->assertJsonPath('data.messages.0.text', 'Hi, do you have this in beige?');

        $storeChatPage = $this->actingAs($sellerA)->get(route('seller.support.messages'));
        $storeChatPage->assertOk()
            ->assertSee('Store Chat Buyer')
            ->assertSee('Hi, do you have this in beige?')
            ->assertSee('Direct message');

        // Buyer threads keep the visible context strip.
        $this->assertDoesNotMatchRegularExpression(
            '/<a[^>]*hidden[^>]*class="message-context-strip/',
            $storeChatPage->getContent(),
        );

        // Another store's seller cannot list, open, or reply to the thread.
        $this->actingAs($sellerB)->getJson(route('seller.support.messages.inbox'))
            ->assertOk()
            ->assertJsonCount(0, 'data.conversations');

        $this->actingAs($sellerB)->getJson(route('seller.support.messages.thread', ['conversation' => $conversationId]))
            ->assertNotFound();

        $this->actingAs($sellerB)->postJson(route('seller.support.messages.send', ['conversation' => $conversationId]), [
            'message' => 'Sneaking in',
        ])->assertNotFound();
    }

    public function test_admin_support_conversations_are_visible_and_replyable_for_the_seller(): void
    {
        [$sellerA] = $this->storeFixture('Alpha Store', 'alpha-store');
        [$sellerB] = $this->storeFixture('Beta Store', 'beta-store');
        $admin = $this->adminFixture();

        $this->actingAs($sellerA)->get(route('seller.support.messages'))
            ->assertOk()
            ->assertSee('No conversations yet');

        // The real Admin messaging endpoint creates the persisted support
        // thread; the same record the Admin workspace writes.
        $this->actingAs($admin)->postJson(route('admin.messages.conversations.store'), [
            'recipient_id' => $sellerA->id,
            'recipient_role' => 'seller',
            'subject' => 'Store verification',
            'message' => 'Please re-upload your business permit.',
        ])->assertCreated();

        $conversation = Conversation::query()->latest('id')->firstOrFail();

        $this->actingAs($sellerA)->getJson(route('seller.support.messages.inbox'))
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.id', $conversation->id)
            ->assertJsonPath('data.conversations.0.buyer', 'Bearly Support')
            ->assertJsonPath('data.conversations.0.initials', 'BS')
            ->assertJsonPath('data.conversations.0.counterpart_role', 'admin')
            ->assertJsonPath('data.conversations.0.unread', 1);

        $this->actingAs($sellerA)->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.conversation.title', 'Bearly Support')
            ->assertJsonPath('data.conversation.counterpart.since_caption', 'Member since')
            ->assertJsonPath('data.conversation.product', null)
            ->assertJsonPath('data.messages.0.from', 'them')
            ->assertJsonPath('data.messages.0.text', 'Please re-upload your business permit.');

        $this->actingAs($sellerA)->postJson(route('seller.support.messages.send', ['conversation' => $conversation->id]), [
            'message' => 'Uploading the renewed permit today.',
        ])->assertOk();

        $this->assertSame(
            'Uploading the renewed permit today.',
            Message::query()->where('conversation_id', $conversation->id)->latest('id')->firstOrFail()->body,
        );

        $supportPage = $this->actingAs($sellerA)->get(route('seller.support.messages'));
        $supportPage->assertOk()
            ->assertSee('Bearly Support')
            ->assertSee('Please re-upload your business permit.');

        // The buyer-only context strip stays hidden for support threads.
        $this->assertMatchesRegularExpression(
            '/<a[^>]*hidden[^>]*class="message-context-strip/',
            $supportPage->getContent(),
        );

        // Unrelated sellers and buyers stay locked out of the support thread.
        $this->actingAs($sellerB)->getJson(route('seller.support.messages.inbox'))
            ->assertOk()
            ->assertJsonCount(0, 'data.conversations');

        $this->actingAs($sellerB)->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertNotFound();

        $this->actingAs($sellerB)->postJson(route('seller.support.messages.send', ['conversation' => $conversation->id]), [
            'message' => 'Not for me',
        ])->assertNotFound();

        $buyer = $this->buyerFixture('Role Buyer');
        $this->actingAs($buyer)->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertForbidden();
    }

    public function test_seller_can_open_a_dispute_update_conversation_from_the_seller_inbox(): void
    {
        [$seller, $store] = $this->storeFixture('Alpha Store', 'alpha-store');
        [$otherSeller] = $this->storeFixture('Beta Store', 'beta-store');
        $buyer = $this->buyerFixture('Dispute Buyer');
        $admin = $this->adminFixture();

        $order = Order::query()->create([
            'order_no' => 'ORD-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '12 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'currency' => 'PHP',
            'subtotal_minor' => 10000,
            'shipping_fee_minor' => 1000,
            'discount_minor' => 0,
            'total_minor' => 11000,
            'status' => 'processing',
            'payment_status' => 'paid',
            'placed_at' => now(),
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'processing',
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'shipping_fee_minor' => 1000,
            'total_minor' => 11000,
        ]);

        $dispute = Dispute::query()->create([
            'dispute_no' => 'DSP-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'opened_by' => $buyer->id,
            'subject' => 'Dispute message test',
            'description' => 'A dispute update should reach the Seller inbox.',
            'priority' => 'normal',
            'status' => 'open',
            'amount_minor' => 11000,
            'opened_at' => now(),
        ]);

        $dispute->participants()->attach([
            $buyer->id => [
                'participant_role' => 'buyer',
                'joined_at' => now(),
            ],
            $seller->id => [
                'participant_role' => 'seller',
                'joined_at' => now(),
            ],
        ]);

        $conversation = Conversation::query()->create([
            'subject' => 'Dispute '.$dispute->dispute_no,
            'dispute_id' => $dispute->id,
            'type' => 'dispute',
            'status' => 'open',
            'created_by' => $admin->id,
            'last_message_at' => now(),
        ]);

        $conversation->participants()->attach([
            $admin->id => [
                'participant_role' => 'admin',
                'last_read_at' => now(),
                'joined_at' => now(),
            ],
            $buyer->id => [
                'participant_role' => 'buyer',
                'last_read_at' => null,
                'joined_at' => now(),
            ],
            $seller->id => [
                'participant_role' => 'seller',
                'last_read_at' => null,
                'joined_at' => now(),
            ],
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
            'body' => 'Please review the dispute update.',
            'message_type' => 'text',
            'sent_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.support.messages', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Dispute '.$dispute->dispute_no)
            ->assertSee('Dispute case')
            ->assertSee('Dispute message test')
            ->assertSee($sellerOrder->seller_order_no)
            ->assertSee('Please review the dispute update.');

        $this->actingAs($seller)
            ->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.conversation.context', 'Dispute '.$dispute->dispute_no)
            ->assertJsonPath('data.conversation.dispute.number', $dispute->dispute_no)
            ->assertJsonPath('data.conversation.dispute.subject', 'Dispute message test')
            ->assertJsonPath('data.conversation.order.number', $order->order_no)
            ->assertJsonPath('data.messages.0.text', 'Please review the dispute update.');

        $this->actingAs($otherSeller)
            ->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertNotFound();
    }

    public function test_seller_reading_the_thread_syncs_notification_and_conversation_read_state(): void
    {
        [$seller] = $this->storeFixture('Alpha Store', 'alpha-store');
        $admin = $this->adminFixture();

        // The Admin endpoint writes a real database notification for the
        // Seller; this test intentionally does not fake the notification
        // channel so the stored row and its read state are exercised.
        $this->actingAs($admin)->postJson(route('admin.messages.conversations.store'), [
            'recipient_id' => $seller->id,
            'recipient_role' => 'seller',
            'subject' => 'Store verification',
            'message' => 'Please re-upload your business permit.',
        ])->assertCreated();

        $conversation = Conversation::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $seller->unreadNotifications()->count());
        $this->assertNull($seller->notifications()->firstOrFail()->read_at);

        $this->actingAs($seller)
            ->postJson(route('seller.support.messages.read', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        // Reading the thread clears both the conversation unread state and
        // the matching notification rows.
        $this->assertSame(0, $seller->unreadNotifications()->count());
        $this->assertNotNull($seller->notifications()->firstOrFail()->read_at);

        $this->actingAs($seller)
            ->getJson(route('seller.support.messages.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unread', 0);

        $this->actingAs($seller)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_seller_thread_endpoint_returns_only_messages_after_a_given_id(): void
    {
        [$seller, $store] = $this->storeFixture('Alpha Store', 'alpha-store');
        [$buyer, , $conversation] = $this->buyerThread($seller, $store, 'Incremental Buyer', 'Alpha Basket', 'Opening question');
        $firstMessage = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->actingAs($seller)->getJson(route('seller.support.messages.thread', [
            'conversation' => $conversation->id,
            'after' => $firstMessage->id,
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.messages');

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => 'Follow-up question',
        ])->assertOk();

        $this->actingAs($seller)->getJson(route('seller.support.messages.thread', [
            'conversation' => $conversation->id,
            'after' => $firstMessage->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.text', 'Follow-up question')
            ->assertJsonPath('data.messages.0.from', 'them');
    }

    private function buyerFixture(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'buyer',
            'status' => 'active',
        ]);
    }

    private function adminFixture(): User
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $adminRole = Role::query()->firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        return $admin;
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
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => Category::query()->firstOrFail()->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => 'Default',
            'price_minor' => 129_900,
        ]);

        return $product;
    }

    /**
     * @return array{0: User, 1: Product, 2: Conversation}
     */
    private function buyerThread(User $seller, Store $store, string $buyerName, string $productName, string $body): array
    {
        $buyer = $this->buyerFixture($buyerName);
        $product = $this->productFixture($store, $productName);

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

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $buyer->id,
            'body' => $body,
            'message_type' => 'text',
            'sent_at' => now(),
        ]);

        return [$buyer, $product, $conversation];
    }
}
