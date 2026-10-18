<?php

namespace Tests\Feature\Admin;

use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Role;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMessageTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(
        string $role,
        string $status = 'active'
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'status' => $status,
        ]);

        $roleModel = Role::query()->firstOrCreate(
            ['name' => $role],
            ['display_name' => ucfirst($role)]
        );

        $user->roles()->syncWithoutDetaching([
            $roleModel->id,
        ]);

        return $user;
    }

    private function createAdmin(): User
    {
        return $this->createUserWithRole('admin');
    }

    private function createConversation(
        User $admin,
        User $recipient,
        string $recipientRole = 'buyer',
        string $status = 'open'
    ): Conversation {
        $conversation = Conversation::query()->create([
            'subject' => 'Support conversation',
            'type' => 'direct',
            'status' => $status,
            'created_by' => $admin->id,
            'last_message_at' => now(),
        ]);

        $conversation->participants()->attach(
            $admin->id,
            [
                'participant_role' => 'admin',
                'last_read_at' => now(),
                'joined_at' => now(),
            ]
        );

        $conversation->participants()->attach(
            $recipient->id,
            [
                'participant_role' => $recipientRole,
                'last_read_at' => null,
                'joined_at' => now(),
            ]
        );

        return $conversation;
    }

    private function createDisputeWithParticipant(
        User $participant,
        string $role = 'buyer'
    ): Dispute {
        $dispute = Dispute::query()->create([
            'dispute_no' => 'DSP-'.fake()->unique()->numerify('####'),

            'opened_by' => $participant->id,

            'subject' => 'Test dispute',

            'description' => 'Test dispute description.',

            'priority' => 'normal',

            'status' => 'open',

            'opened_at' => now(),
        ]);

        $dispute
            ->participants()
            ->attach(
                $participant->id,
                [
                    'participant_role' => $role,

                    'joined_at' => now(),
                ]
            );

        return $dispute;
    }

    public function test_admin_can_create_conversation_with_active_buyer(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.conversations.store'
                ),
                [
                    'recipient_id' => $buyer->id,
                    'recipient_role' => 'buyer',
                    'message' => 'Hello Buyer',
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'conversation.participant.id',
                $buyer->id
            )
            ->assertJsonPath(
                'conversation.participant.role',
                'buyer'
            );

        $this->assertDatabaseHas(
            'conversations',
            [
                'created_by' => $admin->id,
                'type' => 'direct',
                'status' => 'open',
            ]
        );

        $conversation =
            Conversation::query()
                ->where(
                    'created_by',
                    $admin->id
                )
                ->firstOrFail();

        $this->assertDatabaseHas(
            'conversation_participants',
            [
                'conversation_id' => $conversation->id,

                'user_id' => $admin->id,

                'participant_role' => 'admin',
            ]
        );

        $this->assertDatabaseHas(
            'conversation_participants',
            [
                'conversation_id' => $conversation->id,

                'user_id' => $buyer->id,

                'participant_role' => 'buyer',
            ]
        );

        $this->assertDatabaseHas(
            'messages',
            [
                'conversation_id' => $conversation->id,

                'sender_id' => $admin->id,

                'body' => 'Hello Buyer',

                'message_type' => 'text',
            ]
        );

        $this->assertNotNull(
            $conversation
                ->fresh()
                ->last_message_at
        );
    }

    public function test_admin_cannot_start_conversation_with_self(): void
    {
        $admin = $this->createAdmin();

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.conversations.store'
                ),
                [
                    'recipient_id' => $admin->id,
                    'recipient_role' => 'buyer',
                    'message' => 'Hello',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'recipient_id'
            );

        $this->assertDatabaseCount(
            'conversations',
            0
        );
    }

    public function test_recipient_role_must_match_selected_user(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.conversations.store'
                ),
                [
                    'recipient_id' => $buyer->id,
                    'recipient_role' => 'seller',
                    'message' => 'Hello',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'recipient_id'
            );

        $this->assertDatabaseCount(
            'conversations',
            0
        );
    }

    public function test_inactive_recipient_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $buyer =
            $this->createUserWithRole(
                'buyer',
                'deactivated'
            );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.conversations.store'
                ),
                [
                    'recipient_id' => $buyer->id,
                    'recipient_role' => 'buyer',
                    'message' => 'Hello',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'recipient_id'
            );

        $this->assertDatabaseCount(
            'conversations',
            0
        );
    }

    public function test_admin_participant_can_send_message(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.send',
                    $conversation
                ),
                [
                    'message' => 'Second message',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.text',
                'Second message'
            );

        $this->assertDatabaseHas(
            'messages',
            [
                'conversation_id' => $conversation->id,

                'sender_id' => $admin->id,

                'body' => 'Second message',
            ]
        );

        $this->assertNotNull(
            $conversation
                ->fresh()
                ->last_message_at
        );
    }

    public function test_buyer_reply_notifies_conversation_admin(): void
    {
        Notification::fake();

        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $this
            ->actingAs($buyer)
            ->postJson(
                route(
                    'buyer.chat.send',
                    $conversation
                ),
                [
                    'message' => 'Hello, I need help with this order.',
                ]
            )
            ->assertOk();

        Notification::assertSentTo(
            $admin,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'admin_message_received'
                && $notification->meta()['conversation_id'] === (int) $conversation->id,
        );
    }

    public function test_admin_message_notifies_seller_and_logistics_recipients(): void
    {
        Notification::fake();

        $admin = $this->createAdmin();

        $seller = $this->createUserWithRole(
            'seller'
        );

        $logistics = $this->createUserWithRole(
            'logistics'
        );

        $sellerConversation =
            $this->createConversation(
                $admin,
                $seller,
                'seller'
            );

        $logisticsConversation =
            $this->createConversation(
                $admin,
                $logistics,
                'logistics'
            );

        $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.send',
                    $sellerConversation
                ),
                [
                    'message' => 'About your store.',
                ]
            )
            ->assertOk();

        $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.send',
                    $logisticsConversation
                ),
                [
                    'message' => 'About the pickup.',
                ]
            )
            ->assertOk();

        Notification::assertSentTo(
            $seller,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'seller_buyer_message'
                && $notification->meta()['conversation_id'] === (int) $sellerConversation->id,
        );

        Notification::assertSentTo(
            $logistics,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'logistics_message_received'
                && $notification->meta()['conversation_id'] === (int) $logisticsConversation->id,
        );
    }

    /**
     * These two tests intentionally do NOT use Notification::fake(): they
     * exercise the real database notification channel so a warning logged by
     * the delivery guard cannot hide a missing seller notification row.
     */
    public function test_admin_created_seller_conversation_writes_a_real_notification_row(): void
    {
        $admin = $this->createAdmin();

        $seller = $this->createUserWithRole('seller');

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route('admin.messages.conversations.store'),
                [
                    'recipient_id' => $seller->id,
                    'recipient_role' => 'seller',
                    'subject' => 'Pickup address confirmation',
                    'message' => 'Please confirm the pickup address on file.',
                ]
            );

        $response->assertCreated();

        $conversationId = (int) $response->json('conversation.id');

        $notification = $seller->notifications()->get()->sole();

        $this->assertSame(WorkflowNotification::class, $notification->type);
        $this->assertSame('seller_buyer_message', $notification->data['event']);
        $this->assertSame($conversationId, $notification->data['meta']['conversation_id']);
        $this->assertSame(
            route('seller.support.messages', ['conversation' => $conversationId]),
            $notification->data['url']
        );
        $this->assertNull($notification->read_at);
        $this->assertSame(1, $seller->unreadNotifications()->count());

        $this->actingAs($seller)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.items.0.event', 'seller_buyer_message')
            ->assertJsonPath('data.items.0.read', false)
            ->assertJsonPath('data.items.0.title', 'New message from '.$admin->name)
            ->assertJsonPath(
                'data.items.0.url',
                route('seller.support.messages', ['conversation' => $conversationId])
            );
    }

    public function test_admin_sent_seller_message_writes_a_real_notification_row(): void
    {
        $admin = $this->createAdmin();

        $seller = $this->createUserWithRole('seller');

        $conversation = $this->createConversation(
            $admin,
            $seller,
            'seller'
        );

        $this->assertSame(0, $seller->notifications()->count());

        $this
            ->actingAs($admin)
            ->postJson(
                route('admin.messages.send', $conversation),
                [
                    'message' => 'Following up on your compliance documents.',
                ]
            )
            ->assertOk();

        $notification = $seller->notifications()->get()->sole();

        $this->assertSame(WorkflowNotification::class, $notification->type);
        $this->assertSame('seller_buyer_message', $notification->data['event']);
        $this->assertSame((int) $conversation->id, $notification->data['meta']['conversation_id']);
        $this->assertSame(
            route('seller.support.messages', ['conversation' => $conversation->id]),
            $notification->data['url']
        );
        $this->assertNull($notification->read_at);
        $this->assertSame(1, $seller->unreadNotifications()->count());
    }

    public function test_non_participant_cannot_send_message(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $otherAdmin =
            $this->createAdmin();

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $response = $this
            ->actingAs($otherAdmin)
            ->postJson(
                route(
                    'admin.messages.send',
                    $conversation
                ),
                [
                    'message' => 'Unauthorized message',
                ]
            );

        $response->assertNotFound();

        $this->assertDatabaseMissing(
            'messages',
            [
                'conversation_id' => $conversation->id,

                'sender_id' => $otherAdmin->id,

                'body' => 'Unauthorized message',
            ]
        );
    }

    public function test_closed_conversation_cannot_receive_new_message(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer,
                'buyer',
                'closed'
            );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.send',
                    $conversation
                ),
                [
                    'message' => 'Should not send',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'message'
            );

        $this->assertDatabaseMissing(
            'messages',
            [
                'conversation_id' => $conversation->id,

                'body' => 'Should not send',
            ]
        );
    }

    public function test_admin_can_mark_conversation_as_read(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        DB::table(
            'conversation_participants'
        )
            ->where(
                'conversation_id',
                $conversation->id
            )
            ->where(
                'user_id',
                $admin->id
            )
            ->update([
                'last_read_at' => null,
            ]);

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.read',
                    $conversation
                )
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'unread',
                0
            );

        $this->assertNotNull(
            DB::table(
                'conversation_participants'
            )
                ->where(
                    'conversation_id',
                    $conversation->id
                )
                ->where(
                    'user_id',
                    $admin->id
                )
                ->value('last_read_at')
        );

        $this->assertNull(
            DB::table(
                'conversation_participants'
            )
                ->where(
                    'conversation_id',
                    $conversation->id
                )
                ->where(
                    'user_id',
                    $buyer->id
                )
                ->value('last_read_at')
        );
    }

    public function test_admin_can_mark_latest_received_message_as_unread(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        Message::query()->create([
            'conversation_id' => $conversation->id,

            'sender_id' => $buyer->id,

            'body' => 'Buyer reply',

            'message_type' => 'text',

            'sent_at' => now(),
        ]);

        DB::table(
            'conversation_participants'
        )
            ->where(
                'conversation_id',
                $conversation->id
            )
            ->where(
                'user_id',
                $admin->id
            )
            ->update([
                'last_read_at' => now(),
            ]);

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.unread',
                    $conversation
                )
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'unread',
                1
            );
    }

    public function test_admin_can_send_message_with_attachment(): void
    {
        Storage::fake('local');

        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $file =
            UploadedFile::fake()->create(
                'proof.jpg',
                250,
                'image/jpeg'
            );

        $response = $this
            ->actingAs($admin)
            ->post(
                route(
                    'admin.messages.send',
                    $conversation
                ),
                [
                    'message' => 'See attached proof.',

                    'attachment' => $file,
                ],
                [
                    'Accept' => 'application/json',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.text',
                'See attached proof.'
            )
            ->assertJsonPath(
                'data.attachments.0.name',
                'proof.jpg'
            );

        $message =
            Message::query()
                ->where(
                    'conversation_id',
                    $conversation->id
                )
                ->latest('id')
                ->firstOrFail();

        $attachment =
            MessageAttachment::query()
                ->where(
                    'message_id',
                    $message->id
                )
                ->firstOrFail();

        $this->assertDatabaseHas(
            'message_attachments',
            [
                'id' => $attachment->id,

                'message_id' => $message->id,

                'original_name' => 'proof.jpg',
            ]
        );

        Storage::disk('local')
            ->assertExists(
                $attachment->file_path
            );

        $this->assertNotEmpty(
            $response->json(
                'data.attachments.0.download_url'
            )
        );
    }

    public function test_admin_can_send_attachment_only_message(): void
    {
        Storage::fake('local');

        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $file =
            UploadedFile::fake()->create(
                'invoice.pdf',
                250,
                'application/pdf'
            );

        $response = $this
            ->actingAs($admin)
            ->post(
                route(
                    'admin.messages.send',
                    $conversation
                ),
                [
                    'attachment' => $file,
                ],
                [
                    'Accept' => 'application/json',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.text',
                ''
            )
            ->assertJsonPath(
                'data.attachments.0.name',
                'invoice.pdf'
            );

        $this->assertDatabaseHas(
            'messages',
            [
                'conversation_id' => $conversation->id,

                'sender_id' => $admin->id,

                'body' => '',

                'message_type' => 'attachment',
            ]
        );
    }

    public function test_invalid_attachment_type_is_rejected(): void
    {
        Storage::fake('local');

        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $file =
            UploadedFile::fake()->create(
                'script.exe',
                100,
                'application/octet-stream'
            );

        $response = $this
            ->actingAs($admin)
            ->post(
                route(
                    'admin.messages.send',
                    $conversation
                ),
                [
                    'message' => 'Invalid attachment',

                    'attachment' => $file,
                ],
                [
                    'Accept' => 'application/json',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'attachment'
            );

        $this->assertDatabaseCount(
            'message_attachments',
            0
        );
    }

    public function test_oversized_attachment_is_rejected(): void
    {
        Storage::fake('local');

        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $file =
            UploadedFile::fake()->create(
                'large.pdf',
                10241,
                'application/pdf'
            );

        $response = $this
            ->actingAs($admin)
            ->post(
                route(
                    'admin.messages.send',
                    $conversation
                ),
                [
                    'message' => 'Large file',

                    'attachment' => $file,
                ],
                [
                    'Accept' => 'application/json',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'attachment'
            );

        $this->assertDatabaseCount(
            'message_attachments',
            0
        );
    }

    public function test_participant_admin_can_download_attachment(): void
    {
        Storage::fake('local');

        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $message =
            Message::query()->create([
                'conversation_id' => $conversation->id,

                'sender_id' => $admin->id,

                'body' => 'Attached file',

                'message_type' => 'text',

                'sent_at' => now(),
            ]);

        $path =
            "message-attachments/{$conversation->id}/document.pdf";

        Storage::disk('local')
            ->put(
                $path,
                'test document'
            );

        $attachment =
            MessageAttachment::query()->create([
                'message_id' => $message->id,

                'file_path' => $path,

                'original_name' => 'document.pdf',

                'mime_type' => 'application/pdf',

                'size_bytes' => strlen(
                    'test document'
                ),
            ]);

        $response = $this
            ->actingAs($admin)
            ->get(
                route(
                    'admin.messages.attachments.download',
                    $attachment
                )
            );

        $response->assertOk();

        $response
            ->assertHeader(
                'content-disposition'
            );
    }

    public function test_non_participant_admin_cannot_download_attachment(): void
    {
        Storage::fake('local');

        $admin = $this->createAdmin();

        $otherAdmin =
            $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $message =
            Message::query()->create([
                'conversation_id' => $conversation->id,

                'sender_id' => $admin->id,

                'body' => 'Private file',

                'message_type' => 'text',

                'sent_at' => now(),
            ]);

        $path =
            "message-attachments/{$conversation->id}/private.pdf";

        Storage::disk('local')
            ->put(
                $path,
                'private document'
            );

        $attachment =
            MessageAttachment::query()->create([
                'message_id' => $message->id,

                'file_path' => $path,

                'original_name' => 'private.pdf',

                'mime_type' => 'application/pdf',

                'size_bytes' => strlen(
                    'private document'
                ),
            ]);

        $response = $this
            ->actingAs($otherAdmin)
            ->get(
                route(
                    'admin.messages.attachments.download',
                    $attachment
                )
            );

        $response->assertNotFound();
    }

    public function test_admin_can_open_dispute_conversation_with_participant(): void
    {
        $admin = $this->createAdmin();

        $buyer =
            $this->createUserWithRole(
                'buyer'
            );

        $dispute =
            $this->createDisputeWithParticipant(
                $buyer,
                'buyer'
            );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.disputes.open',
                    $dispute
                ),
                [
                    'recipient_id' => $buyer->id,

                    'recipient_role' => 'buyer',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'conversation.dispute_id',
                $dispute->id
            )
            ->assertJsonPath(
                'conversation.type',
                'dispute'
            )
            ->assertJsonPath(
                'conversation.participant.id',
                $buyer->id
            )
            ->assertJsonPath(
                'conversation.participant.role',
                'buyer'
            );

        $conversation =
            Conversation::query()
                ->where(
                    'dispute_id',
                    $dispute->id
                )
                ->firstOrFail();

        $this->assertDatabaseHas(
            'conversations',
            [
                'id' => $conversation->id,

                'dispute_id' => $dispute->id,

                'created_by' => $admin->id,

                'type' => 'dispute',

                'status' => 'open',
            ]
        );

        $this->assertDatabaseHas(
            'conversation_participants',
            [
                'conversation_id' => $conversation->id,

                'user_id' => $admin->id,

                'participant_role' => 'admin',
            ]
        );

        $this->assertDatabaseHas(
            'conversation_participants',
            [
                'conversation_id' => $conversation->id,

                'user_id' => $buyer->id,

                'participant_role' => 'buyer',
            ]
        );
    }

    public function test_opening_same_dispute_conversation_reuses_existing_thread(): void
    {
        $admin = $this->createAdmin();

        $buyer =
            $this->createUserWithRole(
                'buyer'
            );

        $dispute =
            $this->createDisputeWithParticipant(
                $buyer,
                'buyer'
            );

        $firstResponse = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.disputes.open',
                    $dispute
                ),
                [
                    'recipient_id' => $buyer->id,

                    'recipient_role' => 'buyer',
                ]
            );

        $firstResponse->assertOk();

        $firstConversationId =
            $firstResponse->json(
                'conversation.id'
            );

        $secondResponse = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.disputes.open',
                    $dispute
                ),
                [
                    'recipient_id' => $buyer->id,

                    'recipient_role' => 'buyer',
                ]
            );

        $secondResponse
            ->assertOk()
            ->assertJsonPath(
                'conversation.id',
                $firstConversationId
            );

        $this->assertSame(
            1,
            Conversation::query()
                ->where(
                    'dispute_id',
                    $dispute->id
                )
                ->count()
        );
    }

    public function test_non_participant_cannot_open_dispute_conversation(): void
    {
        $admin = $this->createAdmin();

        $buyer =
            $this->createUserWithRole(
                'buyer'
            );

        $otherBuyer =
            $this->createUserWithRole(
                'buyer'
            );

        $dispute =
            $this->createDisputeWithParticipant(
                $buyer,
                'buyer'
            );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.messages.disputes.open',
                    $dispute
                ),
                [
                    'recipient_id' => $otherBuyer->id,

                    'recipient_role' => 'buyer',
                ]
            );

        $response->assertNotFound();

        $this->assertDatabaseMissing(
            'conversations',
            [
                'dispute_id' => $dispute->id,
            ]
        );
    }

    public function test_inbox_endpoint_returns_only_admin_conversations_with_live_state(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        Message::query()->create([
            'conversation_id' => $conversation->id,

            'sender_id' => $admin->id,

            'body' => 'Opening note',

            'message_type' => 'text',

            'sent_at' => now()->subMinutes(5),
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,

            'sender_id' => $buyer->id,

            'body' => 'Latest buyer reply',

            'message_type' => 'text',

            'sent_at' => now(),
        ]);

        DB::table(
            'conversation_participants'
        )
            ->where(
                'conversation_id',
                $conversation->id
            )
            ->where(
                'user_id',
                $admin->id
            )
            ->update([
                'last_read_at' => now()->subMinute(),
            ]);

        $otherAdmin = $this->createAdmin();

        $otherBuyer = $this->createUserWithRole(
            'buyer'
        );

        $this->createConversation(
            $otherAdmin,
            $otherBuyer
        );

        $response = $this
            ->actingAs($admin)
            ->getJson(
                route(
                    'admin.messages.inbox'
                )
            );

        $response
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.conversations'
            )
            ->assertJsonPath(
                'data.conversations.0.id',
                $conversation->id
            )
            ->assertJsonPath(
                'data.conversations.0.preview',
                'Latest buyer reply'
            )
            ->assertJsonPath(
                'data.conversations.0.unread',
                1
            );
    }

    public function test_thread_endpoint_returns_only_messages_after_the_given_id(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $first = Message::query()->create([
            'conversation_id' => $conversation->id,

            'sender_id' => $admin->id,

            'body' => 'First message',

            'message_type' => 'text',

            'sent_at' => now()->subMinutes(2),
        ]);

        $second = Message::query()->create([
            'conversation_id' => $conversation->id,

            'sender_id' => $buyer->id,

            'body' => 'Second message',

            'message_type' => 'text',

            'sent_at' => now()->subMinute(),
        ]);

        $response = $this
            ->actingAs($admin)
            ->getJson(
                route(
                    'admin.messages.thread',
                    $conversation
                )
            );

        $response
            ->assertOk()
            ->assertJsonCount(
                2,
                'data.messages'
            )
            ->assertJsonPath(
                'data.messages.0.id',
                $first->id
            )
            ->assertJsonPath(
                'data.messages.0.from',
                'me'
            )
            ->assertJsonPath(
                'data.messages.1.from',
                'them'
            );

        $afterResponse = $this
            ->actingAs($admin)
            ->getJson(
                route(
                    'admin.messages.thread',
                    [
                        'conversation' => $conversation,
                        'after' => $first->id,
                    ]
                )
            );

        $afterResponse
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.messages'
            )
            ->assertJsonPath(
                'data.messages.0.id',
                $second->id
            )
            ->assertJsonPath(
                'data.messages.0.text',
                'Second message'
            );
    }

    public function test_thread_endpoint_is_hidden_from_non_participant_admins(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole(
            'buyer'
        );

        $conversation =
            $this->createConversation(
                $admin,
                $buyer
            );

        $outsider = $this->createAdmin();

        $this
            ->actingAs($outsider)
            ->getJson(
                route(
                    'admin.messages.thread',
                    $conversation
                )
            )
            ->assertNotFound();

        $this
            ->actingAs($outsider)
            ->getJson(
                route(
                    'admin.messages.inbox'
                )
            )
            ->assertOk()
            ->assertJsonCount(
                0,
                'data.conversations'
            );
    }
}
