<?php

namespace Tests\Feature;

use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
