<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('package_weight_kg', 10, 3)->nullable();
            $table->decimal('package_length_cm', 10, 2)->nullable();
            $table->decimal('package_width_cm', 10, 2)->nullable();
            $table->decimal('package_height_cm', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn([
                'package_weight_kg',
                'package_length_cm',
                'package_width_cm',
                'package_height_cm',
            ]);
        });
    }
};
