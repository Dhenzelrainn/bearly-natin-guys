<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_cash_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('collection_no', 40)->unique();
            $table->foreignId('shipment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_due_minor');
            $table->unsignedBigInteger('amount_collected_minor');
            $table->bigInteger('discrepancy_minor')->default(0);
            $table->unsignedBigInteger('amount_received_by_logistics_minor')->nullable();
            $table->unsignedBigInteger('amount_remitted_to_finance_minor')->nullable();
            $table->unsignedBigInteger('amount_reconciled_minor')->nullable();
            $table->string('status', 32)->default('collected');
            $table->timestamp('collected_at');
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->string('logistics_reference', 150)->nullable();
            $table->timestamp('logistics_received_at')->nullable();
            $table->foreignId('logistics_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finance_remittance_reference', 150)->nullable();
            $table->timestamp('remitted_to_finance_at')->nullable();
            $table->foreignId('remitted_to_finance_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finance_reference', 150)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['logistics_profile_id', 'status', 'collected_at'], 'cod_cash_logistics_status_idx');
            $table->index(['payment_id', 'status'], 'cod_cash_payment_status_idx');
            $table->index(['seller_order_id', 'status'], 'cod_cash_seller_order_status_idx');
        });

        Schema::create('cod_cash_collection_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cod_cash_collection_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('from_role', 24)->nullable();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('to_role', 24)->nullable();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 150)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['cod_cash_collection_id', 'occurred_at'], 'cod_cash_events_collection_time_idx');
        });

        Schema::create('refund_cash_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('payout_no', 40)->unique();
            $table->foreignId('refund_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('return_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 40)->default('awaiting_logistics_receipt');
            $table->string('finance_issue_reference', 150)->nullable();
            $table->timestamp('issued_to_logistics_at')->nullable();
            $table->foreignId('issued_to_logistics_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('logistics_receipt_reference', 150)->nullable();
            $table->timestamp('received_by_logistics_at')->nullable();
            $table->foreignId('received_by_logistics')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_by_rider_at')->nullable();
            $table->foreignId('received_by_rider_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('amount_returned_to_logistics_minor')->nullable();
            $table->string('cash_return_reference', 150)->nullable();
            $table->timestamp('cash_returned_to_logistics_at')->nullable();
            $table->foreignId('cash_returned_to_logistics_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('logistics_cash_return_reference', 150)->nullable();
            $table->timestamp('logistics_cash_return_received_at')->nullable();
            $table->foreignId('logistics_cash_return_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_name', 160)->nullable();
            $table->string('acknowledgment_reference', 150)->nullable();
            $table->string('acknowledgment_disk', 32)->nullable();
            $table->string('acknowledgment_path', 500)->nullable();
            $table->string('acknowledgment_mime_type', 100)->nullable();
            $table->string('acknowledgment_original_name', 255)->nullable();
            $table->unsignedBigInteger('acknowledgment_size_bytes')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('logistics_verified_at')->nullable();
            $table->foreignId('logistics_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('delivery_attempt_count')->default(0);
            $table->timestamps();
            $table->index(['logistics_profile_id', 'status', 'created_at'], 'refund_cash_logistics_status_idx');
            $table->index(['rider_profile_id', 'status'], 'refund_cash_rider_status_idx');
        });

        Schema::create('refund_cash_payout_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_cash_payout_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('reference', 150)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['refund_cash_payout_id', 'occurred_at'], 'refund_cash_events_payout_time_idx');
        });

        Schema::create('seller_payout_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_payout_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('commission_minor');
            $table->bigInteger('net_minor');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['seller_payout_id', 'seller_transaction_id'], 'seller_payout_items_batch_idx');
        });

        Schema::table('seller_payouts', function (Blueprint $table): void {
            $table->string('external_method', 40)->nullable()->after('reference');
            $table->string('receipt_disk', 32)->nullable()->after('external_method');
            $table->string('receipt_path', 500)->nullable()->after('receipt_disk');
            $table->string('receipt_mime_type', 100)->nullable()->after('receipt_path');
            $table->string('receipt_original_name', 255)->nullable()->after('receipt_mime_type');
            $table->unsignedBigInteger('receipt_size_bytes')->nullable()->after('receipt_original_name');
            $table->foreignId('paid_by')->nullable()->after('receipt_size_bytes')->constrained('users')->nullOnDelete();
            $table->text('payment_reason')->nullable()->after('paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('seller_payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn([
                'external_method',
                'receipt_disk',
                'receipt_path',
                'receipt_mime_type',
                'receipt_original_name',
                'receipt_size_bytes',
                'payment_reason',
            ]);
        });
        Schema::dropIfExists('seller_payout_items');
        Schema::dropIfExists('refund_cash_payout_events');
        Schema::dropIfExists('refund_cash_payouts');
        Schema::dropIfExists('cod_cash_collection_events');
        Schema::dropIfExists('cod_cash_collections');
    }
};
