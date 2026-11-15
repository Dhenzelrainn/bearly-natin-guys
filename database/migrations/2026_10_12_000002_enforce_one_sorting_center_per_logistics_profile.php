<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $profiles = DB::table('logistics_profiles as profiles')
            ->leftJoin('sorting_centers as centers', 'centers.logistics_profile_id', '=', 'profiles.id')
            ->select('profiles.id')
            ->selectRaw('COUNT(centers.id) as center_count')
            ->selectRaw('SUM(CASE WHEN centers.deleted_at IS NULL AND centers.id IS NOT NULL THEN 1 ELSE 0 END) as available_center_count')
            ->groupBy('profiles.id')
            ->havingRaw('COUNT(centers.id) <> 1 OR SUM(CASE WHEN centers.deleted_at IS NULL AND centers.id IS NOT NULL THEN 1 ELSE 0 END) <> 1')
            ->orderBy('profiles.id')
            ->get();

        if ($profiles->isNotEmpty()) {
            $profileIds = $profiles->pluck('id')->implode(', ');

            throw new RuntimeException(
                "Cannot enforce one Sorting Center per Logistics profile. Review profile IDs {$profileIds}; each must have exactly one non-deleted Sorting Center. No center records were changed."
            );
        }

        Schema::table('sorting_centers', function (Blueprint $table): void {
            $table->unique(
                'logistics_profile_id',
                'sorting_centers_logistics_profile_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sorting_centers', function (Blueprint $table): void {
            $table->dropUnique('sorting_centers_logistics_profile_id_unique');
        });
    }
};
