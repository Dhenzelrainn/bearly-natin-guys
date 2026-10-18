<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class BearlyNotification extends Notification
{
    use Queueable;

    /**
     * Registry key from config/notifications.php.
     */
    abstract public function eventKey(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    public function type(): string
    {
        return 'info';
    }

    public function actionUrl(): ?string
    {
        return null;
    }

    /**
     * Extra machine-readable context stored with the notification.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [];
    }

    /**
     * Channel selection honours the recipient's stored preferences for the
     * event. Mandatory events always deliver in-app. A user who disabled both
     * channels receives nothing for a non-mandatory event.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        $channels = app(NotificationPreferenceService::class)->channelsFor($notifiable, $this->eventKey());

        $via = [];

        if ($channels['in_app']) {
            $via[] = 'database';
        }

        if ($channels['email']) {
            $via[] = 'mail';
        }

        return $via;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return array_filter([
            'event' => $this->eventKey(),
            'title' => $this->title(),
            'body' => $this->body(),
            'message' => $this->body(),
            'url' => $this->actionUrl(),
            'type' => $this->type(),
            'meta' => $this->meta() ?: null,
        ], fn ($value) => $value !== null);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->salutation('— Bearly');

        if ($url = $this->actionUrl()) {
            $message->action('Open in Bearly', url($url));
        }

        return $message;
    }
}
