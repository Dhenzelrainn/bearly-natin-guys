<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->string('return_delivery_method', 30)->nullable()->after('request_type');
            $table->timestamp('dropoff_due_at')->nullable()->after('return_shipment_id');
            $table->foreignId('dropoff_sorting_center_id')->nullable()->constrained('sorting_centers')->nullOnDelete();
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->json('return_shipping_quote')->nullable()->after('destination_address_snapshot');
        });

        Schema::table('shipping_settlements', function (Blueprint $table): void {
            $table->string('settlement_type', 24)->default('outbound')->after('settlement_no');
            $table->foreignId('return_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('return_shipping_fee_minor')->nullable();
            $table->string('financially_responsible_party', 20)->nullable();
            $table->index(['settlement_type', 'status', 'eligible_at'], 'shipping_settlement_type_status_idx');
        });

        Schema::table('seller_refund_recoveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('refund_recovery_minor')->default(0);
            $table->unsignedBigInteger('refund_recovered_minor')->default(0);
            $table->unsignedBigInteger('refund_recovery_voided_minor')->default(0);
            $table->unsignedBigInteger('return_shipping_fee_minor')->default(0);
            $table->unsignedBigInteger('return_shipping_fee_recovered_minor')->default(0);
        });

        DB::table('seller_refund_recoveries')->update([
            'refund_recovery_minor' => DB::raw('amount_minor'),
            'refund_recovered_minor' => DB::raw('recovered_amount_minor'),
        ]);
        DB::table('seller_refund_recoveries')
            ->where('status', 'voided')
            ->update([
                'refund_recovery_voided_minor' => DB::raw(
                    'CASE WHEN refund_recovery_minor > refund_recovered_minor THEN refund_recovery_minor - refund_recovered_minor ELSE 0 END',
                ),
            ]);

        Schema::table('seller_refund_recovery_events', function (Blueprint $table): void {
            $table->string('component', 30)->default('refund_recovery');
        });
    }

    public function down(): void
    {
        if (DB::table('shipping_settlements')->where('settlement_type', 'return')->exists()
            || DB::table('return_requests')->whereNotNull('return_delivery_method')->exists()
            || DB::table('return_requests')->whereNotNull('dropoff_sorting_center_id')->exists()
            || DB::table('shipments')->whereNotNull('return_shipping_quote')->exists()
            || DB::table('seller_refund_recoveries')->where('return_shipping_fee_minor', '>', 0)->exists()) {
            throw new RuntimeException('Cannot roll back return drop-off schema while return delivery or recovery records exist.');
        }

        Schema::table('seller_refund_recovery_events', function (Blueprint $table): void {
            $table->dropColumn('component');
        });

        Schema::table('seller_refund_recoveries', function (Blueprint $table): void {
            $table->dropColumn([
                'refund_recovery_minor',
                'refund_recovered_minor',
                'refund_recovery_voided_minor',
                'return_shipping_fee_minor',
                'return_shipping_fee_recovered_minor',
            ]);
        });

        Schema::table('shipping_settlements', function (Blueprint $table): void {
            $table->dropIndex('shipping_settlement_type_status_idx');
            $table->dropUnique('shipping_settlements_return_request_id_unique');
            $table->dropConstrainedForeignId('return_request_id');
            $table->dropColumn([
                'settlement_type',
                'return_shipping_fee_minor',
                'financially_responsible_party',
            ]);
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('return_shipping_quote');
        });

        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dropoff_sorting_center_id');
            $table->dropColumn(['return_delivery_method', 'dropoff_due_at']);
        });
    }
};
