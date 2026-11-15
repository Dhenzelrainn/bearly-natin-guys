<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->string('status', 30)->default('pending')->change();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('checkout_request_key')->nullable();
            $table->unique(['buyer_id', 'checkout_request_key'], 'orders_buyer_checkout_request_unique');
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->string('delivery_provider_key', 50)->nullable();
            $table->json('shipping_quote')->nullable();
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->bigInteger('reserved_delta')->default(0);
            $table->unsignedInteger('reserved_balance_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn(['reserved_delta', 'reserved_balance_after']);
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn(['delivery_provider_key', 'shipping_quote']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_buyer_checkout_request_unique');
            $table->dropColumn('checkout_request_key');
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->string('status', 30)->default('placed')->change();
        });
    }
};
