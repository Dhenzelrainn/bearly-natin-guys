<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->string('archive_reason', 20)->nullable()->after('status');
            $table->index(['status', 'archive_reason'], 'announcements_status_archive_reason_index');
        });
    }

    public function down(): void
    {
        DB::table('announcements')
            ->where('archive_reason', 'retention')
            ->update([
                'status' => 'published',
                'archive_reason' => null,
            ]);

        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropIndex('announcements_status_archive_reason_index');
            $table->dropColumn('archive_reason');
        });
    }
};
