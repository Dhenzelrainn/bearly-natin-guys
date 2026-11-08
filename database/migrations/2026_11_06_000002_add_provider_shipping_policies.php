<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->string('scope', 40)->default('platform')->after('category');
            $table->string('scope_key', 100)->nullable()->after('scope');
            $table->index(
                ['scope', 'scope_key', 'status'],
                'policies_scope_key_status_index'
            );
        });

        Schema::table('policy_versions', function (Blueprint $table): void {
            $table->string('title', 180)->nullable()->after('summary');
            $table->string('category', 80)->nullable()->after('title');
            $table->text('source_reference')->nullable()->after('category');
        });

        Schema::create('shipping_policy_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key', 100)->unique();
            $table->foreignId('manager_logistics_profile_id')
                ->nullable()
                ->constrained('logistics_profiles', 'id', 'sps_manager_profile_fk')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users', 'id', 'sps_updated_by_fk')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('shipping_policy_settings')->exists()
            || DB::table('policies')->where('scope', 'shipping_provider')->exists()
            || DB::table('policy_versions')->where(function ($query): void {
                $query->whereNotNull('title')
                    ->orWhereNotNull('category')
                    ->orWhereNotNull('source_reference');
            })->exists()) {
            throw new RuntimeException(
                'Cannot roll back provider shipping policy schema while manager assignments or policy history exist.'
            );
        }

        Schema::dropIfExists('shipping_policy_settings');

        Schema::table('policy_versions', function (Blueprint $table): void {
            $table->dropColumn('source_reference');
        });

        Schema::table('policies', function (Blueprint $table): void {
            $table->dropIndex('policies_scope_key_status_index');
            $table->dropColumn(['scope', 'scope_key']);
        });
    }
};
