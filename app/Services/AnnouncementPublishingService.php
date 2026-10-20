<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementMedia;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class AnnouncementPublishingService
{
    /**
     * Number of published announcements retained after delivery. When more
     * than this many posts exist, the oldest posts whose expiry has already
     * passed are removed automatically so the recipient history does not
     * grow without bound. Posts without an expiry are never pruned.
     */
    private const RETENTION_LIMIT = 10;

    public function __construct(
        private readonly AnnouncementDeliveryService $delivery,
        private readonly AnnouncementMediaService $mediaService,
    ) {}

    /** @return array{published: int, removed: int} */
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
            'removed' => $this->pruneExpiredAnnouncements(),
        ];
    }

    /**
     * Remove the oldest expired published announcements while more than
     * RETENTION_LIMIT published posts exist. Expired posts remain readable
     * until they are pruned; drafts, scheduled, manually archived, and
     * unexpired announcements are never removed automatically.
     */
    private function pruneExpiredAnnouncements(): int
    {
        $removedFiles = [];
        $removed = 0;

        DB::transaction(function () use (&$removed, &$removedFiles): void {
            while (Announcement::query()->where('status', 'published')->count() > self::RETENTION_LIMIT) {
                $oldestExpired = Announcement::query()
                    ->with('media')
                    ->where('status', 'published')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())
                    ->orderByRaw('COALESCE(published_at, publish_at) ASC')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $oldestExpired) {
                    break;
                }

                foreach ($oldestExpired->media as $media) {
                    $removedFiles[] = [
                        'disk' => (string) $media->disk,
                        'path' => (string) $media->file_path,
                    ];
                }

                $announcementId = (int) $oldestExpired->id;
                $announcementNo = (string) $oldestExpired->announcement_no;
                $oldValues = $oldestExpired->attributesToArray();
                $oldValues['media_summary'] = $oldestExpired->media
                    ->map(fn (AnnouncementMedia $media): array => [
                        'id' => (int) $media->id,
                        'media_type' => (string) $media->media_type,
                        'original_name' => (string) $media->original_name,
                    ])
                    ->values()
                    ->all();

                $oldestExpired->delete();

                AuditLog::query()->create([
                    'action' => 'announcement.auto_deleted',
                    'module' => 'announcements',
                    'auditable_type' => Announcement::class,
                    'auditable_id' => $announcementId,
                    'description' => "Announcement {$announcementNo} was removed automatically after expiring to keep the latest ".self::RETENTION_LIMIT.' published announcements.',
                    'old_values' => $oldValues,
                    'new_values' => null,
                    'severity' => 'info',
                    'created_at' => now(),
                ]);

                $removed++;
            }
        }, 3);

        $this->mediaService->deleteFiles($removedFiles);

        return $removed;
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
