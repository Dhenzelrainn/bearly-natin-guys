<?php

namespace Tests\Feature\Rider;

use App\Models\Conversation;
use App\Models\LogisticsProfile;
use App\Models\Message;
use App\Models\RiderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RiderMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_inbox_uses_admin_and_logistics_workflows_and_only_lists_participating_threads(): void
    {
        $admin = $this->user('admin', 'Bearly Administrator');
        $provider = $this->logisticsProvider();
        $rider = $this->rider($provider['profile'], 'Juan Rider');
        $otherRider = $this->user('rider', 'Other Rider');

        $adminConversation = $this->startAdminConversation(
            $admin,
            $rider,
            'Support handover',
            'Please confirm the pickup window.'
        );

        $logisticsResponse = $this->actingAs($provider['user'])->postJson(
            route('logistics.messages.conversations.store'),
            [
                'recipient_id' => $rider->id,
                'subject' => 'Dispatch handover',
                'message' => 'Meet the coordinator at Bay 4.',
            ]
        );

        $logisticsResponse->assertCreated();
        $logisticsConversationId = (int) $logisticsResponse->json('conversation.id');

        $this->startAdminConversation(
            $admin,
            $otherRider,
            'Private conversation',
            'This message belongs to another rider.'
        );

        $response = $this->actingAs($rider)->get(route(
            'rider.messages.index',
            ['conversation' => $logisticsConversationId]
        ));

        $response->assertOk()
            ->assertSee('Bearly Administrator')
            ->assertSee('Logistics Provider')
            ->assertSee('Please confirm the pickup window.')
            ->assertSee('Meet the coordinator at Bay 4.')
            ->assertDontSee('This message belongs to another rider.')
            ->assertSee('data-unread="1"', false)
            ->assertSee('class="conversation is-active"', false);

        $this->assertSame(2, substr_count($response->getContent(), 'data-conversation='));

        $this->actingAs($rider)
            ->postJson('/rider/messages/conversations', [
                'recipient_id' => $otherRider->id,
                'message' => 'Start a conversation',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $adminConversation->id,
            'user_id' => $rider->id,
            'participant_role' => 'rider',
        ]);
    }

    public function test_rider_can_read_and_reply_only_to_a_participating_conversation(): void
    {
        $admin = $this->user('admin', 'Bearly Administrator');
        $rider = $this->user('rider', 'Juan Rider');
        $otherRider = $this->user('rider', 'Other Rider');

        $conversation = $this->startAdminConversation(
            $admin,
            $rider,
            'Delivery instructions',
            'Please call the recipient on arrival.'
        );

        $this->actingAs($rider)
            ->getJson(route('rider.messages.thread', $conversation))
            ->assertOk()
            ->assertJsonPath('data.messages.0.mine', false)
            ->assertJsonPath('data.messages.0.text', 'Please call the recipient on arrival.');

        $this->actingAs($rider)
            ->get(route('rider.messages.index'))
            ->assertOk()
            ->assertSee('data-unread="1"', false);

        $this->actingAs($rider)
            ->postJson(route('rider.messages.read', $conversation))
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $rider->id,
        ]);

        $this->assertNotNull(
            $conversation->participants()
                ->where('users.id', $rider->id)
                ->firstOrFail()
                ->pivot
                ->last_read_at
        );

        $this->actingAs($rider)
            ->get(route('rider.messages.index'))
            ->assertOk()
            ->assertSee('data-unread="0"', false);

        $this->actingAs($rider)
            ->postJson(route('rider.messages.send', $conversation), [
                'message' => '  I will call on arrival.  ',
            ])
            ->assertOk()
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.text', 'I will call on arrival.');

        $reply = Message::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($rider->id, (int) $reply->sender_id);
        $this->assertSame('I will call on arrival.', $reply->body);
        $this->assertTrue(
            $conversation->fresh()->last_message_at->greaterThanOrEqualTo($reply->sent_at)
        );

        $this->actingAs($otherRider)
            ->getJson(route('rider.messages.thread', $conversation))
            ->assertNotFound();

        $this->actingAs($otherRider)
            ->postJson(route('rider.messages.read', $conversation))
            ->assertNotFound();

        $this->actingAs($otherRider)
            ->postJson(route('rider.messages.send', $conversation), [])
            ->assertNotFound();

        $this->assertSame(2, Message::query()->where('conversation_id', $conversation->id)->count());

        $conversation->forceFill(['status' => 'closed'])->save();

        $this->actingAs($rider)
            ->postJson(route('rider.messages.send', $conversation), [
                'message' => 'A late reply',
            ])
            ->assertStatus(409);
    }

    public function test_rider_can_send_and_download_attachments_only_from_participating_threads(): void
    {
        Storage::fake('local');

        $admin = $this->user('admin', 'Bearly Administrator');
        $rider = $this->user('rider', 'Juan Rider');
        $otherRider = $this->user('rider', 'Other Rider');

        $conversation = $this->startAdminConversation(
            $admin,
            $rider,
            'Proof of delivery',
            'Send the signed receipt when available.'
        );

        $this->actingAs($rider)->post(
            route('rider.messages.send', $conversation),
            ['attachment' => UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('data.attachments.0.name', 'receipt.pdf');

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('attachment', $message->message_type);

        $attachment = $message->attachments()->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($attachment->file_path));

        $this->actingAs($rider)
            ->get(route('rider.messages.attachments.download', $attachment))
            ->assertOk();

        $this->actingAs($otherRider)
            ->get(route('rider.messages.attachments.download', $attachment))
            ->assertNotFound();

        $this->actingAs($rider)
            ->post(
                route('rider.messages.send', $conversation),
                ['attachment' => UploadedFile::fake()->create('payload.php', 4, 'text/x-php')],
                ['Accept' => 'application/json'],
            )
            ->assertUnprocessable();
    }

    private function user(string $role, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /** @return array{user: User, profile: LogisticsProfile} */
    private function logisticsProvider(): array
    {
        $user = $this->user('logistics', 'Logistics Provider');

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Logistics Provider Inc.',
            'display_name' => 'Logistics Provider',
            'contact_phone' => '09170000001',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function rider(LogisticsProfile $profile, string $name): User
    {
        $user = $this->user('rider', $name);

        RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $profile->id,
            'vehicle_type' => 'Motorcycle',
            'verification_status' => 'approved',
        ]);

        return $user;
    }

    private function startAdminConversation(
        User $admin,
        User $rider,
        string $subject,
        string $message
    ): Conversation {
        $response = $this->actingAs($admin)->postJson(
            route('admin.messages.conversations.store'),
            [
                'recipient_id' => $rider->id,
                'recipient_role' => 'rider',
                'subject' => $subject,
                'message' => $message,
            ]
        );

        $response->assertCreated();

        return Conversation::query()->findOrFail(
            (int) $response->json('conversation.id')
        );
    }
}
