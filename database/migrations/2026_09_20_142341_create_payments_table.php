<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('method', [
                'cod',
                'wallet',
            ]);

            $table->unsignedInteger('amount_minor');

            $table->enum('status', [
                'pending',
                'paid',
                'refunded',
                'partially_refunded',
            ])->default('pending');

            $table->string('provider_ref')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};