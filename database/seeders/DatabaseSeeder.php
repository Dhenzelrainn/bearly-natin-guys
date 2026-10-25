<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ComplianceRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categoryMismatchRuntimeTerms = config('product_compliance.category_mismatch_runtime_terms', []);

        foreach (['admin' => 'Administrator', 'buyer' => 'Buyer', 'seller' => 'Seller', 'logistics' => 'Logistics', 'rider' => 'Rider'] as $name => $label) {
            Role::firstOrCreate(['name' => $name], ['display_name' => $label]);
        }
        $categories = ['Fashion and Apparel', 'Jewelry and Watches', 'Electronics and Gadgets', 'Home and Furniture', 'Beauty and Personal Care', 'Food and Gourmet', 'Sports and Outdoors', 'Books and Stationery', 'Automotive and Parts', 'Toys and Hobbies'];
        foreach ($categories as $i => $name) {
            Category::firstOrCreate(['slug' => str($name)->slug()], ['name' => $name, 'position' => $i + 1]);
        }
        $this->call(MarketplaceTaxonomySeeder::class);
        $rules = [
            ['code' => 'PROHIBITED_FIREARMS', 'name' => 'Firearms and ammunition', 'rule_type' => 'keyword', 'target_field' => 'name_description', 'pattern' => 'firearm|handgun|pistol|rifle|shotgun|ammunition|live rounds', 'severity' => 'critical', 'default_action' => 'block'],
            [
                'code' => 'PROHIBITED_FIREARM_GUN_ALIAS',
                'name' => 'Prohibited firearm listing (gun)',
                'rule_type' => 'keyword',
                'target_field' => 'name_description',
                'pattern' => 'gun|guns',
                'severity' => 'critical',
                'default_action' => 'block',
                'configuration' => [
                    'match_mode' => 'whole_word',
                    'managed_by_migration' => '2026_10_11_000003_add_product_compliance_detection_rules',
                ],
                'is_active' => true,
                'version' => 1,
            ],
            ['code' => 'PROHIBITED_ILLEGAL_DRUGS', 'name' => 'Illegal drugs', 'rule_type' => 'keyword', 'target_field' => 'name_description', 'pattern' => 'cocaine|methamphetamine|shabu|heroin|ecstasy', 'severity' => 'critical', 'default_action' => 'block'],
            ['code' => 'SUSPICIOUS_HEALTH_CLAIMS', 'name' => 'Unverified health claims', 'rule_type' => 'phrase', 'target_field' => 'name_description', 'pattern' => 'guaranteed cure|instant cure|fda approved|no side effects', 'severity' => 'high', 'default_action' => 'hold'],
            ['code' => 'CATEGORY_MISMATCH', 'name' => 'Product category does not match seller approval', 'rule_type' => 'category_mismatch', 'target_field' => 'category', 'pattern' => null, 'severity' => 'high', 'default_action' => 'hold'],
            [
                'code' => 'CATEGORY_MISMATCH_ELECTRONICS_APPAREL',
                'name' => 'Apparel product listed under Electronics and Gadgets',
                'rule_type' => 'category_mismatch',
                'target_field' => 'name_description',
                'pattern' => null,
                'category_id' => null,
                'severity' => 'high',
                'default_action' => 'hold',
                'configuration' => [
                    'managed_by_migration' => '2026_10_11_000003_add_product_compliance_detection_rules',
                    'mismatched_terms_by_category_slug' => [
                        'electronics-and-gadgets' => array_merge([
                            'shirt', 'shirts', 't-shirt', 't-shirts', 'tshirt', 'tshirts',
                            'polo shirt', 'polo shirts', 'shorts', 'short pants', 'bermuda shorts',
                            'dress', 'dresses', 'skirt', 'skirts', 'blouse', 'blouses',
                            'jeans', 'trousers',
                        ], $categoryMismatchRuntimeTerms['CATEGORY_MISMATCH_ELECTRONICS_APPAREL']['electronics-and-gadgets'] ?? []),
                    ],
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'code' => 'CATEGORY_MISMATCH_FASHION_ELECTRONICS',
                'name' => 'Electronics product listed under Fashion and Apparel',
                'rule_type' => 'category_mismatch',
                'target_field' => 'name_description',
                'pattern' => null,
                'category_id' => null,
                'severity' => 'high',
                'default_action' => 'hold',
                'configuration' => [
                    'managed_by_migration' => '2026_10_18_000003_add_fashion_electronics_category_mismatch_rule',
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
            ],
        ];
        foreach ($rules as $rule) {
            ComplianceRule::updateOrCreate(['code' => $rule['code']], $rule);
        }
        $adminEmail = env('SEED_ADMIN_EMAIL', 'admin@bearly.test');
        $adminPassword = env('SEED_ADMIN_PASSWORD');

        /*
         * A predictable demo password is allowed only in local/testing.
         * Production must explicitly provide SEED_ADMIN_PASSWORD.
         */
        if (
            blank($adminPassword)
            && app()->environment(['local', 'testing'])
        ) {
            $adminPassword = 'Password123';
        }

        if (filled($adminEmail) && filled($adminPassword)) {
            $admin = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => 'Bearly Admin',
                    'first_name' => 'Bearly',
                    'last_name' => 'Admin',
                    'sex' => 'prefer_not_to_say',
                    'birthday' => '2000-01-01',
                    'contact_number' => '09000000000',
                    'province' => 'Metro Manila',
                    'city' => 'Manila',
                    'barangay' => 'System',
                    'street_address' => 'Bearly Administration',
                    'role' => 'admin',
                    'status' => 'active',
                    'password' => Hash::make($adminPassword),
                ]
            );

            $admin->roles()->syncWithoutDetaching([
                Role::where('name', 'admin')->value('id') => [
                    'assigned_at' => now(),
                ],
            ]);
        }
    }
}
