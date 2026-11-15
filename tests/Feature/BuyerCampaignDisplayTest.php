<?php

namespace Tests\Feature;

use App\Enums\SellerOrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformCampaignParticipationItem;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use App\Services\BuyerCatalogService;
use App\Services\PisoSaleEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerCampaignDisplayTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_active_public_campaign_page_shows_only_approved_buyer_visible_products(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('campaign-approved-store');
        $buyer = $this->buyer();
        $this->actingAs($buyer);

        $campaign = $this->campaign([
            'name' => '10.10 Sale',
            'description' => 'A public campaign description.',
            'terms' => 'Internal terms only.',
            'campaign_notes' => 'Internal campaign notes.',
        ]);

        $approvedProduct = $this->product($store, $category, 'Approved Campaign Product');
        $approvedVariant = $this->variant($approvedProduct, 'CAMPAIGN-APPROVED-001', 10_000);
        $approvedParticipation = $this->participation($campaign, $store, $seller, $approvedProduct, $approvedVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        [$pendingSeller, $pendingStore] = $this->sellerAndStore('campaign-pending-store');
        $pendingProduct = $this->product($pendingStore, $category, 'Pending Campaign Product');
        $pendingVariant = $this->variant($pendingProduct, 'CAMPAIGN-PENDING-001', 10_000);
        $this->participation($campaign, $pendingStore, $pendingSeller, $pendingProduct, $pendingVariant, PlatformCampaignParticipation::STATUS_PENDING_REVIEW);

        [$withdrawnSeller, $withdrawnStore] = $this->sellerAndStore('campaign-withdrawn-store');
        $withdrawnProduct = $this->product($withdrawnStore, $category, 'Withdrawn Campaign Product');
        $withdrawnVariant = $this->variant($withdrawnProduct, 'CAMPAIGN-WITHDRAWN-001', 10_000);
        $this->participation($campaign, $withdrawnStore, $withdrawnSeller, $withdrawnProduct, $withdrawnVariant, PlatformCampaignParticipation::STATUS_WITHDRAWN);

        [$unpublishedSeller, $unpublishedStore] = $this->sellerAndStore('campaign-unpublished-store', false);
        $hiddenStoreProduct = $this->product($unpublishedStore, $category, 'Unpublished Store Product');
        $hiddenStoreVariant = $this->variant($hiddenStoreProduct, 'CAMPAIGN-HIDDEN-STORE-001', 10_000);
        $this->participation($campaign, $unpublishedStore, $unpublishedSeller, $hiddenStoreProduct, $hiddenStoreVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        $hiddenProduct = $this->product($store, $category, 'Archived Campaign Product', [
            'compliance_status' => 'flagged',
        ]);
        $hiddenVariant = $this->variant($hiddenProduct, 'CAMPAIGN-HIDDEN-PRODUCT-001', 10_000);
        $this->campaignItem($approvedParticipation, $hiddenProduct, $hiddenVariant);

        $this->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('class="campaign-back" href="'.route('home').'"', false)
            ->assertSee('Back to Home')
            ->assertSee('10.10 Sale')
            ->assertSee('Piso Sale')
            ->assertSee('Bearly-funded')
            ->assertSee($store->name)
            ->assertSee('A public campaign description.')
            ->assertSee('Approved Campaign Product')
            ->assertSee('₱1.00')
            ->assertSee('₱100.00')
            ->assertSee('99% OFF')
            ->assertDontSee('Pending Campaign Product')
            ->assertDontSee('Withdrawn Campaign Product')
            ->assertDontSee('Unpublished Store Product')
            ->assertDontSee($unpublishedStore->name)
            ->assertDontSee('Archived Campaign Product')
            ->assertDontSee('Internal terms only.')
            ->assertDontSee('Internal campaign notes.')
            ->assertDontSee('Internal reviewer note.');
    }

    public function test_public_landing_shows_featured_live_campaigns_even_before_products_are_shoppable(): void
    {
        Storage::fake('public');

        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('landing-featured-campaign-store');
        $campaign = $this->campaign([
            'name' => 'Public Payday Campaign',
            'is_featured' => true,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
            'banner_path' => 'campaigns/banners/public-payday.webp',
            'banner_disk' => 'public',
            'banner_title' => 'Payday picks for everyone',
        ]);
        Storage::disk('public')->put('campaigns/banners/public-payday.webp', 'campaign banner');

        $product = $this->product($store, $category, 'Featured Campaign Product');
        $variant = $this->variant($product, 'PUBLIC-PAYDAY-SKU', 10_000);
        $this->participation($campaign, $store, $seller, $product, $variant, PlatformCampaignParticipation::STATUS_APPROVED);

        $this->campaign([
            'name' => 'Unfeatured Campaign',
            'is_featured' => false,
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
        ]);
        $featuredWithoutProducts = $this->campaign([
            'name' => 'Featured Campaign Without Products',
            'is_featured' => true,
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
        ]);
        $this->campaign([
            'name' => 'Paused Featured Campaign',
            'is_featured' => true,
            'status' => PlatformPromotion::STATUS_PAUSED,
            'campaign_type' => PlatformPromotion::CAMPAIGN_DOUBLE_DATE,
        ]);

        $response = $this->get(route('shop.home'))
            ->assertOk()
            ->assertSee('Public Payday Campaign')
            ->assertSee('Payday picks for everyone')
            ->assertSee(Storage::disk('public')->url('campaigns/banners/public-payday.webp'), false)
            ->assertSee(route('campaigns.preview', $campaign), false)
            ->assertSee('Featured Campaign Without Products')
            ->assertSee(route('campaigns.preview', $featuredWithoutProducts), false)
            ->assertDontSee('Unfeatured Campaign')
            ->assertDontSee('Paused Featured Campaign');

        $this->assertStringContainsString('Live campaigns', $response->getContent());

        $this->get(route('campaigns.preview', $featuredWithoutProducts))
            ->assertOk()
            ->assertSee('There are no shoppable products in this campaign right now.');
    }

    public function test_guest_campaign_preview_only_exposes_campaign_context_and_product_image_name_and_price(): void
    {
        Storage::fake('public');

        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('guest-campaign-preview-store');
        $campaign = $this->campaign([
            'name' => 'Guest Preview Payday Sale',
            'description' => 'A short public campaign description.',
            'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
            'terms' => 'CAMPAIGN_PRIVATE_TERMS_SENTINEL',
            'campaign_notes' => 'CAMPAIGN_PRIVATE_NOTES_SENTINEL',
        ]);
        $product = $this->product($store, $category, 'Guest Campaign Headphones', [
            'description' => 'CAMPAIGN_PRIVATE_PRODUCT_DESCRIPTION_SENTINEL',
        ]);
        $variant = $this->variant($product, 'CAMPAIGN_PRIVATE_SKU_SENTINEL', 10_000, [
            'name' => 'CAMPAIGN_PRIVATE_VARIANT_SENTINEL',
            'options' => ['Color' => 'CAMPAIGN_PRIVATE_OPTION_SENTINEL'],
        ]);
        $this->participation($campaign, $store, $seller, $product, $variant, PlatformCampaignParticipation::STATUS_APPROVED);

        $imagePath = 'seller-products/guest-campaign-preview.webp';
        Storage::disk('public')->put($imagePath, 'product image');
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $imagePath,
            'disk' => 'public',
            'purpose' => 'primary',
            'alt_text' => 'Guest campaign product image',
            'position' => 0,
            'is_primary' => true,
        ]);

        $this->get(route('campaigns.preview', $campaign))
            ->assertOk()
            ->assertSee($campaign->name)
            ->assertSee('A short public campaign description.')
            ->assertSee('Guest Campaign Headphones')
            ->assertSee('₱80.00')
            ->assertSee(Storage::disk('public')->url($imagePath), false)
            ->assertSee(route('products.preview', $product), false)
            ->assertSee(route('campaigns.show', $campaign), false)
            ->assertDontSee($store->name)
            ->assertDontSee('CAMPAIGN_PRIVATE_TERMS_SENTINEL')
            ->assertDontSee('CAMPAIGN_PRIVATE_NOTES_SENTINEL')
            ->assertDontSee('CAMPAIGN_PRIVATE_PRODUCT_DESCRIPTION_SENTINEL')
            ->assertDontSee('CAMPAIGN_PRIVATE_SKU_SENTINEL')
            ->assertDontSee('CAMPAIGN_PRIVATE_VARIANT_SENTINEL')
            ->assertDontSee('CAMPAIGN_PRIVATE_OPTION_SENTINEL');

        $this->get(route('campaigns.show', $campaign))
            ->assertRedirect(route('login'));

        $buyer = $this->buyer();
        $this->post(route('login.submit'), [
            'email' => $buyer->email,
            'password' => 'Password123',
        ])->assertRedirect(route('campaigns.show', $campaign));

        $this->get(route('campaigns.preview', $campaign))
            ->assertRedirect(route('campaigns.show', $campaign));
    }

    public function test_piso_sale_is_not_exposed_to_guests_or_the_public_landing_page(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('public-piso-sale-store');
        $campaign = $this->campaign([
            'name' => 'Public Piso Sale Must Stay Hidden',
            'is_featured' => true,
        ]);
        $product = $this->product($store, $category, 'Public Piso Sale Product');
        $variant = $this->variant($product, 'PUBLIC-PISO-SALE-001', 10_000);
        $this->participation($campaign, $store, $seller, $product, $variant, PlatformCampaignParticipation::STATUS_APPROVED);

        $this->get(route('shop.home'))
            ->assertOk()
            ->assertDontSee($campaign->name);
        $this->get(route('campaigns.preview', $campaign))->assertNotFound();

        $card = app(BuyerCatalogService::class)->card($product);
        $this->assertSame(10_000, $card['price_minor']);
        $this->assertNull($card['campaign_name']);
    }

    public function test_piso_sale_visibility_and_pricing_require_a_recent_buyer_without_a_fulfilled_order(): void
    {
        Storage::fake('public');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('eligible-piso-sale-store');
        $buyer = $this->buyer();
        $campaign = $this->campaign([
            'name' => 'Recent Buyer Piso Sale',
            'is_featured' => true,
            'banner_path' => 'campaigns/banners/recent-buyer-piso.webp',
            'banner_disk' => 'public',
        ]);
        Storage::disk('public')->put('campaigns/banners/recent-buyer-piso.webp', 'eligible featured Piso banner');
        $product = $this->product($store, $category, 'Recent Buyer Piso Product');
        $variant = $this->variant($product, 'RECENT-PISO-001', 10_000);
        $this->participation($campaign, $store, $seller, $product, $variant, PlatformCampaignParticipation::STATUS_APPROVED);

        $this->actingAs($buyer)->get(route('home'))
            ->assertOk()
            ->assertSee($campaign->name)
            ->assertSee('data-hero-campaign-id="'.$campaign->id.'"', false);
        $eligibleCard = app(BuyerCatalogService::class)->card($product, buyer: $buyer);
        $this->assertSame(PlatformPromotion::PISO_SALE_PRICE_MINOR, $eligibleCard['price_minor']);
        $this->assertSame('campaign', $eligibleCard['price_source']);

        $buyer->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
        $this->actingAs($buyer)->get(route('home'))
            ->assertOk()
            ->assertDontSee($campaign->name)
            ->assertDontSee('data-hero-campaign-id="'.$campaign->id.'"', false);
        $this->actingAs($buyer)->get(route('campaigns.show', $campaign))->assertNotFound();
        $agedCard = app(BuyerCatalogService::class)->card($product->fresh(), buyer: $buyer->fresh());
        $this->assertSame(10_000, $agedCard['price_minor']);
        $this->assertSame('regular', $agedCard['price_source']);

        $buyer->forceFill(['created_at' => now()])->saveQuietly();
        Order::query()->create([
            'order_no' => 'ORDER-FULFILLED-PISO-'.(++$this->sequence),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '1 Piso Sale Street',
            'barangay' => 'Poblacion',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'currency' => 'PHP',
            'subtotal_minor' => 1000,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 1000,
            'status' => 'fulfilled',
            'payment_status' => 'paid',
            'placed_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        $this->actingAs($buyer)->get(route('home'))
            ->assertOk()
            ->assertDontSee($campaign->name);
        $this->actingAs($buyer)->get(route('campaigns.show', $campaign))->assertNotFound();
        $completedCard = app(BuyerCatalogService::class)->card($product->fresh(), buyer: $buyer->fresh());
        $this->assertSame(10_000, $completedCard['price_minor']);
        $this->assertFalse(app(PisoSaleEligibilityService::class)->canDisplay($buyer->fresh()));
    }

    public function test_non_public_or_inactive_campaigns_return_not_found(): void
    {
        $buyer = $this->buyer();
        $this->actingAs($buyer);

        $manila = now('Asia/Manila');
        $cases = [
            'draft' => ['status' => PlatformPromotion::STATUS_DRAFT],
            'open' => ['status' => PlatformPromotion::STATUS_OPEN],
            'scheduled' => ['status' => PlatformPromotion::STATUS_SCHEDULED],
            'paused' => ['status' => PlatformPromotion::STATUS_PAUSED],
            'ended' => ['status' => PlatformPromotion::STATUS_ENDED],
            'future schedule' => [
                'status' => PlatformPromotion::STATUS_ACTIVE,
                'starts_on' => $manila->copy()->addDay()->toDateString(),
                'starts_time' => '00:00:00',
            ],
            'expired schedule' => [
                'status' => PlatformPromotion::STATUS_ACTIVE,
                'ends_on' => $manila->copy()->subDay()->toDateString(),
                'ends_time' => '23:59:59',
            ],
            'private' => [
                'status' => PlatformPromotion::STATUS_ACTIVE,
                'is_public' => false,
            ],
            'voucher instead of campaign' => [
                'status' => PlatformPromotion::STATUS_ACTIVE,
                'kind' => PlatformPromotion::KIND_VOUCHER,
                'campaign_type' => null,
            ],
        ];

        foreach ($cases as $label => $overrides) {
            $campaign = $this->campaign(array_merge([
                'name' => 'Hidden Campaign '.Str::headline($label),
            ], $overrides));

            $this->get(route('campaigns.show', $campaign))
                ->assertNotFound();

            $this->get(route('campaigns.preview', $campaign))
                ->assertNotFound();
        }
    }

    public function test_buyer_home_shows_campaign_banners_without_voucher_promotions(): void
    {
        Storage::fake('public');
        $buyer = $this->buyer();
        $this->actingAs($buyer);
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('buyer-home-campaigns');
        $regularVoucher = PlatformPromotion::query()->create([
            'name' => 'Buyer Home Voucher',
            'code' => 'HOMEVOUCHER',
            'kind' => PlatformPromotion::KIND_VOUCHER,
            'campaign_type' => null,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'discount_type' => PlatformPromotion::DISCOUNT_PERCENT,
            'discount_percent' => 15,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'description' => 'A regular eligible Buyer voucher.',
            'minimum_spend_minor' => 0,
            'starts_on' => now('Asia/Manila')->subDay()->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(7)->toDateString(),
            'is_claimable' => true,
            'is_public' => true,
            'is_featured' => true,
        ]);

        $payday = $this->campaign([
            'name' => 'Payday Rush',
            'position' => 1,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
            'is_featured' => true,
            'banner_path' => 'campaigns/banners/buyer-home-payday.webp',
            'banner_disk' => 'public',
            'banner_title' => 'Payday Rush banner',
        ]);
        Storage::disk('public')->put('campaigns/banners/buyer-home-payday.webp', 'buyer home campaign banner');
        $paydayProduct = $this->product($store, $category, 'Payday Rush Product');
        $paydayVariant = $this->variant($paydayProduct, 'BUYER-HOME-PAYDAY-001', 10000);
        $paydayParticipation = $this->participation($payday, $store, $seller, $paydayProduct, $paydayVariant, PlatformCampaignParticipation::STATUS_APPROVED);
        $paydayParticipation->items()->firstOrFail()->forceFill(['discount_percent' => 20])->save();

        $doubleDate = $this->campaign([
            'name' => '10.10 Sale',
            'position' => 2,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PISO_SALE,
            'banner_path' => 'campaigns/banners/buyer-home-piso.webp',
            'banner_disk' => 'public',
            'banner_title' => '10.10 Sale banner',
        ]);
        Storage::disk('public')->put('campaigns/banners/buyer-home-piso.webp', 'buyer home Piso campaign banner');
        $pisoProduct = $this->product($store, $category, 'Piso Sale Home Product');
        $pisoVariant = $this->variant($pisoProduct, 'BUYER-HOME-PISO-001', 10000);
        $this->participation($doubleDate, $store, $seller, $pisoProduct, $pisoVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        $hidden = $this->campaign([
            'name' => 'Hidden Open Campaign',
            'status' => PlatformPromotion::STATUS_OPEN,
            'position' => 0,
        ]);
        $this->campaign([
            'name' => 'Ended Campaign From Buyer Listing',
            'status' => PlatformPromotion::STATUS_ENDED,
            'position' => 3,
        ]);
        $emptyFeatured = $this->campaign([
            'name' => 'Featured Campaign Without Shoppable Products',
            'is_featured' => true,
            'banner_path' => 'campaigns/banners/empty-featured.webp',
            'banner_disk' => 'public',
        ]);
        Storage::disk('public')->put('campaigns/banners/empty-featured.webp', 'empty featured campaign banner');

        $pausedFeatured = $this->campaign([
            'name' => 'Paused Featured Campaign',
            'status' => PlatformPromotion::STATUS_PAUSED,
            'is_featured' => true,
            'banner_path' => 'campaigns/banners/paused-featured.webp',
            'banner_disk' => 'public',
        ]);
        Storage::disk('public')->put('campaigns/banners/paused-featured.webp', 'paused featured campaign banner');
        $pausedProduct = $this->product($store, $category, 'Paused Featured Product');
        $pausedVariant = $this->variant($pausedProduct, 'BUYER-HOME-PAUSED-001', 10000);
        $this->participation($pausedFeatured, $store, $seller, $pausedProduct, $pausedVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        $expiredFeatured = $this->campaign([
            'name' => 'Expired Featured Campaign',
            'is_featured' => true,
            'ends_on' => now('Asia/Manila')->subDay()->toDateString(),
            'ends_time' => '23:59:59',
            'banner_path' => 'campaigns/banners/expired-featured.webp',
            'banner_disk' => 'public',
        ]);
        Storage::disk('public')->put('campaigns/banners/expired-featured.webp', 'expired featured campaign banner');
        $expiredProduct = $this->product($store, $category, 'Expired Featured Product');
        $expiredVariant = $this->variant($expiredProduct, 'BUYER-HOME-EXPIRED-001', 10000);
        $this->participation($expiredFeatured, $store, $seller, $expiredProduct, $expiredVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        $home = $this->get(route('home'))
            ->assertOk()
            ->assertSee('Campaigns & Offers')
            ->assertSee('All Campaigns')
            ->assertSee('View all campaigns')
            ->assertSee('data-campaign-filter="all"', false)
            ->assertSee('data-campaign-filter="payday_sale"', false)
            ->assertSee('data-campaign-filter="piso_sale"', false)
            ->assertSee('data-campaign-type="payday_sale"', false)
            ->assertSee('Payday Rush')
            ->assertSee('Bearly-funded campaign')
            ->assertSee($payday->startsAt()->format('M j, Y'))
            ->assertSee($payday->endsAt()->format('M j, Y'))
            ->assertSee('View Details')
            ->assertSee('aria-label="View details for Payday Rush"', false)
            ->assertSee('10.10 Sale')
            ->assertSee('data-hero-campaign-id="'.$payday->id.'"', false)
            ->assertSee('data-hero-campaign-id="'.$doubleDate->id.'"', false)
            ->assertSee(Storage::disk('public')->url('campaigns/banners/buyer-home-payday.webp'), false)
            ->assertSee(Storage::disk('public')->url('campaigns/banners/buyer-home-piso.webp'), false)
            ->assertSee(route('campaigns.show', $payday))
            ->assertSee(route('campaigns.show', $doubleDate))
            ->assertSee(route('campaigns.index'), false)
            ->assertSee('Featured Campaign Without Shoppable Products')
            ->assertDontSee('Buyer Home Voucher')
            ->assertDontSee('data-offer-tone="voucher"', false)
            ->assertDontSee('live-platform-offers', false)
            ->assertSee('Open My Vouchers')
            ->assertSee('Open shipping vouchers in My Vouchers')
            ->assertSee('sidebar-perk-copy"><strong>Vouchers</strong>', false)
            ->assertSee('sidebar-perk-copy"><strong>Free Shipping</strong>', false)
            ->assertDontSee('BEARLY100')
            ->assertDontSee('₱100 off your')
            ->assertSee('data-hero-campaign-id="'.$emptyFeatured->id.'"', false)
            ->assertDontSee('Paused Featured Campaign')
            ->assertDontSee('Expired Featured Campaign')
            ->assertDontSee('data-offer-tone="campaign"', false)
            ->assertDontSee('Hidden Open Campaign')
            ->assertDontSee('Ended Campaign From Buyer Listing')
            ->assertDontSee('Brand Deals')
            ->assertDontSee('New User Deals')
            ->assertDontSee('11.11 Big Sale');

        $home->assertSeeInOrder(['Payday Rush', '10.10 Sale']);
        $homeHtml = $home->getContent();
        $this->assertSame(1, substr_count($homeHtml, 'data-hero-campaign-id="'.$payday->id.'"'));
        $this->assertSame(5, substr_count($homeHtml, 'class="bearly-promo-slide '));
        $this->assertSame(5, preg_match_all('/data-slide="\d+"/', $homeHtml));

        $flashDeals = $this->get(route('flash-deals'))
            ->assertOk()
            ->assertSee('Payday Sale')
            ->assertSee('10.10 Sale')
            ->assertDontSee('Ended Campaign From Buyer Listing');

        $html = $flashDeals->getContent();
        $this->assertMatchesRegularExpression(
            '/<article class="live-platform-offer-card" data-offer-tone="campaign">.*?<h3>Payday Rush<\/h3>.*?href="'.preg_quote(route('campaigns.show', $payday), '/').'".*?<\/article>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<article class="live-platform-offer-card" data-offer-tone="campaign">.*?<h3>10\.10 Sale<\/h3>.*?href="'.preg_quote(route('campaigns.show', $doubleDate), '/').'".*?<\/article>/s',
            $html,
        );

        $fallbackHtml = view('buyer.partials.live-offers', [
            'promotions' => collect([[
                'id' => 999,
                'name' => 'Demo Flash Deal',
                'kind' => 'flash_deal',
                'code' => null,
                'description' => null,
                'terms' => null,
                'discount_label' => 'Up to 50% off',
                'minimum_spend_minor' => 0,
                'value_minor' => 0,
                'discount_percent' => null,
                'max_discount_minor' => null,
                'starts_at' => null,
                'ends_at' => null,
                'banner_url' => null,
                'banner_link' => null,
                'is_campaign' => false,
                'campaign_type' => null,
                'campaign_type_label' => null,
            ]]),
        ])->render();

        $this->assertStringContainsString('href="'.url('/flash-deals').'"', $fallbackHtml);
        $this->assertStringContainsString('View Details', $fallbackHtml);
        $this->assertStringContainsString('live-platform-offer-fallback', $fallbackHtml);
    }

    public function test_buyer_all_campaigns_page_lists_all_currently_eligible_campaigns_beyond_four_and_preserves_visibility_rules(): void
    {
        $this->get(route('campaigns.index'))->assertRedirect(route('login'));

        $buyer = $this->buyer();
        $campaignNames = [
            ['name' => 'Flash Event', 'type' => PlatformPromotion::CAMPAIGN_FLASH_DEAL],
            ['name' => 'Payday Event', 'type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE],
            ['name' => 'Monthly Event', 'type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL],
            ['name' => 'Double Date Event', 'type' => PlatformPromotion::CAMPAIGN_DOUBLE_DATE],
            ['name' => 'Mega Sale', 'type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE],
        ];

        foreach ($campaignNames as $position => $campaignData) {
            $this->campaign([
                'name' => $campaignData['name'],
                'campaign_type' => $campaignData['type'],
                'position' => $position,
            ]);
        }

        $pisoSale = $this->campaign([
            'name' => 'Eligible Piso Sale',
            'campaign_type' => PlatformPromotion::CAMPAIGN_PISO_SALE,
            'position' => 5,
        ]);
        $this->campaign([
            'name' => 'Private Campaign Must Stay Hidden',
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
            'is_public' => false,
        ]);
        $this->campaign([
            'name' => 'Paused Campaign Must Stay Hidden',
            'campaign_type' => PlatformPromotion::CAMPAIGN_DOUBLE_DATE,
            'status' => PlatformPromotion::STATUS_PAUSED,
        ]);
        $this->campaign([
            'name' => 'Expired Campaign Must Stay Hidden',
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
            'ends_on' => now('Asia/Manila')->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($buyer)->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('All Campaigns')
            ->assertSee('Back to Home')
            ->assertSee('Live campaigns')
            ->assertSee('Mega Sale')
            ->assertSee(route('campaigns.show', $pisoSale), false)
            ->assertSee('Eligible Piso Sale')
            ->assertDontSee('Private Campaign Must Stay Hidden')
            ->assertDontSee('Paused Campaign Must Stay Hidden')
            ->assertDontSee('Expired Campaign Must Stay Hidden');

        $this->assertSame(6, substr_count($response->getContent(), 'class="campaign-index-card"'));
        foreach ($campaignNames as $campaignData) {
            $response->assertSee($campaignData['name']);
        }

        $buyer->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
        $agedBuyerResponse = $this->actingAs($buyer->fresh())->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('Mega Sale')
            ->assertDontSee('Eligible Piso Sale');

        $this->assertSame(5, substr_count($agedBuyerResponse->getContent(), 'class="campaign-index-card"'));
    }

    public function test_buyer_home_carousel_prioritizes_featured_banners_and_caps_campaign_slides_at_four(): void
    {
        Storage::fake('public');
        $buyer = $this->buyer();

        $campaigns = collect([
            $this->campaign([
                'name' => 'Earlier Unfeatured Banner',
                'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
                'position' => 0,
                'banner_path' => 'campaigns/banners/unfeatured-early.webp',
                'banner_disk' => 'public',
            ]),
            $this->campaign([
                'name' => 'Featured Banner One',
                'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
                'is_featured' => true,
                'position' => 4,
                'banner_path' => 'campaigns/banners/featured-one.webp',
                'banner_disk' => 'public',
            ]),
            $this->campaign([
                'name' => 'Middle Unfeatured Banner',
                'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
                'position' => 2,
                'banner_path' => 'campaigns/banners/unfeatured-middle.webp',
                'banner_disk' => 'public',
            ]),
            $this->campaign([
                'name' => 'Featured Banner Two',
                'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
                'is_featured' => true,
                'position' => 5,
                'banner_path' => 'campaigns/banners/featured-two.webp',
                'banner_disk' => 'public',
            ]),
            $this->campaign([
                'name' => 'Last Unfeatured Banner',
                'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
                'position' => 6,
                'banner_path' => 'campaigns/banners/unfeatured-last.webp',
                'banner_disk' => 'public',
            ]),
        ]);

        foreach ($campaigns as $campaign) {
            Storage::disk('public')->put($campaign->banner_path, 'campaign banner');
        }

        $home = $this->actingAs($buyer)->get(route('home'))->assertOk();
        $homeHtml = $home->getContent();
        preg_match_all('/data-hero-campaign-id="(\d+)"/', $homeHtml, $matches);

        $this->assertSame([
            (string) $campaigns[1]->id,
            (string) $campaigns[3]->id,
            (string) $campaigns[0]->id,
            (string) $campaigns[2]->id,
        ], $matches[1]);
        $this->assertSame(6, substr_count($homeHtml, 'class="bearly-promo-slide '));
        $this->assertSame(6, preg_match_all('/data-slide="\d+"/', $homeHtml));
    }

    public function test_home_hides_campaign_flash_and_ranking_sections_without_live_results(): void
    {
        $this->actingAs($this->buyer());

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('home-campaign-showcase', false)
            ->assertDontSee('Campaigns &amp; Offers', false)
            ->assertDontSee('class="flash-deals-preview home-flash-deals', false)
            ->assertDontSee('data-home-ranking-section', false)
            ->assertDontSee('No live Flash Deal products right now')
            ->assertDontSee('No live product rankings are available yet');
    }

    public function test_home_shows_ranking_sections_only_for_buyer_saves_and_completed_sales(): void
    {
        $buyer = $this->buyer();
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('home-authoritative-rankings');
        $soldProduct = $this->product($store, $category, 'Product with completed sales');
        $soldVariant = $this->variant($soldProduct, 'HOME-RANK-SOLD-001', 12000);
        $newProduct = $this->product($store, $category, 'New product without sales or saves');
        $this->variant($newProduct, 'HOME-RANK-NEW-001', 15000);

        $this->actingAs($buyer)
            ->postJson(route('wishlist.toggle'), ['product_id' => $soldProduct->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);

        $order = Order::query()->create([
            'order_no' => 'ORDER-HOME-RANK-'.(++$this->sequence),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '1 Ranking Street',
            'barangay' => 'Poblacion',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'currency' => 'PHP',
            'subtotal_minor' => 36000,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 36000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'placed_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SELLER-HOME-RANK-'.(++$this->sequence),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => 36000,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => 36000,
            'completed_at' => now(),
        ]);
        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $soldProduct->id,
            'product_variant_id' => $soldVariant->id,
            'product_name' => $soldProduct->name,
            'variant_name' => $soldVariant->name,
            'sku' => $soldVariant->sku,
            'unit_price_minor' => 12000,
            'quantity' => 3,
            'discount_minor' => 0,
            'subtotal_minor' => 36000,
        ]);

        $response = $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-home-ranking-section', false)
            ->assertSee('data-ranking-kind="popular"', false)
            ->assertSee('data-ranking-kind="top-sales"', false)
            ->assertSee('Product with completed sales')
            ->assertSee('1 Buyer save')
            ->assertSee('3 completed units sold');

        $this->assertStringNotContainsString(
            'data-home-ranking-product-id="'.$newProduct->id.'"',
            $response->getContent(),
            'A published listing without saves or completed sales must not be ranked.',
        );
    }

    public function test_flash_deals_landing_lists_only_products_priced_by_their_active_flash_campaign(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('flash-landing-store');
        $buyer = $this->buyer();
        $this->actingAs($buyer);

        $flashCampaign = $this->campaign([
            'name' => 'Live Flash Product Drop',
            'kind' => PlatformPromotion::KIND_FLASH_DEAL,
            'campaign_type' => PlatformPromotion::CAMPAIGN_FLASH_DEAL,
            'minimum_discount_percent' => 20,
            'budget_minor' => 50000,
        ]);
        $flashProduct = $this->product($store, $category, 'Eligible Flash Product');
        $flashVariant = $this->variant($flashProduct, 'FLASH-LANDING-001', 10000);
        $flashParticipation = $this->participation($flashCampaign, $store, $seller, $flashProduct, $flashVariant, PlatformCampaignParticipation::STATUS_APPROVED);
        $limitedCampaignItem = PlatformCampaignParticipationItem::query()
            ->where('platform_campaign_participation_id', $flashParticipation->id)
            ->where('product_id', $flashProduct->id)
            ->firstOrFail();
        $limitedCampaignItem->update(['stock_limit' => 20]);
        $this->recordCampaignOrderItem($buyer, $store, $flashProduct, $flashVariant, $limitedCampaignItem, 7, SellerOrderStatus::Completed->value);
        $this->recordCampaignOrderItem($buyer, $store, $flashProduct, $flashVariant, $limitedCampaignItem, 9, SellerOrderStatus::Packing->value);

        for ($productNumber = 2; $productNumber <= 7; $productNumber++) {
            $additionalFlashProduct = $this->product($store, $category, "Eligible Flash Product {$productNumber}");
            $additionalFlashVariant = $this->variant($additionalFlashProduct, "FLASH-LANDING-00{$productNumber}", 10000);
            $this->campaignItem($flashParticipation, $additionalFlashProduct, $additionalFlashVariant);
        }

        $paydayCampaign = $this->campaign([
            'name' => 'Payday Discount Campaign',
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PAYDAY_SALE,
        ]);
        $paydayOnlyProduct = $this->product($store, $category, 'Payday-only Campaign Product');
        $paydayOnlyVariant = $this->variant($paydayOnlyProduct, 'FLASH-LANDING-PAYDAY-001', 10000);
        $this->participation($paydayCampaign, $store, $seller, $paydayOnlyProduct, $paydayOnlyVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        $competingCampaign = $this->campaign([
            'name' => 'Lower Competing Campaign',
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
        ]);
        $competingProduct = $this->product($store, $category, 'Competing Price Product');
        $competingVariant = $this->variant($competingProduct, 'FLASH-LANDING-COMPETE-001', 10000);
        $this->campaignItem($flashParticipation, $competingProduct, $competingVariant);
        $competingParticipation = PlatformCampaignParticipation::query()->create([
            'platform_promotion_id' => $competingCampaign->id,
            'store_id' => $store->id,
            'submitted_by' => $seller->id,
            'status' => PlatformCampaignParticipation::STATUS_APPROVED,
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);
        $this->campaignItem($competingParticipation, $competingProduct, $competingVariant, 6000);

        $inactiveCampaign = $this->campaign([
            'name' => 'Paused Flash Campaign',
            'kind' => PlatformPromotion::KIND_FLASH_DEAL,
            'campaign_type' => PlatformPromotion::CAMPAIGN_FLASH_DEAL,
            'status' => PlatformPromotion::STATUS_PAUSED,
        ]);
        $inactiveProduct = $this->product($store, $category, 'Paused Flash Product');
        $inactiveVariant = $this->variant($inactiveProduct, 'FLASH-LANDING-PAUSED-001', 10000);
        $this->participation($inactiveCampaign, $store, $seller, $inactiveProduct, $inactiveVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        $futureCampaign = $this->campaign([
            'name' => 'Future Flash Campaign',
            'kind' => PlatformPromotion::KIND_FLASH_DEAL,
            'campaign_type' => PlatformPromotion::CAMPAIGN_FLASH_DEAL,
            'starts_on' => now('Asia/Manila')->addDay()->toDateString(),
            'starts_time' => '00:00:00',
        ]);
        $futureProduct = $this->product($store, $category, 'Future Flash Product');
        $futureVariant = $this->variant($futureProduct, 'FLASH-LANDING-FUTURE-001', 10000);
        $this->participation($futureCampaign, $store, $seller, $futureProduct, $futureVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        [$pendingSeller, $pendingStore] = $this->sellerAndStore('flash-landing-pending');
        $pendingProduct = $this->product($pendingStore, $category, 'Pending Flash Product');
        $pendingVariant = $this->variant($pendingProduct, 'FLASH-LANDING-PENDING-001', 10000);
        $this->participation($flashCampaign, $pendingStore, $pendingSeller, $pendingProduct, $pendingVariant, PlatformCampaignParticipation::STATUS_PENDING_REVIEW);

        [$rejectedSeller, $rejectedStore] = $this->sellerAndStore('flash-landing-rejected');
        $rejectedProduct = $this->product($rejectedStore, $category, 'Rejected Flash Product');
        $rejectedVariant = $this->variant($rejectedProduct, 'FLASH-LANDING-REJECTED-001', 10000);
        $this->participation($flashCampaign, $rejectedStore, $rejectedSeller, $rejectedProduct, $rejectedVariant, PlatformCampaignParticipation::STATUS_REJECTED);

        $onlineProduct = $this->product($store, $category, 'Online-only Flash Product', ['payment_methods' => ['online']]);
        $onlineVariant = $this->variant($onlineProduct, 'FLASH-LANDING-ONLINE-001', 10000);
        $this->campaignItem($flashParticipation, $onlineProduct, $onlineVariant);

        $outOfStockProduct = $this->product($store, $category, 'Out-of-stock Flash Product');
        $outOfStockVariant = $this->variant($outOfStockProduct, 'FLASH-LANDING-OUT-001', 10000, ['stock_on_hand' => 0]);
        $this->campaignItem($flashParticipation, $outOfStockProduct, $outOfStockVariant);

        $unavailableProduct = $this->product($store, $category, 'Unavailable Flash Product', ['compliance_status' => 'flagged']);
        $unavailableVariant = $this->variant($unavailableProduct, 'FLASH-LANDING-UNAVAILABLE-001', 10000);
        $this->campaignItem($flashParticipation, $unavailableProduct, $unavailableVariant);

        $campaignEndsAt = $flashCampaign->endsAt()->toIso8601String();
        $response = $this->get(route('flash-deals'))
            ->assertOk()
            ->assertSee('Live Flash Product Drop')
            ->assertSee($flashCampaign->schedulePeriod())
            ->assertSee('data-campaign-countdown', false)
            ->assertSee('data-campaign-ends-at="'.$campaignEndsAt.'"', false)
            ->assertSee('data-campaign-countdown-value', false)
            ->assertSee('Eligible Flash Product')
            ->assertSee('₱80.00')
            ->assertSee('7 SOLD')
            ->assertSee('aria-label="7 of 20 campaign units sold"', false)
            ->assertSee('aria-valuenow="7"', false)
            ->assertSee('style="--campaign-sales-progress: 35%"', false)
            ->assertSee(route('campaigns.show', $flashCampaign))
            ->assertSee(route('products.show', $flashProduct))
            ->assertSee('Other live offers')
            ->assertDontSee('No live Flash Deal products right now');

        $this->assertStringNotContainsString('Payday-only Campaign Product', $response->getContent());
        $this->assertStringNotContainsString('Competing Price Product', $response->getContent());
        $this->assertStringNotContainsString('Paused Flash Product', $response->getContent());
        $this->assertStringNotContainsString('Future Flash Product', $response->getContent());
        $this->assertStringNotContainsString('Pending Flash Product', $response->getContent());
        $this->assertStringNotContainsString('Rejected Flash Product', $response->getContent());
        $this->assertStringNotContainsString('Online-only Flash Product', $response->getContent());
        $this->assertStringNotContainsString('Out-of-stock Flash Product', $response->getContent());
        $this->assertStringNotContainsString('Unavailable Flash Product', $response->getContent());
        $this->assertStringContainsString('Payday Discount Campaign', $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), 'role="progressbar"'));
        $this->assertStringContainsString('0 SOLD', $response->getContent(), 'Uncapped products show a count without a misleading stock progress meter.');

        $homeResponse = $this->get(route('home'))
            ->assertOk()
            ->assertSee('class="flash-deals-preview home-flash-deals', false)
            ->assertSee('data-campaign-countdown', false)
            ->assertSee('data-campaign-ends-at="'.$campaignEndsAt.'"', false)
            ->assertSee('Live Flash Product Drop')
            ->assertSee('Eligible Flash Product')
            ->assertSee('7 SOLD')
            ->assertSee('aria-label="7 of 20 campaign units sold"', false)
            ->assertSee('aria-valuenow="7"', false)
            ->assertSee('style="--campaign-sales-progress: 35%"', false);

        $homeHtml = $homeResponse->getContent();
        $this->assertSame(6, substr_count($homeHtml, 'class="home-flash-product-card discovery-card"'));
        $this->assertSame(1, substr_count($homeHtml, 'role="progressbar"'));
        $this->assertStringContainsString('0 SOLD', $homeHtml, 'Uncapped homepage products show a count without a misleading stock progress meter.');
        $this->assertStringNotContainsString('class="text-button top-view-all" href="'.url('/flash-deals').'"', $homeHtml);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('class="flash-deals-preview home-flash-deals', false)
            ->assertSee('Live Flash Product Drop')
            ->assertSee('Eligible Flash Product');
    }

    public function test_flash_deals_shows_empty_state_when_no_eligible_flash_product_exists(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('flash-landing-no-cod');
        $buyer = $this->buyer();
        $this->actingAs($buyer);

        $campaign = $this->campaign([
            'name' => 'No COD Flash Product Campaign',
            'kind' => PlatformPromotion::KIND_FLASH_DEAL,
            'campaign_type' => PlatformPromotion::CAMPAIGN_FLASH_DEAL,
        ]);
        $product = $this->product($store, $category, 'Non COD Campaign Product', ['payment_methods' => ['online']]);
        $variant = $this->variant($product, 'FLASH-LANDING-NOCOD-001', 10000);
        $this->participation($campaign, $store, $seller, $product, $variant, PlatformCampaignParticipation::STATUS_APPROVED);

        $this->get(route('flash-deals'))
            ->assertOk()
            ->assertSee('No live Flash Deal products right now')
            ->assertDontSee('Non COD Campaign Product')
            ->assertDontSee('data-flash-campaign-id', false);
    }

    public function test_product_cards_and_detail_use_campaign_name_while_product_discounts_keep_their_label(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore('campaign-label-store');
        $buyer = $this->buyer();
        $this->actingAs($buyer);

        $campaign = $this->campaign([
            'name' => '10.10 Sale',
            'campaign_type' => PlatformPromotion::CAMPAIGN_PISO_SALE,
        ]);

        $campaignProduct = $this->product($store, $category, 'Campaign Priced Product');
        $campaignVariant = $this->variant($campaignProduct, 'CAMPAIGN-LABEL-001', 10_000, [
            'compare_at_price_minor' => 20_000,
        ]);
        $campaignRegularVariant = $this->variant($campaignProduct, 'CAMPAIGN-LABEL-002', 15_000, [
            'position' => 1,
            'stock_on_hand' => 5,
        ]);
        $this->assertSame(15_000, (int) $campaignRegularVariant->price_minor);
        $this->participation($campaign, $store, $seller, $campaignProduct, $campaignVariant, PlatformCampaignParticipation::STATUS_APPROVED);

        $discountProduct = $this->product($store, $category, 'Product Discount Priced Item');
        $discountVariant = $this->variant($discountProduct, 'PRODUCT-DISCOUNT-001', 10_000);

        $startsAt = now('Asia/Manila')->startOfMinute()->subMinutes(2);
        $endsAt = $startsAt->copy()->addHours(2);
        $productDiscount = StorePromotion::query()->create([
            'store_id' => $store->id,
            'created_by' => $seller->id,
            'name' => 'Internal product discount name',
            'type' => StorePromotion::TYPE_PRODUCT_DISCOUNT,
            'status' => StorePromotion::STATUS_ACTIVE,
            'discount_type' => StorePromotion::DISCOUNT_PERCENT,
            'discount_percent' => 10,
            'buyer_campaign_name' => 'Weekend Markdown',
            'starts_on' => $startsAt->toDateString(),
            'ends_on' => $endsAt->toDateString(),
            'starts_time' => $startsAt->format('H:i:s'),
            'ends_time' => $endsAt->format('H:i:s'),
        ]);
        $productDiscount->products()->attach($discountProduct->id);

        $cards = app(BuyerCatalogService::class)->cards(collect([
            $campaignProduct->fresh(),
            $discountProduct->fresh(),
        ]), $buyer);
        $cardsByProduct = collect($cards)->keyBy('product_id');

        $this->assertSame('campaign', $cardsByProduct[$campaignProduct->id]['price_source']);
        $this->assertSame('10.10 Sale', $cardsByProduct[$campaignProduct->id]['campaign_name']);
        $this->assertSame(10_000, $cardsByProduct[$campaignProduct->id]['regular_price_minor']);
        $this->assertSame(PlatformPromotion::PISO_SALE_PRICE_MINOR, $cardsByProduct[$campaignProduct->id]['price_minor']);
        $this->assertSame(99, $cardsByProduct[$campaignProduct->id]['discount_percent']);

        $this->assertSame('product_discount', $cardsByProduct[$discountProduct->id]['price_source']);
        $this->assertSame('Weekend Markdown', $cardsByProduct[$discountProduct->id]['product_discount_name']);

        $this->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('10.10 Sale')
            ->assertSee('data-price-campaign>10.10 Sale', false)
            ->assertSee('₱1.00')
            ->assertSee('99% OFF');

        $this->get(route('products.show', $campaignProduct))
            ->assertOk()
            ->assertSee('10.10 Sale')
            ->assertSee('data-price-campaign>10.10 Sale', false)
            ->assertSee('data-detail-promotion-name>10.10 Sale', false)
            ->assertSee('99% OFF');

        $this->get(route('products.show', $discountProduct))
            ->assertOk()
            ->assertSee('Weekend Markdown')
            ->assertSee('data-price-campaign>Weekend Markdown', false)
            ->assertSee('data-detail-promotion-name>Weekend Markdown', false)
            ->assertSee('10% OFF');

        $this->get(route('stores.show', ['store' => $store->slug]))
            ->assertOk()
            ->assertSee('data-price-campaign>10.10 Sale', false)
            ->assertSee('data-price-campaign>Weekend Markdown', false)
            ->assertSee('data-campaign-name="10.10 Sale"', false)
            ->assertSee('data-campaign-name=""', false);

        $this->postJson(route('cart.add'), [
            'product_variant_id' => $campaignVariant->id,
            'quantity' => 1,
        ])->assertOk();

        $this->getJson(route('cart.data'))
            ->assertOk()
            ->assertJsonPath('data.items.0.price_minor', PlatformPromotion::PISO_SALE_PRICE_MINOR)
            ->assertJsonPath('data.items.0.campaign_name', '10.10 Sale');
    }

    private function buyer(): User
    {
        $sequence = ++$this->sequence;

        return User::query()->create([
            'name' => 'Campaign Buyer '.$sequence,
            'first_name' => 'Campaign',
            'last_name' => 'Buyer '.$sequence,
            'email' => "campaign-buyer-{$sequence}@example.test",
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function category(): Category
    {
        $sequence = ++$this->sequence;

        return Category::query()->create([
            'name' => 'Campaign Display Category '.$sequence,
            'slug' => 'campaign-display-category-'.$sequence,
            'is_active' => true,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(string $slug, bool $published = true): array
    {
        $sequence = ++$this->sequence;
        $seller = User::query()->create([
            'name' => 'Campaign Seller '.$sequence,
            'first_name' => 'Campaign',
            'last_name' => 'Seller '.$sequence,
            'email' => "campaign-seller-{$sequence}@example.test",
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Campaign Seller Business '.$sequence,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);

        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Campaign Store '.$sequence,
            'slug' => $slug.'-'.$sequence,
            'publication_status' => $published ? 'published' : 'unpublished',
        ]);

        return [$seller, $store];
    }

    private function product(Store $store, Category $category, string $name, array $overrides = []): Product
    {
        $sequence = ++$this->sequence;

        return Product::query()->create(array_merge([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.$sequence,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'payment_methods' => ['cod', 'online'],
            'voucher_eligible' => true,
            'published_at' => now(),
        ], $overrides));
    }

    private function variant(Product $product, string $sku, int $priceMinor, array $overrides = []): ProductVariant
    {
        return ProductVariant::query()->create(array_merge([
            'product_id' => $product->id,
            'sku' => $sku.'-'.(++$this->sequence),
            'name' => 'Standard',
            'price_minor' => $priceMinor,
            'compare_at_price_minor' => null,
            'stock_on_hand' => 10,
            'stock_reserved' => 0,
            'low_stock_threshold' => 2,
            'package_weight_kg' => 0.25,
            'package_length_cm' => 10,
            'package_width_cm' => 10,
            'package_height_cm' => 10,
            'is_active' => true,
            'position' => 0,
        ], $overrides));
    }

    private function campaign(array $overrides = []): PlatformPromotion
    {
        $sequence = ++$this->sequence;
        $manila = now('Asia/Manila');

        return PlatformPromotion::query()->create(array_merge([
            'name' => 'Campaign Display '.$sequence,
            'code' => null,
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_PISO_SALE,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'description' => 'Campaign display test event.',
            'terms' => 'Internal terms.',
            'campaign_notes' => 'Internal notes.',
            'value_minor' => 0,
            'minimum_spend_minor' => 0,
            'starts_on' => $manila->copy()->subDay()->toDateString(),
            'starts_time' => '00:00:00',
            'ends_on' => $manila->copy()->addDays(7)->toDateString(),
            'ends_time' => '23:59:59',
            'join_deadline' => $manila->copy()->subDays(2)->toDateString(),
            'requires_approval' => true,
            'budget_minor' => 5_000_000,
            'is_claimable' => false,
            'is_public' => true,
            'is_featured' => false,
            'position' => 0,
        ], $overrides));
    }

    private function participation(
        PlatformPromotion $campaign,
        Store $store,
        User $seller,
        Product $product,
        ProductVariant $variant,
        string $status,
    ): PlatformCampaignParticipation {
        $participation = PlatformCampaignParticipation::query()->create([
            'platform_promotion_id' => $campaign->id,
            'store_id' => $store->id,
            'submitted_by' => $seller->id,
            'status' => $status,
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'review_reason' => 'Internal reviewer note.',
        ]);

        $this->campaignItem($participation, $product, $variant);

        return $participation;
    }

    private function campaignItem(
        PlatformCampaignParticipation $participation,
        Product $product,
        ProductVariant $variant,
        int $campaignPriceMinor = 8000,
    ): PlatformCampaignParticipationItem {
        return PlatformCampaignParticipationItem::query()->create([
            'platform_campaign_participation_id' => $participation->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'regular_price_minor' => (int) $variant->price_minor,
            'campaign_price_minor' => $campaignPriceMinor,
            'discount_percent' => null,
            'stock_limit' => null,
            'per_buyer_limit' => null,
        ]);
    }

    private function recordCampaignOrderItem(
        User $buyer,
        Store $store,
        Product $product,
        ProductVariant $variant,
        PlatformCampaignParticipationItem $campaignItem,
        int $quantity,
        string $sellerOrderStatus,
    ): void {
        $completed = $sellerOrderStatus === SellerOrderStatus::Completed->value;
        $unitPriceMinor = (int) $campaignItem->campaign_price_minor;
        $subtotalMinor = $unitPriceMinor * $quantity;
        $completedAt = $completed ? now() : null;
        $order = Order::query()->create([
            'order_no' => 'ORDER-FLASH-SALES-'.(++$this->sequence),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '1 Flash Deal Street',
            'barangay' => 'Poblacion',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'currency' => 'PHP',
            'subtotal_minor' => $subtotalMinor,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => $subtotalMinor,
            'status' => $completed ? 'completed' : 'processing',
            'payment_status' => $completed ? 'paid' : 'unpaid',
            'placed_at' => now()->subDay(),
            'completed_at' => $completedAt,
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SELLER-FLASH-SALES-'.(++$this->sequence),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $sellerOrderStatus,
            'subtotal_minor' => $subtotalMinor,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => $subtotalMinor,
            'completed_at' => $completedAt,
        ]);
        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'platform_campaign_participation_item_id' => $campaignItem->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'unit_price_minor' => $unitPriceMinor,
            'quantity' => $quantity,
            'discount_minor' => 0,
            'subtotal_minor' => $subtotalMinor,
        ]);
    }
}
