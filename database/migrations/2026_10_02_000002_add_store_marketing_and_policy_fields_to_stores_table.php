<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('promo_banner_path', 500)->nullable()->after('banner_path');
            $table->string('promo_banner_title', 160)->nullable()->after('promo_banner_path');
            $table->string('promo_banner_link', 500)->nullable()->after('promo_banner_title');
            $table->text('shipping_policy')->nullable()->after('promo_banner_link');
            $table->text('return_policy')->nullable()->after('shipping_policy');
            $table->text('warranty_policy')->nullable()->after('return_policy');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn([
                'promo_banner_path',
                'promo_banner_title',
                'promo_banner_link',
                'shipping_policy',
                'return_policy',
                'warranty_policy',
            ]);
        });
    }
};
