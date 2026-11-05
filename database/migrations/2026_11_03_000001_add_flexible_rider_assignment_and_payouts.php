<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->string('specialization', 20)
                ->default('both')
                ->after('work_type');
            $table->index(
                ['logistics_profile_id', 'specialization'],
                'rider_profiles_provider_specialization_idx',
            );
        });

        Schema::table('dispatch_batches', function (Blueprint $table): void {
            $table->timestamp('planned_window_start')->nullable();
            $table->timestamp('planned_window_end')->nullable();
            $table->index(
                ['rider_profile_id', 'planned_window_start'],
                'dispatch_batches_rider_planned_window_idx',
            );
        });

        Schema::create('rider_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')->constrained()->restrictOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->string('check_in_source', 30)->default('rider');
            $table->string('check_out_source', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['rider_profile_id', 'checked_in_at'], 'rider_shifts_rider_checked_in_idx');
        });

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rider_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('size_class_snapshot', 20)->nullable();
            $table->unsignedBigInteger('rate_minor')->nullable();
            $table->unique(
                ['pickup_assignment_id', 'parcel_id'],
                'rider_earnings_pickup_parcel_unique',
            );
        });

        Schema::create('rider_payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_no', 40)->unique();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_profile_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending_review');
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('method', 30)->nullable();
            $table->string('reference', 150)->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(
                ['logistics_profile_id', 'status', 'created_at'],
                'rider_payout_batches_provider_status_idx',
            );
            $table->index(
                ['rider_profile_id', 'status'],
                'rider_payout_batches_rider_status_idx',
            );
        });

        Schema::create('rider_payout_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_payout_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_earning_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('earning_type', 30);
            $table->timestamps();
            $table->index(
                ['rider_payout_batch_id', 'earning_type'],
                'rider_payout_batch_items_batch_type_idx',
            );
        });
    }

    public function down(): void
    {
        $hasPayoutHistory = (Schema::hasTable('rider_payout_batches')
                && DB::table('rider_payout_batches')->exists())
            || (Schema::hasTable('rider_payout_batch_items')
                && DB::table('rider_payout_batch_items')->exists())
            || (Schema::hasTable('rider_shifts')
                && DB::table('rider_shifts')->exists())
            || (Schema::hasColumn('rider_earnings', 'parcel_id')
                && DB::table('rider_earnings')->whereNotNull('parcel_id')->exists())
            || (Schema::hasColumn('dispatch_batches', 'planned_window_start')
                && DB::table('dispatch_batches')->where(function ($query): void {
                    $query->whereNotNull('planned_window_start')
                        ->orWhereNotNull('planned_window_end');
                })->exists())
            || (Schema::hasColumn('rider_profiles', 'specialization')
                && DB::table('rider_profiles')->where('specialization', '<>', 'both')->exists());

        if ($hasPayoutHistory) {
            throw new RuntimeException(
                'Rider assignment, attendance, or payout history exists; preserve it before rolling back this migration.',
            );
        }

        Schema::dropIfExists('rider_payout_batch_items');
        Schema::dropIfExists('rider_payout_batches');

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->dropUnique('rider_earnings_pickup_parcel_unique');
            $table->dropConstrainedForeignId('parcel_id');
            $table->dropConstrainedForeignId('rider_shift_id');
            $table->dropColumn(['size_class_snapshot', 'rate_minor']);
        });

        Schema::dropIfExists('rider_shifts');

        Schema::table('dispatch_batches', function (Blueprint $table): void {
            $table->dropIndex('dispatch_batches_rider_planned_window_idx');
            $table->dropColumn(['planned_window_start', 'planned_window_end']);
        });

        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->dropIndex('rider_profiles_provider_specialization_idx');
            $table->dropColumn('specialization');
        });
    }
};
