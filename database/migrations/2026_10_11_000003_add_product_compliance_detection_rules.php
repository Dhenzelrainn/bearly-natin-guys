<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MIGRATION_OWNER = '2026_10_11_000003_add_product_compliance_detection_rules';

    public function up(): void
    {
        $now = now();

        $this->insertRuleIfMissing([
            'code' => 'PROHIBITED_FIREARM_GUN_ALIAS',
            'name' => 'Prohibited firearm listing (gun)',
            'rule_type' => 'keyword',
            'target_field' => 'name_description',
            'pattern' => 'gun|guns',
            'category_id' => null,
            'severity' => 'critical',
            'default_action' => 'block',
            'configuration' => ['match_mode' => 'whole_word', 'managed_by_migration' => self::MIGRATION_OWNER],
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertRuleIfMissing([
            'code' => 'CATEGORY_MISMATCH_ELECTRONICS_APPAREL',
            'name' => 'Apparel product listed under Electronics and Gadgets',
            'rule_type' => 'category_mismatch',
            'target_field' => 'name_description',
            'pattern' => null,
            'category_id' => null,
            'severity' => 'high',
            'default_action' => 'hold',
            'configuration' => [
                'managed_by_migration' => self::MIGRATION_OWNER,
                'mismatched_terms_by_category_slug' => [
                    'electronics-and-gadgets' => [
                        'shirt', 'shirts', 't-shirt', 't-shirts', 'tshirt', 'tshirts',
                        'polo shirt', 'polo shirts', 'shorts', 'short pants', 'bermuda shorts',
                        'dress', 'dresses', 'skirt', 'skirts', 'blouse', 'blouses',
                        'jeans', 'trousers',
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
        foreach ([
            'PROHIBITED_FIREARM_GUN_ALIAS',
            'CATEGORY_MISMATCH_ELECTRONICS_APPAREL',
        ] as $code) {
            $rule = DB::table('compliance_rules')->where('code', $code)->first();

            if (! $rule) {
                continue;
            }

            $configuration = json_decode((string) $rule->configuration, true) ?: [];

            if (($configuration['managed_by_migration'] ?? null) !== self::MIGRATION_OWNER) {
                continue;
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

                continue;
            }

            DB::table('compliance_rules')->where('id', $rule->id)->delete();
        }
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
