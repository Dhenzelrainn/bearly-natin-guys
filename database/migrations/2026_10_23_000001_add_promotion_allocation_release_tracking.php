<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promotion_redemption_allocations')) {
            if (! Schema::hasColumn('promotion_redemption_allocations', 'released_amount_minor')) {
                Schema::table('promotion_redemption_allocations', function (Blueprint $table): void {
                    $table->unsignedBigInteger('released_amount_minor')->default(0)->after('amount_minor');
                });
            }
            if (! Schema::hasColumn('promotion_redemption_allocations', 'released_at')) {
                Schema::table('promotion_redemption_allocations', function (Blueprint $table): void {
                    $table->timestamp('released_at')->nullable()->after('released_amount_minor');
                });
            }

            $this->ensureIndex(
                'promotion_redemption_allocations',
                ['seller_order_id', 'released_at'],
                'promo_alloc_seller_release_index',
            );
        }

        $this->ensureIndex(
            'promotion_redemptions',
            ['platform_promotion_id', 'status', 'buyer_id'],
            'promo_redemption_platform_usage_index',
        );
        $this->ensureIndex(
            'promotion_redemptions',
            ['store_promotion_id', 'status', 'buyer_id'],
            'promo_redemption_store_usage_index',
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('promotion_redemptions', 'promo_redemption_store_usage_index');
        $this->dropIndexIfExists('promotion_redemptions', 'promo_redemption_platform_usage_index');
        $this->dropIndexIfExists('promotion_redemption_allocations', 'promo_alloc_seller_release_index');

        foreach (['released_at', 'released_amount_minor'] as $column) {
            if (Schema::hasTable('promotion_redemption_allocations')
                && Schema::hasColumn('promotion_redemption_allocations', $column)) {
                Schema::table('promotion_redemption_allocations', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function ensureIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $exists = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name
                || ($index['columns'] ?? []) === $columns);
        if ($exists) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $exists = collect(Schema::getIndexes($table))->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
        if ($exists) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }
};
