<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MIGRATION_OWNER = '2026_10_18_000003_add_fashion_electronics_category_mismatch_rule';

    public function up(): void
    {
        $now = now();

        $this->insertRuleIfMissing([
            'code' => 'CATEGORY_MISMATCH_FASHION_ELECTRONICS',
            'name' => 'Electronics product listed under Fashion and Apparel',
            'rule_type' => 'category_mismatch',
            'target_field' => 'name_description',
            'pattern' => null,
            'category_id' => null,
            'severity' => 'high',
            'default_action' => 'hold',
            'configuration' => [
                'managed_by_migration' => self::MIGRATION_OWNER,
                'mismatched_terms_by_category_slug' => [
                    'fashion-and-apparel' => [
                        'smartphone', 'smartphones',
                        'cellphone', 'cellphones', 'cell phone', 'cell phones',
                        'mobile phone', 'mobile phones',
                        'laptop', 'laptops',
                        'tablet', 'tablets',
                        'smartwatch', 'smartwatches',
                        'earbud', 'earbuds', 'earphone', 'earphones',
                        'headphone', 'headphones', 'headset', 'headsets',
                        'power bank', 'powerbank',
                        'phone charger', 'laptop charger', 'wireless charger',
                        'charging cable', 'usb cable',
                        'bluetooth speaker', 'bluetooth headphones', 'bluetooth earbuds', 'bluetooth headset',
                        'gaming console', 'game console', 'playstation', 'xbox',
                        'drone', 'drones',
                        'wifi router', 'wireless router', 'modem',
                        'graphics card', 'motherboard',
                        'hard drive', 'external hard drive', 'solid state drive',
                        'flash drive', 'usb flash drive', 'memory card',
                        'keyboard', 'gaming mouse', 'wireless mouse',
                        'computer monitor', 'gaming monitor',
                        'desktop computer', 'gaming laptop', 'gaming pc',
                        'smart tv', 'television',
                        'action camera', 'webcam', 'dslr',
                    ],
                ],
            ],
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $rule = DB::table('compliance_rules')
            ->where('code', 'CATEGORY_MISMATCH_FASHION_ELECTRONICS')
            ->first();

        if (! $rule) {
            return;
        }

        $configuration = json_decode((string) $rule->configuration, true) ?: [];

        if (($configuration['managed_by_migration'] ?? null) !== self::MIGRATION_OWNER) {
            return;
        }

        $hasHistory = DB::table('product_compliance_check_matches')->where('rule_id', $rule->id)->exists()
            || DB::table('product_violations')->where('rule_id', $rule->id)->exists();

        if ($hasHistory) {
            DB::table('compliance_rules')
                ->where('id', $rule->id)
                ->update([
                    'is_active' => false,
                    'version' => ((int) $rule->version) + 1,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('compliance_rules')->where('id', $rule->id)->delete();
    }

    /** @param array<string, mixed> $rule */
    private function insertRuleIfMissing(array $rule): void
    {
        $exists = DB::table('compliance_rules')->where('code', $rule['code'])->exists();

        if ($exists) {
            return;
        }

        $rule['configuration'] = json_encode($rule['configuration'], JSON_THROW_ON_ERROR);

        DB::table('compliance_rules')->insert($rule);
    }
};
