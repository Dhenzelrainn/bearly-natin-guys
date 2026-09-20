<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('seller_order_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('logistics_provider_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('rider_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('tracking_code')->unique();

            $table->enum('status', [
                'unassigned',
                'assigned',
                'picked_up',
                'in_transit',
                'out_for_delivery',
                'delivered',
                'failed',
                'returned',
            ])->default('unassigned');

            $table->unsignedInteger('fee_minor')->default(0);
            $table->unsignedInteger('cod_amount_minor')->default(0);
            $table->boolean('cod_collected')->default(false);
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};