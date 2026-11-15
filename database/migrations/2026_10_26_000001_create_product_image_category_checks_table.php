<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_category_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->string('image_disk', 40)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->char('image_sha256', 64)->nullable();
            $table->json('listing_snapshot');
            $table->char('snapshot_hash', 64);
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->string('prompt_version', 40);
            $table->string('status', 30)->default('pending');
            $table->string('visible_item_description', 180)->nullable();
            $table->string('reason', 320)->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->boolean('is_current')->default(true);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'is_current']);
            $table->index(['status', 'created_at']);
            $table->index('snapshot_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_category_checks');
    }
};
