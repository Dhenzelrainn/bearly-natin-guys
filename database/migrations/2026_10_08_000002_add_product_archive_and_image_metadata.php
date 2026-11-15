<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('archived_previous_status', 30)->nullable()->after('archived_at');
            $table->timestamp('archived_previous_published_at')->nullable()->after('archived_previous_status');
        });

        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('disk', 40)->default('public')->after('path');
            $table->string('mime_type', 100)->nullable()->after('disk');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            $table->string('purpose', 40)->default('product_image')->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn(['disk', 'mime_type', 'size_bytes', 'purpose']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['archived_previous_status', 'archived_previous_published_at']);
        });
    }
};
