<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class AnnouncementPublishingService
{
    /** Maximum number of announcements kept in the active published feed. */
    private const RETENTION_LIMIT = 10;

    public function __construct(
        private readonly AnnouncementDeliveryService $delivery,
    ) {}

    /** @return array{published: int, archived: int} */
    public function process(): array
    {
        $result = DB::transaction(function (): array {
            $published = 0;
            $publishedIds = [];

            Announcement::query()
                ->where('status', 'scheduled')
                ->whereNotNull('publish_at')
                ->where('publish_at', '<=', now())
                ->lockForUpdate()
                ->get()
                ->each(function (Announcement $announcement) use (&$published, &$publishedIds): void {
                    $announcement->forceFill([
                        'status' => 'published',
                        'published_at' => $announcement->publish_at,
                    ])->save();
                    $this->recordAutomaticChange($announcement, 'announcement.published');
                    $published++;
                    $publishedIds[] = (int) $announcement->id;
                });

            return compact('published', 'publishedIds');
        }, 3);

        if ($result['publishedIds'] !== []) {
            Announcement::query()
                ->with('roles')
                ->whereIn('id', $result['publishedIds'])
                ->orderBy('id')
                ->get()
                ->each(function (Announcement $announcement): void {
                    $this->delivery->broadcastPublished($announcement);
                    $this->delivery->deliverMissing($announcement);
                });
        }

        return [
            'published' => $result['published'],
            'archived' => $this->archiveOverflowAnnouncements(),
        ];
    }

    /**
     * Keep the active feed bounded without deleting announcement content or
     * media. Retention-archived posts remain readable to their original
     * audience; manual archives remain hidden.
     */
    private function archiveOverflowAnnouncements(): int
    {
        if (Announcement::query()->where('status', 'published')->count() <= self::RETENTION_LIMIT) {
            return 0;
        }

        return DB::transaction(function (): int {
            $published = Announcement::query()
                ->where('status', 'published')
                ->orderByRaw('COALESCE(published_at, publish_at) ASC')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $overflow = max(0, $published->count() - self::RETENTION_LIMIT);
            $archived = 0;

            foreach ($published->take($overflow) as $announcement) {
                $oldValues = [
                    'status' => $announcement->status,
                    'archive_reason' => $announcement->archive_reason,
                ];
                $announcement->forceFill([
                    'status' => 'archived',
                    'archive_reason' => 'retention',
                ])->save();

                AuditLog::query()->create([
                    'action' => 'announcement.auto_archived',
                    'module' => 'announcements',
                    'auditable_type' => Announcement::class,
                    'auditable_id' => $announcement->id,
                    'description' => "Announcement {$announcement->announcement_no} was archived automatically to keep the latest ".self::RETENTION_LIMIT.' published announcements.',
                    'old_values' => $oldValues,
                    'new_values' => [
                        'status' => 'archived',
                        'archive_reason' => 'retention',
                        'announcement_no' => $announcement->announcement_no,
                    ],
                    'severity' => 'info',
                    'created_at' => now(),
                ]);

                $archived++;
            }

            return $archived;
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
