<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('financially_responsible_party', 20)->nullable()->after('status');
            $table->text('financial_responsibility_reason')->nullable()->after('financially_responsible_party');
            $table->unsignedBigInteger('payout_amount_minor')->nullable()->after('amount_minor');
            $table->date('external_paid_on')->nullable()->after('provider_reference');
            $table->string('payout_receipt_disk', 50)->nullable()->after('external_paid_on');
            $table->string('payout_receipt_path', 500)->nullable()->after('payout_receipt_disk');
            $table->string('payout_receipt_mime_type', 100)->nullable()->after('payout_receipt_path');
            $table->string('payout_receipt_original_name', 255)->nullable()->after('payout_receipt_mime_type');
            $table->unsignedBigInteger('payout_receipt_size_bytes')->nullable()->after('payout_receipt_original_name');
        });

        Schema::create('seller_refund_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('recovered_amount_minor')->default(0);
            $table->string('status', 30)->default('pending');
            $table->timestamps();
            $table->index(['seller_profile_id', 'status']);
        });

        Schema::create('seller_refund_recovery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_refund_recovery_id')
                ->constrained('seller_refund_recoveries')
                ->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('seller_payout_id')->nullable()->constrained('seller_payouts')->restrictOnDelete();
            $table->string('method', 30);
            $table->unsignedBigInteger('amount_minor');
            $table->string('external_reference', 150);
            $table->date('recovered_on');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['seller_refund_recovery_id', 'created_at'], 'seller_refund_recovery_events_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_refund_recovery_events');
        Schema::dropIfExists('seller_refund_recoveries');

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn([
                'financially_responsible_party',
                'financial_responsibility_reason',
                'payout_amount_minor',
                'external_paid_on',
                'payout_receipt_disk',
                'payout_receipt_path',
                'payout_receipt_mime_type',
                'payout_receipt_original_name',
                'payout_receipt_size_bytes',
            ]);
        });
    }
};
