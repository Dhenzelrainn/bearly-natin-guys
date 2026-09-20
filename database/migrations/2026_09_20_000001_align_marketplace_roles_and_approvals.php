<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY role VARCHAR(32) NOT NULL');
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'logistics_id')) {
                $table->foreignId('logistics_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('logistics_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('users', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('users', 'or_cr_path')) {
                $table->string('or_cr_path')->nullable()->after('business_permit_path');
            }
            if (! Schema::hasColumn('users', 'driver_license_path')) {
                $table->string('driver_license_path')->nullable()->after('or_cr_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $foreignColumns = array_filter(
                ['logistics_id', 'approved_by'],
                fn (string $column) => Schema::hasColumn('users', $column)
            );
            if ($foreignColumns !== []) {
                $table->dropConstrainedForeignId($foreignColumns[0]);
                if (isset($foreignColumns[1])) {
                    $table->dropConstrainedForeignId($foreignColumns[1]);
                }
            }

            $columns = array_filter(
                ['approved_at', 'rejection_reason', 'or_cr_path', 'driver_license_path'],
                fn (string $column) => Schema::hasColumn('users', $column)
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
