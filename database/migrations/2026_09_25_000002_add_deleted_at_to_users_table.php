<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Compatibility migration.
         * The base users migration already creates deleted_at.
         */
    }

    public function down(): void
    {
        /*
         * Intentionally empty.
         * Do not remove a column owned by the base users migration.
         */
    }
};