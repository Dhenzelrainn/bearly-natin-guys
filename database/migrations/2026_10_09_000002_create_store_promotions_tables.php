<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('type', 32);
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->unsignedBigInteger('voucher_amount_minor')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('minimum_spend_minor')->default(0);
            $table->unsignedInteger('redemption_limit')->nullable();
            $table->unsignedSmallInteger('per_buyer_limit')->default(1);
            $table->timestamps();
            $table->index(['store_id', 'starts_on', 'ends_on']);
        });

        Schema::create('store_promotion_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_promotion_id')->constrained('store_promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['store_promotion_id', 'product_id']);
            $table->index(['product_id', 'store_promotion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_promotion_products');
        Schema::dropIfExists('store_promotions');
    }
};
