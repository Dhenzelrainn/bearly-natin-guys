<?php

use Database\Seeders\MarketplaceTaxonomySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_categories') || ! Schema::hasTable('categories')) {
            return;
        }

        // On a fresh install, business categories are inserted by DatabaseSeeder
        // after migrations. The seeder will populate taxonomy after those roots
        // exist. Existing installs must already contain every explicitly mapped
        // approved business category or stop before seeding partial mappings.
        if (DB::table('categories')->count() === 0) {
            return;
        }

        $requiredSlugs = MarketplaceTaxonomySeeder::requiredBusinessCategorySlugs();
        $foundSlugs = DB::table('categories')
            ->whereIn('slug', $requiredSlugs)
            ->pluck('slug')
            ->all();
        $missingSlugs = array_values(array_diff($requiredSlugs, $foundSlugs));

        if ($missingSlugs !== []) {
            throw new RuntimeException(
                'Marketplace taxonomy seed stopped. Missing approved business category slugs: '.implode(', ', $missingSlugs).'.',
            );
        }

        app(MarketplaceTaxonomySeeder::class)->run();
    }

    public function down(): void
    {
        // Canonical taxonomy may now be referenced by products and audit history.
        // Its schema migration refuses to drop non-empty taxonomy tables.
    }
};
