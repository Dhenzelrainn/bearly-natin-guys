<?php

namespace App\Http\Controllers;

use App\Events\CampaignParticipationSubmitted;
use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformPromotion;
use App\Services\CampaignService;
use App\Services\SellerIdentityService;
use App\Services\SellerNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

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

    public function participationStatus(
        Request $request,
        PlatformPromotion $campaign,
        CampaignService $campaigns,
    ): JsonResponse {
        abort_unless($campaign->isCampaign() && $campaign->statusKey() !== 'draft', 404);

        $participation = $campaigns->sellerCampaignParticipationStatus($request->user(), $campaign);
        abort_if($participation === null, 404);

        return response()->json([
            'data' => ['participation' => $participation],
            'message' => 'Campaign participation status retrieved.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function join(
        Request $request,
        PlatformPromotion $campaign,
        CampaignService $campaigns,
    ): RedirectResponse {
        $isPisoSale = $campaign->campaign_type === PlatformPromotion::CAMPAIGN_PISO_SALE;
        $validated = $request->validate([
            'terms_accepted' => ['required', 'accepted'],
            'seller_note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => [$isPisoSale
                ? 'array:product_id,stock_limit,per_buyer_limit'
                : 'array:product_id,discount_percent,stock_limit,per_buyer_limit'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.campaign_price' => ['prohibited'],
            'items.*.variant_id' => ['prohibited'],
            'items.*.discount_percent' => $isPisoSale
                ? ['prohibited']
                : ['required', 'integer', 'between:1,90'],
            'items.*.stock_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'items.*.per_buyer_limit' => ['nullable', 'integer', 'between:1,1000'],
        ]);

        $participation = $campaigns->join($request->user(), $campaign, $validated);

        try {
            CampaignParticipationSubmitted::dispatch((int) $campaign->id);
        } catch (Throwable $exception) {
            report($exception);
        }

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
