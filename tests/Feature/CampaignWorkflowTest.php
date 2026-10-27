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
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_admin_campaign_banner_accepts_images_up_to_ten_mb(): void
    {
        Storage::fake('public');

        $admin = $this->user('admin');
        $startsOn = now('Asia/Manila')->addDays(3)->toDateString();
        $payload = [
            'name' => 'Large Banner Upload Campaign',
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'minimum_discount_percent' => '10',
            'budget' => '100.00',
            'starts_on' => $startsOn,
            'start_time' => '09:00',
            'ends_on' => now('Asia/Manila')->addDays(5)->toDateString(),
            'end_time' => '18:00',
            'join_deadline' => now('Asia/Manila')->addDays(2)->toDateString(),
            'status' => PlatformPromotion::STATUS_DRAFT,
            'banner' => UploadedFile::fake()->create('large-banner.jpg', 6000, 'image/jpeg'),
        ];

        $this->actingAs($admin)
            ->post(route('admin.campaigns.store'), $payload)
            ->assertRedirect();

        $campaign = PlatformPromotion::query()->where('name', 'Large Banner Upload Campaign')->firstOrFail();
        $this->assertNotEmpty($campaign->banner_path);
        Storage::disk('public')->assertExists($campaign->banner_path);

        $payload['name'] = 'Oversized Banner Upload Campaign';
        $payload['banner'] = UploadedFile::fake()->create('oversized-banner.jpg', 10241, 'image/jpeg');

        $this->actingAs($admin)
            ->from(route('admin.campaigns.create'))
            ->post(route('admin.campaigns.store'), $payload)
            ->assertRedirect(route('admin.campaigns.create'))
            ->assertSessionHasErrors('banner');

        $this->assertDatabaseMissing('platform_promotions', ['name' => 'Oversized Banner Upload Campaign']);
    }

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
            'minimum_discount_percent' => '20',
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
            ->assertRedirect()
            ->assertSessionHas('success_title', 'Campaign created')
            ->assertSessionHas('success', 'Campaign created successfully: Flash Deal Test Event.');

        $campaign = PlatformPromotion::query()->where('name', 'Flash Deal Test Event')->firstOrFail();
        $this->assertSame(PlatformPromotion::KIND_FLASH_DEAL, $campaign->kind);
        $this->assertSame(PlatformPromotion::CAMPAIGN_FLASH_DEAL, $campaign->campaign_type);
        $this->assertSame(20, (int) $campaign->minimum_discount_percent);
        $this->assertSame(50000, (int) $campaign->budget_minor);
        $this->assertNull($campaign->banner_path);
        $this->assertNull($campaign->banner_link);
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
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('data-admin-server-toast', false)
            ->assertSeeText('Campaign created successfully: Flash Deal Test Event.')
            ->assertSee('Internal Admin notes')
            ->assertSee('Internal calendar note.');

        $this->actingAs($admin)
            ->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('Flash Deal Test Event')
            ->assertSee('name="minimum_discount_percent"', false)
            ->assertSee('Every Seller-submitted product discount must meet this whole-number minimum.')
            ->assertSee('name="budget"', false)
            ->assertSee('Required budget cap')
            ->assertSee('name="store_ids[]"', false)
            ->assertSee('Optional targeting, limits and settings')
            ->assertSee('Sellers choose products when they join')
            ->assertSee('Optional banner alt text')
            ->assertDontSee('name="product_ids[]"', false)
            ->assertDontSee('name="banner_link"', false);

        $campaign->update([
            'banner_title' => 'Legacy banner alt text',
            'banner_link' => 'https://example.test/legacy-campaign-banner',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), [
                ...$createPayload,
                'name' => 'Updated Flash Deal Event',
                'description' => 'Updated event copy.',
            ])
            ->assertRedirect(route('admin.campaigns.show', $campaign))
            ->assertSessionHas('success_title', 'Campaign updated')
            ->assertSessionHas('success', 'Campaign changes saved successfully.');

        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Campaign updated')
            ->assertSeeText('Campaign changes saved successfully.');

        $campaign->refresh();
        $this->assertSame('Updated Flash Deal Event', $campaign->name);
        $this->assertSame('Updated event copy.', $campaign->description);
        $this->assertSame(PlatformPromotion::FUNDING_PLATFORM, $campaign->funding_type);
        $this->assertSame('Legacy banner alt text', $campaign->banner_title);
        $this->assertSame('https://example.test/legacy-campaign-banner', $campaign->banner_link);
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
            ->assertSee('Campaign Eligible Product')
            ->assertDontSee('Internal calendar note.');

        $sellerCampaignPayload = app(CampaignService::class)->sellerCampaignDetail($seller, $campaign);
        $this->assertArrayNotHasKey('notes', $sellerCampaignPayload['campaign']);

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'seller_note' => 'Ready to join.',
                'items' => [[
                    'product_id' => $product->id,
                    'discount_percent' => '20',
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
            ->assertSee('Back to campaigns')
            ->assertSee(route('admin.campaigns'))
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

    public function test_admin_can_change_campaign_schedule_dates_and_times(): void
    {
        $admin = $this->user('admin');
        $startsOn = now('Asia/Manila')->addDays(5)->toDateString();
        $endsOn = now('Asia/Manila')->addDays(10)->toDateString();
        $joinDeadline = now('Asia/Manila')->addDays(4)->toDateString();
        $campaign = $this->campaign([
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'starts_on' => $startsOn,
            'starts_time' => '08:00:00',
            'ends_on' => $endsOn,
            'ends_time' => '23:59:59',
            'join_deadline' => $joinDeadline,
        ]);
        $newStartsOn = now('Asia/Manila')->addDays(8)->toDateString();
        $newEndsOn = now('Asia/Manila')->addDays(13)->toDateString();
        $newJoinDeadline = now('Asia/Manila')->addDays(7)->toDateString();
        $invalidStartsOn = now('Asia/Manila')->addDays(3)->toDateString();

        $this->actingAs($admin)
            ->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('value="'.$campaign->starts_on->toDateString().'"', false)
            ->assertSee('value="08:00"', false)
            ->assertSee('value="'.$campaign->ends_on->toDateString().'"', false)
            ->assertSee('value="23:59"', false);

        $this->actingAs($admin)
            ->from(route('admin.campaigns.edit', $campaign))
            ->patch(route('admin.campaigns.update', $campaign), [
                'name' => $campaign->name,
                'campaign_type' => $campaign->campaign_type,
                'funding_type' => $campaign->funding_type,
                'starts_on' => $invalidStartsOn,
                'start_time' => '10:15',
                'ends_on' => $endsOn,
                'end_time' => '21:45',
                'join_deadline' => $joinDeadline,
            ])
            ->assertRedirect(route('admin.campaigns.edit', $campaign))
            ->assertSessionHasErrors('join_deadline');

        $this->assertSame($startsOn, $campaign->fresh()->starts_on->toDateString());
        $this->actingAs($admin)
            ->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('The Seller join deadline must be on or before the campaign start date. If you move the start earlier, update the deadline too.')
            ->assertSee('value="'.$invalidStartsOn.'"', false);

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), [
                'name' => $campaign->name,
                'campaign_type' => $campaign->campaign_type,
                'funding_type' => $campaign->funding_type,
                'starts_on' => $newStartsOn,
                'start_time' => '10:15',
                'ends_on' => $newEndsOn,
                'end_time' => '21:45',
                'join_deadline' => $newJoinDeadline,
            ])
            ->assertRedirect(route('admin.campaigns.show', $campaign));

        $campaign->refresh();
        $this->assertSame($newStartsOn, $campaign->starts_on->toDateString());
        $this->assertSame('10:15:00', $campaign->starts_time);
        $this->assertSame($newEndsOn, $campaign->ends_on->toDateString());
        $this->assertSame('21:45:59', $campaign->ends_time);
        $this->assertSame($newJoinDeadline, $campaign->join_deadline->toDateString());

        $this->actingAs($admin)
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee($campaign->schedulePeriod());

        $this->actingAs($admin)
            ->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('value="'.$newStartsOn.'"', false)
            ->assertSee('value="10:15"', false)
            ->assertSee('value="'.$newEndsOn.'"', false)
            ->assertSee('value="21:45"', false)
            ->assertSee('value="'.$newJoinDeadline.'"', false);
    }

    public function test_admin_requires_a_whole_minimum_discount_for_new_campaigns(): void
    {
        $admin = $this->user('admin');
        $startsOn = now('Asia/Manila')->addDays(3)->toDateString();
        $payload = [
            'name' => 'Minimum Discount Campaign',
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'description' => 'A minimum discount validation test.',
            'budget' => '100.00',
            'starts_on' => $startsOn,
            'start_time' => '09:00',
            'ends_on' => now('Asia/Manila')->addDays(8)->toDateString(),
            'end_time' => '23:59',
            'join_deadline' => now('Asia/Manila')->addDays(2)->toDateString(),
            'status' => PlatformPromotion::STATUS_DRAFT,
        ];

        $this->actingAs($admin)
            ->get(route('admin.campaigns.create'))
            ->assertOk()
            ->assertSee('name="minimum_discount_percent"', false)
            ->assertSee('name="funding_type" value="platform"', false)
            ->assertDontSee('<option value="seller">', false)
            ->assertSee('name="budget"', false)
            ->assertSee('min="0.01"', false)
            ->assertSee('Required budget cap')
            ->assertSee('Optional targeting, limits and settings')
            ->assertSee('Optional banner alt text')
            ->assertDontSee('name="banner_link"', false)
            ->assertDontSee('name="product_ids[]"', false);

        $missingBudget = $payload;
        unset($missingBudget['budget']);
        $this->actingAs($admin)
            ->post(route('admin.campaigns.store'), [
                ...$missingBudget,
                'minimum_discount_percent' => '10',
            ])
            ->assertSessionHasErrors('budget');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.store'), [
                ...$payload,
                'budget' => '0.00',
                'minimum_discount_percent' => '10',
            ])
            ->assertSessionHasErrors('budget');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.store'), [
                ...$payload,
                'funding_type' => PlatformPromotion::FUNDING_SELLER,
                'minimum_discount_percent' => '10',
            ])
            ->assertSessionHasErrors('funding_type');

        foreach ([null, '0', '91', '10.5'] as $invalidMinimum) {
            $attempt = $payload;
            if ($invalidMinimum !== null) {
                $attempt['minimum_discount_percent'] = $invalidMinimum;
            }

            $this->actingAs($admin)
                ->post(route('admin.campaigns.store'), $attempt)
                ->assertSessionHasErrors('minimum_discount_percent');
        }

        $this->actingAs($admin)
            ->post(route('admin.campaigns.store'), [
                ...$payload,
                'minimum_discount_percent' => '10',
            ])
            ->assertRedirect();

        $createdCampaign = PlatformPromotion::query()->where('name', 'Minimum Discount Campaign')->firstOrFail();
        $this->assertSame(10, (int) $createdCampaign->minimum_discount_percent);
        $this->assertSame(
            10000,
            (int) $createdCampaign->budget_minor,
        );

        $withoutBudget = $payload;
        unset($withoutBudget['budget'], $withoutBudget['status']);
        $withoutBudget['minimum_discount_percent'] = '10';
        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $createdCampaign), $withoutBudget)
            ->assertSessionHasErrors('budget');
        $this->assertSame(10000, (int) $createdCampaign->fresh()->budget_minor);
    }

    public function test_seller_discount_is_computed_for_each_active_sku_and_must_meet_the_campaign_minimum(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-percent-skus');
        $product = $this->product($store, $category, 'Multi SKU Campaign Product');
        $firstVariant = $this->variant($product, 'CAMPAIGN-PERCENT-001', 10000);
        $secondVariant = $this->variant($product, 'CAMPAIGN-PERCENT-002', 25000);
        $this->variant($product, 'CAMPAIGN-PERCENT-INACTIVE', 40000)->update(['is_active' => false]);
        $this->variant($product, 'CAMPAIGN-PERCENT-NOSTOCK', 50000)->update(['stock_on_hand' => 0]);
        $flaggedProduct = $this->product($store, $category, 'Flagged Campaign Product');
        $this->variant($flaggedProduct, 'CAMPAIGN-PERCENT-FLAGGED', 12000);
        $flaggedProduct->update(['compliance_status' => 'flagged']);
        [, $otherStore] = $this->sellerAndStore($category, 'campaign-percent-foreign');
        $foreignProduct = $this->product($otherStore, $category, 'Foreign Campaign Product');
        $this->variant($foreignProduct, 'CAMPAIGN-PERCENT-FOREIGN', 12000);
        $campaign = $this->campaign(['minimum_discount_percent' => 20]);

        $this->actingAs($seller)
            ->get(route('seller.promotions.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Minimum 20%; maximum 90%')
            ->assertSee('data-minimum-discount="20"', false)
            ->assertSee('min="20"', false)
            ->assertSee('data-campaign-discount', false)
            ->assertSee('data-baseline-minor="10000"', false)
            ->assertSee('data-baseline-minor="25000"', false)
            ->assertDontSee('CAMPAIGN-PERCENT-INACTIVE')
            ->assertDontSee('CAMPAIGN-PERCENT-NOSTOCK')
            ->assertDontSee('Flagged Campaign Product')
            ->assertDontSee('Foreign Campaign Product');

        foreach ([$flaggedProduct, $foreignProduct] as $ineligibleProduct) {
            $this->actingAs($seller)
                ->post(route('seller.promotions.campaigns.join', $campaign), [
                    'terms_accepted' => '1',
                    'items' => [[
                        'product_id' => $ineligibleProduct->id,
                        'discount_percent' => '20',
                    ]],
                ])
                ->assertSessionHasErrors('items');
        }

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'items' => [[
                    'product_id' => $product->id,
                    'discount_percent' => '19',
                ]],
            ])
            ->assertSessionHasErrors('items');

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'items' => [[
                    'product_id' => $product->id,
                    'discount_percent' => '20',
                    'campaign_price' => '1.00',
                ]],
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('platform_campaign_participations', [
            'platform_promotion_id' => $campaign->id,
            'store_id' => $store->id,
        ]);

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'items' => [[
                    'product_id' => $product->id,
                    'discount_percent' => '20',
                    'stock_limit' => '3',
                    'per_buyer_limit' => '1',
                ]],
            ])
            ->assertRedirect(route('seller.promotions.campaigns.show', $campaign));

        $participation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $store->id)
            ->firstOrFail();
        $items = $participation->items()->orderBy('product_variant_id')->get()->keyBy('product_variant_id');

        $this->assertCount(2, $items);
        $this->assertSame(10000, (int) $items[$firstVariant->id]->regular_price_minor);
        $this->assertSame(8000, (int) $items[$firstVariant->id]->campaign_price_minor);
        $this->assertSame(20, (int) $items[$firstVariant->id]->discount_percent);
        $this->assertSame(25000, (int) $items[$secondVariant->id]->regular_price_minor);
        $this->assertSame(20000, (int) $items[$secondVariant->id]->campaign_price_minor);
        $this->assertSame(20, (int) $items[$secondVariant->id]->discount_percent);
        $this->assertSame(3, (int) $items[$firstVariant->id]->stock_limit);
        $this->assertSame(1, (int) $items[$secondVariant->id]->per_buyer_limit);
    }

    public function test_admin_cannot_configure_a_minimum_below_existing_pending_or_approved_prices(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-legacy-minimum');
        $product = $this->product($store, $category, 'Legacy Minimum Product');
        $variant = $this->variant($product, 'CAMPAIGN-LEGACY-MIN-001', 10000);
        $campaign = $this->campaign(['minimum_discount_percent' => null]);

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'items' => [['product_id' => $product->id, 'discount_percent' => '20']],
            ])
            ->assertRedirect();

        $participation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $store->id)
            ->firstOrFail();
        $item = $participation->items()->firstOrFail();
        $admin = $this->user('admin');
        $payload = [
            'name' => $campaign->name,
            'campaign_type' => $campaign->campaign_type,
            'funding_type' => $campaign->funding_type,
            'minimum_discount_percent' => '25',
            'starts_on' => $campaign->starts_on->toDateString(),
            'start_time' => '00:00',
            'ends_on' => $campaign->ends_on->toDateString(),
            'end_time' => '23:59',
            'join_deadline' => $campaign->starts_on->toDateString(),
        ];

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), $payload)
            ->assertSessionHasErrors('minimum_discount_percent');
        $this->assertNull($campaign->fresh()->minimum_discount_percent);

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.participations.review', $participation), [
                'decision' => 'approve',
                'reason' => 'The saved 20% discount is approved.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), $payload)
            ->assertSessionHasErrors('minimum_discount_percent');

        $payload['minimum_discount_percent'] = '20';
        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), $payload)
            ->assertRedirect(route('admin.campaigns.show', $campaign));

        $this->assertSame(20, (int) $campaign->fresh()->minimum_discount_percent);
        $this->assertSame(8000, (int) $item->fresh()->campaign_price_minor);
        $this->assertSame(10000, (int) $item->fresh()->regular_price_minor);
    }

    public function test_admin_cannot_lower_product_limit_below_existing_participation(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'campaign-product-limit');
        $firstProduct = $this->product($store, $category, 'First Campaign Limit Product');
        $secondProduct = $this->product($store, $category, 'Second Campaign Limit Product');
        $this->variant($firstProduct, 'CAMPAIGN-PRODUCT-LIMIT-001', 10000);
        $this->variant($secondProduct, 'CAMPAIGN-PRODUCT-LIMIT-002', 10000);
        $admin = $this->user('admin');
        $campaign = $this->campaign([
            'status' => PlatformPromotion::STATUS_OPEN,
            'starts_on' => now('Asia/Manila')->addDays(3)->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(8)->toDateString(),
            'join_deadline' => now('Asia/Manila')->addDays(2)->toDateString(),
            'max_products_per_store' => 5,
        ]);

        $this->actingAs($seller)
            ->post(route('seller.promotions.campaigns.join', $campaign), [
                'terms_accepted' => '1',
                'items' => [
                    ['product_id' => $firstProduct->id, 'discount_percent' => '20'],
                    ['product_id' => $secondProduct->id, 'discount_percent' => '25'],
                ],
            ])
            ->assertRedirect();

        $participation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $store->id)
            ->firstOrFail();
        $this->assertSame(2, $participation->items()->count());

        $this->actingAs($admin)
            ->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('name="funding_type" value="seller"', false)
            ->assertSee('Legacy Seller-funded campaign')
            ->assertDontSee('name="budget"', false);

        $payload = [
            'name' => $campaign->name,
            'campaign_type' => $campaign->campaign_type,
            'funding_type' => $campaign->funding_type,
            'description' => $campaign->description,
            'terms' => $campaign->terms,
            'starts_on' => $campaign->starts_on->toDateString(),
            'start_time' => '00:00',
            'ends_on' => $campaign->ends_on->toDateString(),
            'end_time' => '23:59',
            'join_deadline' => $campaign->join_deadline->toDateString(),
            'max_products_per_store' => '1',
        ];

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), $payload)
            ->assertSessionHasErrors('max_products_per_store');

        $this->assertSame(5, (int) $campaign->fresh()->max_products_per_store);

        $payload['max_products_per_store'] = '2';

        $this->actingAs($admin)
            ->patch(route('admin.campaigns.update', $campaign), $payload)
            ->assertRedirect(route('admin.campaigns.show', $campaign));

        $this->assertSame(2, (int) $campaign->fresh()->max_products_per_store);
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
            ->post(route('seller.promotions.campaigns.join', $campaign), $this->joinPayload($firstProduct, 20))
            ->assertRedirect();
        $firstParticipation = PlatformCampaignParticipation::query()
            ->where('platform_promotion_id', $campaign->id)
            ->where('store_id', $firstStore->id)
            ->firstOrFail();

        $this->actingAs($secondSeller)
            ->post(route('seller.promotions.campaigns.join', $campaign), $this->joinPayload($secondProduct, 20))
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
            ->post(route('seller.promotions.campaigns.join', $campaign), $this->joinPayload($secondProduct, 20))
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

        $campaign->update(['status' => PlatformPromotion::STATUS_ENDED]);
        $this->actingAs($secondSeller)
            ->get(route('seller.products.pricing', ['tab' => 'campaigns']))
            ->assertOk()
            ->assertDontSee($campaign->name);

        $this->actingAs($secondSeller)
            ->get(route('seller.promotions.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee($campaign->name)
            ->assertSee('Withdrawn');

        $campaign->update([
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'ends_on' => now('Asia/Manila')->subDay()->toDateString(),
        ]);
        $this->actingAs($secondSeller)
            ->get(route('seller.products.pricing', ['tab' => 'campaigns']))
            ->assertOk()
            ->assertDontSee($campaign->name);
    }

    private function joinPayload(Product $product, int $discountPercent): array
    {
        return [
            'terms_accepted' => '1',
            'items' => [[
                'product_id' => $product->id,
                'discount_percent' => $discountPercent,
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
