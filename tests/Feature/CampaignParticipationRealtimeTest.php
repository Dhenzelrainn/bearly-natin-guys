<?php

namespace Tests\Feature;

use App\Events\CampaignParticipationSubmitted;
use App\Models\Address;
use App\Models\Category;
use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignParticipationRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_seller_join_broadcasts_only_a_campaign_refresh_signal_after_saving(): void
    {
        [$seller, $store, $product] = $this->sellerWithProduct();
        $campaign = $this->campaign();

        Event::fake([CampaignParticipationSubmitted::class]);

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'items' => [[
                    'product_id' => $product->id,
                    'discount_percent' => '20',
                ]],
            ])
            ->assertRedirect(route('seller.promotions.campaigns.show', $campaign));

        $participation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $store->id)
            ->firstOrFail();

        Event::assertDispatched(
            CampaignParticipationSubmitted::class,
            function (CampaignParticipationSubmitted $event) use ($campaign, $participation): bool {
                $channels = collect($event->broadcastOn())
                    ->map(fn ($channel): string => $channel->name)
                    ->all();

                return $event->campaignId === (int) $campaign->id
                    && $event->broadcastAs() === 'campaign.participation-submitted'
                    && $event->broadcastWith() === ['campaign_id' => (int) $campaign->id]
                    && $channels === ['private-admin.campaigns']
                    && $participation->exists;
            },
        );
    }

    public function test_admin_participation_refresh_returns_current_rows_and_rejects_sellers(): void
    {
        [$seller, $store, $product] = $this->sellerWithProduct();
        $campaign = $this->campaign();

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'items' => [[
                    'product_id' => $product->id,
                    'discount_percent' => '20',
                ]],
            ])
            ->assertRedirect();

        $admin = $this->user('admin');
        $response = $this->actingAs($admin)
            ->getJson(route('admin.campaigns.participations.refresh', $campaign))
            ->assertOk()
            ->assertJsonStructure(['data' => ['html'], 'message'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $html = (string) $response->json('data.html');
        $this->assertStringContainsString('data-campaign-participation-rows', $html);
        $this->assertStringContainsString('data-campaign-participation="'.PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $store->id)
            ->value('id').'"', $html);
        $this->assertStringContainsString(e($store->name), $html);
        $this->assertStringContainsString(e($seller->name), $html);
        $this->assertStringContainsString(e($product->name), $html);

        $this->actingAs($seller)
            ->getJson(route('admin.campaigns.participations.refresh', $campaign))
            ->assertForbidden();
    }

    public function test_admin_campaign_broadcast_channel_rejects_non_admin_and_inactive_users(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');

        $admin = $this->user('admin');
        $seller = $this->user('seller');
        $inactiveAdmin = $this->user('admin', 'suspended');

        $this->actingAs($admin)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-admin.campaigns',
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-admin.campaigns',
            ])
            ->assertForbidden();

        $this->actingAs($inactiveAdmin)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-admin.campaigns',
            ])
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Store, 2: Product} */
    private function sellerWithProduct(): array
    {
        $seller = $this->user('seller');
        $category = Category::query()->create([
            'name' => 'Campaign Realtime Category '.$this->sequence,
            'slug' => 'campaign-realtime-category-'.$this->sequence,
        ]);
        $address = Address::query()->create([
            'user_id' => $seller->id,
            'label' => 'Pickup',
            'recipient_name' => $seller->name,
            'phone' => '+639171234567',
            'street' => '10 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'psgc_province_code' => '0434',
            'psgc_city_code' => '043424000',
            'is_default_shipping' => true,
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Campaign Realtime Business '.$seller->id,
            'approved_category_id' => $category->id,
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Campaign Realtime Store '.$seller->id,
            'slug' => 'campaign-realtime-store-'.$seller->id,
            'publication_status' => 'published',
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Campaign Realtime Product '.$seller->id,
            'slug' => 'campaign-realtime-product-'.$seller->id,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'payment_methods' => ['cod', 'online'],
            'voucher_eligible' => true,
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'CAMPAIGN-REALTIME-'.$seller->id,
            'name' => 'Standard',
            'price_minor' => 10000,
            'stock_on_hand' => 10,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        return [$seller, $store, $product];
    }

    private function campaign(): PlatformPromotion
    {
        return PlatformPromotion::query()->create([
            'name' => 'Campaign Realtime '.$this->sequence,
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
            'funding_type' => PlatformPromotion::FUNDING_SELLER,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_OPEN,
            'description' => 'Campaign realtime test.',
            'terms' => 'Accept campaign terms.',
            'value_minor' => 0,
            'minimum_spend_minor' => 0,
            'minimum_discount_percent' => 10,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(7)->toDateString(),
            'join_deadline' => now('Asia/Manila')->addDays(2)->toDateString(),
            'requires_approval' => true,
            'max_participating_stores' => null,
            'max_products_per_store' => null,
            'budget_minor' => null,
            'is_claimable' => false,
            'is_public' => true,
            'is_featured' => false,
            'position' => 0,
        ]);
    }

    private function user(string $role, string $status = 'active'): User
    {
        $sequence = ++$this->sequence;

        return User::query()->create([
            'name' => Str::headline($role).' Campaign Realtime '.$sequence,
            'first_name' => ucfirst($role),
            'last_name' => 'Campaign Realtime '.$sequence,
            'email' => "{$role}-campaign-realtime-{$sequence}@example.test",
            'role' => $role,
            'status' => $status,
            'password' => Hash::make('Password123'),
        ]);
    }
}
