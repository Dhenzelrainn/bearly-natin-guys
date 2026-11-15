<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Services\BuyerCatalogService;
use App\Services\CampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    private const PRODUCT_LIMIT = 60;

    private const PRODUCT_GROUPS = [
        'automotive-and-parts' => 'Automotive',
        'books-and-media' => 'Books',
        'books-and-stationery' => 'Books',
        'electronics-and-gadgets' => 'Tech',
        'fashion-and-apparel' => 'Fashion',
        'food-and-gourmet' => 'Food',
        'furniture-and-office-equipment' => 'Home',
        'health-and-beauty' => 'Beauty',
        'home-and-furniture' => 'Home',
        'home-and-garden' => 'Home',
        'jewelry-and-watches' => 'Accessories',
        'kids-and-baby' => 'Kids & Baby',
        'men-s-apparel' => 'Fashion',
        'pet-supplies' => 'Pets',
        'sports-and-outdoors' => 'Sports',
        'women-s-apparel' => 'Fashion',
    ];

    public function index(BuyerCatalogService $catalog, CampaignService $campaigns): View
    {
        $homeCategories = json_decode(
            file_get_contents(resource_path('data/categories.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $landingProducts = collect($catalog->cards(
            $catalog->publishedProducts(limit: self::PRODUCT_LIMIT),
        ))
            ->map(function (array $product): array {
                $id = (int) $product['id'];

                return [
                    'id' => (string) $id,
                    'name' => (string) $product['name'],
                    'price_minor' => max(0, (int) ($product['price_minor'] ?? 0)),
                    'image' => (string) ($product['image'] ?? ''),
                    'group' => $this->productGroup($product),
                    'detail_url' => route('products.preview', ['product' => $id]),
                ];
            })
            ->values()
            ->all();

        $featuredCampaigns = $campaigns->featuredBuyerCampaignsForLanding()
            ->map(function (PlatformPromotion $campaign) use ($campaigns): array {
                $summary = $campaigns->buyerCampaignSummaryPayload($campaign);

                return [
                    'id' => (int) $summary['id'],
                    'name' => (string) $summary['name'],
                    'type_label' => (string) $summary['type_label'],
                    'period' => $campaign->schedulePeriod(),
                    'banner_url' => $summary['banner_url'],
                    'banner_title' => $summary['banner_title'],
                    'preview_url' => route('campaigns.preview', ['campaign' => $summary['id']]),
                ];
            })
            ->values()
            ->all();

        return view('landing-page.landingpage', [
            'homeCategories' => $homeCategories,
            'landingProducts' => $landingProducts,
            'featuredCampaigns' => $featuredCampaigns,
        ]);
    }

    public function campaignPreview(
        Request $request,
        PlatformPromotion $campaign,
        CampaignService $campaigns,
        BuyerCatalogService $catalog,
    ): View|RedirectResponse {
        $user = $request->user();
        $campaign = $campaigns->buyerCampaignDetail($campaign, buyer: $user);

        if (
            $user?->role === UserRole::Buyer->value
            && $user->status === AccountStatus::Active->value
        ) {
            return redirect()->route('campaigns.show', ['campaign' => $campaign]);
        }

        $summary = $campaigns->buyerCampaignSummaryPayload($campaign);
        $productCards = $catalog->cards($campaigns->buyerCampaignProductPreview($campaign, 24));

        return view('landing-page.campaign-preview', [
            'campaign' => [
                'id' => (int) $summary['id'],
                'name' => (string) $summary['name'],
                'type_label' => (string) $summary['type_label'],
                'description' => trim((string) ($summary['description'] ?? '')),
                'period' => $campaign->schedulePeriod(),
                'banner_url' => $summary['banner_url'],
                'banner_title' => (string) ($summary['banner_title'] ?: $summary['name'].' campaign banner'),
            ],
            'products' => collect($productCards)
                ->map(fn (array $product): array => [
                    'id' => (int) $product['id'],
                    'name' => (string) $product['name'],
                    'image' => (string) ($product['image'] ?? ''),
                    'price_minor' => max(0, (int) ($product['price_minor'] ?? 0)),
                    'preview_url' => route('products.preview', ['product' => (int) $product['id']]),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function productPreview(
        Request $request,
        Product $product,
        BuyerCatalogService $catalog,
    ): View|RedirectResponse {
        $visibleProduct = Product::query()
            ->visibleToBuyers()
            ->acceptsCashOnDelivery()
            ->whereKey($product->getKey())
            ->firstOrFail();

        $user = $request->user();

        if (
            $user?->role === UserRole::Buyer->value
            && $user->status === AccountStatus::Active->value
        ) {
            return redirect()->route('products.show', ['product' => $visibleProduct]);
        }

        $card = $catalog->card($visibleProduct);

        // Deliberately pass an allowlisted preview payload. Do not serialize the
        // catalog card here: it also contains variants, stock, seller, and copy.
        return view('landing-page.product-preview', [
            'product' => [
                'id' => (int) $card['id'],
                'name' => (string) $card['name'],
                'price_minor' => max(0, (int) ($card['price_minor'] ?? 0)),
                'image' => (string) ($card['image'] ?? ''),
            ],
        ]);
    }

    /** @param array<string, mixed> $product */
    private function productGroup(array $product): string
    {
        $slug = (string) ($product['marketplace_category_slug'] ?? $product['category_slug'] ?? '');
        $name = trim((string) ($product['marketplace_category_name'] ?? $product['category'] ?? ''));

        if (isset(self::PRODUCT_GROUPS[$slug])) {
            return self::PRODUCT_GROUPS[$slug];
        }

        return match (Str::lower($name)) {
            'electronics and gadgets' => 'Tech',
            'fashion and apparel', "men's apparel", "women's apparel" => 'Fashion',
            'food and gourmet' => 'Food',
            'furniture and office equipment', 'home and furniture', 'home and garden' => 'Home',
            'health and beauty' => 'Beauty',
            'books and media', 'books and stationery' => 'Books',
            'jewelry and watches' => 'Accessories',
            'pet supplies' => 'Pets',
            'sports and outdoors' => 'Sports',
            default => $name !== '' ? $name : 'Marketplace',
        };
    }
}
