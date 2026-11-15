<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')
                ->unique()
                ->constrained('rider_profiles')
                ->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_locations');
    }
};
