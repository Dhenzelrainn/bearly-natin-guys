<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_items', function (Blueprint $table): void {
            $table->string('quarantine_review_status', 20)->nullable();
            $table->string('quarantine_final_condition_code', 40)->nullable();
            $table->string('quarantine_final_resolution', 30)->nullable();
            $table->text('quarantine_review_note')->nullable();
            $table->foreignId('quarantine_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quarantine_reviewed_at')->nullable();
            $table->index(
                ['quarantine_review_status', 'return_request_id'],
                'return_items_quarantine_review_status_index',
            );
        });

        DB::table('return_items')
            ->where('resolution', 'quarantine')
            ->update(['quarantine_review_status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $table): void {
            $table->dropIndex('return_items_quarantine_review_status_index');
            $table->dropConstrainedForeignId('quarantine_reviewed_by');
            $table->dropColumn([
                'quarantine_review_status',
                'quarantine_final_condition_code',
                'quarantine_final_resolution',
                'quarantine_review_note',
                'quarantine_reviewed_at',
            ]);
        });
    }
};
