<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('available_balance_minor')->default(0);
            $table->unsignedBigInteger('pending_balance_minor')->default(0);
            $table->unsignedBigInteger('reserved_balance_minor')->default(0);
            $table->unsignedBigInteger('total_withdrawn_minor')->default(0);
            $table->timestamps();
        });

        Schema::create('seller_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->string('bank_name', 120);
            $table->string('account_name', 160);
            $table->text('account_number');
            $table->char('account_number_last4', 4);
            $table->string('status', 20)->default('active');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['seller_profile_id', 'status'], 'seller_bank_accounts_owner_status_idx');
        });

        Schema::create('seller_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->string('withdrawal_no', 40)->unique();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_bank_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('bank_name_snapshot', 120);
            $table->string('account_name_snapshot', 160);
            $table->char('account_number_last4', 4);
            $table->string('status', 30)->default('pending_review');
            $table->string('idempotency_key', 80);
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('processing_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['seller_profile_id', 'idempotency_key'], 'seller_withdrawals_seller_idempotency_unique');
            $table->index(['status', 'requested_at'], 'seller_withdrawals_status_requested_idx');
            $table->index(['seller_profile_id', 'created_at'], 'seller_withdrawals_seller_created_idx');
        });

        Schema::create('seller_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_no', 40)->unique();
            $table->foreignId('seller_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('seller_withdrawal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('seller_payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('available_delta_minor')->default(0);
            $table->bigInteger('pending_delta_minor')->default(0);
            $table->bigInteger('reserved_delta_minor')->default(0);
            $table->bigInteger('withdrawn_delta_minor')->default(0);
            $table->unsignedBigInteger('available_balance_after_minor');
            $table->unsignedBigInteger('pending_balance_after_minor');
            $table->unsignedBigInteger('reserved_balance_after_minor');
            $table->unsignedBigInteger('total_withdrawn_after_minor');
            $table->string('reference', 150)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['seller_transaction_id', 'type'], 'seller_wallet_tx_source_type_unique');
            $table->unique(['seller_withdrawal_id', 'type'], 'seller_wallet_tx_withdrawal_type_unique');
            $table->unique('seller_payout_id', 'seller_wallet_tx_legacy_payout_unique');
            $table->index(['seller_wallet_id', 'created_at'], 'seller_wallet_tx_wallet_time_idx');
            $table->index(['seller_wallet_id', 'type'], 'seller_wallet_tx_wallet_type_idx');
        });

        Schema::create('seller_withdrawal_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_withdrawal_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['seller_withdrawal_id', 'to_status'], 'seller_withdrawal_history_target_unique');
            $table->index(['seller_withdrawal_id', 'occurred_at'], 'seller_withdrawal_history_time_idx');
        });

        Schema::create('seller_withdrawal_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no', 40)->unique();
            $table->foreignId('seller_withdrawal_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('bank_name', 120);
            $table->char('account_number_last4', 4);
            $table->string('bank_reference', 150)->nullable();
            $table->date('transfer_date');
            $table->string('status', 30)->default('pending_verification');
            $table->string('proof_disk', 32)->nullable();
            $table->string('proof_path', 500)->nullable();
            $table->string('proof_mime_type', 100)->nullable();
            $table->string('proof_original_name', 255)->nullable();
            $table->unsignedBigInteger('proof_size_bytes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->foreignId('failure_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('failure_confirmed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        $this->backfillPaidLegacyPayouts();
    }

    public function down(): void
    {
        foreach ([
            'seller_wallet_transactions',
            'seller_withdrawal_transfers',
            'seller_withdrawal_status_histories',
            'seller_withdrawals',
            'seller_bank_accounts',
            'seller_wallets',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Seller Wallet data exists; this financial migration cannot be rolled back without an explicit data-preservation plan.');
            }
        }

        Schema::dropIfExists('seller_withdrawal_transfers');
        Schema::dropIfExists('seller_withdrawal_status_histories');
        Schema::dropIfExists('seller_wallet_transactions');
        Schema::dropIfExists('seller_withdrawals');
        Schema::dropIfExists('seller_bank_accounts');
        Schema::dropIfExists('seller_wallets');
    }

    private function backfillPaidLegacyPayouts(): void
    {
        DB::table('seller_payouts')
            ->whereIn('status', ['paid', 'completed'])
            ->whereNotNull('paid_at')
            ->where('net_minor', '>', 0)
            ->orderBy('id')
            ->chunkById(100, function ($payouts): void {
                foreach ($payouts as $payout) {
                    DB::transaction(function () use ($payout): void {
                        if (DB::table('seller_wallet_transactions')->where('seller_payout_id', $payout->id)->exists()) {
                            return;
                        }

                        $wallet = DB::table('seller_wallets')
                            ->where('seller_profile_id', $payout->seller_profile_id)
                            ->lockForUpdate()
                            ->first();

                        if (! $wallet) {
                            $now = now();
                            $walletId = DB::table('seller_wallets')->insertGetId([
                                'seller_profile_id' => $payout->seller_profile_id,
                                'available_balance_minor' => 0,
                                'pending_balance_minor' => 0,
                                'reserved_balance_minor' => 0,
                                'total_withdrawn_minor' => 0,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $wallet = DB::table('seller_wallets')->where('id', $walletId)->lockForUpdate()->first();
                        }

                        $amountMinor = (int) $payout->net_minor;
                        $totalWithdrawn = (int) $wallet->total_withdrawn_minor + $amountMinor;
                        DB::table('seller_wallets')->where('id', $wallet->id)->update([
                            'total_withdrawn_minor' => $totalWithdrawn,
                            'updated_at' => now(),
                        ]);

                        DB::table('seller_wallet_transactions')->insert([
                            'transaction_no' => 'SWX-'.Str::upper((string) Str::ulid()),
                            'seller_wallet_id' => $wallet->id,
                            'seller_payout_id' => $payout->id,
                            'type' => 'legacy_withdrawal_completion',
                            'amount_minor' => $amountMinor,
                            'available_delta_minor' => 0,
                            'pending_delta_minor' => 0,
                            'reserved_delta_minor' => 0,
                            'withdrawn_delta_minor' => $amountMinor,
                            'available_balance_after_minor' => (int) $wallet->available_balance_minor,
                            'pending_balance_after_minor' => (int) $wallet->pending_balance_minor,
                            'reserved_balance_after_minor' => (int) $wallet->reserved_balance_minor,
                            'total_withdrawn_after_minor' => $totalWithdrawn,
                            'reference' => $payout->reference,
                            'description' => 'Historical paid Seller payout '.$payout->payout_no,
                            'idempotency_key' => 'legacy-seller-payout:'.$payout->id,
                            'actor_user_id' => $payout->paid_by,
                            'metadata' => json_encode(['seller_payout_id' => (int) $payout->id]),
                            'created_at' => $payout->paid_at,
                        ]);
                    });
                }
            });
    }
};
