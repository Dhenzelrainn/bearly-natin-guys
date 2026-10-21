<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_admin_can_create_edit_review_and_transition_a_campaign(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-admin-flow');
        $product = $this->product($store, $category, 'Campaign Eligible Product');
        $variant = $this->variant($product, 'CAMPAIGN-ADMIN-001', 10000);
        $admin = $this->user('admin');
        $startsOn = now('Asia/Manila')->addDays(3)->toDateString();
        $joinDeadline = now('Asia/Manila')->addDays(2)->toDateString();
        $endsOn = now('Asia/Manila')->addDays(8)->toDateString();
        $createPayload = [
            'name' => 'Flash Deal Test Event',
            'campaign_type' => PlatformPromotion::CAMPAIGN_FLASH_DEAL,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'description' => 'A platform-funded campaign event.',
            'terms' => 'Keep the submitted campaign price during the event.',
            'campaign_notes' => 'Internal calendar note.',
            'budget' => '500.00',
            'starts_on' => $startsOn,
            'start_time' => '09:30',
            'ends_on' => $endsOn,
            'end_time' => '18:00',
            'join_deadline' => $joinDeadline,
            'requires_approval' => '1',
            'max_participating_stores' => '3',
            'max_products_per_store' => '2',
            'status' => PlatformPromotion::STATUS_OPEN,
            'scope_submitted' => '1',
            'store_ids' => [$store->id],
            'category_ids' => [$category->id],
        ];

        $this->actingAs($admin)
            ->post(route('admin.campaigns.store'), $createPayload)
            ->assertRedirect();

        $campaign = PlatformPromotion::query()->where('name', 'Flash Deal Test Event')->firstOrFail();
        $this->assertSame(PlatformPromotion::KIND_FLASH_DEAL, $campaign->kind);
        $this->assertSame(PlatformPromotion::CAMPAIGN_FLASH_DEAL, $campaign->campaign_type);
        $this->assertSame(50000, (int) $campaign->budget_minor);
        $this->assertSame('09:30:00', $campaign->starts_time);
        $this->assertSame('18:00:59', $campaign->ends_time);
        $this->assertSame(
            PlatformPromotion::STATUS_ENDED,
            $campaign->statusKey($campaign->endsAt()->copy()->addSecond()),
        );
        $this->assertDatabaseHas('platform_promotion_stores', [
            'platform_promotion_id' => $campaign->id,
            'store_id' => $store->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.created',
            'auditable_type' => $campaign->getMorphClass(),
            'auditable_id' => $campaign->id,
            'actor_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('Flash Deal Test Event')
            ->assertSee('name="store_ids[]"', false);

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), [
                ...$createPayload,
                'name' => 'Updated Flash Deal Event',
                'description' => 'Updated event copy.',
            ])
            ->assertRedirect(route('admin.campaigns.show', $campaign));

        $campaign->refresh();
        $this->assertSame('Updated Flash Deal Event', $campaign->name);
        $this->assertSame('Updated event copy.', $campaign->description);
        $this->assertSame(PlatformPromotion::FUNDING_PLATFORM, $campaign->funding_type);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.updated',
            'auditable_type' => $campaign->getMorphClass(),
            'auditable_id' => $campaign->id,
            'actor_user_id' => $admin->id,
        ]);

        $this->actingAs($seller)
            ->get(route('seller.promotions.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Updated Flash Deal Event')
            ->assertSee('Campaign Eligible Product');

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'seller_note' => 'Ready to join.',
                'items' => [[
                    'product_id' => $product->id,
                    'campaign_price' => '80.00',
                    'stock_limit' => '4',
                    'per_buyer_limit' => '1',
                ]],
            ])
            ->assertRedirect(route('seller.promotions.campaigns.show', $campaign));

        $participation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $store->id)
            ->firstOrFail();
        $this->assertSame(PlatformCampaignParticipation::STATUS_PENDING_REVIEW, $participation->status);
        $this->assertSame($seller->id, (int) $participation->submitted_by);
        $this->assertSame(1, $participation->items()->count());
        $this->assertSame(8000, (int) $participation->items()->first()->campaign_price_minor);
        $this->assertSame(10000, (int) $participation->items()->first()->regular_price_minor);

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), [
                ...$createPayload,
                'name' => $campaign->name,
                'funding_type' => PlatformPromotion::FUNDING_SELLER,
                'budget' => '',
            ])
            ->assertSessionHasErrors('funding_type');
        $this->assertSame(PlatformPromotion::FUNDING_PLATFORM, $campaign->fresh()->funding_type);

        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Campaign Eligible Product')
            ->assertSee('Pending review');

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.participations.review', $participation), [
                'decision' => 'approve',
                'reason' => 'Campaign price approved.',
            ])
            ->assertRedirect();

        $participation->refresh();
        $this->assertSame(PlatformCampaignParticipation::STATUS_APPROVED, $participation->status);
        $this->assertSame($admin->id, (int) $participation->reviewed_by);
        $this->assertSame('Campaign price approved.', $participation->review_reason);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.participation_reviewed',
            'auditable_type' => $participation->getMorphClass(),
            'auditable_id' => $participation->id,
            'actor_user_id' => $admin->id,
        ]);
        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('campaign.participation_reviewed')
            ->assertSee($admin->name);

        foreach ([
            PlatformPromotion::STATUS_ACTIVE,
            PlatformPromotion::STATUS_PAUSED,
            PlatformPromotion::STATUS_ACTIVE,
            PlatformPromotion::STATUS_ENDED,
        ] as $status) {
            $this->actingAs($admin)
                ->patch(route('admin.campaigns.status', $campaign), ['status' => $status])
                ->assertRedirect();
        }

        $this->assertSame(PlatformPromotion::STATUS_ENDED, $campaign->fresh()->status);
        $this->assertSame(4, AuditLog::query()->where('action', 'campaign.status_updated')->count());
    }

    public function test_campaign_join_limits_rejection_withdrawal_and_store_ownership_are_enforced(): void
    {
        $category = $this->category();
        [$firstSeller, $firstStore] = $this->sellerAndStore($category, 'campaign-limit-first');
        [$secondSeller, $secondStore] = $this->sellerAndStore($category, 'campaign-limit-second');
        $firstProduct = $this->product($firstStore, $category, 'First Seller Campaign Product');
        $secondProduct = $this->product($secondStore, $category, 'Second Seller Campaign Product');
        $this->variant($firstProduct, 'CAMPAIGN-LIMIT-FIRST-001', 10000);
        $this->variant($secondProduct, 'CAMPAIGN-LIMIT-SECOND-001', 10000);
        $admin = $this->user('admin');
        $campaign = $this->campaign([
            'status' => PlatformPromotion::STATUS_OPEN,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(4)->toDateString(),
            'join_deadline' => now('Asia/Manila')->toDateString(),
            'max_participating_stores' => 1,
        ]);

        $this->actingAs($firstSeller)
            ->post(route('seller.promotions.campaigns.join', $campaign), $this->joinPayload($firstProduct, '80.00'))
            ->assertRedirect();
        $firstParticipation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $firstStore->id)
            ->firstOrFail();

        $this->actingAs($secondSeller)
            ->post(route('seller.promotions.campaigns.join', $campaign), $this->joinPayload($secondProduct, '80.00'))
            ->assertSessionHasErrors('campaign');

        $this->actingAs($secondSeller)
            ->patch(route('seller.promotions.campaigns.withdraw', $firstParticipation))
            ->assertNotFound();

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.participations.review', $firstParticipation), [
                'decision' => 'reject',
                'reason' => 'Please revise the campaign price.',
            ])
            ->assertRedirect();
        $this->assertSame(PlatformCampaignParticipation::STATUS_REJECTED, $firstParticipation->fresh()->status);

        $this->actingAs($secondSeller)
            ->post(route('seller.promotions.campaigns.join', $campaign), $this->joinPayload($secondProduct, '80.00'))
            ->assertRedirect();
        $secondParticipation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $secondStore->id)
            ->firstOrFail();
        $this->assertSame(PlatformCampaignParticipation::STATUS_PENDING_REVIEW, $secondParticipation->status);

        $this->actingAs($secondSeller)
            ->patch(route('seller.promotions.campaigns.withdraw', $secondParticipation))
            ->assertRedirect();
        $this->assertSame(PlatformCampaignParticipation::STATUS_WITHDRAWN, $secondParticipation->fresh()->status);
        $this->assertSame(2, PlatformCampaignParticipation::query()->where('platform_promotion_id', $campaign->id)->count());

        $campaign->update([
            'status' => PlatformPromotion::STATUS_ENDED,
            'ends_on' => now('Asia/Manila')->subDay()->toDateString(),
        ]);
        $this->actingAs($secondSeller)
            ->get(route('seller.products.pricing', ['tab' => 'campaigns']))
            ->assertOk()
            ->assertSee($campaign->name)
            ->assertSee('Withdrawn');
    }

    private function joinPayload(Product $product, string $price): array
    {
        return [
            'terms_accepted' => '1',
            'items' => [[
                'product_id' => $product->id,
                'campaign_price' => $price,
            ]],
        ];
    }

    private function campaign(array $overrides = []): PlatformPromotion
    {
        return PlatformPromotion::query()->create(array_merge([
            'name' => 'Campaign '.$this->sequence,
            'code' => null,
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PISO_SALE,
            'funding_type' => PlatformPromotion::FUNDING_SELLER,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_OPEN,
            'description' => 'Campaign testing event.',
            'terms' => 'Accept the campaign terms.',
            'value_minor' => 0,
            'minimum_spend_minor' => 0,
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
        ], $overrides));
    }

    private function user(string $role): User
    {
        $sequence = ++$this->sequence;

        return User::query()->create([
            'name' => Str::headline($role).' '.$sequence,
            'first_name' => ucfirst($role),
            'last_name' => 'Campaign '.$sequence,
            'email' => "{$role}-campaign-{$sequence}@example.test",
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function category(): Category
    {
        $sequence = ++$this->sequence;

        return Category::query()->create([
            'name' => 'Campaign Category '.$sequence,
            'slug' => 'campaign-category-'.$sequence,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(Category $category, string $slug): array
    {
        $seller = $this->user('seller');
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
            'legal_business_name' => 'Campaign Business '.$seller->id,
            'approved_category_id' => $category->id,
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => Str::headline($slug),
            'slug' => $slug.'-'.$seller->id,
            'publication_status' => 'published',
        ]);

        return [$seller->fresh('sellerProfile.store'), $store];
    }

    private function product(Store $store, Category $category, string $name): Product
    {
        return Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.$store->id,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'payment_methods' => ['cod', 'online'],
            'voucher_eligible' => true,
        ]);
    }

    private function variant(Product $product, string $sku, int $priceMinor): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'name' => 'Standard',
            'price_minor' => $priceMinor,
            'stock_on_hand' => 10,
            'stock_reserved' => 0,
            'low_stock_threshold' => 2,
            'package_weight_kg' => 0.25,
            'package_length_cm' => 10,
            'package_width_cm' => 10,
            'package_height_cm' => 10,
            'is_active' => true,
        ]);
    }
}
