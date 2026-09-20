<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('buyer_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('reference')->unique();

            $table->unsignedInteger('total_minor');

            $table->enum('payment_method', [
                'cod',
                'wallet',
            ]);

            $table->enum('payment_status', [
                'pending',
                'paid',
                'refunded',
                'partially_refunded',
            ])->default('pending');

            $table->json('shipping_address');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};