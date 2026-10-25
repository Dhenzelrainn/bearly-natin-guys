<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['package_length_cm', 'package_width_cm', 'package_height_cm'] as $column) {
            if (! Schema::hasColumn('product_variants', $column)) {
                throw new RuntimeException("Cannot expand product_variants.{$column}; run the package-dimensions migration first.");
            }
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('package_length_cm', 10, 3)->nullable()->change();
            $table->decimal('package_width_cm', 10, 3)->nullable()->change();
            $table->decimal('package_height_cm', 10, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Variant package dimensions retain thousandth-centimeter precision; this migration is forward-only to avoid rounding stored measurements.');
    }
};
