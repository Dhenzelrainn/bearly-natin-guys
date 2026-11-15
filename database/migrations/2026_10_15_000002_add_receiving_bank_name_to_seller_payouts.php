<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_payouts', function (Blueprint $table): void {
            $table->string('receiving_bank_name', 120)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        if (DB::table('seller_payouts')->whereNotNull('receiving_bank_name')->exists()) {
            throw new RuntimeException('Cannot remove receiving bank names while legacy payout records contain them.');
        }

        Schema::table('seller_payouts', function (Blueprint $table): void {
            $table->dropColumn('receiving_bank_name');
        });
    }
};
