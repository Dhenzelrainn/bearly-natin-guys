<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sorting_zones', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('dispatch_batches', function (Blueprint $table): void {
            $table->dropForeign(['sorting_zone_id']);
            $table->foreign('sorting_zone_id')
                ->references('id')
                ->on('sorting_zones')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_batches', function (Blueprint $table): void {
            $table->dropForeign(['sorting_zone_id']);
            $table->foreign('sorting_zone_id')
                ->references('id')
                ->on('sorting_zones')
                ->restrictOnDelete();
        });

        Schema::table('sorting_zones', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
