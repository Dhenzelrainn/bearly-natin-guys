<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SellerRegistrationCategorySeeder extends Seeder
{
    /**
     * Validate current Category and Buyer-root data before writing.
     *
     * @return array{taxonomy: array, fresh: bool, category_ids: array<string, int|null>}
     */
    public function preflight(): array
    {
        $taxonomy = json_decode(
            file_get_contents(resource_path('data/buyer-categories.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($taxonomy) || count($taxonomy) !== 12) {
            throw new RuntimeException('Seller registration reconciliation expects the existing 12 Buyer categories.');
        }

        $categoryColumns = ['id', 'parent_id', 'name', 'slug', 'is_active', 'position'];
        $hasRegistrationOptionColumn = Schema::hasColumn('categories', 'is_registration_option');
        if ($hasRegistrationOptionColumn) {
            $categoryColumns[] = 'is_registration_option';
        }

        $businessCategories = Category::query()->get($categoryColumns);
        $marketplaceCategories = MarketplaceCategory::query()
            ->get(['id', 'name', 'slug', 'is_active', 'position']);
        $fresh = $businessCategories->isEmpty() && $marketplaceCategories->isEmpty();

        if (! $fresh) {
            if ($marketplaceCategories->isEmpty()) {
                throw new RuntimeException('Buyer marketplace roots are missing; no roots will be recreated for a non-empty Category database.');
            }

            foreach ($taxonomy as $entry) {
                $root = $marketplaceCategories->firstWhere('slug', $entry['slug']);
                if (! $root || $root->name !== $entry['name'] || ! $root->is_active) {
                    throw new RuntimeException("Existing Buyer root [{$entry['slug']}] is missing, inactive, or renamed; no registration data was written.");
                }
            }
        }

        $categoryIds = [];
        foreach ($taxonomy as $entry) {
            $nameKey = self::nameKey((string) $entry['name']);
            $nameMatches = $businessCategories
                ->filter(fn (Category $category): bool => self::nameKey($category->name) === $nameKey)
                ->values();

            if ($nameMatches->count() > 1) {
                throw new RuntimeException("Multiple business Category rows match [{$entry['name']}] after normalization; no registration data was written.");
            }

            $category = $nameMatches->first();
            if ($category) {
                if (
                    $category->name !== $entry['name']
                    || $category->slug !== $entry['slug']
                    || $category->parent_id !== null
                ) {
                    throw new RuntimeException("Business Category [{$entry['name']}] is not an exact top-level match; no legacy row will be renamed or repurposed.");
                }
                if (! $category->is_active) {
                    throw new RuntimeException("Business Category [{$entry['name']}] is inactive; the migration will not reactivate it.");
                }

                if (! $fresh) {
                    $buyerRootId = $marketplaceCategories->firstWhere('slug', $entry['slug'])?->id;
                    $hasUnexpectedMapping = DB::table('marketplace_category_business_categories')
                        ->where('category_id', $category->id)
                        ->where('marketplace_category_id', '<>', $buyerRootId)
                        ->exists();
                    if ($hasUnexpectedMapping) {
                        throw new RuntimeException("Business Category [{$entry['name']}] has an existing mapping to another Buyer root; no mapping was changed.");
                    }
                }

                $categoryIds[$entry['slug']] = (int) $category->id;
            } else {
                $slugCollision = $businessCategories->firstWhere('slug', $entry['slug']);
                if ($slugCollision) {
                    throw new RuntimeException("Buyer slug [{$entry['slug']}] is already used by a different business Category; no legacy row was changed.");
                }

                $categoryIds[$entry['slug']] = null;
            }

            $normalizedSubcategories = array_map(
                fn (string $name): string => self::nameKey($name),
                $entry['subcategories'] ?? [],
            );
            if (count($normalizedSubcategories) !== count(array_unique($normalizedSubcategories))) {
                throw new RuntimeException("Buyer root [{$entry['slug']}] contains duplicate normalized subcategory names.");
            }

            $root = $marketplaceCategories->firstWhere('slug', $entry['slug']);
            if ($root) {
                foreach ($entry['subcategories'] ?? [] as $name) {
                    $nameKey = self::nameKey((string) $name);
                    $existsByName = MarketplaceSubcategory::query()
                        ->where('marketplace_category_id', $root->id)
                        ->where('name_key', $nameKey)
                        ->exists();
                    $baseSlug = Str::slug((string) $name) ?: 'subcategory';
                    $existsBySlug = MarketplaceSubcategory::query()
                        ->where('marketplace_category_id', $root->id)
                        ->where('slug', $baseSlug)
                        ->exists();

                    if (! $existsByName && $existsBySlug) {
                        throw new RuntimeException("Buyer subcategory slug [{$baseSlug}] conflicts under [{$entry['slug']}]; no taxonomy rows were changed.");
                    }
                }
            }
        }

        if ($hasRegistrationOptionColumn) {
            $registrationCategoryIds = array_values(array_filter($categoryIds));
            $unexpectedOption = $businessCategories->first(
                fn (Category $category): bool => $category->is_registration_option
                    && ! in_array((int) $category->id, $registrationCategoryIds, true),
            );

            if ($unexpectedOption) {
                throw new RuntimeException("Legacy business Category [{$unexpectedOption->slug}] is already marked as a Seller registration option; no flags were changed.");
            }
        }

        return [
            'taxonomy' => $taxonomy,
            'fresh' => $fresh,
            'category_ids' => $categoryIds,
        ];
    }

    public function run(): void
    {
        $plan = $this->preflight();

        DB::transaction(function () use ($plan): void {
            $buyerRoots = [];
            foreach ($plan['taxonomy'] as $position => $entry) {
                $root = $plan['fresh']
                    ? MarketplaceCategory::query()->create([
                        'name' => $entry['name'],
                        'slug' => $entry['slug'],
                        'icon' => $entry['icon'] ?? null,
                        'is_active' => true,
                        'position' => $position + 1,
                    ])
                    : MarketplaceCategory::query()->where('slug', $entry['slug'])->firstOrFail();

                $buyerRoots[$entry['slug']] = $root;
            }

            foreach ($plan['taxonomy'] as $entry) {
                $root = $buyerRoots[$entry['slug']];
                $categoryId = $plan['category_ids'][$entry['slug']];

                $businessCategory = $categoryId === null
                    ? Category::query()->create([
                        'parent_id' => null,
                        'name' => $entry['name'],
                        'slug' => $entry['slug'],
                        'is_active' => true,
                        'position' => $root->position,
                        'is_registration_option' => true,
                    ])
                    : Category::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();

                if (! $businessCategory->is_registration_option) {
                    $businessCategory->update(['is_registration_option' => true]);
                }

                $businessCategory->marketplaceCategories()->syncWithoutDetaching([$root->id]);
            }

            foreach ($plan['taxonomy'] as $entry) {
                $root = $buyerRoots[$entry['slug']];
                foreach ($entry['subcategories'] ?? [] as $position => $sourceName) {
                    $name = Str::squish((string) $sourceName);
                    $nameKey = self::nameKey($name);
                    if ($name === '' || $nameKey === '') {
                        continue;
                    }

                    if (MarketplaceSubcategory::query()
                        ->where('marketplace_category_id', $root->id)
                        ->where('name_key', $nameKey)
                        ->exists()) {
                        continue;
                    }

                    $baseSlug = Str::slug($name) ?: 'subcategory';
                    $slug = $baseSlug;
                    $suffix = 2;
                    while (MarketplaceSubcategory::query()
                        ->where('marketplace_category_id', $root->id)
                        ->where('slug', $slug)
                        ->exists()) {
                        $slug = $baseSlug.'-'.$suffix;
                        $suffix++;
                    }

                    MarketplaceSubcategory::query()->create([
                        'marketplace_category_id' => $root->id,
                        'name' => $name,
                        'name_key' => $nameKey,
                        'slug' => $slug,
                        'is_active' => true,
                        'position' => $position + 1,
                    ]);
                }
            }
        });
    }

    private static function nameKey(string $name): string
    {
        return Str::lower(Str::squish($name));
    }
}
