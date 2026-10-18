<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SellerAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function sellerFixture(): User
    {
        $seller = User::factory()->create([
            'name' => 'Test Seller Owner',
            'role' => 'seller',
            'status' => 'active',
            'email' => 'test.seller@example.test',
            'phone' => '+639171234567',
            'contact_number' => '+639171234567',
            'birthday' => '1996-03-14',
            'sex' => 'female',
            'street_address' => '12 Sampaguita Street',
            'barangay' => 'Balibago',
            'city' => 'Santa Rosa',
            'province' => 'Laguna',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'last_login_at' => now()->subDay(),
        ]);

        SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Test Seller Store',
            'approved_category_id' => Category::query()->firstOrFail()->id,
            'approved_at' => now()->subWeek(),
        ]);

        return $seller->fresh();
    }

    public function test_account_page_renders_the_authenticated_sellers_own_details(): void
    {
        $seller = $this->sellerFixture();

        $this->actingAs($seller)
            ->get(route('seller.settings.account'))
            ->assertOk()
            ->assertSee('Test Seller Owner')
            ->assertSee('test.seller@example.test')
            ->assertSee('+639171234567')
            ->assertSee('March 14, 1996')
            ->assertSee('Female')
            ->assertSee('Santa Rosa')
            ->assertSee('Identity verified')
            ->assertSee('Email verified')
            ->assertDontSee('Bea Rivera')
            ->assertDontSee('Frontend preview only');
    }

    public function test_seller_can_update_the_contact_number(): void
    {
        $seller = $this->sellerFixture();

        $this->actingAs($seller)
            ->from(route('seller.settings.account'))
            ->patch(route('seller.settings.account.update'), [
                'contact' => '09189876543',
            ])
            ->assertRedirect(route('seller.settings.account').'#contact')
            ->assertSessionHas('success');

        $seller->refresh();

        $this->assertSame($seller->phone, $seller->contact_number);
        $this->assertStringStartsWith('+63', (string) $seller->phone);
        $this->assertStringContainsString('9189876543', (string) $seller->phone);
    }

    public function test_seller_contact_update_rejects_invalid_numbers(): void
    {
        $seller = $this->sellerFixture();

        $this->actingAs($seller)
            ->from(route('seller.settings.account'))
            ->patch(route('seller.settings.account.update'), [
                'contact' => 'not-a-number',
            ])
            ->assertSessionHasErrors(['contact']);

        $this->assertSame('+639171234567', $seller->fresh()->contact_number);
    }

    public function test_security_page_reflects_recorded_activity_and_current_session_only(): void
    {
        $seller = $this->sellerFixture();

        $this->actingAs($seller)
            ->get(route('seller.settings.security'))
            ->assertOk()
            ->assertSee('Current browser session')
            ->assertSee('Successful sign-in')
            ->assertSee('Email verified')
            ->assertSee('Account created')
            ->assertSee('Not available yet')
            ->assertDontSee('Sign out other sessions')
            ->assertDontSee('Windows · Microsoft Edge');
    }

    public function test_security_page_renders_when_optional_activity_timestamps_are_missing(): void
    {
        $seller = $this->sellerFixture();

        $seller->forceFill([
            'last_login_at' => null,
            'phone_verified_at' => null,
        ])->save();

        $this->actingAs($seller)
            ->get(route('seller.settings.security'))
            ->assertOk()
            ->assertSee('Email verified')
            ->assertSee('Account created')
            ->assertSee('No sign-in recorded yet')
            ->assertDontSee('Successful sign-in')
            ->assertDontSee('Contact verified');
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $seller = $this->sellerFixture();

        $this->actingAs($seller)
            ->from(route('seller.settings.security'))
            ->patch(route('seller.settings.security.password'), [
                'current_password' => 'wrong-password',
                'new_password' => 'new-secure-password',
                'new_password_confirmation' => 'new-secure-password',
            ])
            ->assertSessionHasErrors(['current_password']);

        $this->assertTrue(Hash::check('password', $seller->fresh()->password));
    }

    public function test_seller_can_change_the_password_with_current_password_verification(): void
    {
        $seller = $this->sellerFixture();

        $this->actingAs($seller)
            ->from(route('seller.settings.security'))
            ->patch(route('seller.settings.security.password'), [
                'current_password' => 'password',
                'new_password' => 'brand-new-password',
                'new_password_confirmation' => 'brand-new-password',
            ])
            ->assertRedirect(route('seller.settings.security').'#password')
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('brand-new-password', $seller->fresh()->password));
    }

    public function test_password_change_rejects_mismatched_confirmation(): void
    {
        $seller = $this->sellerFixture();

        $this->actingAs($seller)
            ->from(route('seller.settings.security'))
            ->patch(route('seller.settings.security.password'), [
                'current_password' => 'password',
                'new_password' => 'brand-new-password',
                'new_password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors(['new_password']);

        $this->assertTrue(Hash::check('password', $seller->fresh()->password));
    }

    public function test_non_sellers_cannot_open_seller_account_pages(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($buyer)
            ->get(route('seller.settings.account'))
            ->assertForbidden();

        $this->actingAs($buyer)
            ->get(route('seller.settings.security'))
            ->assertForbidden();

        $this->actingAs($buyer)
            ->patch(route('seller.settings.security.password'), [
                'current_password' => 'password',
                'new_password' => 'brand-new-password',
                'new_password_confirmation' => 'brand-new-password',
            ])
            ->assertForbidden();
    }
}
