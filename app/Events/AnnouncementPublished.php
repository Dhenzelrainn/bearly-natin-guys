<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnnouncementPublished implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @var list<string> */
    private const AUDIENCE_ROLES = ['buyer', 'seller', 'logistics', 'rider'];

    /**
     * @param  list<string>  $recipientRoles
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $recipientRoles,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        $roles = collect($this->recipientRoles)
            ->intersect(self::AUDIENCE_ROLES)
            ->unique()
            ->values();

        return $roles
            ->map(fn (string $role): PrivateChannel => new PrivateChannel('announcements.'.$role))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'announcement.published';
    }

    /**
     * The payload deliberately carries only announcement metadata and an
     * excerpt. Full body/media remain protected by the authenticated page and
     * feed endpoint.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
