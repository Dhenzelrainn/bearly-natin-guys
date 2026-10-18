<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Events\UserNotificationCreated;
use App\Models\User;
use App\Notifications\BearlyNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * Deliver a notification immediately.
     */
    public function send(User $user, BearlyNotification $notification): void
    {
        $this->deliver($user, $notification);
    }

    /**
     * Deliver a notification after the surrounding database transaction
     * commits. Runs immediately when no transaction is active, so callers can
     * use this inside or outside a transaction.
     */
    public function sendAfterCommit(User $user, BearlyNotification $notification): void
    {
        DB::afterCommit(fn () => $this->deliver($user, $notification));
    }

    /**
     * Deliver one notification to many recipients after commit, de-duplicating
     * by user id.
     *
     * @param  iterable<User|null>  $users
     */
    public function sendManyAfterCommit(iterable $users, BearlyNotification $notification): void
    {
        $recipients = [];

        foreach ($users as $user) {
            if ($user instanceof User) {
                $recipients[$user->id] = $user;
            }
        }

        if ($recipients === []) {
            return;
        }

        DB::afterCommit(function () use ($recipients, $notification) {
            foreach ($recipients as $user) {
                $this->deliver($user, $notification);
            }
        });
    }

    /**
     * Deliver through the user's enabled channels. The in-app row is written
     * before the mail attempt, and a channel failure (for example an SMTP
     * transport error) is logged instead of breaking the workflow that
     * produced the notification.
     */
    private function deliver(User $user, BearlyNotification $notification): void
    {
        try {
            $user->notify($notification);

            if (! in_array('database', $notification->via($user), true)) {
                return;
            }

            try {
                event(new UserNotificationCreated(
                    userId: (int) $user->id,
                    event: $notification->eventKey(),
                    title: $notification->title(),
                    body: $notification->body(),
                    url: $notification->actionUrl(),
                    type: $notification->type(),
                    meta: $notification->meta(),
                ));
            } catch (Throwable $broadcastException) {
                Log::notice('Bearly realtime notification broadcast failed.', [
                    'event' => $notification->eventKey(),
                    'user_id' => (int) $user->id,
                    'exception' => $broadcastException::class,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Bearly notification delivery failed.', [
                'event' => $notification->eventKey(),
                'user_id' => (int) $user->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Active Admin users, matching the platform's admin role/status convention.
     *
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('status', AccountStatus::Active->value)
            ->get();
    }
}
