<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->date('work_date')->nullable()->after('paid_at');
            $table->foreignId('linehaul_run_id')
                ->nullable()
                ->after('rider_shift_id')
                ->constrained('linehaul_runs')
                ->nullOnDelete();
            $table->index(
                ['rider_profile_id', 'work_date'],
                'rider_earnings_courier_work_date_idx',
            );
            $table->unique(
                ['rider_profile_id', 'type', 'work_date'],
                'rider_earnings_courier_daily_unique',
            );
        });
    }

    public function down(): void
    {
        $hasCourierDailyHistory = Schema::hasColumn('rider_earnings', 'work_date')
            && DB::table('rider_earnings')
                ->where(function ($query): void {
                    $query->whereNotNull('work_date')
                        ->orWhereNotNull('linehaul_run_id');
                })
                ->exists();

        if ($hasCourierDailyHistory) {
            throw new RuntimeException(
                'Courier daily earning history exists; preserve it before rolling back this migration.',
            );
        }

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->dropUnique('rider_earnings_courier_daily_unique');
            $table->dropIndex('rider_earnings_courier_work_date_idx');
            $table->dropConstrainedForeignId('linehaul_run_id');
            $table->dropColumn('work_date');
        });
    }
};
