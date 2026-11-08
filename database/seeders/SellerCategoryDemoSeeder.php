<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\SellerProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SellerCategoryDemoSeeder extends Seeder
{
    /**
     * Reserved local/testing demo credentials for categories currently without
     * a Seller. Never use these accounts or passwords in a production database.
     *
     * @var array<string, string>
     */
    private const DEMO_PASSWORDS = [
        'jewelry-and-watches' => 'BearlyDemo-Jewelry26!',
        'food-and-gourmet' => 'BearlyDemo-Food26!',
        'sports-and-outdoors' => 'BearlyDemo-Sports26!',
        'pet-supplies' => 'BearlyDemo-Pet26!',
        'women-s-apparel' => 'BearlyDemo-Women26!',
        'kids-and-baby' => 'BearlyDemo-Kids26!',
        'home-and-garden' => 'BearlyDemo-Home26!',
        'furniture-and-office-equipment' => 'BearlyDemo-Furniture26!',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                static::class.' may only run in local or testing environments.'
            );
        }

        $credentials = DB::transaction(function (): array {
            $categories = Category::query()
                ->whereIn('slug', array_keys(self::DEMO_PASSWORDS))
                ->where('is_active', true)
                ->where('is_registration_option', true)
                ->orderBy('slug')
                ->lockForUpdate()
                ->get()
                ->keyBy('slug');

            if ($categories->count() !== count(self::DEMO_PASSWORDS)) {
                throw new RuntimeException(
                    'The expected active Seller registration categories are not all available; no demo accounts were created.'
                );
            }

            $sellerRole = Role::query()->firstOrCreate(
                ['name' => 'seller'],
                ['display_name' => 'Seller'],
            );
            $createdCredentials = [];

            foreach (self::DEMO_PASSWORDS as $slug => $password) {
                /** @var Category $category */
                $category = $categories->get($slug);

                if (SellerProfile::query()->whereApprovedCategoryId($category->id)->exists()) {
                    continue;
                }

                $email = 'seller.demo.'.$slug.'@bearly.test';
                if (User::withTrashed()->where('email', $email)->exists()) {
                    throw new RuntimeException(
                        "Reserved demo email [{$email}] is already in use; no demo accounts were created."
                    );
                }

                $now = now();
                $businessName = 'Demo '.$category->name.' Store';
                $displayName = 'Demo '.$category->name.' Seller';

                $user = User::query()->create([
                    'name' => $displayName,
                    'first_name' => 'Demo',
                    'last_name' => $category->name.' Seller',
                    'sex' => 'prefer_not_to_say',
                    'birthday' => '1995-01-01',
                    'email' => $email,
                    'email_verified_at' => $now,
                    'role' => 'seller',
                    'business_name' => $businessName,
                    'business_category' => $category->name,
                    'status' => 'active',
                    'password' => Hash::make($password),
                ]);

                $user->roles()->syncWithoutDetaching([
                    $sellerRole->id => [
                        'assigned_at' => $now,
                    ],
                ]);

                $profile = SellerProfile::query()->create([
                    'user_id' => $user->id,
                    'legal_business_name' => $businessName,
                    'approved_category_id' => $category->id,
                    'approved_at' => $now,
                ]);

                // Reuse the normal Seller Store factory path. It creates a draft
                // Store and its default Store-only sections, without bypassing
                // StorePublicationService readiness requirements.
                app(SellerProductService::class)->storeForProfile($profile, $user);

                $createdCredentials[] = [
                    'category' => $category->name,
                    'email' => $email,
                    'password' => $password,
                ];
            }

            return $createdCredentials;
        });

        if ($credentials === []) {
            $this->command?->info('No Seller category demo accounts were needed; every target category already has a Seller.');

            return;
        }

        $this->command?->info('Local Seller demo accounts created. Stores are drafts and are not Buyer-visible yet.');
        $this->command?->warn('Demo-only approved profiles; no real Seller applications or identity documents were reviewed.');

        if (app()->environment('local')) {
            foreach ($credentials as $credential) {
                $this->command?->line('Category: '.$credential['category']);
                $this->command?->line('Email: '.$credential['email']);
                $this->command?->line('Password: '.$credential['password']);
                $this->command?->line('Store status: draft');
            }
        }
    }
}
