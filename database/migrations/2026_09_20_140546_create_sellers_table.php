<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();

            $table->enum('status', [
                'pending',
                'approved',
                'suspended',
                'rejected',
            ])->default('pending');

            $table->text('rejection_reason')->nullable();

            $table->unsignedInteger('commission_bps')
                ->default(800);

            $table->foreignId('pickup_address_id')
                ->nullable()
                ->constrained('addresses')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};