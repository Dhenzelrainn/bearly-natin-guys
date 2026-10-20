<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail-only view of a BearlyNotification.
 *
 * NotificationService writes the in-app row and dispatches the realtime event
 * immediately, then queues SendBearlyNotificationEmail with this wrapper. The
 * wrapper only exposes the mail channel, so handling the job never writes a
 * second database notification, and the slow SMTP transport stays off the
 * request that produced the notification.
 */
class BearlyEmailNotification extends Notification
{
    public function __construct(
        private readonly BearlyNotification $notification,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->notification->toMail($notifiable);
    }
}
