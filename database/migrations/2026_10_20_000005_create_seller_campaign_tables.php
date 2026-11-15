<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('platform_promotions', 'campaign_type', fn (Blueprint $table) => $table->string('campaign_type', 40)->nullable());
        $this->addColumnIfMissing('platform_promotions', 'join_deadline', fn (Blueprint $table) => $table->date('join_deadline')->nullable());
        $this->addColumnIfMissing('platform_promotions', 'requires_approval', fn (Blueprint $table) => $table->boolean('requires_approval')->default(true));
        $this->addColumnIfMissing('platform_promotions', 'max_participating_stores', fn (Blueprint $table) => $table->unsignedInteger('max_participating_stores')->nullable());
        $this->addColumnIfMissing('platform_promotions', 'max_products_per_store', fn (Blueprint $table) => $table->unsignedSmallInteger('max_products_per_store')->nullable());
        $this->addColumnIfMissing('platform_promotions', 'campaign_notes', fn (Blueprint $table) => $table->text('campaign_notes')->nullable());

        if (! Schema::hasTable('platform_campaign_participations')) {
            Schema::create('platform_campaign_participations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('platform_promotion_id')->constrained('platform_promotions')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 24)->default('pending_review');
                $table->text('seller_note')->nullable();
                $table->timestamp('terms_accepted_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_reason')->nullable();
                $table->timestamp('withdrawn_at')->nullable();
                $table->timestamps();
                $table->unique(['platform_promotion_id', 'store_id'], 'pcp_campaign_store_unique');
                $table->index(['store_id', 'status'], 'pcp_store_status_index');
                $table->index(['platform_promotion_id', 'status'], 'pcp_campaign_status_index');
            });
        }

        if (! Schema::hasTable('platform_campaign_participation_items')) {
            Schema::create('platform_campaign_participation_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('platform_campaign_participation_id')
                    ->constrained('platform_campaign_participations', 'id', 'pcpi_participation_fk')
                    ->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
                $table->unsignedBigInteger('regular_price_minor')->default(0);
                $table->unsignedBigInteger('campaign_price_minor')->nullable();
                $table->unsignedTinyInteger('discount_percent')->nullable();
                $table->unsignedInteger('stock_limit')->nullable();
                $table->unsignedSmallInteger('per_buyer_limit')->nullable();
                $table->timestamps();
                $table->unique(
                    ['platform_campaign_participation_id', 'product_id', 'product_variant_id'],
                    'pcpi_participation_product_unique',
                );
                $table->index(['product_id', 'product_variant_id'], 'pcpi_product_variant_index');
            });
        }

        $this->addColumnIfMissing('order_items', 'campaign_platform_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('campaign_platform_discount_minor')->default(0));
        $this->addColumnIfMissing('seller_orders', 'campaign_platform_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('campaign_platform_discount_minor')->default(0));
        $this->addColumnIfMissing('orders', 'campaign_platform_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('campaign_platform_discount_minor')->default(0));

        if (! Schema::hasColumn('order_items', 'platform_campaign_participation_item_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->foreignId('platform_campaign_participation_item_id')
                    ->nullable()
                    ->after('platform_discount_minor')
                    ->constrained('platform_campaign_participation_items', 'id', 'oi_pcpi_fk')
                    ->nullOnDelete();
            });
        }

        $this->ensureIndex('platform_promotions', ['kind', 'status', 'join_deadline'], 'platform_promotions_campaign_join_index');
        $this->ensureIndex('order_items', ['platform_campaign_participation_item_id'], 'oi_pcpi_index');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('order_items', 'oi_pcpi_fk');
        $this->dropIndexIfExists('order_items', 'oi_pcpi_index');
        $this->dropColumnIfExists('order_items', 'platform_campaign_participation_item_id');
        $this->dropColumnIfExists('order_items', 'campaign_platform_discount_minor');
        $this->dropColumnIfExists('seller_orders', 'campaign_platform_discount_minor');
        $this->dropColumnIfExists('orders', 'campaign_platform_discount_minor');

        Schema::dropIfExists('platform_campaign_participation_items');
        Schema::dropIfExists('platform_campaign_participations');

        $this->dropIndexIfExists('platform_promotions', 'platform_promotions_campaign_join_index');

        foreach ([
            'campaign_type',
            'join_deadline',
            'requires_approval',
            'max_participating_stores',
            'max_products_per_store',
            'campaign_notes',
        ] as $column) {
            $this->dropColumnIfExists('platform_promotions', $column);
        }
    }

    private function addColumnIfMissing(string $table, string $column, Closure $definition): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($definition): void {
            $definition($blueprint);
        });
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
        }
    }

    private function ensureIndex(string $table, array $columns, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
                $unique
                    ? $blueprint->unique($columns, $name)
                    : $blueprint->index($columns, $name);
            });
        } catch (Throwable) {
            // The index already exists.
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        } catch (Throwable) {
            // The index does not exist.
        }
    }

    private function dropForeignIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        } catch (Throwable) {
            // The constraint does not exist.
        }
    }
};
