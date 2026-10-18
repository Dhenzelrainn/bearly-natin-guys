<?php

namespace App\Notifications;

class AccountStatusChangedNotification extends BearlyNotification
{
    public function __construct(
        private readonly string $fromStatus,
        private readonly string $toStatus,
        private readonly string $reason,
    ) {}

    public function eventKey(): string
    {
        return 'account_status_changed';
    }

    public function title(): string
    {
        return 'Account status updated';
    }

    public function body(): string
    {
        return 'Your Bearly account status changed to '.str_replace('_', ' ', $this->toStatus).'.';
    }

    public function type(): string
    {
        return in_array($this->toStatus, ['suspended', 'rejected', 'deactivated'], true) ? 'danger' : 'success';
    }

    /**
     * Keep the historical keys (from_status/to_status/reason/message) and add
     * display keys used by the shared notification surfaces.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => $this->eventKey(),
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'reason' => $this->reason,
            'title' => $this->title(),
            'body' => $this->body(),
            'message' => $this->body(),
            'type' => $this->type(),
        ];
    }
}
