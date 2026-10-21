<?php

namespace App\Http\Controllers;

use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformPromotion;
use App\Services\CampaignService;
use App\Services\SellerIdentityService;
use App\Services\SellerNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerCampaignController extends Controller
{
    public function show(
        Request $request,
        PlatformPromotion $campaign,
        CampaignService $campaigns,
    ): View {
        abort_unless($campaign->isCampaign() && $campaign->statusKey() !== 'draft', 404);
        $payload = $campaigns->sellerCampaignDetail($request->user(), $campaign);

        return view('seller.Promotions.campaign-show', [
            'seller' => app(SellerIdentityService::class)->current(),
            'notifications' => app(SellerNotificationService::class)->recentFor($request->user()),
            ...$payload,
        ]);
    }

    public function join(
        Request $request,
        PlatformPromotion $campaign,
        CampaignService $campaigns,
    ): RedirectResponse {
        $validated = $request->validate([
            'terms_accepted' => ['required', 'accepted'],
            'seller_note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.campaign_price' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'items.*.discount_percent' => ['nullable', 'integer', 'between:1,90'],
            'items.*.stock_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'items.*.per_buyer_limit' => ['nullable', 'integer', 'between:1,1000'],
        ]);

        $participation = $campaigns->join($request->user(), $campaign, $validated);

        return redirect()
            ->route('seller.promotions.campaigns.show', $campaign)
            ->with('success', $participation->status === PlatformCampaignParticipation::STATUS_PENDING_REVIEW
                ? 'Campaign participation submitted for Bearly review.'
                : 'Campaign participation approved and scheduled.');
    }

    public function withdraw(
        Request $request,
        PlatformCampaignParticipation $participation,
        CampaignService $campaigns,
    ): RedirectResponse {
        $campaign = $participation->campaign;
        $campaigns->withdraw($request->user(), $participation);

        return redirect()
            ->route('seller.promotions.campaigns.show', $campaign)
            ->with('success', 'Campaign participation withdrawn. Historical orders keep their snapshots.');
    }
}
