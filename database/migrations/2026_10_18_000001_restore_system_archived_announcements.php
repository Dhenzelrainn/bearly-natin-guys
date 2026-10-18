<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expired announcements are no longer hidden from their audience; they
     * stay readable until retention prunes them. Anything the old scheduled
     * cleanup archived automatically (recorded as "announcement.expired"
     * with no actor) is restored to "published" so recipients can read it
     * again. Posts an admin archived by hand have a later audit entry and
     * are left untouched.
     */
    public function up(): void
    {
        foreach ($this->systemArchivedIds('archived') as $id) {
            DB::table('announcements')
                ->where('id', $id)
                ->where('status', 'archived')
                ->update(['status' => 'published', 'updated_at' => now()]);
        }
    }

    /**
     * Approximate inverse: announcements whose latest audit entry is still
     * the automatic expiry are returned to the archived state.
     */
    public function down(): void
    {
        foreach ($this->systemArchivedIds('published') as $id) {
            DB::table('announcements')
                ->where('id', $id)
                ->where('status', 'published')
                ->update(['status' => 'archived', 'updated_at' => now()]);
        }
    }

    /** @return list<int> */
    private function systemArchivedIds(string $status): array
    {
        return DB::table('announcements')
            ->where('status', $status)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(function (int $id): bool {
                $lastAction = DB::table('audit_logs')
                    ->where('module', 'announcements')
                    ->where('auditable_id', $id)
                    ->orderByDesc('id')
                    ->value('action');

                return $lastAction === 'announcement.expired';
            })
            ->values()
            ->all();
    }
};
