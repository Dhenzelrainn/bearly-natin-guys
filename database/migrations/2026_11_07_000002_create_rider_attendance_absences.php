<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_attendance_absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_profile_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->foreignId('marked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('marked_at');
            $table->timestamps();

            $table->unique(
                ['rider_profile_id', 'work_date'],
                'rider_attendance_absences_rider_date_unique',
            );
            $table->index(
                ['logistics_profile_id', 'work_date'],
                'rider_attendance_absences_provider_date_idx',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('rider_attendance_absences')
            && DB::table('rider_attendance_absences')->exists()) {
            throw new RuntimeException(
                'Rider attendance absence audit records exist; preserve them before rolling back this migration.',
            );
        }

        Schema::dropIfExists('rider_attendance_absences');
    }
};
