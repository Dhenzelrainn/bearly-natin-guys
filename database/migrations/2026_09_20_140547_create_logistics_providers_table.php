<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_providers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();

            $table->enum('status', [
                'pending',
                'approved',
                'suspended',
                'rejected',
            ])->default('pending');

            $table->text('rejection_reason')->nullable();
            $table->string('contact_phone');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_providers');
    }
};