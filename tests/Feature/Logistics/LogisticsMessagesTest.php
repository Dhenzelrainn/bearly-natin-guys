<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\LogisticsProfile;
use App\Models\Message;
use App\Models\User;
use App\Models\Address;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\SellerProfile;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogisticsMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_conversation_directory_is_provider_scoped(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'DIRECTORY'
            );

        $admin =
            User::factory()->create([
                'name' =>
                    'Messaging Admin',

                'email' =>
                    'messaging-admin@example.test',

                'role' =>
                    UserRole::Admin->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $inactiveAdmin =
            User::factory()->create([
                'name' =>
                    'Inactive Admin',

                'email' =>
                    'inactive-messaging-admin@example.test',

                'role' =>
                    UserRole::Admin->value,

                'status' =>
                    'suspended',
            ]);

        $relatedSeller =
            $this->makeMessageSeller(
                'RELATED',
                $operator
            );

        $unrelatedSeller =
            $this->makeMessageSeller(
                'UNRELATED'
            );

        $ownRider =
            $this->makeMessageRider(
                $operator,
                'OWN'
            );

        $foreignOperator =
            $this->makeLogisticsOperator(
                'FOREIGN-DIRECTORY'
            );

        $foreignRider =
            $this->makeMessageRider(
                $foreignOperator,
                'FOREIGN'
            );

        $response = $this
            ->actingAs($operator)
            ->get(
                route(
                    'logistics.messages.index'
                )
            );

        $response
            ->assertOk()
            ->assertViewHas(
                'messageRecipients',
                function (
                    array $recipients
                ) use (
                    $admin,
                    $inactiveAdmin,
                    $relatedSeller,
                    $unrelatedSeller,
                    $ownRider,
                    $foreignRider
                ): bool {
                    $ids = collect(
                        $recipients
                    )
                        ->pluck('id')
                        ->map(
                            fn ($id) =>
                                (int) $id
                        );

                    return
                        $ids->contains(
                            $admin->id
                        )
                        && $ids->contains(
                            $relatedSeller->id
                        )
                        && $ids->contains(
                            $ownRider->id
                        )
                        && ! $ids->contains(
                            $inactiveAdmin->id
                        )
                        && ! $ids->contains(
                            $unrelatedSeller->id
                        )
                        && ! $ids->contains(
                            $foreignRider->id
                        );
                }
            );
    }

    public function test_logistics_can_start_conversation_with_related_seller(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'CREATE'
            );

        $seller =
            $this->makeMessageSeller(
                'CREATE',
                $operator
            );

        $response = $this
            ->actingAs($operator)
            ->postJson(
                route(
                    'logistics.messages.conversations.store'
                ),
                [
                    'recipient_id' =>
                        $seller->id,

                    'subject' =>
                        'Pickup coordination',

                    'message' =>
                        'Please confirm the pickup window.',
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'conversation.recipient_id',
                $seller->id
            )
            ->assertJsonPath(
                'conversation.recipient_role',
                UserRole::Seller->value
            )
            ->assertJsonPath(
                'conversation.latest_message',
                'Please confirm the pickup window.'
            );

        $conversation =
            Conversation::query()
                ->latest('id')
                ->firstOrFail();

        $this->assertDatabaseHas(
            'conversations',
            [
                'id' =>
                    $conversation->id,

                'created_by' =>
                    $operator->id,

                'subject' =>
                    'Pickup coordination',

                'type' =>
                    'direct',

                'status' =>
                    'open',
            ]
        );

        $this->assertDatabaseHas(
            'conversation_participants',
            [
                'conversation_id' =>
                    $conversation->id,

                'user_id' =>
                    $operator->id,

                'participant_role' =>
                    UserRole::Logistics->value,
            ]
        );

        $this->assertDatabaseHas(
            'conversation_participants',
            [
                'conversation_id' =>
                    $conversation->id,

                'user_id' =>
                    $seller->id,

                'participant_role' =>
                    UserRole::Seller->value,
            ]
        );

        $this->assertDatabaseHas(
            'messages',
            [
                'conversation_id' =>
                    $conversation->id,

                'sender_id' =>
                    $operator->id,

                'body' =>
                    'Please confirm the pickup window.',

                'message_type' =>
                    'text',
            ]
        );
    }

    public function test_logistics_cannot_start_conversation_with_disallowed_contacts(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'DENIED'
            );

        $unrelatedSeller =
            $this->makeMessageSeller(
                'DENIED'
            );

        $foreignOperator =
            $this->makeLogisticsOperator(
                'FOREIGN-DENIED'
            );

        $foreignRider =
            $this->makeMessageRider(
                $foreignOperator,
                'DENIED'
            );

        $inactiveAdmin =
            User::factory()->create([
                'name' =>
                    'Disabled Admin',

                'email' =>
                    'disabled-admin@example.test',

                'role' =>
                    UserRole::Admin->value,

                'status' =>
                    'suspended',
            ]);

        foreach (
            [
                $operator,
                $unrelatedSeller,
                $foreignRider,
                $inactiveAdmin,
            ]
            as $recipient
        ) {
            $response = $this
                ->actingAs($operator)
                ->postJson(
                    route(
                        'logistics.messages.conversations.store'
                    ),
                    [
                        'recipient_id' =>
                            $recipient->id,

                        'message' =>
                            'Unauthorized conversation.',
                    ]
                );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors(
                    'recipient_id'
                );
        }

        $this->assertDatabaseCount(
            'conversations',
            0
        );

        $this->assertDatabaseCount(
            'messages',
            0
        );
    }

    public function test_logistics_participant_can_send_message(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'SEND'
            );

        $seller =
            User::factory()->create([
                'name' =>
                    'Reply Seller',

                'email' =>
                    'reply-seller@example.test',

                'role' =>
                    UserRole::Seller->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $conversation =
            Conversation::query()->create([
                'subject' =>
                    'Pickup reply',

                'type' =>
                    'direct',

                'status' =>
                    'open',

                'created_by' =>
                    $seller->id,

                'last_message_at' =>
                    now()->subMinute(),
            ]);

        $conversation
            ->participants()
            ->attach(
                $operator->id,
                [
                    'participant_role' =>
                        'logistics',

                    'last_read_at' =>
                        null,

                    'joined_at' =>
                        now()->subHour(),
                ]
            );

        $conversation
            ->participants()
            ->attach(
                $seller->id,
                [
                    'participant_role' =>
                        'seller',

                    'last_read_at' =>
                        null,

                    'joined_at' =>
                        now()->subHour(),
                ]
            );

        $response = $this
            ->actingAs($operator)
            ->postJson(
                route(
                    'logistics.messages.send',
                    $conversation
                ),
                [
                    'message' =>
                        'Rider is already assigned.',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.mine',
                true
            )
            ->assertJsonPath(
                'data.text',
                'Rider is already assigned.'
            );

        $this->assertDatabaseHas(
            'messages',
            [
                'conversation_id' =>
                    $conversation->id,

                'sender_id' =>
                    $operator->id,

                'body' =>
                    'Rider is already assigned.',

                'message_type' =>
                    'text',
            ]
        );

        $conversation->refresh();

        $this->assertNotNull(
            $conversation
                ->last_message_at
        );

        $participant =
            DB::table(
                'conversation_participants'
            )
                ->where(
                    'conversation_id',
                    $conversation->id
                )
                ->where(
                    'user_id',
                    $operator->id
                )
                ->first();

        $this->assertNotNull(
            $participant->last_read_at
        );
    }

    public function test_logistics_cannot_send_to_conversation_it_does_not_belong_to(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'OUTSIDER'
            );

        $foreignOperator =
            $this->makeLogisticsOperator(
                'OWNER'
            );

        $seller =
            User::factory()->create([
                'name' =>
                    'Foreign Seller',

                'email' =>
                    'foreign-message-seller@example.test',

                'role' =>
                    UserRole::Seller->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $conversation =
            Conversation::query()->create([
                'type' =>
                    'direct',

                'status' =>
                    'open',

                'created_by' =>
                    $foreignOperator->id,

                'last_message_at' =>
                    null,
            ]);

        $conversation
            ->participants()
            ->attach(
                $foreignOperator->id,
                [
                    'participant_role' =>
                        'logistics',

                    'joined_at' =>
                        now(),
                ]
            );

        $conversation
            ->participants()
            ->attach(
                $seller->id,
                [
                    'participant_role' =>
                        'seller',

                    'joined_at' =>
                        now(),
                ]
            );

        $response = $this
            ->actingAs($operator)
            ->postJson(
                route(
                    'logistics.messages.send',
                    $conversation
                ),
                [
                    'message' =>
                        'Unauthorized reply',
                ]
            );

        $response->assertNotFound();

        $this->assertDatabaseMissing(
            'messages',
            [
                'conversation_id' =>
                    $conversation->id,

                'sender_id' =>
                    $operator->id,

                'body' =>
                    'Unauthorized reply',
            ]
        );
    }

    public function test_logistics_cannot_send_to_closed_conversation(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'CLOSED'
            );

        $seller =
            User::factory()->create([
                'name' =>
                    'Closed Seller',

                'email' =>
                    'closed-message-seller@example.test',

                'role' =>
                    UserRole::Seller->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $conversation =
            Conversation::query()->create([
                'type' =>
                    'direct',

                'status' =>
                    'closed',

                'created_by' =>
                    $seller->id,

                'last_message_at' =>
                    null,
            ]);

        $conversation
            ->participants()
            ->attach(
                $operator->id,
                [
                    'participant_role' =>
                        'logistics',

                    'joined_at' =>
                        now(),
                ]
            );

        $conversation
            ->participants()
            ->attach(
                $seller->id,
                [
                    'participant_role' =>
                        'seller',

                    'joined_at' =>
                        now(),
                ]
            );

        $response = $this
            ->actingAs($operator)
            ->postJson(
                route(
                    'logistics.messages.send',
                    $conversation
                ),
                [
                    'message' =>
                        'Should not be sent',
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
                'conversation_id' =>
                    $conversation->id,

                'body' =>
                    'Should not be sent',
            ]
        );
    }

    public function test_logistics_reply_requires_message_body(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'EMPTY-REPLY'
            );

        $seller =
            User::factory()->create([
                'name' =>
                    'Validation Seller',

                'email' =>
                    'validation-message-seller@example.test',

                'role' =>
                    UserRole::Seller->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $conversation =
            Conversation::query()->create([
                'type' =>
                    'direct',

                'status' =>
                    'open',

                'created_by' =>
                    $seller->id,

                'last_message_at' =>
                    null,
            ]);

        $conversation
            ->participants()
            ->attach(
                $operator->id,
                [
                    'participant_role' =>
                        'logistics',

                    'joined_at' =>
                        now(),
                ]
            );

        $conversation
            ->participants()
            ->attach(
                $seller->id,
                [
                    'participant_role' =>
                        'seller',

                    'joined_at' =>
                        now(),
                ]
            );

        $response = $this
            ->actingAs($operator)
            ->postJson(
                route(
                    'logistics.messages.send',
                    $conversation
                ),
                [
                    'message' => '',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'message'
            );

        $this->assertDatabaseCount(
            'messages',
            0
        );
    }

    public function test_messages_page_uses_only_authenticated_logistics_conversations(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'OWN'
            );

        $foreignOperator =
            $this->makeLogisticsOperator(
                'FOREIGN'
            );

        $seller =
            User::factory()->create([
                'name' =>
                    'Seller One',

                'email' =>
                    'message-seller@example.test',

                'role' =>
                    UserRole::Seller->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $foreignRider =
            User::factory()->create([
                'name' =>
                    'Foreign Rider',

                'email' =>
                    'message-foreign-rider@example.test',

                'role' =>
                    UserRole::Rider->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $firstMessageAt =
            Carbon::parse(
                '2026-10-01 10:00:00'
            );

        $replyAt =
            Carbon::parse(
                '2026-10-01 10:05:00'
            );

        $latestMessageAt =
            Carbon::parse(
                '2026-10-01 10:10:00'
            );

        $conversation =
            Conversation::query()->create([
                'subject' =>
                    'Pickup coordination',

                'type' =>
                    'direct',

                'status' =>
                    'open',

                'created_by' =>
                    $seller->id,

                'last_message_at' =>
                    $latestMessageAt,
            ]);

        $conversation
            ->participants()
            ->attach(
                $operator->id,
                [
                    'participant_role' =>
                        'logistics',

                    'last_read_at' =>
                        $replyAt,

                    'joined_at' =>
                        $firstMessageAt,
                ]
            );

        $conversation
            ->participants()
            ->attach(
                $seller->id,
                [
                    'participant_role' =>
                        'seller',

                    'last_read_at' =>
                        null,

                    'joined_at' =>
                        $firstMessageAt,
                ]
            );

        Message::query()->create([
            'conversation_id' =>
                $conversation->id,

            'sender_id' =>
                $seller->id,

            'body' =>
                'First inbound',

            'message_type' =>
                'text',

            'sent_at' =>
                $firstMessageAt,
        ]);

        Message::query()->create([
            'conversation_id' =>
                $conversation->id,

            'sender_id' =>
                $operator->id,

            'body' =>
                'Logistics reply',

            'message_type' =>
                'text',

            'sent_at' =>
                $replyAt,
        ]);

        Message::query()->create([
            'conversation_id' =>
                $conversation->id,

            'sender_id' =>
                $seller->id,

            'body' =>
                'Second inbound',

            'message_type' =>
                'text',

            'sent_at' =>
                $latestMessageAt,
        ]);

        $foreignConversation =
            Conversation::query()->create([
                'subject' =>
                    'Foreign conversation',

                'type' =>
                    'direct',

                'status' =>
                    'open',

                'created_by' =>
                    $foreignOperator->id,

                'last_message_at' =>
                    $latestMessageAt,
            ]);

        $foreignConversation
            ->participants()
            ->attach(
                $foreignOperator->id,
                [
                    'participant_role' =>
                        'logistics',

                    'last_read_at' =>
                        null,

                    'joined_at' =>
                        $firstMessageAt,
                ]
            );

        $foreignConversation
            ->participants()
            ->attach(
                $foreignRider->id,
                [
                    'participant_role' =>
                        'rider',

                    'last_read_at' =>
                        null,

                    'joined_at' =>
                        $firstMessageAt,
                ]
            );

        Message::query()->create([
            'conversation_id' =>
                $foreignConversation->id,

            'sender_id' =>
                $foreignRider->id,

            'body' =>
                'Foreign message',

            'message_type' =>
                'text',

            'sent_at' =>
                $latestMessageAt,
        ]);

        $response = $this
            ->actingAs($operator)
            ->get(
                route(
                    'logistics.messages.index'
                )
            );

        $response
            ->assertOk()
            ->assertViewHas(
                'conversations',
                function (
                    array $items
                ) use (
                    $conversation
                ): bool {
                    if (
                        count($items)
                        !== 1
                    ) {
                        return false;
                    }

                    $item = $items[0];

                    return
                        $item['id']
                            === $conversation->id

                        && $item['name']
                            === 'Seller One'

                        && $item['role']
                            === 'Seller'

                        && $item['preview']
                            === 'Second inbound'

                        && $item['unread']
                            === 1

                        && count(
                            $item['messages']
                        ) === 3

                        && $item['messages'][0]['mine']
                            === false

                        && $item['messages'][0]['text']
                            === 'First inbound'

                        && $item['messages'][1]['mine']
                            === true

                        && $item['messages'][1]['text']
                            === 'Logistics reply'

                        && $item['messages'][2]['mine']
                            === false

                        && $item['messages'][2]['text']
                            === 'Second inbound';
                }
            )
            ->assertDontSee(
                'Foreign Rider'
            )
            ->assertDontSee(
                'Foreign message'
            );
    }

    public function test_messages_page_is_zero_safe_without_conversations(): void
    {
        $operator =
            $this->makeLogisticsOperator(
                'EMPTY'
            );

        $response = $this
            ->actingAs($operator)
            ->get(
                route(
                    'logistics.messages.index'
                )
            );

        $response
            ->assertOk()
            ->assertViewHas(
                'conversations',
                []
            )
            ->assertViewHas(
                'conversationData',
                []
            )
            ->assertSee(
                'No conversations yet.'
            );
    }

    private function makeLogisticsOperator(
        string $suffix
    ): User {
        $user =
            User::factory()->create([
                'name' =>
                    "Message Logistics {$suffix}",

                'email' =>
                    'message-logistics-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    UserRole::Logistics->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        LogisticsProfile::query()->create([
            'user_id' =>
                $user->id,

            'legal_name' =>
                "Message Logistics {$suffix} Incorporated",

            'display_name' =>
                "Message Logistics {$suffix}",

            'contact_phone' =>
                '09170000000',

            'status' =>
                'active',
        ]);

        return $user;
    }

    private function makeMessageSeller(
        string $suffix,
        ?User $relatedOperator = null
    ): User {
        $seller =
            User::factory()->create([
                'name' =>
                    "Message Seller {$suffix}",

                'email' =>
                    'message-seller-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    UserRole::Seller->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $sellerProfile =
            SellerProfile::query()->create([
                'user_id' =>
                    $seller->id,

                'legal_business_name' =>
                    "Message Seller {$suffix} Trading",

                'standing_status' =>
                    'good_standing',
            ]);

        $store =
            Store::query()->create([
                'seller_profile_id' =>
                    $sellerProfile->id,

                'name' =>
                    "Message Store {$suffix}",

                'slug' =>
                    'message-store-'
                    .strtolower($suffix),

                'publication_status' =>
                    'published',
            ]);

        if ($relatedOperator) {
            $address =
                Address::query()->create([
                    'user_id' =>
                        $seller->id,

                    'label' =>
                        'Pickup address',

                    'recipient_name' =>
                        $seller->name,

                    'phone' =>
                        '09175555555',

                    'house_number' =>
                        '15',

                    'street' =>
                        'Seller Street',

                    'barangay' =>
                        'San Antonio',

                    'city_municipality' =>
                        'San Pablo City',

                    'province' =>
                        'Laguna',

                    'postal_code' =>
                        '4000',
                ]);

            PickupRequest::query()->create([
                'pickup_no' =>
                    'MSG-PU-'
                    .strtoupper($suffix),

                'store_id' =>
                    $store->id,

                'logistics_profile_id' =>
                    $relatedOperator
                        ->logisticsProfile
                        ->id,

                'pickup_address_id' =>
                    $address->id,

                'status' =>
                    'requested',

                'requested_date' =>
                    now()->toDateString(),

                'window_start' =>
                    now()->addHour(),

                'window_end' =>
                    now()->addHours(3),
            ]);
        }

        return $seller;
    }

    private function makeMessageRider(
        User $operator,
        string $suffix
    ): User {
        $rider =
            User::factory()->create([
                'name' =>
                    "Message Rider {$suffix}",

                'email' =>
                    'message-rider-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    UserRole::Rider->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        RiderProfile::query()->create([
            'user_id' =>
                $rider->id,

            'logistics_profile_id' =>
                $operator
                    ->logisticsProfile
                    ->id,

            'vehicle_type' =>
                'Motorcycle',

            'availability_status' =>
                'offline',

            'verification_status' =>
                'approved',
        ]);

        return $rider;
    }
}