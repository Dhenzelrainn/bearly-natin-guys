<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_promotions')
            && ! Schema::hasColumn('platform_promotions', 'minimum_discount_percent')) {
            Schema::table('platform_promotions', function (Blueprint $table): void {
                $table->unsignedTinyInteger('minimum_discount_percent')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_promotions')
            && Schema::hasColumn('platform_promotions', 'minimum_discount_percent')) {
            Schema::table('platform_promotions', function (Blueprint $table): void {
                $table->dropColumn('minimum_discount_percent');
            });
        }
    }
};
