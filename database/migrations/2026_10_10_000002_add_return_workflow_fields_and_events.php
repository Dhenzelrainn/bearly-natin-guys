<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->string('status', 40)->default('awaiting_seller')->change();
        });

        Schema::table('pickup_requests', function (Blueprint $table): void {
            $table->foreignId('return_request_id')
                ->nullable()
                ->after('store_id')
                ->constrained('return_requests')
                ->nullOnDelete();
            $table->unique('return_request_id');
            $table->json('pickup_address_snapshot')->nullable()->after('pickup_address_id');
            $table->date('return_pickup_date')->nullable();
            $table->string('return_pickup_window', 30)->nullable();
        });

        Schema::table('return_evidence', function (Blueprint $table): void {
            $table->string('mime_type', 100)->nullable()->after('type');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('original_name');
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignId('processed_by')
                ->nullable()
                ->after('approved_by')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('return_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['return_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_events');

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('processed_by');
        });

        Schema::table('return_evidence', function (Blueprint $table): void {
            $table->dropColumn(['mime_type', 'size_bytes']);
        });

        Schema::table('pickup_requests', function (Blueprint $table): void {
            $table->dropUnique(['return_request_id']);
            $table->dropConstrainedForeignId('return_request_id');
            $table->dropColumn([
                'pickup_address_snapshot',
                'return_pickup_date',
                'return_pickup_window',
            ]);
        });

        Schema::table('return_requests', function (Blueprint $table): void {
            $table->string('status', 40)->default('submitted')->change();
        });
    }
};
