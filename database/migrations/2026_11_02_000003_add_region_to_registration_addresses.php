<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('region', 160)->nullable()->after('barangay');
            $table->string('region_code', 20)->nullable()->after('region');
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('region', 160)->nullable()->after('barangay');
            $table->string('psgc_region_code', 20)->nullable()->after('psgc_province_code');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn(['region', 'psgc_region_code']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['region', 'region_code']);
        });
    }
};
