<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class MarketplaceTaxonomySeeder extends Seeder
{
    /**
     * Explicitly approved business-category -> Buyer category mappings.
     * Deliberately excludes Books, Kids, Furniture, Pet, and Automotive until
     * their mapping is explicitly approved by the Owner.
     *
     * @var array<string, list<string>>
     */
    private const BUSINESS_CATEGORY_MAPPINGS = [
        'fashion-and-apparel' => ['women-s-apparel', 'men-s-apparel'],
        'home-and-furniture' => ['home-and-garden'],
        'electronics-and-gadgets' => ['electronics-and-gadgets'],
        'jewelry-and-watches' => ['jewelry-and-watches'],
        'beauty-and-personal-care' => ['health-and-beauty'],
        'food-and-gourmet' => ['food-and-gourmet'],
        'sports-and-outdoors' => ['sports-and-outdoors'],
    ];

    public function run(): void
    {
        $missingCategories = array_diff(
            self::requiredBusinessCategorySlugs(),
            Category::query()
                ->whereIn('slug', self::requiredBusinessCategorySlugs())
                ->pluck('slug')
                ->all(),
        );
        if ($missingCategories !== []) {
            throw new RuntimeException('Required approved business categories are missing: '.implode(', ', $missingCategories).'.');
        }

        $taxonomy = json_decode(
            file_get_contents(resource_path('data/buyer-categories.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($taxonomy as $position => $entry) {
            $marketplaceCategory = MarketplaceCategory::query()->firstOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name' => $entry['name'],
                    'icon' => $entry['icon'] ?? null,
                    'is_active' => true,
                    'position' => $position + 1,
                ],
            );

            foreach (self::BUSINESS_CATEGORY_MAPPINGS as $businessSlug => $marketplaceSlugs) {
                if (! in_array($entry['slug'], $marketplaceSlugs, true)) {
                    continue;
                }

                $businessCategory = Category::query()->where('slug', $businessSlug)->first();
                if (! $businessCategory) {
                    throw new RuntimeException("Required approved business category [{$businessSlug}] is missing; canonical marketplace mapping was not seeded.");
                }

                $marketplaceCategory->businessCategories()->syncWithoutDetaching([$businessCategory->id]);
            }

            if (! in_array($entry['slug'], array_merge(...array_values(self::BUSINESS_CATEGORY_MAPPINGS)), true)) {
                continue;
            }

            foreach (($entry['subcategories'] ?? []) as $subcategoryPosition => $name) {
                $name = Str::squish((string) $name);
                $nameKey = $this->nameKey($name);
                if ($name === '' || $nameKey === '') {
                    continue;
                }

                if (MarketplaceSubcategory::query()
                    ->where('marketplace_category_id', $marketplaceCategory->id)
                    ->where('name_key', $nameKey)
                    ->exists()) {
                    continue;
                }

                $baseSlug = Str::slug($name) ?: 'subcategory';
                if (MarketplaceSubcategory::query()
                    ->where('marketplace_category_id', $marketplaceCategory->id)
                    ->where('slug', $baseSlug)
                    ->exists()) {
                    continue;
                }

                $slug = $baseSlug;
                $suffix = 2;
                while (MarketplaceSubcategory::query()
                    ->where('marketplace_category_id', $marketplaceCategory->id)
                    ->where('slug', $slug)
                    ->exists()) {
                    $slug = $baseSlug.'-'.$suffix;
                    $suffix++;
                }

                MarketplaceSubcategory::query()->create([
                    'marketplace_category_id' => $marketplaceCategory->id,
                    'name' => $name,
                    'name_key' => $nameKey,
                    'slug' => $slug,
                    'is_active' => true,
                    'position' => $subcategoryPosition + 1,
                ]);
            }
        }
    }

    public static function nameKey(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    /** @return list<string> */
    public static function requiredBusinessCategorySlugs(): array
    {
        return array_keys(self::BUSINESS_CATEGORY_MAPPINGS);
    }
}
