<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('store_promotions', 'code', fn (Blueprint $table) => $table->string('code', 40)->nullable());
        $this->addColumnIfMissing('store_promotions', 'status', fn (Blueprint $table) => $table->string('status', 20)->default('active'));
        $this->addColumnIfMissing('store_promotions', 'funding_type', fn (Blueprint $table) => $table->string('funding_type', 20)->default('seller'));
        $this->addColumnIfMissing('store_promotions', 'discount_type', fn (Blueprint $table) => $table->string('discount_type', 20)->default('fixed'));
        $this->addColumnIfMissing('store_promotions', 'max_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('max_discount_minor')->nullable());
        $this->addColumnIfMissing('store_promotions', 'terms', fn (Blueprint $table) => $table->text('terms')->nullable());
        $this->addColumnIfMissing('store_promotions', 'is_claimable', fn (Blueprint $table) => $table->boolean('is_claimable')->default(true));
        $this->addColumnIfMissing('store_promotions', 'is_public', fn (Blueprint $table) => $table->boolean('is_public')->default(true));
        $this->ensureIndex('store_promotions', ['status', 'starts_on', 'ends_on'], 'store_promotions_status_dates_index');
        $this->ensureIndex('store_promotions', ['code'], 'store_promotions_code_unique', unique: true);

        $this->addColumnIfMissing('orders', 'platform_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('platform_discount_minor')->default(0));
        $this->addColumnIfMissing('orders', 'platform_shipping_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('platform_shipping_discount_minor')->default(0));

        $this->addColumnIfMissing('seller_orders', 'platform_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('platform_discount_minor')->default(0));
        $this->addColumnIfMissing('seller_orders', 'platform_shipping_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('platform_shipping_discount_minor')->default(0));

        $this->addColumnIfMissing('order_items', 'platform_discount_minor', fn (Blueprint $table) => $table->unsignedBigInteger('platform_discount_minor')->default(0));

        if (! Schema::hasTable('platform_promotions')) {
            Schema::create('platform_promotions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 120);
                $table->string('code', 40)->nullable()->unique();
                $table->string('kind', 30)->default('voucher');
                $table->string('funding_type', 20)->default('platform');
                $table->string('discount_type', 30)->default('fixed');
                $table->string('status', 20)->default('active');
                $table->string('description', 255)->nullable();
                $table->text('terms')->nullable();
                $table->unsignedBigInteger('value_minor')->nullable();
                $table->unsignedTinyInteger('discount_percent')->nullable();
                $table->unsignedBigInteger('max_discount_minor')->nullable();
                $table->unsignedBigInteger('minimum_spend_minor')->default(0);
                $table->date('starts_on');
                $table->date('ends_on');
                $table->unsignedInteger('redemption_limit')->nullable();
                $table->unsignedSmallInteger('per_buyer_limit')->default(1);
                $table->unsignedBigInteger('budget_minor')->nullable();
                $table->boolean('is_claimable')->default(false);
                $table->boolean('is_public')->default(true);
                $table->boolean('is_featured')->default(false);
                $table->string('banner_path', 500)->nullable();
                $table->string('banner_disk', 50)->nullable();
                $table->string('banner_title', 160)->nullable();
                $table->string('banner_link', 500)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->index(['status', 'starts_on', 'ends_on'], 'platform_promotions_status_dates_index');
                $table->index(['kind', 'status'], 'platform_promotions_kind_status_index');
                $table->index(['is_featured', 'status', 'position'], 'platform_promotions_featured_index');
            });
        }

        if (! Schema::hasTable('platform_promotion_products')) {
            Schema::create('platform_promotion_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('platform_promotion_id')->constrained('platform_promotions')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['platform_promotion_id', 'product_id'], 'ppp_promo_product_unique');
                $table->index(['product_id', 'platform_promotion_id'], 'ppp_product_promo_index');
            });
        }

        if (! Schema::hasTable('platform_promotion_categories')) {
            Schema::create('platform_promotion_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('platform_promotion_id')->constrained('platform_promotions')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['platform_promotion_id', 'category_id'], 'ppc_promo_category_unique');
            });
        }

        if (! Schema::hasTable('platform_promotion_stores')) {
            Schema::create('platform_promotion_stores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('platform_promotion_id')->constrained('platform_promotions')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['platform_promotion_id', 'store_id'], 'pps_promo_store_unique');
            });
        }

        if (! Schema::hasTable('promotion_claims')) {
            Schema::create('promotion_claims', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('platform_promotion_id')->nullable()->constrained('platform_promotions')->cascadeOnDelete();
                $table->foreignId('store_promotion_id')->nullable()->constrained('store_promotions')->cascadeOnDelete();
                $table->string('status', 20)->default('claimed');
                $table->timestamp('claimed_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
                $table->unique(['buyer_id', 'platform_promotion_id'], 'promo_claim_buyer_platform_unique');
                $table->unique(['buyer_id', 'store_promotion_id'], 'promo_claim_buyer_store_unique');
                $table->index(['buyer_id', 'status'], 'promo_claim_buyer_status_index');
            });
        }

        if (! Schema::hasTable('promotion_redemptions')) {
            Schema::create('promotion_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('platform_promotion_id')->nullable()->constrained('platform_promotions')->restrictOnDelete();
                $table->foreignId('store_promotion_id')->nullable()->constrained('store_promotions')->restrictOnDelete();
                $table->foreignId('promotion_claim_id')->nullable()->constrained('promotion_claims')->nullOnDelete();
                $table->string('code', 40)->nullable();
                $table->string('name', 120);
                $table->string('kind', 30);
                $table->string('funding_type', 20);
                $table->string('discount_type', 30);
                $table->unsignedBigInteger('discount_minor')->default(0);
                $table->unsignedBigInteger('shipping_discount_minor')->default(0);
                $table->string('status', 20)->default('reserved');
                $table->timestamp('reserved_at');
                $table->timestamp('redeemed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();
                $table->unique(['order_id', 'platform_promotion_id'], 'promo_redemption_order_platform_unique');
                $table->unique(['order_id', 'store_promotion_id'], 'promo_redemption_order_store_unique');
                $table->index(['buyer_id', 'status'], 'promo_redemption_buyer_status_index');
                $table->index(['status', 'created_at'], 'promo_redemption_status_created_index');
            });
        }

        if (! Schema::hasTable('promotion_redemption_allocations')) {
            Schema::create('promotion_redemption_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('promotion_redemption_id')->constrained('promotion_redemptions')->cascadeOnDelete();
                $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->cascadeOnDelete();
                $table->unsignedBigInteger('amount_minor')->default(0);
                $table->timestamps();
                $table->index(['promotion_redemption_id', 'seller_order_id'], 'promo_alloc_redemption_seller_index');
                $table->index(['order_item_id'], 'promo_alloc_order_item_index');
            });
        }

        // The first attempt may have created a table before a later DDL
        // statement failed. Guarantee the short indexes exist when resuming.
        $this->ensureIndex('platform_promotion_products', ['platform_promotion_id', 'product_id'], 'ppp_promo_product_unique', unique: true);
        $this->ensureIndex('platform_promotion_products', ['product_id', 'platform_promotion_id'], 'ppp_product_promo_index');
        $this->ensureIndex('platform_promotion_categories', ['platform_promotion_id', 'category_id'], 'ppc_promo_category_unique', unique: true);
        $this->ensureIndex('platform_promotion_stores', ['platform_promotion_id', 'store_id'], 'pps_promo_store_unique', unique: true);
        $this->ensureIndex('promotion_claims', ['buyer_id', 'platform_promotion_id'], 'promo_claim_buyer_platform_unique', unique: true);
        $this->ensureIndex('promotion_claims', ['buyer_id', 'store_promotion_id'], 'promo_claim_buyer_store_unique', unique: true);
        $this->ensureIndex('promotion_claims', ['buyer_id', 'status'], 'promo_claim_buyer_status_index');
        $this->ensureIndex('promotion_redemptions', ['order_id', 'platform_promotion_id'], 'promo_redemption_order_platform_unique', unique: true);
        $this->ensureIndex('promotion_redemptions', ['order_id', 'store_promotion_id'], 'promo_redemption_order_store_unique', unique: true);
        $this->ensureIndex('promotion_redemptions', ['buyer_id', 'status'], 'promo_redemption_buyer_status_index');
        $this->ensureIndex('promotion_redemptions', ['status', 'created_at'], 'promo_redemption_status_created_index');
        $this->ensureIndex('promotion_redemption_allocations', ['promotion_redemption_id', 'seller_order_id'], 'promo_alloc_redemption_seller_index');
        $this->ensureIndex('promotion_redemption_allocations', ['order_item_id'], 'promo_alloc_order_item_index');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemption_allocations');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_claims');
        Schema::dropIfExists('platform_promotion_stores');
        Schema::dropIfExists('platform_promotion_categories');
        Schema::dropIfExists('platform_promotion_products');
        Schema::dropIfExists('platform_promotions');

        $this->dropColumnIfExists('order_items', 'platform_discount_minor');
        $this->dropColumnIfExists('seller_orders', 'platform_discount_minor');
        $this->dropColumnIfExists('seller_orders', 'platform_shipping_discount_minor');
        $this->dropColumnIfExists('orders', 'platform_discount_minor');
        $this->dropColumnIfExists('orders', 'platform_shipping_discount_minor');

        $this->dropIndexIfExists('store_promotions', 'store_promotions_code_unique');
        $this->dropIndexIfExists('store_promotions', 'store_promotions_status_dates_index');
        $this->dropIndexIfExists('store_promotions', 'store_promotions_status_starts_on_ends_on_index');

        foreach ([
            'code',
            'status',
            'funding_type',
            'discount_type',
            'max_discount_minor',
            'terms',
            'is_claimable',
            'is_public',
        ] as $column) {
            $this->dropColumnIfExists('store_promotions', $column);
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

        $exists = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name
                || ($index['columns'] ?? []) === $columns);

        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            $unique
                ? $blueprint->unique($columns, $name)
                : $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $exists = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);

        if ($exists) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }
};
