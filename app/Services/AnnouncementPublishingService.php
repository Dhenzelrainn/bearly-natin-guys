<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class AnnouncementPublishingService
{
    /** @return array{published: int, archived: int} */
    public function process(): array
    {
        return DB::transaction(function (): array {
            $published = 0;
            $archived = 0;

            Announcement::query()
                ->where('status', 'scheduled')
                ->whereNotNull('publish_at')
                ->where('publish_at', '<=', now())
                ->lockForUpdate()
                ->get()
                ->each(function (Announcement $announcement) use (&$published): void {
                    $announcement->forceFill([
                        'status' => 'published',
                        'published_at' => $announcement->publish_at,
                    ])->save();
                    $this->recordAutomaticChange($announcement, 'announcement.published');
                    $published++;
                });

            Announcement::query()
                ->where('status', 'published')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get()
                ->each(function (Announcement $announcement) use (&$archived): void {
                    $announcement->forceFill(['status' => 'archived'])->save();
                    $this->recordAutomaticChange($announcement, 'announcement.expired');
                    $archived++;
                });

            return compact('published', 'archived');
        }, 3);
    }

    private function recordAutomaticChange(Announcement $announcement, string $action): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'module' => 'announcements',
            'auditable_type' => Announcement::class,
            'auditable_id' => $announcement->id,
            'description' => "Announcement {$announcement->announcement_no} changed automatically to {$announcement->status}.",
            'new_values' => ['status' => $announcement->status],
            'severity' => 'info',
            'created_at' => now(),
        ]);
    }
}
