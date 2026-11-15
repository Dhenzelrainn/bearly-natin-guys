<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_image_category_checks', function (Blueprint $table): void {
            $table->string('safety_status', 30)->nullable()->after('status');
            $table->json('matched_rule_codes')->nullable()->after('safety_status');
            $table->string('safety_reason', 320)->nullable()->after('reason');
            $table->index(
                ['product_image_id', 'is_current'],
                'product_image_checks_image_current_index',
            );
        });

        Schema::table('product_violations', function (Blueprint $table): void {
            $table->foreignId('product_image_category_check_id')
                ->nullable()
                ->after('check_id')
                ->constrained('product_image_category_checks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_violations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_image_category_check_id');
        });

        Schema::table('product_image_category_checks', function (Blueprint $table): void {
            $table->dropIndex('product_image_checks_image_current_index');
            $table->dropColumn(['safety_status', 'matched_rule_codes', 'safety_reason']);
        });
    }
};
