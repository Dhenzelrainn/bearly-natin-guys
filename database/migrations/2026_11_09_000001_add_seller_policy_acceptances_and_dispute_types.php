<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('policy_version_id')->constrained()->restrictOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->unique(['user_id', 'policy_version_id'], 'policy_acceptances_user_version_unique');
        });

        Schema::table('disputes', function (Blueprint $table): void {
            $table->string('type', 40)->default('order_issue')->after('dispute_no');
            $table->index(['type', 'status'], 'disputes_type_status_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('policy_acceptances') && DB::table('policy_acceptances')->exists()) {
            throw new RuntimeException('Cannot roll back while policy acknowledgment history exists.');
        }

        if (Schema::hasTable('disputes') && DB::table('disputes')->where('type', '!=', 'order_issue')->exists()) {
            throw new RuntimeException('Cannot roll back while typed dispute cases exist.');
        }

        Schema::dropIfExists('policy_acceptances');

        Schema::table('disputes', function (Blueprint $table): void {
            $table->dropIndex('disputes_type_status_index');
            $table->dropColumn('type');
        });
    }
};
