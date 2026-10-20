<?php

namespace Tests\Feature;

use App\Events\ConversationMessageCreated;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BuyerChatMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_chat_page_lists_only_own_conversations_and_preselects_the_thread(): void
    {
        $buyer = $this->buyerFixture('Buyer One');
        $otherBuyer = $this->buyerFixture('Buyer Two');

        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        [$otherSeller, $otherStore] = $this->storeFixture('Greenline Home', 'greenline-home');

        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $otherProduct = $this->productFixture($otherStore, 'Desk Lamp');

        $conversation = $this->conversationFixture($buyer, $seller, $product, 'Is the large size available?');
        $otherConversation = $this->conversationFixture($otherBuyer, $otherSeller, $otherProduct, 'Is the lamp dimmable?');

        $this->actingAs($buyer)->get(route('buyer.chat'))
            ->assertOk()
            ->assertSee('Sundays Market')
            ->assertSee('Is the large size available?')
            ->assertDontSee('Greenline Home')
            ->assertDontSee('Is the lamp dimmable?')
            ->assertDontSee('Preview only');

        $this->actingAs($buyer)->get(route('buyer.chat', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Is the large size available?');

        $this->actingAs($buyer)->get(route('buyer.chat', ['conversation' => $otherConversation->id]))
            ->assertNotFound();

        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.id', $conversation->id)
            ->assertJsonPath('data.conversations.0.title', 'Sundays Market');
    }

    public function test_starting_a_product_chat_resolves_the_store_server_side_and_reuses_open_threads(): void
    {
        $buyer = $this->buyerFixture('Start Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        [$otherSeller] = $this->storeFixture('Other Store', 'other-store');

        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $hidden = $this->productFixture($store, 'Hidden Item');
        $hidden->forceFill(['product_status' => 'draft'])->save();

        // Ownership fields submitted by the client are ignored: the Seller
        // always comes from the Store that owns the visible product.
        $first = $this->actingAs($buyer)->postJson(route('buyer.chat.product'), [
            'product_id' => $product->id,
            'seller_id' => $otherSeller->id,
            'store_id' => 999_999,
        ]);

        $first->assertCreated();
        $conversationId = (int) $first->json('data.conversation_id');

        $conversation = Conversation::query()->findOrFail($conversationId);
        $this->assertSame($product->id, (int) $conversation->product_id);
        $this->assertSame($product->name, $conversation->subject);

        $participantIds = $conversation->participants()->pluck('users.id')->all();
        $this->assertEqualsCanonicalizing([$buyer->id, $seller->id], $participantIds);
        $this->assertNotContains($otherSeller->id, $participantIds);

        $second = $this->actingAs($buyer)->postJson(route('buyer.chat.product'), [
            'product_id' => $product->id,
        ]);

        $second->assertCreated();
        $this->assertSame($conversationId, (int) $second->json('data.conversation_id'));
        $this->assertSame(1, Conversation::query()->where('product_id', $product->id)->count());

        // Products that are not visible to buyers resolve as missing.
        $this->actingAs($buyer)->postJson(route('buyer.chat.product'), ['product_id' => $hidden->id])
            ->assertNotFound();

        // Browser submits land on the chat page with the conversation open.
        $this->actingAs($buyer)->post(route('buyer.chat.product'), ['product_id' => $product->id])
            ->assertRedirect(route('buyer.chat', ['conversation' => $conversationId]));
    }

    public function test_buyer_messages_persist_update_activity_and_honor_state_rules(): void
    {
        $buyer = $this->buyerFixture('Message Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $conversation = $this->conversationFixture($buyer, $seller, $product, 'First question');

        Notification::fake();

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => '  Hello, is this still available?  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.from', 'me')
            ->assertJsonPath('data.message.text', 'Hello, is this still available?');

        $message = Message::query()->where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->assertSame($buyer->id, (int) $message->sender_id);
        $this->assertSame('Hello, is this still available?', $message->body);
        $this->assertNotNull($message->sent_at);

        Notification::assertSentTo(
            $seller,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'seller_buyer_message'
                && $notification->meta()['conversation_id'] === (int) $conversation->id,
        );

        $conversation->refresh();
        $this->assertTrue($conversation->last_message_at->greaterThanOrEqualTo($message->sent_at));

        $participant = DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $buyer->id)
            ->first();
        $this->assertNotNull($participant->last_read_at);

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [])
            ->assertStatus(422);

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => Str::repeat('x', 5001),
        ])->assertStatus(422);

        $conversation->forceFill(['status' => 'closed'])->save();

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => 'One more question',
        ])->assertStatus(409);

        $this->assertSame(2, Message::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_message_delivery_survives_a_realtime_broadcast_failure(): void
    {
        $buyer = $this->buyerFixture('Realtime Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $conversation = $this->conversationFixture($buyer, $seller, $product, 'First question');

        Notification::fake();

        Event::listen(ConversationMessageCreated::class, function (): void {
            throw new RuntimeException('Realtime transport unavailable.');
        });

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => 'Persist even without realtime',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.text', 'Persist even without realtime');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $buyer->id,
            'body' => 'Persist even without realtime',
        ]);

        Notification::assertSentTo(
            $seller,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'seller_buyer_message',
        );
    }

    public function test_unread_counts_follow_participant_read_timestamps(): void
    {
        $buyer = $this->buyerFixture('Unread Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $conversation = $this->conversationFixture($buyer, $seller, $product, 'Seller sent this while away');

        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unread', 1);

        $this->actingAs($buyer)->postJson(route('buyer.chat.read', ['conversation' => $conversation->id]))
            ->assertOk();

        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unread', 0);

        // The buyer's own message never counts toward their unread badge.
        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => 'My own follow-up',
        ])->assertOk();

        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unread', 0);
    }

    public function test_non_participants_cannot_read_send_or_download_conversation_files(): void
    {
        $buyer = $this->buyerFixture('Thread Owner');
        $attacker = $this->buyerFixture('Attacker Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $conversation = $this->conversationFixture($buyer, $seller, $product, 'Private thread');

        $this->actingAs($attacker)->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertNotFound();

        $this->actingAs($attacker)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => 'Sneaking in',
        ])->assertNotFound();

        $this->actingAs($attacker)->postJson(route('buyer.chat.read', ['conversation' => $conversation->id]))
            ->assertNotFound();

        Storage::fake('local');

        $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $path = "message-attachments/{$conversation->id}/invoice.pdf";
        Storage::disk('local')->put($path, 'pdf-bytes');

        $attachment = MessageAttachment::query()->create([
            'message_id' => $message->id,
            'file_path' => $path,
            'original_name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
        ]);

        $this->actingAs($buyer)->get(route('buyer.chat.attachment', ['attachment' => $attachment->id]))
            ->assertOk();

        $this->actingAs($attacker)->get(route('buyer.chat.attachment', ['attachment' => $attachment->id]))
            ->assertNotFound();

        $this->assertSame(0, Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', $attacker->id)
            ->count());
    }

    public function test_attachment_messages_validate_and_store_on_the_private_disk(): void
    {
        Storage::fake('local');

        $buyer = $this->buyerFixture('File Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $conversation = $this->conversationFixture($buyer, $seller, $product, 'Please check this photo');

        $response = $this->actingAs($buyer)->post(
            route('buyer.chat.send', ['conversation' => $conversation->id]),
            ['attachment' => UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf')],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('data.message.from', 'me')
            ->assertJsonPath('data.message.attachments.0.name', 'receipt.pdf');

        $message = Message::query()->where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->assertSame('attachment', $message->message_type);
        $this->assertCount(1, $message->attachments);

        $attachment = $message->attachments->first();
        $this->assertTrue(Storage::disk('local')->exists($attachment->file_path));
        $this->assertStringStartsWith("message-attachments/{$conversation->id}/", $attachment->file_path);

        $this->actingAs($buyer)->post(
            route('buyer.chat.send', ['conversation' => $conversation->id]),
            ['attachment' => UploadedFile::fake()->create('payload.php', 4, 'text/x-php')],
            ['Accept' => 'application/json'],
        )->assertStatus(422);
    }

    public function test_buyer_chat_requires_an_active_buyer_and_renders_empty_states(): void
    {
        $this->get(route('buyer.chat'))->assertRedirect(route('login'));

        $buyer = $this->buyerFixture('Empty Buyer');

        $this->actingAs($buyer)->get(route('buyer.chat'))
            ->assertOk()
            ->assertSee('No conversations yet')
            ->assertDontSee('Preview only');

        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $this->actingAs($seller)->get(route('buyer.chat'))->assertForbidden();
    }

    public function test_starting_a_store_chat_resolves_the_seller_server_side_and_reuses_open_threads(): void
    {
        $buyer = $this->buyerFixture('Store Chat Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        [$otherSeller] = $this->storeFixture('Greenline Home', 'greenline-home');

        // Ownership fields submitted by the client are ignored: the Seller
        // always comes from the visible Store identified by its slug.
        $first = $this->actingAs($buyer)->postJson(route('buyer.chat.store'), [
            'store' => 'sundays-market',
            'seller_id' => $otherSeller->id,
            'store_id' => 999_999,
        ]);

        $first->assertCreated();
        $conversationId = (int) $first->json('data.conversation_id');

        $conversation = Conversation::query()->findOrFail($conversationId);
        $this->assertNull($conversation->product_id);
        $this->assertNull($conversation->seller_order_id);
        $this->assertNull($conversation->order_id);
        $this->assertNull($conversation->dispute_id);
        $this->assertSame('Sundays Market', $conversation->subject);

        $participantIds = $conversation->participants()->pluck('users.id')->all();
        $this->assertEqualsCanonicalizing([$buyer->id, $seller->id], $participantIds);
        $this->assertNotContains($otherSeller->id, $participantIds);

        $second = $this->actingAs($buyer)->postJson(route('buyer.chat.store'), ['store' => $store->slug]);
        $second->assertCreated();
        $this->assertSame($conversationId, (int) $second->json('data.conversation_id'));
        $this->assertSame(1, Conversation::query()->whereNull('product_id')->where('subject', 'Sundays Market')->count());

        $this->actingAs($buyer)->getJson(route('buyer.chat.thread', ['conversation' => $conversationId]))
            ->assertOk()
            ->assertJsonPath('data.conversation.title', 'Sundays Market')
            ->assertJsonPath('data.conversation.store_url', route('stores.show', ['store' => $store->slug]))
            ->assertJsonPath('data.conversation.context', 'Direct message');

        // Stores that are not visible to buyers resolve as missing.
        [, $draftStore] = $this->storeFixture('Draft Store', 'draft-store');
        $draftStore->forceFill(['publication_status' => 'draft'])->save();

        $this->actingAs($buyer)->postJson(route('buyer.chat.store'), ['store' => 'draft-store'])
            ->assertNotFound();

        $this->actingAs($buyer)->postJson(route('buyer.chat.store'), [])
            ->assertStatus(422);

        // Browser submits land on the chat page with the conversation open.
        $this->actingAs($buyer)->post(route('buyer.chat.store'), ['store' => $store->slug])
            ->assertRedirect(route('buyer.chat', ['conversation' => $conversationId]));
    }

    public function test_thread_payload_exposes_real_product_and_counterpart_details(): void
    {
        $buyer = $this->buyerFixture('Detail Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $conversation = $this->conversationFixture($buyer, $seller, $product, 'Do you have this in large?');

        $this->actingAs($buyer)->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.conversation.initials', 'SM')
            ->assertJsonPath('data.conversation.product.name', 'Classic Linen Shirt')
            ->assertJsonPath('data.conversation.product.url', route('products.show', ['product' => $product->id]))
            ->assertJsonPath('data.conversation.product.price_minor', 129_900)
            ->assertJsonPath('data.conversation.product.price_label', '₱1,299.00')
            ->assertJsonPath('data.conversation.product.image_url', null)
            ->assertJsonPath('data.conversation.counterpart.since_caption', 'Seller on Bearly since')
            ->assertJsonPath('data.conversation.counterpart.since_label', $store->created_at->copy()->setTimezone('Asia/Manila')->format('M Y'))
            ->assertJsonPath('data.conversation.counterpart.image_url', null)
            ->assertJsonPath('data.conversation.order', null);
    }

    public function test_message_timestamps_are_presented_in_philippine_time(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 17, 4, 0, 0, 'UTC')); // 12:00 PM in Manila.

        $buyer = $this->buyerFixture('Timezone Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');

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

        // 10:00 UTC a day earlier is 6:00 PM Manila and reads as "Yesterday";
        // 00:30 UTC is 8:30 AM on the same Manila day.
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $seller->id,
            'body' => 'Yesterday evening in Manila',
            'message_type' => 'text',
            'sent_at' => Carbon::create(2026, 10, 16, 10, 0, 0, 'UTC'),
        ]);

        $latest = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $seller->id,
            'body' => 'This morning in Manila',
            'message_type' => 'text',
            'sent_at' => Carbon::create(2026, 10, 17, 0, 30, 0, 'UTC'),
        ]);

        $conversation->forceFill(['last_message_at' => $latest->sent_at])->save();

        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.time_label', '8:30 AM');

        $this->actingAs($buyer)->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.day_label', 'Yesterday')
            ->assertJsonPath('data.messages.0.time_label', '6:00 PM')
            ->assertJsonPath('data.messages.1.day_label', 'Today')
            ->assertJsonPath('data.messages.1.time_label', '8:30 AM')
            ->assertJsonPath('data.messages.1.sent_at', '2026-10-17T08:30:00+08:00');
    }

    public function test_thread_endpoint_can_return_only_messages_after_a_given_id(): void
    {
        $buyer = $this->buyerFixture('Incremental Buyer');
        [$seller, $store] = $this->storeFixture('Sundays Market', 'sundays-market');
        $product = $this->productFixture($store, 'Classic Linen Shirt');
        $conversation = $this->conversationFixture($buyer, $seller, $product, 'First question');
        $firstMessage = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->actingAs($buyer)->getJson(route('buyer.chat.thread', [
            'conversation' => $conversation->id,
            'after' => $firstMessage->id,
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.messages');

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => 'One more question',
        ])->assertOk();

        $this->actingAs($buyer)->getJson(route('buyer.chat.thread', [
            'conversation' => $conversation->id,
            'after' => $firstMessage->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.text', 'One more question')
            ->assertJsonPath('data.messages.0.from', 'me');
    }

    public function test_admin_support_conversations_are_visible_and_replyable_for_the_buyer(): void
    {
        $buyer = $this->buyerFixture('Support Buyer');
        $otherBuyer = $this->buyerFixture('Unrelated Buyer');
        $admin = $this->adminFixture();

        // The real Admin messaging endpoint creates the persisted thread that
        // the Admin workspace and the recipient share.
        $this->actingAs($admin)->postJson(route('admin.messages.conversations.store'), [
            'recipient_id' => $buyer->id,
            'recipient_role' => 'buyer',
            'subject' => 'Order follow-up',
            'message' => 'We reviewed your report and refunded the shipping fee.',
        ])->assertCreated();

        $conversation = Conversation::query()->latest('id')->firstOrFail();

        $this->actingAs($buyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.id', $conversation->id)
            ->assertJsonPath('data.conversations.0.title', 'Bearly Support')
            ->assertJsonPath('data.conversations.0.unread', 1);

        $this->actingAs($buyer)->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.conversation.title', 'Bearly Support')
            ->assertJsonPath('data.conversation.product', null)
            ->assertJsonPath('data.conversation.order', null)
            ->assertJsonPath('data.messages.0.from', 'them')
            ->assertJsonPath('data.messages.0.text', 'We reviewed your report and refunded the shipping fee.');

        $this->actingAs($buyer)->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
            'message' => 'Thank you for the update!',
        ])->assertOk();

        $this->assertSame(
            'Thank you for the update!',
            Message::query()->where('conversation_id', $conversation->id)->latest('id')->firstOrFail()->body,
        );

        $this->actingAs($buyer)->get(route('buyer.chat'))
            ->assertOk()
            ->assertSee('Bearly Support')
            ->assertSee('Thank you for the update!');

        // The support thread stays invisible to unrelated buyers.
        $this->actingAs($otherBuyer)->get(route('buyer.chat', ['conversation' => $conversation->id]))
            ->assertNotFound();

        $this->actingAs($otherBuyer)->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertNotFound();

        $this->actingAs($otherBuyer)->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonCount(0, 'data.conversations');
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

    private function conversationFixture(User $buyer, User $seller, ?Product $product, string $body): Conversation
    {
        $conversation = Conversation::query()->create([
            'subject' => $product?->name ?? 'Direct message',
            'product_id' => $product?->id,
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
            'sender_id' => $seller->id,
            'body' => $body,
            'message_type' => 'text',
            'sent_at' => now(),
        ]);

        return $conversation;
    }
}
