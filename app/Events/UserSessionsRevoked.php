<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserSessionsRevoked implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'auth.sessions-revoked';
    }

    public function broadcastWith(): array
    {
        // This is only a prompt to recheck the session, not an authority to
        // log out. Never broadcast credentials or the internal session version.
        return ['scope' => 'all_devices'];
    }
}
