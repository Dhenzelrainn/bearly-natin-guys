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
        foreach (['admin' => 'Administrator', 'buyer' => 'Buyer', 'seller' => 'Seller', 'logistics' => 'Logistics', 'rider' => 'Rider'] as $name => $label) {
            Role::firstOrCreate(['name' => $name], ['display_name' => $label]);
        }
        $categories = ['Fashion and Apparel', 'Jewelry and Watches', 'Electronics and Gadgets', 'Home and Furniture', 'Beauty and Personal Care', 'Food and Gourmet', 'Sports and Outdoors', 'Books and Stationery', 'Automotive and Parts', 'Toys and Hobbies'];
        foreach ($categories as $i => $name) {
            Category::firstOrCreate(['slug' => str($name)->slug()], ['name' => $name, 'position' => $i + 1]);
        }
        $rules = [
            ['code' => 'PROHIBITED_FIREARMS', 'name' => 'Firearms and ammunition', 'rule_type' => 'keyword', 'target_field' => 'name_description', 'pattern' => 'firearm|handgun|pistol|rifle|shotgun|ammunition|live rounds', 'severity' => 'critical', 'default_action' => 'block'],
            ['code' => 'PROHIBITED_ILLEGAL_DRUGS', 'name' => 'Illegal drugs', 'rule_type' => 'keyword', 'target_field' => 'name_description', 'pattern' => 'cocaine|methamphetamine|shabu|heroin|ecstasy', 'severity' => 'critical', 'default_action' => 'block'],
            ['code' => 'SUSPICIOUS_HEALTH_CLAIMS', 'name' => 'Unverified health claims', 'rule_type' => 'phrase', 'target_field' => 'name_description', 'pattern' => 'guaranteed cure|instant cure|fda approved|no side effects', 'severity' => 'high', 'default_action' => 'hold'],
            ['code' => 'CATEGORY_MISMATCH', 'name' => 'Product category does not match seller approval', 'rule_type' => 'category_mismatch', 'target_field' => 'category', 'pattern' => null, 'severity' => 'high', 'default_action' => 'hold'],
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
        }    }
}
