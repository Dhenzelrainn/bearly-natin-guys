<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $roleNames = $announcement->roles
            ->pluck('name')
            ->intersect(self::AUDIENCE_ROLES)
            ->values()
            ->all();

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
}
