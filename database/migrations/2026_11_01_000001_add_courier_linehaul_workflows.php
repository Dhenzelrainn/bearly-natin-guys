<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_applications', function (Blueprint $table): void {
            $table->string('requested_work_type', 20)->nullable()->after('requested_role_id');
            $table->string('rider_vehicle_model', 160)->nullable()->after('requested_work_type');
        });

        Schema::table('rider_profiles', function (Blueprint $table): void {
            // Null preserves existing Riders as legacy-local without rewriting accounts.
            $table->string('work_type', 20)->nullable()->after('verification_status');
            $table->unsignedSmallInteger('linehaul_parcel_capacity')->nullable()->after('work_type');
            $table->foreignId('courier_vehicle_verified_by')->nullable()->after('linehaul_parcel_capacity')->constrained('users')->nullOnDelete();
            $table->timestamp('courier_vehicle_verified_at')->nullable()->after('courier_vehicle_verified_by');
        });

        Schema::create('linehaul_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_no', 36)->unique();
            $table->foreignId('origin_logistics_profile_id')->constrained('logistics_profiles')->restrictOnDelete();
            $table->foreignId('destination_logistics_profile_id')->constrained('logistics_profiles')->restrictOnDelete();
            $table->foreignId('origin_sorting_center_id')->constrained('sorting_centers')->restrictOnDelete();
            $table->foreignId('courier_rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->string('status', 24)->default('assigned');
            $table->string('status_before_exception', 24)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['origin_logistics_profile_id', 'status', 'assigned_at'], 'linehaul_runs_origin_status_assigned_idx');
            $table->index(['destination_logistics_profile_id', 'status', 'assigned_at'], 'linehaul_runs_destination_status_assigned_idx');
            $table->index(['courier_rider_profile_id', 'status', 'assigned_at'], 'linehaul_runs_courier_status_assigned_idx');
        });

        Schema::create('linehaul_run_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_run_id')->constrained()->restrictOnDelete();
            // A shipment can only be placed on one line-haul run; never reassign departed cargo.
            $table->foreignId('shipment_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamps();
            $table->index(['linehaul_run_id', 'shipment_id'], 'linehaul_run_shipments_run_shipment_idx');
        });

        Schema::create('linehaul_run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['linehaul_run_id', 'occurred_at'], 'linehaul_run_events_run_occurred_idx');
        });

        Schema::create('rider_vehicle_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')->constrained()->restrictOnDelete();
            $table->string('requested_work_type', 20);
            $table->string('vehicle_type', 80);
            $table->string('vehicle_model', 160)->nullable();
            $table->string('plate_number', 30);
            $table->string('or_cr_disk', 40)->default('local');
            $table->string('or_cr_path', 500);
            $table->string('or_cr_original_name', 255);
            $table->string('or_cr_mime_type', 100);
            $table->unsignedBigInteger('or_cr_size_bytes');
            $table->string('driver_license_disk', 40)->default('local');
            $table->string('driver_license_path', 500);
            $table->string('driver_license_original_name', 255);
            $table->string('driver_license_mime_type', 100);
            $table->unsignedBigInteger('driver_license_size_bytes');
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['rider_profile_id', 'status', 'created_at'], 'rider_vehicle_change_requests_rider_status_created_idx');
        });
    }

    public function down(): void
    {
        $hasProfileCapabilityData = DB::table('rider_profiles')
            ->whereNotNull('work_type')
            ->orWhereNotNull('linehaul_parcel_capacity')
            ->orWhereNotNull('courier_vehicle_verified_by')
            ->orWhereNotNull('courier_vehicle_verified_at')
            ->exists();
        $hasApplicationRequestData = DB::table('account_applications')
            ->whereNotNull('requested_work_type')
            ->orWhereNotNull('rider_vehicle_model')
            ->exists();

        if (
            DB::table('linehaul_runs')->exists()
            || DB::table('rider_vehicle_change_requests')->exists()
            || $hasProfileCapabilityData
            || $hasApplicationRequestData
        ) {
            throw new RuntimeException(
                'Courier applications, verified Rider capability, and line-haul history exist. Preserve the data before rolling back this migration.'
            );
        }

        Schema::dropIfExists('rider_vehicle_change_requests');
        Schema::dropIfExists('linehaul_run_events');
        Schema::dropIfExists('linehaul_run_shipments');
        Schema::dropIfExists('linehaul_runs');

        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('courier_vehicle_verified_by');
            $table->dropColumn([
                'work_type',
                'linehaul_parcel_capacity',
                'courier_vehicle_verified_at',
            ]);
        });

        Schema::table('account_applications', function (Blueprint $table): void {
            $table->dropColumn(['requested_work_type', 'rider_vehicle_model']);
        });
    }
};
