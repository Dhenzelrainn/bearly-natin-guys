<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('category_id_snapshot')->nullable()->after('product_variant_id')->constrained('categories')->nullOnDelete();
            $table->string('category_name_snapshot', 120)->nullable()->after('category_id_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id_snapshot');
            $table->dropColumn('category_name_snapshot');
        });
    }
};
