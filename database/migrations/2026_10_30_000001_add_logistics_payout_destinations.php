<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_profile_id')->constrained()->restrictOnDelete();
            $table->string('channel', 24);
            $table->string('provider_name', 120);
            $table->string('account_name', 160);
            $table->text('destination_value');
            $table->char('destination_last4', 4);
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->index(['logistics_profile_id', 'status'], 'logistics_payout_accounts_profile_status_idx');
            $table->index(['channel', 'status'], 'logistics_payout_accounts_channel_status_idx');
        });

        Schema::table('shipping_settlements', function (Blueprint $table): void {
            $table->foreignId('logistics_payout_account_id')
                ->nullable()
                ->constrained('logistics_payout_accounts')
                ->restrictOnDelete();
            $table->string('remitted_to_provider', 120)->nullable();
            $table->string('remitted_to_account_name', 160)->nullable();
            $table->char('remitted_to_last4', 4)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('logistics_payout_accounts')
            && DB::table('logistics_payout_accounts')->exists()) {
            throw new RuntimeException('Logistics payout destination data exists; preserve it before rolling back this financial migration.');
        }

        if (Schema::hasTable('shipping_settlements')
            && DB::table('shipping_settlements')->whereNotNull('logistics_payout_account_id')->exists()) {
            throw new RuntimeException('Shipping settlements reference Logistics payout destinations; preserve them before rollback.');
        }

        Schema::table('shipping_settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logistics_payout_account_id');
            $table->dropColumn([
                'remitted_to_provider',
                'remitted_to_account_name',
                'remitted_to_last4',
            ]);
        });

        Schema::dropIfExists('logistics_payout_accounts');
    }
};
