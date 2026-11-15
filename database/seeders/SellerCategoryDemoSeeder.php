<?php

namespace Database\Seeders;

use App\Models\Address;
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
     * Deliberately synthetic location for local/testing-only Seller demo accounts.
     * The recognized city code lets existing Seller pickup workflows resolve it.
     *
     * @var array<string, string|null>
     */
    private const DEMO_PICKUP_ADDRESS = [
        'house_number' => '1',
        'street' => 'Bearly Demo Seller Street',
        'barangay' => 'Balibago',
        'region' => 'Region IV-A (CALABARZON)',
        'psgc_region_code' => '0400000000',
        'city_municipality' => 'City of Santa Rosa',
        'province' => 'Laguna',
        'postal_code' => '4026',
        'psgc_province_code' => '0403400000',
        'psgc_city_code' => '0403428000',
        'psgc_barangay_code' => null,
    ];

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
                $email = 'seller.demo.'.$slug.'@bearly.test';

                if (SellerProfile::query()->whereApprovedCategoryId($category->id)->exists()) {
                    $demoUser = User::query()->where('email', $email)->first();
                    $demoProfile = $demoUser?->sellerProfile()->first();

                    if ($demoUser
                        && $demoProfile
                        && (int) $demoProfile->approved_category_id === (int) $category->id) {
                        $this->ensureDemoPickupAddress($demoUser, $demoProfile);
                    }

                    continue;
                }

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
                    'region' => self::DEMO_PICKUP_ADDRESS['region'],
                    'region_code' => self::DEMO_PICKUP_ADDRESS['psgc_region_code'],
                    'province' => self::DEMO_PICKUP_ADDRESS['province'],
                    'city' => self::DEMO_PICKUP_ADDRESS['city_municipality'],
                    'barangay' => self::DEMO_PICKUP_ADDRESS['barangay'],
                    'street_address' => self::DEMO_PICKUP_ADDRESS['house_number'].' '.self::DEMO_PICKUP_ADDRESS['street'],
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

                $this->ensureDemoPickupAddress($user, $profile);

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

    private function ensureDemoPickupAddress(User $user, SellerProfile $profile): void
    {
        $this->fillMissingDemoLocationIdentity($user);

        $address = $profile->pickup_address_id
            ? Address::query()
                ->whereKey($profile->pickup_address_id)
                ->where('user_id', $user->id)
                ->first()
            : null;

        $address ??= Address::query()
            ->where('user_id', $user->id)
            ->where('label', 'Pickup')
            ->latest('id')
            ->first();

        $address ??= Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => $user->contact_number ?: $user->phone ?: 'Not provided',
            ...self::DEMO_PICKUP_ADDRESS,
            'is_default_shipping' => false,
            'is_default_pickup' => true,
        ]);

        if ((int) $profile->pickup_address_id !== (int) $address->id) {
            $profile->forceFill(['pickup_address_id' => $address->id])->save();
        }
    }

    private function fillMissingDemoLocationIdentity(User $user): void
    {
        $location = [
            'region' => self::DEMO_PICKUP_ADDRESS['region'],
            'region_code' => self::DEMO_PICKUP_ADDRESS['psgc_region_code'],
            'province' => self::DEMO_PICKUP_ADDRESS['province'],
            'city' => self::DEMO_PICKUP_ADDRESS['city_municipality'],
            'barangay' => self::DEMO_PICKUP_ADDRESS['barangay'],
            'street_address' => self::DEMO_PICKUP_ADDRESS['house_number'].' '.self::DEMO_PICKUP_ADDRESS['street'],
        ];
        $missing = [];

        foreach ($location as $field => $value) {
            if (blank($user->{$field})) {
                $missing[$field] = $value;
            }
        }

        if ($missing !== []) {
            $user->forceFill($missing)->save();
        }
    }
}
