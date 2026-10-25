<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('buyer_paid_shipping_fee_minor')->nullable();
            $table->string('shipping_funding_source', 40)->nullable();
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('buyer_paid_shipping_fee_minor')->nullable();
            $table->string('shipping_funding_source', 40)->nullable();
        });

        Schema::create('shipping_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('settlement_no', 40)->unique();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_attempt_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('zone_level');
            $table->unsignedBigInteger('original_shipping_fee_minor');
            $table->unsignedBigInteger('buyer_paid_shipping_fee_minor');
            $table->unsignedBigInteger('platform_shipping_subsidy_minor');
            $table->string('shipping_funding_source', 40);
            $table->unsignedBigInteger('logistics_share_minor');
            $table->unsignedBigInteger('rider_share_minor');
            $table->string('status', 24)->default('pending');
            $table->timestamp('eligible_at');
            $table->unsignedBigInteger('remitted_amount_minor')->nullable();
            $table->string('remittance_method', 30)->nullable();
            $table->string('remittance_reference', 150)->nullable();
            $table->date('remitted_on')->nullable();
            $table->timestamp('remitted_at')->nullable();
            $table->foreignId('remitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remittance_reason')->nullable();
            $table->timestamps();
            $table->index(['logistics_profile_id', 'status', 'eligible_at'], 'shipping_settlement_provider_status_idx');
        });

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->foreignId('shipping_settlement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('logistics_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['logistics_profile_id', 'status', 'earned_at'], 'rider_earnings_provider_status_idx');
        });

        Schema::create('rider_earning_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_earning_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 30);
            $table->string('reference', 150);
            $table->date('paid_on');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->index(['logistics_profile_id', 'created_at'], 'rider_earning_payouts_provider_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_earning_payouts');

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->dropIndex('rider_earnings_provider_status_idx');
            $table->dropUnique('rider_earnings_shipping_settlement_id_unique');
            $table->dropConstrainedForeignId('shipping_settlement_id');
            $table->dropConstrainedForeignId('shipment_id');
            $table->dropConstrainedForeignId('logistics_profile_id');
        });

        Schema::dropIfExists('shipping_settlements');

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn(['buyer_paid_shipping_fee_minor', 'shipping_funding_source']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['buyer_paid_shipping_fee_minor', 'shipping_funding_source']);
        });
    }
};
