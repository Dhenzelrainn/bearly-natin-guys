<?php

namespace App\Http\Controllers;

use App\Services\BuyerDisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BuyerDisputeController extends Controller
{
    public function __construct(
        private readonly BuyerDisputeService $disputes,
    ) {}

    public function create(Request $request, int $sellerOrder): View|RedirectResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(['order_issue', 'off_platform_solicitation'])],
        ]);
        $caseType = (string) $request->query('type', 'order_issue');
        $order = $this->disputes->buyerOrder($request->user(), $sellerOrder);

        $activeCase = $caseType === 'off_platform_solicitation'
            ? $order->activePolicyReport
            : $order->activeDispute;

        if ($activeCase) {
            return redirect()->route('buyer.disputes.show', $activeCase);
        }

        return view('buyer.disputes.create', [
            'sellerOrder' => $order,
            'returnRequest' => $order->returnRequests->first(),
            'caseType' => $caseType,
        ]);
    }

    public function store(Request $request, int $sellerOrder): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['order_issue', 'off_platform_solicitation'])],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,txt,doc,docx', 'max:10240'],
            'buyer_id' => ['prohibited'],
            'seller_id' => ['prohibited'],
            'store_id' => ['prohibited'],
            'rider_id' => ['prohibited'],
            'logistics_profile_id' => ['prohibited'],
            'shipment_id' => ['prohibited'],
            'return_request_id' => ['prohibited'],
            'participant_ids' => ['prohibited'],
            'amount_minor' => ['prohibited'],
            'priority' => ['prohibited'],
        ]);

        $dispute = $this->disputes->create(
            $request->user(),
            $sellerOrder,
            $validated['subject'],
            $validated['description'],
            array_values($request->file('evidence', [])),
            $validated['type'] ?? 'order_issue',
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => (int) $dispute->id,
                    'dispute_no' => $dispute->dispute_no,
                    'url' => route('buyer.disputes.show', $dispute),
                ],
                'message' => 'Your dispute was submitted for Admin review.',
            ], 201);
        }

        return redirect()
            ->route('buyer.disputes.show', $dispute)
            ->with('success', 'Your dispute was submitted for Admin review.');
    }

    public function show(Request $request, int $dispute): View
    {
        $case = $this->disputes->participantDispute($request->user(), $dispute);

        return view('buyer.disputes.show', [
            'case' => $case,
            'visibleEvents' => $case->events
                ->reject(fn ($event): bool => $event->event_type === 'internal_note')
                ->values(),
        ]);
    }
}
