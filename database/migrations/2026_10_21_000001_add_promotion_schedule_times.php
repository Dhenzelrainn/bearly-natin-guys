<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('store_promotions', 'starts_time', fn (Blueprint $table) => $table->time('starts_time')->default('00:00:00'));
        $this->addColumnIfMissing('store_promotions', 'ends_time', fn (Blueprint $table) => $table->time('ends_time')->default('23:59:59'));
        $this->addColumnIfMissing('store_promotions', 'buyer_campaign_name', fn (Blueprint $table) => $table->string('buyer_campaign_name', 80)->nullable());

        $this->addColumnIfMissing('platform_promotions', 'starts_time', fn (Blueprint $table) => $table->time('starts_time')->default('00:00:00'));
        $this->addColumnIfMissing('platform_promotions', 'ends_time', fn (Blueprint $table) => $table->time('ends_time')->default('23:59:59'));
    }

    public function down(): void
    {
        foreach ([
            'store_promotions' => ['starts_time', 'ends_time', 'buyer_campaign_name'],
            'platform_promotions' => ['starts_time', 'ends_time'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
                }
            }
        }
    }

    private function addColumnIfMissing(string $table, string $column, Closure $definition): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $definition($blueprint));
    }
};
