<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Events\AnnouncementPublished;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AnnouncementDeliveryService
{
    /** @var list<string> */
    private const AUDIENCE_ROLES = ['buyer', 'seller', 'logistics', 'rider'];

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Send an in-app announcement notification to active members of its
     * selected audience who have not already received this announcement.
     */
    public function deliverMissing(Announcement $announcement): int
    {
        if (
            $announcement->status !== 'published'
            || ($announcement->expires_at && $announcement->expires_at->lte(now()))
        ) {
            return 0;
        }

        $announcement->loadMissing('roles');

        $roleNames = $this->audienceRoles($announcement);

        if ($roleNames === []) {
            return 0;
        }

        $deliveredUserIds = DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('type', WorkflowNotification::class)
            ->where('data->event', 'announcement_published')
            ->where('data->meta->announcement_id', (int) $announcement->id)
            ->pluck('notifiable_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $recipients = User::query()
            ->where('status', AccountStatus::Active->value)
            ->where(function ($query) use ($roleNames): void {
                $query->whereIn('role', $roleNames)
                    ->orWhereHas(
                        'roles',
                        fn ($roleQuery) => $roleQuery->whereIn('roles.name', $roleNames)
                    );
            })
            ->when(
                $deliveredUserIds !== [],
                fn ($query) => $query->whereNotIn('id', $deliveredUserIds)
            )
            ->orderBy('id');

        $notificationBody = Str::limit(
            Str::squish((string) $announcement->body),
            240,
        );
        $notificationBody = $notificationBody !== ''
            ? $notificationBody
            : 'Open this notification to read the announcement.';

        $attempted = 0;

        $recipients->chunkById(250, function (Collection $users) use (
            $announcement,
            $notificationBody,
            &$attempted,
        ): void {
            foreach ($users as $user) {
                $this->notifications->send($user, new WorkflowNotification(
                    eventKey: 'announcement_published',
                    title: (string) $announcement->title,
                    body: $notificationBody,
                    actionUrl: route('announcements.show', $announcement),
                    type: 'info',
                    meta: [
                        'announcement_id' => (int) $announcement->id,
                        'announcement_no' => (string) $announcement->announcement_no,
                    ],
                ));

                $attempted++;
            }
        });

        return $attempted;
    }

    /**
     * Push the published announcement to the private role channels before the
     * slower per-recipient notification fan-out starts. A realtime outage is
     * logged and must never fail or roll back the published announcement.
     */
    public function broadcastPublished(Announcement $announcement): void
    {
        if ($announcement->status !== 'published') {
            return;
        }

        $announcement->loadMissing('roles');

        $roleNames = $this->audienceRoles($announcement);

        if ($roleNames === []) {
            return;
        }

        $publishedAt = $announcement->published_at ?? $announcement->publish_at;

        try {
            event(new AnnouncementPublished(
                recipientRoles: $roleNames,
                payload: [
                    'id' => (int) $announcement->id,
                    'announcement_no' => (string) $announcement->announcement_no,
                    'title' => (string) $announcement->title,
                    'excerpt' => Str::limit(Str::squish((string) $announcement->body), 180),
                    'url' => route('announcements.show', $announcement),
                    'published_at' => $publishedAt?->toIso8601String(),
                    'audience_roles' => $roleNames,
                ],
            ));
        } catch (Throwable $exception) {
            Log::notice('Bearly realtime announcement broadcast failed.', [
                'announcement_id' => (int) $announcement->id,
                'exception' => $exception::class,
            ]);
        }
    }

    /** @return list<string> */
    private function audienceRoles(Announcement $announcement): array
    {
        return $announcement->roles
            ->pluck('name')
            ->intersect(self::AUDIENCE_ROLES)
            ->unique()
            ->values()
            ->all();
    }
}
