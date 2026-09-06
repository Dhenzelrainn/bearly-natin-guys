<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->decimal('price', 10, 2);
            $table->decimal('original_price', 10, 2)->nullable();
            $table->integer('discount_percentage')->nullable();
            $table->string('image')->nullable();
            $table->json('images')->nullable();
            $table->decimal('rating', 3, 1)->default(4.5);
            $table->integer('sold_count')->default(0);
            $table->integer('stock')->default(0);
            $table->boolean('free_shipping')->default(false);
            $table->string('location')->nullable();
            $table->string('badge')->nullable(); // "Best Seller", "Flash Deal", "Voucher", "New", "Official Store"
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
