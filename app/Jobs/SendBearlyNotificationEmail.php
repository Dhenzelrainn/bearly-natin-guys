<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\BearlyEmailNotification;
use App\Notifications\BearlyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends only the email channel of a Bearly notification.
 *
 * NotificationService writes the in-app row and dispatches the realtime event
 * immediately, then hands the slow SMTP transport to this queued job. The
 * mail-only wrapper delegates to the original notification's toMail() so the
 * subject, body, and action link stay identical to the previous synchronous
 * delivery.
 */
class SendBearlyNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly BearlyNotification $notification,
    ) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notifyNow(
                new BearlyEmailNotification($this->notification),
                ['mail'],
            );
        } catch (Throwable $mailException) {
            Log::warning('Bearly notification delivery failed.', [
                'event' => $this->notification->eventKey(),
                'user_id' => $this->userId,
                'exception' => $mailException::class,
                'message' => $mailException->getMessage(),
            ]);
        }
    }
}
