<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_attendance_qr_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_profile_id')->unique()->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rider_attendance_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->constrained('rider_attendance_qr_stations')->restrictOnDelete();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_shift_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('generation');
            $table->string('scan_type', 20);
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->unique(
                ['station_id', 'generation', 'rider_profile_id'],
                'rider_attendance_scans_station_generation_rider_unique',
            );
            $table->index(['rider_profile_id', 'scanned_at'], 'rider_attendance_scans_rider_scanned_idx');
            $table->index(['rider_shift_id', 'scan_type'], 'rider_attendance_scans_shift_type_idx');
        });

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->unsignedInteger('worked_seconds_snapshot')->nullable()->after('rate_minor');
            $table->unsignedBigInteger('daily_cap_minor_snapshot')->nullable()->after('worked_seconds_snapshot');
        });
    }

    public function down(): void
    {
        $hasAttendanceHistory = (Schema::hasTable('rider_attendance_scans')
                && DB::table('rider_attendance_scans')->exists())
            || (Schema::hasColumn('rider_earnings', 'worked_seconds_snapshot')
                && DB::table('rider_earnings')->whereNotNull('worked_seconds_snapshot')->exists());

        if ($hasAttendanceHistory) {
            throw new RuntimeException(
                'QR attendance or hourly Courier earning history exists; preserve it before rolling back this migration.',
            );
        }

        Schema::dropIfExists('rider_attendance_scans');
        Schema::dropIfExists('rider_attendance_qr_stations');

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->dropColumn(['worked_seconds_snapshot', 'daily_cap_minor_snapshot']);
        });
    }
};
