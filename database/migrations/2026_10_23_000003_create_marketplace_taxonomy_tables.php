<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'categories', 'seller_profiles', 'stores', 'products'] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("Marketplace taxonomy requires the [{$requiredTable}] table first.");
            }
        }

        if (! Schema::hasColumn('products', 'subcategory_id')) {
            throw new RuntimeException('Marketplace taxonomy requires products.subcategory_id from the Store-subcategory migration first.');
        }

        if (! Schema::hasTable('marketplace_categories')) {
            Schema::create('marketplace_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 140)->unique();
                $table->string('icon', 60)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->index(['is_active', 'position'], 'marketplace_categories_active_position_index');
            });
        }

        if (! Schema::hasTable('marketplace_subcategories')) {
            Schema::create('marketplace_subcategories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketplace_category_id');
                $table->string('name', 120);
                $table->string('name_key', 120);
                $table->string('slug', 140);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->foreignId('created_by_user_id')->nullable();
                $table->foreignId('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['marketplace_category_id', 'name_key'], 'marketplace_subcategory_parent_name_key_unique');
                $table->unique(['marketplace_category_id', 'slug'], 'marketplace_subcategory_parent_slug_unique');
                $table->index(['marketplace_category_id', 'is_active', 'position'], 'marketplace_subcategory_active_position_index');
            });
        }

        if (! Schema::hasTable('marketplace_category_business_categories')) {
            Schema::create('marketplace_category_business_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketplace_category_id');
                $table->foreignId('category_id');
                $table->timestamps();
                $table->unique(['marketplace_category_id', 'category_id'], 'marketplace_category_business_unique');
                $table->index(['category_id', 'marketplace_category_id'], 'marketplace_business_category_lookup_index');
            });
        }

        if (! Schema::hasTable('marketplace_subcategory_requests')) {
            Schema::create('marketplace_subcategory_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seller_profile_id');
                $table->foreignId('store_id');
                $table->foreignId('business_category_id');
                $table->string('suggested_name', 120);
                $table->string('name_key', 120);
                $table->string('reason', 500);
                $table->string('status', 24)->default('pending_review');
                $table->foreignId('marketplace_category_id')->nullable();
                $table->foreignId('marketplace_subcategory_id')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->timestamps();
                $table->index(['status', 'created_at'], 'marketplace_subcategory_requests_status_created_index');
                $table->index(['seller_profile_id', 'status'], 'marketplace_subcategory_requests_seller_status_index');
                $table->index(['business_category_id', 'name_key', 'status'], 'marketplace_subcategory_requests_business_name_status_index');
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'marketplace_subcategory_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('marketplace_subcategory_id')->nullable()->after('subcategory_id');
            });
        }

        // Use explicit short constraint identifiers for MySQL's 64-character
        // limit. ensureForeignKey also resumes safely after a partial MySQL DDL
        // failure, without dropping any already-created tables or rows.
        $this->ensureForeignKey('marketplace_subcategories', 'marketplace_category_id', 'marketplace_categories', 'mp_subcat_parent_fk');
        $this->ensureForeignKey('marketplace_subcategories', 'created_by_user_id', 'users', 'mp_subcat_created_user_fk', 'set null');
        $this->ensureForeignKey('marketplace_subcategories', 'updated_by_user_id', 'users', 'mp_subcat_updated_user_fk', 'set null');
        $this->ensureForeignKey('marketplace_category_business_categories', 'marketplace_category_id', 'marketplace_categories', 'mp_catbiz_marketplace_fk');
        $this->ensureForeignKey('marketplace_category_business_categories', 'category_id', 'categories', 'mp_catbiz_business_fk');
        $this->ensureForeignKey('marketplace_subcategory_requests', 'seller_profile_id', 'seller_profiles', 'mp_req_seller_profile_fk');
        $this->ensureForeignKey('marketplace_subcategory_requests', 'store_id', 'stores', 'mp_req_store_fk');
        $this->ensureForeignKey('marketplace_subcategory_requests', 'business_category_id', 'categories', 'mp_req_business_category_fk');
        $this->ensureForeignKey('marketplace_subcategory_requests', 'marketplace_category_id', 'marketplace_categories', 'mp_req_marketplace_category_fk');
        $this->ensureForeignKey('marketplace_subcategory_requests', 'marketplace_subcategory_id', 'marketplace_subcategories', 'mp_req_marketplace_subcategory_fk');
        $this->ensureForeignKey('marketplace_subcategory_requests', 'reviewed_by_user_id', 'users', 'mp_req_reviewer_user_fk', 'set null');
        $this->ensureForeignKey('products', 'marketplace_subcategory_id', 'marketplace_subcategories', 'mp_product_marketplace_subcategory_fk');
    }

    public function down(): void
    {
        foreach ([
            'marketplace_subcategory_requests',
            'marketplace_category_business_categories',
            'marketplace_subcategories',
            'marketplace_categories',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Marketplace taxonomy rollback stopped to preserve existing taxonomy and review data.');
            }
        }

        if (Schema::hasTable('products')
            && Schema::hasColumn('products', 'marketplace_subcategory_id')
            && DB::table('products')->whereNotNull('marketplace_subcategory_id')->exists()) {
            throw new RuntimeException('Marketplace taxonomy rollback stopped because products reference marketplace subcategories.');
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'marketplace_subcategory_id')) {
            $foreignKey = collect(Schema::getForeignKeys('products'))
                ->first(fn (array $key): bool => ($key['columns'] ?? []) === ['marketplace_subcategory_id']);
            Schema::table('products', function (Blueprint $table) use ($foreignKey): void {
                if ($foreignKey) {
                    $table->dropForeign($foreignKey['name']);
                }
                $table->dropColumn('marketplace_subcategory_id');
            });
        }

        Schema::dropIfExists('marketplace_subcategory_requests');
        Schema::dropIfExists('marketplace_category_business_categories');
        Schema::dropIfExists('marketplace_subcategories');
        Schema::dropIfExists('marketplace_categories');
    }

    private function ensureForeignKey(
        string $tableName,
        string $column,
        string $referencedTable,
        string $constraintName,
        string $onDelete = 'restrict',
    ): void {
        if (! Schema::hasTable($tableName)
            || ! Schema::hasTable($referencedTable)
            || ! Schema::hasColumn($tableName, $column)) {
            throw new RuntimeException("Cannot add marketplace taxonomy foreign key [{$constraintName}]; required table or column is missing.");
        }

        $existingForeignKey = collect(Schema::getForeignKeys($tableName))
            ->first(fn (array $key): bool => ($key['columns'] ?? []) === [$column]);

        if ($existingForeignKey) {
            if (($existingForeignKey['foreign_table'] ?? null) === $referencedTable) {
                return;
            }

            throw new RuntimeException("Marketplace taxonomy column [{$tableName}.{$column}] already has an unexpected foreign key.");
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $constraintName, $onDelete): void {
            $foreignKey = $table->foreign($column, $constraintName)
                ->references('id')
                ->on($referencedTable);

            match ($onDelete) {
                'cascade' => $foreignKey->cascadeOnDelete(),
                'set null' => $foreignKey->nullOnDelete(),
                default => $foreignKey->restrictOnDelete(),
            };
        });
    }
};
