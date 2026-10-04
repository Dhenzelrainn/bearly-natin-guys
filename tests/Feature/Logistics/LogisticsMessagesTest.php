<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\LogisticsProfile;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LogisticsMessagesTest extends TestCase
{
    use RefreshDatabase;

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
}