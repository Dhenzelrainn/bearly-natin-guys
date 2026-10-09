<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotionalBanner;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerStoreProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_approval_creates_one_draft_store_and_is_idempotent(): void
    {
        $seller = $this->seller('Sundays Market');
        $approver = $this->admin();

        $this->actingAs($approver)
            ->post(route('admin.applications.approve', $seller))
            ->assertRedirect();

        $sellerProfile = $seller->sellerProfile()->firstOrFail();
        $store = $sellerProfile->store()->firstOrFail();

        $this->assertSame('Sundays Market', $store->name);
        $this->assertSame('sundays-market-'.$sellerProfile->id, $store->slug);
        $this->assertSame('draft', $store->publication_status);
        $this->assertNull($store->published_at);
        $this->assertSame($seller->email, $store->contact_email);
        $this->assertSame($seller->contact_number, $store->contact_phone);
        $this->assertDatabaseMissing('stores', [
            'id' => $store->id,
            'publication_status' => 'published',
        ]);

        app(RegistrationLifecycleService::class)->approve($seller->fresh(), $approver);

        $this->assertSame(1, SellerProfile::where('user_id', $seller->id)->count());
        $this->assertSame(1, Store::where('seller_profile_id', $sellerProfile->id)->count());
    }

    public function test_newly_provisioned_store_is_not_visible_to_buyers(): void
    {
        $seller = $this->seller('Hidden Market');
        $approver = $this->admin();

        app(RegistrationLifecycleService::class)->approve($seller, $approver);

        $store = $seller->fresh('sellerProfile')->sellerProfile->store;
        $buyer = User::create([
            'name' => 'Integration Buyer',
            'first_name' => 'Integration',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertNotFound();
    }

    public function test_store_slug_collision_gets_a_deterministic_suffix(): void
    {
        $seller = $this->seller('Market House');
        $targetProfile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Market House',
        ]);
        $reservedSeller = User::create([
            'name' => 'Reserved Seller',
            'first_name' => 'Reserved',
            'last_name' => 'Seller',
            'email' => 'reserved-'.uniqid().'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
        $reservedProfile = SellerProfile::create([
            'user_id' => $reservedSeller->id,
            'legal_business_name' => 'Reserved Seller',
        ]);

        Store::create([
            'seller_profile_id' => $reservedProfile->id,
            'name' => 'Reserved Store',
            'slug' => 'market-house-'.$targetProfile->id,
            'publication_status' => 'draft',
        ]);

        $approver = $this->admin();
        app(RegistrationLifecycleService::class)->approve($seller, $approver);

        $targetProfile = $seller->fresh('sellerProfile')->sellerProfile;

        $this->assertSame(
            'market-house-'.$targetProfile->id.'-2',
            $targetProfile->store->slug,
        );
    }

    public function test_seller_can_update_their_own_store_contact_details(): void
    {
        $seller = $this->approvedSeller('Own Store');
        $store = $seller->sellerProfile->store;

        $this->actingAs($seller)
            ->post(route('seller.store.save'), [
                'email' => 'newemail@example.com',
                'phone' => '+639171234567',
                'intent' => 'draft',
            ])
            ->assertRedirect(route('seller.store'))
            ->assertSessionHas('success', 'Store contact details saved successfully.');

        $this->assertSame('newemail@example.com', $store->fresh()->contact_email);
        $this->assertSame('+639171234567', $store->fresh()->contact_phone);
    }

    public function test_seller_cannot_update_another_seller_store_contact_details(): void
    {
        $sellerA = $this->approvedSeller('Seller A');
        $sellerB = $this->approvedSeller('Seller B');
        $storeA = $sellerA->sellerProfile->store;
        $storeB = $sellerB->sellerProfile->store;

        $storeA->update([
            'contact_email' => 'originala@example.com',
            'contact_phone' => '+639171111111',
        ]);
        $storeB->update([
            'contact_email' => 'originalb@example.com',
            'contact_phone' => '+639171222222',
        ]);

        $this->actingAs($sellerA)
            ->post(route('seller.store.save'), [
                'email' => 'updateda@example.com',
                'phone' => '+639171333333',
                'intent' => 'draft',
                'store_id' => $storeB->id,
            ])
            ->assertRedirect(route('seller.store'))
            ->assertSessionHas('success', 'Store contact details saved successfully.');

        $this->assertSame('updateda@example.com', $storeA->fresh()->contact_email);
        $this->assertSame('+639171333333', $storeA->fresh()->contact_phone);
        $this->assertSame('originalb@example.com', $storeB->fresh()->contact_email);
        $this->assertSame('+639171222222', $storeB->fresh()->contact_phone);
    }

    public function test_store_shows_approved_category_overrides_legacy_user_category(): void
    {
        $category = Category::create([
            'name' => 'Approved Category',
            'slug' => 'approved-category',
        ]);
        $seller = $this->approvedSeller('Category Store');

        $seller->sellerProfile->update(['approved_category_id' => $category->id]);
        $seller->update(['business_category' => 'Legacy Category']);
        $seller->refresh();

        $this->actingAs($seller)
            ->get(route('seller.store'))
            ->assertOk()
            ->assertViewHas('store', fn (array $store): bool => $store['category'] === 'Approved Category');
    }

    public function test_formatted_phone_input_is_saved_as_e164(): void
    {
        $seller = $this->approvedSeller('Phone Format Seller');
        $store = $seller->sellerProfile->store;

        $this->actingAs($seller)
            ->post(route('seller.store.save'), [
                'email' => 'phoneformat@example.com',
                'phone' => '(0917) 123-4567',
                'intent' => 'draft',
            ])
            ->assertRedirect(route('seller.store'))
            ->assertSessionHas('success', 'Store contact details saved successfully.');

        $this->assertSame('+639171234567', $store->fresh()->contact_phone);
    }

    public function test_invalid_store_phone_returns_error_on_phone_field(): void
    {
        $seller = $this->approvedSeller('Invalid Phone Seller');

        $this->actingAs($seller)
            ->from(route('seller.store'))
            ->post(route('seller.store.save'), [
                'email' => 'invalidphone@example.com',
                'phone' => 'not-a-phone',
                'intent' => 'draft',
            ])
            ->assertRedirect(route('seller.store'))
            ->assertSessionHasErrors('phone');
    }

    public function test_submitting_another_seller_store_id_is_ignored(): void
    {
        $sellerA = $this->approvedSeller('Seller A');
        $sellerB = $this->approvedSeller('Seller B');
        $storeA = $sellerA->sellerProfile->store;
        $storeB = $sellerB->sellerProfile->store;

        $storeA->update([
            'contact_email' => 'originala@example.com',
            'contact_phone' => '+639171111111',
        ]);
        $storeB->update([
            'contact_email' => 'originalb@example.com',
            'contact_phone' => '+639171222222',
        ]);

        $this->actingAs($sellerA)
            ->post(route('seller.store.save'), [
                'email' => 'updateda@example.com',
                'phone' => '+639171333333',
                'intent' => 'draft',
                'store_id' => $storeB->id,
            ])
            ->assertRedirect(route('seller.store'));

        $this->assertSame('updateda@example.com', $storeA->fresh()->contact_email);
        $this->assertSame('+639171333333', $storeA->fresh()->contact_phone);
        $this->assertSame('originalb@example.com', $storeB->fresh()->contact_email);
        $this->assertSame('+639171222222', $storeB->fresh()->contact_phone);
    }

    public function test_seller_can_view_store_appearance_page(): void
    {
        $seller = $this->approvedSeller('Appearance Seller');

        $this->actingAs($seller)
            ->get(route('seller.store.appearance'))
            ->assertOk()
            ->assertViewIs('seller.Store.appearance');
    }

    public function test_seller_can_persist_replace_and_remove_store_appearance_files(): void
    {
        Storage::fake('public');
        $seller = $this->approvedSeller('Appearance Files Seller');

        $this->actingAs($seller)
            ->post(route('seller.store.appearance.save'), [
                'description' => 'Handmade home goods for everyday spaces.',
                'announcement' => 'New arrivals every Friday.',
                'operating_hours' => 'Monday-Saturday, 9:00 AM-6:00 PM',
                'shipping_policy' => 'Orders ship within two business days.',
                'return_policy' => 'Returns are accepted within seven days.',
                'warranty_policy' => 'Selected items include a one-year warranty.',
                'promo_banner_form' => '1',
                'promo_banner_titles' => ['Weekend collection'],
                'promo_banner_links' => ['https://example.com/weekend'],
                'profile_photo' => UploadedFile::fake()->create('profile-one.jpg', 10, 'image/jpeg'),
                'cover_photo' => UploadedFile::fake()->create('cover-one.jpg', 10, 'image/jpeg'),
                'promo_banners' => [UploadedFile::fake()->create('promo-one.jpg', 10, 'image/jpeg')],
            ])
            ->assertRedirect(route('seller.store.appearance'))
            ->assertSessionHas('success', 'Store appearance saved successfully.');

        $store = $seller->fresh('sellerProfile.store')->sellerProfile->store;
        $banner = $store->promotionalBanners()->firstOrFail();
        $oldPaths = [
            $store->logo_path,
            $store->banner_path,
            $banner->image_path,
        ];

        $this->assertSame('Handmade home goods for everyday spaces.', $store->description);
        $this->assertSame('New arrivals every Friday.', $store->announcement);
        $this->assertSame('Monday-Saturday, 9:00 AM-6:00 PM', $store->operating_hours);
        $this->assertSame('Orders ship within two business days.', $store->shipping_policy);
        $this->assertSame('Returns are accepted within seven days.', $store->return_policy);
        $this->assertSame('Selected items include a one-year warranty.', $store->warranty_policy);
        $this->assertSame('Weekend collection', $banner->title);
        $this->assertSame('https://example.com/weekend', $banner->link);
        Storage::disk('public')->assertExists($store->logo_path);
        Storage::disk('public')->assertExists($store->banner_path);
        Storage::disk('public')->assertExists($banner->image_path);

        $this->actingAs($seller)
            ->post(route('seller.store.appearance.save'), [
                'description' => 'Updated handmade home goods.',
                'announcement' => 'Updated Friday collection.',
                'operating_hours' => 'Tuesday-Saturday, 10:00 AM-5:00 PM',
                'shipping_policy' => 'Updated shipping policy.',
                'return_policy' => 'Updated return policy.',
                'warranty_policy' => 'Updated warranty policy.',
                'promo_banner_form' => '1',
                'promo_banner_ids' => [$banner->id],
                'promo_banner_titles' => ['Updated collection'],
                'promo_banner_links' => ['https://example.com/updated'],
                'profile_photo' => UploadedFile::fake()->create('profile-two.jpg', 10, 'image/jpeg'),
                'cover_photo' => UploadedFile::fake()->create('cover-two.jpg', 10, 'image/jpeg'),
                'promo_banners' => [UploadedFile::fake()->create('promo-two.jpg', 10, 'image/jpeg')],
            ])
            ->assertRedirect(route('seller.store.appearance'));

        $store = $store->fresh();
        $banner = $banner->fresh();
        Storage::disk('public')->assertMissing($oldPaths[0]);
        Storage::disk('public')->assertMissing($oldPaths[1]);
        Storage::disk('public')->assertMissing($oldPaths[2]);
        Storage::disk('public')->assertExists($store->logo_path);
        Storage::disk('public')->assertExists($store->banner_path);
        Storage::disk('public')->assertExists($banner->image_path);
        $replacementPaths = [$store->logo_path, $store->banner_path, $banner->image_path];

        $this->actingAs($seller)
            ->post(route('seller.store.appearance.save'), [
                'description' => 'Updated handmade home goods.',
                'announcement' => 'Updated Friday collection.',
                'operating_hours' => 'Tuesday-Saturday, 10:00 AM-5:00 PM',
                'shipping_policy' => 'Updated shipping policy.',
                'return_policy' => 'Updated return policy.',
                'warranty_policy' => 'Updated warranty policy.',
                'remove_profile_photo' => '1',
                'remove_cover_photo' => '1',
                'promo_banner_form' => '1',
                'promo_banner_ids' => [],
                'promo_banner_titles' => [],
                'promo_banner_links' => [],
            ])
            ->assertRedirect(route('seller.store.appearance'));

        $store = $store->fresh();
        $this->assertNull($store->logo_path);
        $this->assertNull($store->banner_path);
        $this->assertSame(0, $store->promotionalBanners()->count());
        foreach ($replacementPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_store_appearance_rejects_invalid_uploads_and_non_http_banner_links(): void
    {
        $seller = $this->approvedSeller('Appearance Validation Seller');

        $this->actingAs($seller)
            ->from(route('seller.store.appearance'))
            ->post(route('seller.store.appearance.save'), [
                'promo_banner_form' => '1',
                'profile_photo' => UploadedFile::fake()->create('profile.txt', 10, 'text/plain'),
            ])
            ->assertRedirect(route('seller.store.appearance'))
            ->assertSessionHasErrors('profile_photo');

        $this->actingAs($seller)
            ->from(route('seller.store.appearance'))
            ->post(route('seller.store.appearance.save'), [
                'promo_banner_form' => '1',
                'promo_banner_links' => ['ftp://example.com/not-allowed'],
            ])
            ->assertRedirect(route('seller.store.appearance'))
            ->assertSessionHasErrors('promo_banner_links.0');
    }

    public function test_seller_cannot_mutate_another_sellers_promotional_banner(): void
    {
        Storage::fake('public');
        $sellerA = $this->approvedSeller('Appearance Seller A');
        $sellerB = $this->approvedSeller('Appearance Seller B');
        $storeB = $sellerB->sellerProfile->store;
        $bannerPath = 'seller-store/foreign-banner.jpg';
        Storage::disk('public')->put($bannerPath, 'banner');
        $banner = StorePromotionalBanner::create([
            'store_id' => $storeB->id,
            'image_path' => $bannerPath,
            'title' => 'Seller B banner',
            'link' => 'https://example.com/original',
            'position' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($sellerA)
            ->post(route('seller.store.appearance.save'), [
                'description' => 'Unauthorized update',
                'promo_banner_form' => '1',
                'promo_banner_ids' => [$banner->id],
                'promo_banner_titles' => ['Tampered banner'],
                'promo_banner_links' => ['https://example.com/tampered'],
            ])
            ->assertForbidden();

        $this->assertSame('Seller B banner', $banner->fresh()->title);
        $this->assertSame('https://example.com/original', $banner->fresh()->link);
        Storage::disk('public')->assertExists($bannerPath);
    }

    public function test_buyer_storefront_consumes_persisted_appearance_fields(): void
    {
        $category = Category::create([
            'name' => 'Home and Living',
            'slug' => 'home-and-living',
        ]);
        $seller = $this->approvedSeller('Buyer-facing Store');
        $seller->sellerProfile->update(['approved_category_id' => $category->id]);
        $store = $seller->sellerProfile->store;
        $store->update([
            'description' => 'A persisted store description for buyers.',
            'announcement' => 'Fresh arrivals are available today.',
            'operating_hours' => 'Daily, 9:00 AM-6:00 PM',
            'logo_path' => 'seller-store/buyer-logo.jpg',
            'banner_path' => 'seller-store/buyer-cover.jpg',
            'shipping_policy' => 'Ships within two business days.',
            'return_policy' => 'Returns accepted within seven days.',
            'warranty_policy' => 'One-year warranty on selected products.',
            'publication_status' => 'published',
        ]);
        StorePromotionalBanner::create([
            'store_id' => $store->id,
            'image_path' => 'seller-store/buyer-promo.jpg',
            'title' => 'Buyer-facing promotion',
            'link' => 'https://example.com/promotion',
            'position' => 0,
            'is_active' => true,
        ]);
        $buyer = User::create([
            'name' => 'Integration Buyer',
            'first_name' => 'Integration',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertOk()
            ->assertSee('A persisted store description for buyers.')
            ->assertSee('Home and Living')
            ->assertSee('Fresh arrivals are available today.')
            ->assertSee('Daily, 9:00 AM-6:00 PM')
            ->assertSee('Buyer-facing promotion')
            ->assertSee('Ships within two business days.')
            ->assertSee('Returns accepted within seven days.')
            ->assertSee('One-year warranty on selected products.');
    }

    private function approvedSeller(string $businessName): User
    {
        $seller = $this->seller($businessName);
        app(RegistrationLifecycleService::class)->approve($seller, $this->admin());

        $seller->forceFill(['status' => 'active'])->save();

        return $seller->fresh('sellerProfile.store');
    }

    private function seller(string $businessName): User
    {
        return User::create([
            'name' => 'Integration Seller',
            'first_name' => 'Integration',
            'last_name' => 'Seller',
            'business_name' => $businessName,
            'email' => 'seller-'.uniqid().'@example.test',
            'contact_number' => '+639171234567',
            'role' => 'seller',
            'status' => 'pending',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Integration Admin',
            'first_name' => 'Integration',
            'last_name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.test',
            'role' => 'admin',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }
}
