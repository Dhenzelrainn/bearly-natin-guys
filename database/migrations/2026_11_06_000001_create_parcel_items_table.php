<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['parcel_id', 'order_item_id']);
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_items');
    }
};
