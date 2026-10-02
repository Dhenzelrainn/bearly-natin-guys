<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_promotional_banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('image_path', 500);
            $table->string('title', 160)->nullable();
            $table->string('link', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['store_id', 'position']);
        });

        DB::table('stores')
            ->whereNotNull('promo_banner_path')
            ->where('promo_banner_path', '!=', '')
            ->get(['id', 'promo_banner_path', 'promo_banner_title', 'promo_banner_link'])
            ->each(function (object $store): void {
                DB::table('store_promotional_banners')->insert([
                    'store_id' => $store->id,
                    'image_path' => $store->promo_banner_path,
                    'title' => $store->promo_banner_title,
                    'link' => $store->promo_banner_link,
                    'position' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_promotional_banners');
    }
};
