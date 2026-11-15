<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceTaxonomyService;
use App\Services\SellerIdentityService;
use App\Services\SellerNotificationService;
use App\Services\SellerProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerMarketplaceSubcategoryRequestController extends Controller
{
    public function index(
        Request $request,
        MarketplaceTaxonomyService $taxonomy,
        SellerIdentityService $identity,
        SellerNotificationService $notifications,
        SellerProductService $products,
    ): View {
        $products->storeFor($request->user());

        return view('seller.Products.subcategory-requests', [
            'seller' => $identity->current(),
            'notifications' => $notifications->recentFor($request->user()),
            'approvedCategory' => $taxonomy->sellerContext($request->user())['category'],
            'requests' => $taxonomy->sellerRequests($request->user()),
        ]);
    }

    public function create(
        Request $request,
        MarketplaceTaxonomyService $taxonomy,
        SellerIdentityService $identity,
        SellerNotificationService $notifications,
        SellerProductService $products,
    ): View {
        $products->storeFor($request->user());
        $context = $taxonomy->sellerContext($request->user());

        return view('seller.Products.subcategory-request-form', [
            'seller' => $identity->current(),
            'notifications' => $notifications->recentFor($request->user()),
            'approvedCategory' => $context['category'],
            'mappedMarketplaceCategories' => $taxonomy->marketplaceCategoriesForSeller($request->user()),
        ]);
    }

    public function store(Request $request, MarketplaceTaxonomyService $taxonomy, SellerProductService $products): RedirectResponse
    {
        $data = $request->validate([
            'suggested_name' => ['required', 'string', 'min:2', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $products->storeFor($request->user());
        $taxonomy->requestSubcategory(
            $request->user(),
            $data['suggested_name'],
            $data['reason'],
            $request,
        );

        return redirect()->route('seller.products.subcategory-requests.index')
            ->with('success', 'Your marketplace subcategory request was sent to Admin for review. It will not be available to Buyers before approval.');
    }
}
