<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_promotions') || ! Schema::hasColumn('platform_promotions', 'description')) {
            throw new RuntimeException('Cannot expand platform_promotions.description; run the promotion engine migration first.');
        }

        Schema::table('platform_promotions', function (Blueprint $table): void {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_promotions') || ! Schema::hasColumn('platform_promotions', 'description')) {
            return;
        }

        DB::table('platform_promotions')
            ->select(['id', 'description'])
            ->whereNotNull('description')
            ->orderBy('id')
            ->chunk(200, function ($campaigns): void {
                foreach ($campaigns as $campaign) {
                    if (mb_strlen((string) $campaign->description, 'UTF-8') > 255) {
                        throw new RuntimeException(
                            'Cannot narrow platform_promotions.description: a campaign description exceeds 255 characters.'
                        );
                    }
                }
            });

        Schema::table('platform_promotions', function (Blueprint $table): void {
            $table->string('description', 255)->nullable()->change();
        });
    }
};
