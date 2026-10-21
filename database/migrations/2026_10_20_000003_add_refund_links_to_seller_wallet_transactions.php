<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL uses the old composite unique index to support the
        // seller_transaction_id foreign key. Give that foreign key its own
        // index before removing the composite unique.
        Schema::table('seller_wallet_transactions', function (Blueprint $table): void {
            $table->index('seller_transaction_id', 'seller_wallet_tx_seller_transaction_index');
        });

        Schema::table('seller_wallet_transactions', function (Blueprint $table): void {
            $table->dropUnique('seller_wallet_tx_source_type_unique');
        });

        Schema::table('seller_wallet_transactions', function (Blueprint $table): void {
            $table->foreignId('refund_id')
                ->nullable()
                ->after('seller_transaction_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unique(['refund_id', 'type'], 'seller_wallet_tx_refund_type_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('seller_wallet_transactions')->whereNotNull('refund_id')->exists()) {
            throw new RuntimeException('Seller wallet refund-linked history exists and cannot be rolled back without losing financial history.');
        }

        Schema::table('seller_wallet_transactions', function (Blueprint $table): void {
            $table->dropForeign(['refund_id']);
            $table->dropUnique('seller_wallet_tx_refund_type_unique');
            $table->dropColumn('refund_id');
        });

        Schema::table('seller_wallet_transactions', function (Blueprint $table): void {
            $table->unique(['seller_transaction_id', 'type'], 'seller_wallet_tx_source_type_unique');
        });

        Schema::table('seller_wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex('seller_wallet_tx_seller_transaction_index');
        });
    }
};
