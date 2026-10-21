<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageReceiptUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<array{message_id: int, receipt: array<string, mixed>}>  $receipts
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly int $senderId,
        public readonly array $receipts,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->senderId)];
    }

    public function broadcastAs(): string
    {
        return 'message.receipt';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'receipts' => $this->receipts,
        ];
    }
}
