<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('product_image_disk', 40)->nullable()->after('options');
            $table->string('product_image_path', 500)->nullable()->after('product_image_disk');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['product_image_disk', 'product_image_path']);
        });
    }
};
