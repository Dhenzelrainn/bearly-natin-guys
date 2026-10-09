<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_subcategories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'parent_category_id', 'name'], 'store_subcategories_store_parent_name_unique');
            $table->unique(['store_id', 'parent_category_id', 'slug'], 'store_subcategories_store_parent_slug_unique');
            $table->index(['store_id', 'is_active']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('subcategory_id')
                ->nullable()
                ->after('category_id')
                ->constrained('store_subcategories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subcategory_id');
        });

        Schema::dropIfExists('store_subcategories');
    }
};
