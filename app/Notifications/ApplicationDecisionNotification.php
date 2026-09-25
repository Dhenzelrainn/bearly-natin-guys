<?php

namespace App\Notifications;

use App\Models\AccountApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ApplicationDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly AccountApplication $application,
        private readonly string $decision,
        private readonly ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_no' => $this->application->application_no,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'message' => match ($this->decision) {
                'approved' => 'Your Bearly account application was approved.',
                'rejected' => 'Your Bearly account application was rejected.',
                default => 'Your Bearly account application needs revision.',
            },
        ];
    }
}
