<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shipment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('status', [
                'unassigned',
                'assigned',
                'picked_up',
                'in_transit',
                'out_for_delivery',
                'delivered',
                'failed',
                'returned',
            ]);

            $table->unsignedInteger('attempt')->default(0);

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->unique([
                'shipment_id',
                'status',
                'attempt',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
    }
};