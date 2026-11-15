<?php

namespace Tests\Feature;

use App\Events\ConversationMessageReceiptUpdated;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageReceipt;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MessageReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_delivery_acknowledgement_persists_a_receipt_and_broadcasts_to_the_sender(): void
    {
        [$buyer, $seller, $conversation] = $this->threadFixture();
        $message = $this->messageFrom($conversation, $seller, 'Your order is on the way.');

        Event::fake([ConversationMessageReceiptUpdated::class]);

        $this->actingAs($buyer)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$message->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.delivered', 1);

        $receipt = MessageReceipt::query()
            ->where('message_id', $message->id)
            ->where('recipient_id', $buyer->id)
            ->firstOrFail();

        $this->assertNotNull($receipt->delivered_at);
        $this->assertNull($receipt->read_at);

        Event::assertDispatched(
            ConversationMessageReceiptUpdated::class,
            function (ConversationMessageReceiptUpdated $event) use ($buyer, $seller, $conversation, $message): bool {
                $entry = $event->receipts[0] ?? [];

                return $event->conversationId === (int) $conversation->id
                    && $event->senderId === (int) $seller->id
                    && (int) ($entry['message_id'] ?? 0) === (int) $message->id
                    && ($entry['receipt']['status'] ?? null) === 'delivered'
                    && ($entry['receipt']['recipients'][0]['id'] ?? null) === (int) $buyer->id;
            },
        );

        // Receipts are sender-only: the recipient sees no receipt on messages
        // they received, while the sender sees the delivered state.
        $this->actingAs($buyer)
            ->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.receipt', null);

        $this->actingAs($seller)
            ->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.receipt.status', 'delivered')
            ->assertJsonPath('data.messages.0.receipt.label', 'Delivered')
            ->assertJsonPath('data.messages.0.receipt.recipient_count', 1);

        // A freshly sent message starts as "sent" in its own send response.
        $this->actingAs($buyer)
            ->postJson(route('buyer.chat.send', ['conversation' => $conversation->id]), [
                'message' => 'Thank you!',
            ])
            ->assertOk()
            ->assertJsonPath('data.message.receipt.status', 'sent')
            ->assertJsonPath('data.message.receipt.label', 'Sent');
    }

    public function test_delivery_acknowledgements_are_idempotent_and_restricted_to_recipients(): void
    {
        [$buyer, $seller, $conversation] = $this->threadFixture();
        $inbound = $this->messageFrom($conversation, $seller, 'Inbound message');
        $own = $this->messageFrom($conversation, $buyer, 'My own message');
        $attacker = $this->buyerFixture('Attacker');

        $this->actingAs($attacker)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$inbound->id],
            ])
            ->assertNotFound();

        // The sender cannot create receipts for their own messages.
        $this->actingAs($seller)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$inbound->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.delivered', 0);

        $this->assertSame(0, MessageReceipt::query()->count());

        // Submitting both own and inbound ids only writes inbound receipts.
        $this->actingAs($buyer)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$inbound->id, $own->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.delivered', 1);

        $this->assertDatabaseHas('message_receipts', [
            'message_id' => $inbound->id,
            'recipient_id' => $buyer->id,
        ]);

        $this->assertDatabaseMissing('message_receipts', [
            'message_id' => $own->id,
            'recipient_id' => $buyer->id,
        ]);

        // Repeating the acknowledgement is a no-op.
        $this->actingAs($buyer)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$inbound->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.delivered', 0);

        $this->assertSame(1, MessageReceipt::query()->where('message_id', $inbound->id)->count());

        $this->actingAs($buyer)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => ['not-an-id'],
            ])
            ->assertStatus(422);
    }

    public function test_marking_a_thread_read_advances_delivered_and_seen_receipts(): void
    {
        [$buyer, $seller, $conversation] = $this->threadFixture();
        $first = $this->messageFrom($conversation, $seller, 'First update');
        $second = $this->messageFrom($conversation, $seller, 'Second update');

        Event::fake([ConversationMessageReceiptUpdated::class]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.chat.read', ['conversation' => $conversation->id]))
            ->assertOk();

        foreach ([$first, $second] as $message) {
            $receipt = MessageReceipt::query()
                ->where('message_id', $message->id)
                ->where('recipient_id', $buyer->id)
                ->firstOrFail();

            $this->assertNotNull($receipt->delivered_at);
            $this->assertNotNull($receipt->read_at);
        }

        $this->assertSame(2, MessageReceipt::query()->where('recipient_id', $buyer->id)->count());

        $this->actingAs($seller)
            ->getJson(route('seller.support.messages.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.receipt.status', 'seen')
            ->assertJsonPath('data.messages.0.receipt.label', 'Seen')
            ->assertJsonPath('data.messages.0.receipt.read_count', 1)
            ->assertJsonPath('data.messages.0.receipt.delivered_count', 1);

        $this->actingAs($buyer)
            ->getJson(route('buyer.chat.inbox'))
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unread', 0);
    }

    public function test_group_receipts_expose_per_recipient_statuses(): void
    {
        $buyer = $this->buyerFixture('Group Buyer');
        [$seller, $store] = $this->storeFixture('Group Store', 'group-store');
        $admin = User::factory()->create([
            'name' => 'Group Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $conversation = Conversation::query()->create([
            'subject' => 'Group thread',
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
        $conversation->participants()->attach($admin->id, [
            'participant_role' => 'admin',
            'last_read_at' => null,
            'joined_at' => now(),
        ]);

        $message = $this->messageFrom($conversation, $buyer, 'Everyone please review this.');

        $this->actingAs($buyer)
            ->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.receipt.is_group', true)
            ->assertJsonPath('data.messages.0.receipt.recipient_count', 2)
            ->assertJsonPath('data.messages.0.receipt.status', 'sent')
            ->assertJsonCount(2, 'data.messages.0.receipt.recipients');

        $this->actingAs($seller)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$message->id],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$message->id],
            ])
            ->assertOk();

        $thread = $this->actingAs($buyer)
            ->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.receipt.status', 'delivered')
            ->assertJsonPath('data.messages.0.receipt.delivered_count', 2)
            ->assertJsonPath('data.messages.0.receipt.read_count', 0)
            ->assertJsonPath('data.messages.0.receipt.label', 'Delivered 2/2');

        $statuses = array_column($thread->json('data.messages.0.receipt.recipients'), 'status');
        $this->assertEqualsCanonicalizing(['delivered', 'delivered'], $statuses);

        // Seller opens the thread: one recipient is now seen, the other is
        // still only delivered, and the aggregate label reports the split.
        $this->actingAs($seller)
            ->postJson(route('seller.support.messages.read', ['conversation' => $conversation->id]))
            ->assertOk();

        $updated = $this->actingAs($buyer)
            ->getJson(route('buyer.chat.thread', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.receipt.read_count', 1)
            ->assertJsonPath('data.messages.0.receipt.label', 'Seen 1/2');

        $statuses = array_column($updated->json('data.messages.0.receipt.recipients'), 'status');
        $this->assertEqualsCanonicalizing(['seen', 'delivered'], $statuses);

        $detail = (string) $updated->json('data.messages.0.receipt.detail');
        $this->assertStringContainsString('Seen', $detail);
        $this->assertStringContainsString('Delivered', $detail);
    }

    public function test_polling_receipt_updates_recover_a_missed_realtime_event(): void
    {
        [$buyer, $seller, $conversation] = $this->threadFixture();
        $message = $this->messageFrom($conversation, $seller, 'Delivered while polling');

        $this->actingAs($buyer)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$message->id],
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->getJson(route('seller.support.messages.thread', [
                'conversation' => $conversation->id,
                'after' => $message->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.receipt_updates')
            ->assertJsonPath('data.receipt_updates.0.message_id', $message->id)
            ->assertJsonPath('data.receipt_updates.0.receipt.status', 'delivered');

        // The recipient has no sent messages, so their polling payload has no
        // receipt updates.
        $this->actingAs($buyer)
            ->getJson(route('buyer.chat.thread', [
                'conversation' => $conversation->id,
                'after' => $message->id,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.receipt_updates');
    }

    public function test_authorized_images_and_videos_are_served_inline_with_safe_headers(): void
    {
        Storage::fake('local');

        [$buyer, $seller, $conversation] = $this->threadFixture();
        $message = $this->messageFrom($conversation, $seller, '');
        $attacker = $this->buyerFixture('Media Attacker');

        $image = $this->attachmentFixture($message, $conversation, 'photo.png', 'image/png');
        $video = $this->attachmentFixture($message, $conversation, 'clip.mp4', 'video/mp4');
        $document = $this->attachmentFixture($message, $conversation, 'invoice.pdf', 'application/pdf');

        $imageResponse = $this->actingAs($buyer)
            ->get(route('buyer.chat.attachment', ['attachment' => $image->id]));

        $imageResponse->assertOk();
        $this->assertSame('image/png', $imageResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith(
            'inline',
            (string) $imageResponse->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'nosniff',
            $imageResponse->headers->get('X-Content-Type-Options'),
        );

        $videoResponse = $this->actingAs($buyer)
            ->get(route('buyer.chat.attachment', ['attachment' => $video->id]));

        $videoResponse->assertOk();
        $this->assertSame('video/mp4', $videoResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith(
            'inline',
            (string) $videoResponse->headers->get('Content-Disposition'),
        );

        $documentResponse = $this->actingAs($buyer)
            ->get(route('buyer.chat.attachment', ['attachment' => $document->id]));

        $documentResponse->assertOk();
        $this->assertStringStartsWith(
            'attachment',
            (string) $documentResponse->headers->get('Content-Disposition'),
        );

        $this->actingAs($attacker)
            ->get(route('buyer.chat.attachment', ['attachment' => $image->id]))
            ->assertNotFound();

        // Video uploads are accepted and exposed as previewable attachments.
        $this->actingAs($buyer)
            ->post(
                route('buyer.chat.send', ['conversation' => $conversation->id]),
                ['attachment' => UploadedFile::fake()->create('walkthrough.mp4', 120, 'video/mp4')],
                ['Accept' => 'application/json'],
            )
            ->assertOk()
            ->assertJsonPath('data.message.attachments.0.is_video', true)
            ->assertJsonPath('data.message.attachments.0.is_previewable', true);

        // Executable/script payloads stay rejected.
        $this->actingAs($buyer)
            ->post(
                route('buyer.chat.send', ['conversation' => $conversation->id]),
                ['attachment' => UploadedFile::fake()->create('payload.php', 4, 'text/x-php')],
                ['Accept' => 'application/json'],
            )
            ->assertStatus(422);
    }

    public function test_receipt_acknowledgement_survives_a_realtime_broadcast_failure(): void
    {
        [$buyer, $seller, $conversation] = $this->threadFixture();
        $message = $this->messageFrom($conversation, $seller, 'Persist regardless of Reverb.');

        Event::listen(ConversationMessageReceiptUpdated::class, function (): void {
            throw new RuntimeException('Realtime transport unavailable.');
        });

        $this->actingAs($buyer)
            ->postJson(route('messages.delivered', ['conversation' => $conversation->id]), [
                'message_ids' => [$message->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.delivered', 1);

        $this->assertDatabaseHas('message_receipts', [
            'message_id' => $message->id,
            'recipient_id' => $buyer->id,
        ]);
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
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function threadFixture(): array
    {
        $buyer = $this->buyerFixture('Receipt Buyer');
        [$seller] = $this->storeFixture('Receipt Store', 'receipt-store');

        $conversation = Conversation::query()->create([
            'subject' => 'Receipt thread',
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

        return [$buyer, $seller, $conversation];
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

    private function messageFrom(Conversation $conversation, User $sender, string $body): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $body,
            'message_type' => $body === '' ? 'attachment' : 'text',
            'sent_at' => now(),
        ]);
    }

    private function attachmentFixture(
        Message $message,
        Conversation $conversation,
        string $name,
        string $mime,
    ): MessageAttachment {
        $path = "message-attachments/{$conversation->id}/{$name}";
        Storage::disk('local')->put($path, 'preview-bytes');

        return MessageAttachment::query()->create([
            'message_id' => $message->id,
            'file_path' => $path,
            'original_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => 13,
        ]);
    }
}
