<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformPromotion;
use App\Models\Store;
use App\Services\CampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminCampaignController extends Controller
{
    public function index(CampaignService $campaigns): View
    {
        return view('admin.campaigns.index', [
            'campaigns' => $campaigns->adminListing(),
        ]);
    }

    public function create(): View
    {
        return $this->formView();
    }

    public function edit(PlatformPromotion $campaign): View
    {
        abort_unless($campaign->isCampaign(), 404);

        return $this->formView($campaign->load(['stores:id,name', 'categories:id,name']));
    }

    public function store(Request $request, CampaignService $campaigns): RedirectResponse
    {
        $campaign = $campaigns->createCampaign(
            $request->user(),
            $this->validated($request, true),
            $request->file('banner'),
        );

        return redirect()
            ->route('admin.campaigns.show', $campaign)
            ->with('success', 'Bearly campaign created.');
    }

    public function update(
        Request $request,
        PlatformPromotion $campaign,
        CampaignService $campaigns,
    ): RedirectResponse {
        abort_unless($campaign->isCampaign(), 404);

        $campaigns->updateCampaign(
            $request->user(),
            $campaign,
            $this->validated($request, false),
            $request->file('banner'),
        );

        return redirect()
            ->route('admin.campaigns.show', $campaign)
            ->with('success', 'Bearly campaign updated.');
    }

    public function show(PlatformPromotion $campaign, CampaignService $campaigns): View
    {
        abort_unless($campaign->isCampaign(), 404);

        $campaign = $campaigns->adminDetail($campaign);
        $participationIds = $campaign->participations->pluck('id')->all();

        return view('admin.campaigns.show', [
            'campaign' => $campaign,
            'auditLogs' => AuditLog::query()
                ->with('actor:id,name')
                ->where(function ($query) use ($campaign, $participationIds): void {
                    $query->where(function ($campaignLogs) use ($campaign): void {
                        $campaignLogs->where('auditable_type', $campaign->getMorphClass())
                            ->where('auditable_id', $campaign->id);
                    });
                    if ($participationIds !== []) {
                        $query->orWhere(function ($participationLogs) use ($participationIds): void {
                            $participationLogs->where('auditable_type', (new PlatformCampaignParticipation)->getMorphClass())
                                ->whereIn('auditable_id', $participationIds);
                        });
                    }
                })
                ->latest('id')
                ->take(10)
                ->get(),
        ]);
    }

    public function updateStatus(
        Request $request,
        PlatformPromotion $campaign,
        CampaignService $campaigns,
    ): RedirectResponse {
        abort_unless($campaign->isCampaign(), 404);
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                PlatformPromotion::STATUS_DRAFT,
                PlatformPromotion::STATUS_OPEN,
                PlatformPromotion::STATUS_ACTIVE,
                PlatformPromotion::STATUS_PAUSED,
                PlatformPromotion::STATUS_ENDED,
            ])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $campaigns->updateStatus($request->user(), $campaign, $validated['status'], $validated['reason'] ?? null);

        return back()->with('success', 'Campaign status updated.');
    }

    public function reviewParticipation(
        Request $request,
        PlatformCampaignParticipation $participation,
        CampaignService $campaigns,
    ): RedirectResponse {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $campaigns->reviewParticipation(
            $request->user(),
            $participation,
            $validated['decision'],
            $validated['reason'] ?? null,
        );

        return back()->with('success', 'Seller participation reviewed.');
    }

    private function formView(?PlatformPromotion $campaign = null): View
    {
        return view('admin.campaigns.form', [
            'campaign' => $campaign,
            'campaignTypes' => $this->campaignTypes(),
            'stores' => Store::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return array<string, string> */
    private function campaignTypes(): array
    {
        return [
            PlatformPromotion::CAMPAIGN_FLASH_DEAL => 'Flash Deal',
            PlatformPromotion::CAMPAIGN_PISO_SALE => 'Piso Sale',
            PlatformPromotion::CAMPAIGN_PAYDAY_SALE => 'Payday Sale',
            PlatformPromotion::CAMPAIGN_MONTHLY_DEAL => 'Monthly Deal',
            PlatformPromotion::CAMPAIGN_DOUBLE_DATE => '9.9 / 10.10 / 11.11 / 12.12',
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $request->merge([
            'start_time' => $request->input('start_time', '00:00'),
            'end_time' => $request->input('end_time', '23:59'),
        ]);

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'campaign_type' => ['required', Rule::in(array_keys($this->campaignTypes()))],
            'funding_type' => ['required', Rule::in([
                PlatformPromotion::FUNDING_PLATFORM,
                PlatformPromotion::FUNDING_SELLER,
            ])],
            'description' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'campaign_notes' => ['nullable', 'string', 'max:2000'],
            'budget' => ['nullable', 'prohibited_if:funding_type,seller', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'join_deadline' => ['required', 'date_format:Y-m-d', 'before_or_equal:starts_on'],
            'requires_approval' => ['sometimes', 'boolean'],
            'max_participating_stores' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'max_products_per_store' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_featured' => ['sometimes', 'boolean'],
            'banner_title' => ['nullable', 'string', 'max:120'],
            'banner_link' => ['nullable', 'url', 'max:255'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'scope_submitted' => ['sometimes', 'boolean'],
            'store_ids' => ['nullable', 'array', 'max:500'],
            'store_ids.*' => ['integer', 'exists:stores,id'],
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ];

        if ($creating) {
            $rules['status'] = ['required', Rule::in([
                PlatformPromotion::STATUS_DRAFT,
                PlatformPromotion::STATUS_OPEN,
            ])];
        }

        $validated = $request->validate($rules);

        if (PlatformPromotion::dateTimeFromInput($validated['ends_on'], $validated['end_time'], true)
            ->lessThan(PlatformPromotion::dateTimeFromInput($validated['starts_on'], $validated['start_time']))) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }

        return $validated;
    }
}
