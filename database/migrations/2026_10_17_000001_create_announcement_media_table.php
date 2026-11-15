<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 20);
            $table->string('disk', 40)->default('local');
            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['announcement_id', 'position']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('announcement_media') && DB::table('announcement_media')->exists()) {
            throw new RuntimeException(
                'Cannot roll back announcement media metadata while stored announcement files are still referenced.'
            );
        }

        Schema::dropIfExists('announcement_media');
    }
};
