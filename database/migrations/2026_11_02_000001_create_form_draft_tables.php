<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('flow', 24);
            $table->char('browser_token_hash', 64);
            $table->longText('payload');
            $table->unsignedTinyInteger('step')->default(1);
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['browser_token_hash', 'flow'], 'registration_drafts_browser_flow_unique');
        });

        Schema::create('seller_product_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('seller_product_draft_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_product_draft_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 24);
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('color', 120)->nullable();
            $table->timestamps();

            $table->index(['seller_product_draft_id', 'purpose', 'position'], 'seller_product_draft_images_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_product_draft_images');
        Schema::dropIfExists('seller_product_drafts');
        Schema::dropIfExists('registration_drafts');
    }
};
