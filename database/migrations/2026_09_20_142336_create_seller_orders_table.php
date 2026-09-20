<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('seller_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('logistics_provider_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->unsignedInteger('subtotal_minor');
            $table->unsignedInteger('shipping_fee_minor')->default(0);
            $table->unsignedInteger('commission_minor')->default(0);

            $table->enum('status', [
                'pending',
                'accepted',
                'packed',
                'ready_to_ship',
                'shipped',
                'delivered',
                'completed',
                'cancelled',
            ])->default('pending');

            $table->timestamp('delivered_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'seller_id']);
            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_orders');
    }
};