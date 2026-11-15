<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->text('comment');
            $t->string('photo_path', 500)->nullable();
            $t->string('photo_disk', 20)->default('public');
            $t->text('seller_response')->nullable();
            $t->foreignId('seller_response_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('seller_response_at')->nullable();
            $t->boolean('is_visible')->default(true);
            $t->timestamps();
            $t->index(['product_id', 'is_visible', 'created_at']);
            $t->index(['store_id', 'created_at']);
            $t->index(['buyer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
