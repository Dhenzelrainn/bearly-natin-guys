<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\CodCashCollection;
use App\Models\OrderItem;
use App\Models\RefundCashPayout;
use App\Models\ReturnItem;
use App\Models\RiderEarning;
use App\Models\RiderPayoutBatch;
use App\Services\AuditService;
use App\Services\BuyerCatalogService;
use App\Services\CodCashCollectionService;
use App\Services\RefundCashPayoutService;
use App\Services\RiderPayoutBatchService;
use App\Services\RiderPickupEarningService;
use App\Services\ShippingSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class LogisticsCashController extends Controller
{
    public function index(Request $request, RefundCashPayoutService $cash): View
    {
        $user = $request->user();
        $workspace = $cash->logisticsWorkspace($user);
        $remittanceCandidates = $workspace['codCollections']
            ->where('status', 'received_by_logistics');
        $selectedCodCollection = null;
        $requestedCollectionId = $request->query('cod_remittance');

        if (is_scalar($requestedCollectionId)
            && ctype_digit((string) $requestedCollectionId)) {
            $requestedCollectionId = (int) $requestedCollectionId;
            $selectedCodCollection = $remittanceCandidates->first(
                fn (CodCashCollection $collection): bool => (int) $collection->id === $requestedCollectionId,
            );
        }

        $selectedCodCollection ??= $remittanceCandidates->first();

        return view('logistics.cash.index', $this->layoutData($request) + $workspace + [
            'selectedCodCollection' => $selectedCodCollection,
        ]);
    }

    public function refundCashStatusVersion(Request $request, RefundCashPayoutService $cash): JsonResponse
    {
        return response()->json([
            'data' => ['version' => $cash->logisticsRefundCashStatusVersion($request->user())],
            'message' => 'Refund cash custody status version loaded.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function shippingSettlements(
        Request $request,
        ShippingSettlementService $settlements,
        BuyerCatalogService $catalog,
        RiderPayoutBatchService $payoutBatches,
    ): View {
        $earningsStatusVersion = $settlements->riderEarningStatusVersionForLogistics($request->user());
        $earningsSummary = $settlements->logisticsEarningsSummary($request->user(), materialize: false);
        $earnings = $settlements->riderEarningsForLogistics($request->user(), materialize: false)
            ->map(function (RiderEarning $earning) use ($catalog): RiderEarning {
                $settlement = $earning->shippingSettlement;
                $isReturn = $settlement?->settlement_type === 'return';
                $itemEntries = $isReturn
                    ? ($settlement->returnRequest?->items ?? collect())
                        ->map(fn (ReturnItem $returnItem): array => [
                            'item' => $returnItem->orderItem,
                            'quantity' => (int) $returnItem->quantity,
                        ])
                    : ($earning->shipment?->sellerOrder?->items ?? collect())
                        ->map(fn (OrderItem $item): array => [
                            'item' => $item,
                            'quantity' => (int) $item->quantity,
                        ]);
                $itemEntries = $itemEntries
                    ->filter(fn (array $entry): bool => $entry['item'] instanceof OrderItem)
                    ->values();

                $earning->setAttribute('product_count', $itemEntries->count());
                $earning->setAttribute('product_previews', $itemEntries
                    ->take(3)
                    ->map(fn (array $entry): array => [
                        'name' => trim((string) $entry['item']->product_name) ?: 'Purchased item',
                        'variant' => trim((string) $entry['item']->variant_name),
                        'quantity' => (int) $entry['quantity'],
                        'image_url' => $catalog->orderItemImageUrl($entry['item']),
                    ])
                    ->all());

                return $earning;
            });

        return view('logistics.cash.shipping-settlements', $this->layoutData($request) + [
            'earnings' => $earnings,
            'originRemittances' => $settlements->originRemittancesForLogistics($request->user()),
            'earningsSummary' => $earningsSummary,
            'earningsStatusVersion' => $earningsStatusVersion,
            'payoutCandidates' => $payoutBatches->eligibleEarningsByRider($request->user()),
            'heldPickupEarnings' => $payoutBatches->heldPickupEarningsFor($request->user()),
            'payoutBatches' => $payoutBatches->batchesFor($request->user()),
            'pickupRates' => app(RiderPickupEarningService::class)->rates(),
        ]);
    }

    public function shippingSettlementsStatusVersion(Request $request, ShippingSettlementService $settlements): JsonResponse
    {
        return response()->json([
            'data' => ['version' => $settlements->riderEarningStatusVersionForLogistics($request->user())],
            'message' => 'Logistics Rider earning status version loaded.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function recordRiderShippingPayout(
        Request $request,
        int $earning,
        ShippingSettlementService $settlements,
    ): RedirectResponse {
        $data = $request->validate([
            'earning_id' => ['required', 'integer'],
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'method' => ['required', 'in:bank_transfer,gcash,cash,other'],
            'reference' => ['nullable', 'required_unless:method,cash', 'string', 'max:150'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'payout_confirmed' => ['required', 'accepted'],
        ], [
            'actual_amount.regex' => 'Enter the actual amount with up to two decimal places.',
            'payout_confirmed.accepted' => 'Confirm that the Rider already received the payout.',
        ]);

        if ((int) $data['earning_id'] !== $earning) {
            throw ValidationException::withMessages(['earning_id' => 'The selected Rider earning changed. Refresh and try again.']);
        }

        $payout = $settlements->recordRiderPayout(
            $request->user(),
            $earning,
            $this->amountToMinor($data['actual_amount']),
            $data['method'],
            $data['method'] === 'cash' ? '' : trim((string) ($data['reference'] ?? '')),
            $data['paid_on'],
            trim($data['reason']),
            $request,
        );

        $successMessage = $payout->method === 'cash'
            ? "Cash paid to the Rider was recorded. Bearly reference: {$payout->reference}. Print the receipt from the settlement row."
            : "Rider payout {$payout->reference} was recorded. The Rider earning is now marked paid.";

        return redirect()
            ->route('logistics.settlements.index')
            ->with('success', $successMessage);
    }

    public function printRiderCashPayoutReceipt(
        Request $request,
        int $earning,
        ShippingSettlementService $settlements,
        AuditService $audit,
    ): Response {
        $payout = $settlements->cashRiderPayoutForLogistics($request->user(), $earning);
        $riderEarning = $payout->riderEarning;
        $settlement = $riderEarning?->shippingSettlement;
        $riderName = $riderEarning?->riderProfile?->user?->name ?? 'Rider';

        $audit->record(
            action: 'rider_earning.cash_receipt_printed',
            module: 'Logistics',
            subject: $payout,
            description: "Cash Rider payout receipt {$payout->reference} viewed for earning {$riderEarning?->id}.",
            newValues: ['cash_payout_reference' => $payout->reference],
            severity: 'info',
            request: $request,
        );

        return response()
            ->view('logistics.cash.rider-payout-receipt', $this->layoutData($request) + [
                'payout' => $payout,
                'riderEarning' => $riderEarning,
                'settlement' => $settlement,
                'riderName' => $riderName,
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function printRiderCashPayoutBatchReceipt(
        Request $request,
        int $batch,
        RiderPayoutBatchService $payoutBatches,
        AuditService $audit,
    ): Response {
        $batch = $payoutBatches->cashBatchForLogistics($request->user(), $batch);

        $audit->record(
            action: 'rider_payout_batch.cash_receipt_printed',
            module: 'Logistics',
            subject: $batch,
            description: "Cash Rider payout receipt {$batch->reference} viewed for batch {$batch->batch_no}.",
            newValues: [
                'batch_no' => $batch->batch_no,
                'cash_payout_reference' => $batch->reference,
                'earning_ids' => $batch->items->pluck('rider_earning_id')->map(fn ($id): int => (int) $id)->all(),
            ],
            severity: 'info',
            request: $request,
        );

        return response()
            ->view('logistics.cash.rider-payout-receipt', $this->layoutData($request) + [
                'batch' => $batch,
                'payout' => null,
                'riderEarning' => null,
                'settlement' => null,
                'riderName' => $batch->riderProfile?->user?->name ?? 'Rider',
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function receiveCod(Request $request, CodCashCollection $collection, CodCashCollectionService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
        ]);
        $collection = $cash->receiveAtLogistics(
            $request->user(),
            (int) $collection->id,
            $this->amountToMinor($data['actual_amount']),
        );

        return redirect()
            ->route('logistics.cash.index', ['cod_remittance' => $collection->id])
            ->with('success', "Cash received from the Rider. Rider-to-Logistics reference: {$collection->logistics_reference}.");
    }

    public function remitCod(Request $request, CodCashCollection $collection, CodCashCollectionService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^(?:\d{1,8}|\d{1,3},\d{3}|\d{1,2}(?:,\d{3}){2})(?:\.\d{1,2})?$/'],
        ]);
        $collection = $cash->remitToFinance(
            $request->user(),
            (int) $collection->id,
            $this->amountToMinor(str_replace(',', '', $data['actual_amount'])),
        );

        return redirect()
            ->route('logistics.cash.index')
            ->with('success', "Cash given to Finance has been recorded. Handoff reference: {$collection->finance_remittance_reference}.");
    }

    public function receiveRefundCash(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->receiveAtLogistics($request->user(), (int) $payout->id, $this->amountToMinor($data['actual_amount']), trim($data['reference']));

        return back()->with('success', "Refund cash {$payout->payout_no} was received by Logistics.");
    }

    public function assignRefundCash(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'rider_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $payout = $cash->assignRider($request->user(), (int) $payout->id, (int) $data['rider_user_id']);

        return back()->with('success', "Rider assigned to {$payout->payout_no}. Print acknowledgment {$payout->acknowledgment_reference} and give it to the Rider with the cash.");
    }

    public function prepareRefundAcknowledgmentSlip(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $payout = $cash->prepareAcknowledgmentForLogistics($request->user(), (int) $payout->id);

        return redirect()
            ->route('logistics.cash.refunds.acknowledgment-slip', $payout)
            ->with('success', "Acknowledgment {$payout->acknowledgment_reference} is ready to print or save as PDF.");
    }

    public function refundAcknowledgmentSlip(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): View
    {
        $payout = $cash->acknowledgmentSlipForLogistics($request->user(), (int) $payout->id);

        return view('logistics.cash.acknowledgment-slip', $this->layoutData($request) + ['payout' => $payout]);
    }

    public function confirmReturnedRefundCash(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->confirmReturnedCashAtLogistics($request->user(), (int) $payout->id, $this->amountToMinor($data['actual_amount']), trim($data['reference']));

        return back()->with('success', "Logistics confirmed receipt of the returned refund cash {$payout->payout_no}.");
    }

    public function verifyRefundReceipt(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'verification_note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $cash->verifyBuyerReceipt($request->user(), (int) $payout->id, trim($data['verification_note']));

        return back()->with('success', "The Buyer acknowledgment for {$payout->payout_no} is verified and awaiting Finance completion.");
    }

    public function downloadRefundAcknowledgment(
        Request $request,
        RefundCashPayout $payout,
        RefundCashPayoutService $cash,
        AuditService $audit,
    ): BinaryFileResponse {
        $verifiedPayout = $cash->acknowledgmentForLogistics($request->user(), (int) $payout->id);
        $audit->record(
            action: 'refund_cash.acknowledgment_downloaded',
            module: 'Logistics',
            subject: $verifiedPayout,
            description: "Signed Buyer acknowledgment downloaded for {$verifiedPayout->payout_no}.",
            newValues: ['original_name' => $verifiedPayout->acknowledgment_original_name],
            severity: 'info',
            request: $request,
        );

        return response()->download(
            Storage::disk('local')->path($verifiedPayout->acknowledgment_path),
            $this->safeDownloadName($verifiedPayout->acknowledgment_original_name ?: 'buyer-acknowledgment'),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function prepareRiderPayoutBatch(
        Request $request,
        RiderPayoutBatchService $payoutBatches,
    ): RedirectResponse {
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $profile = $request->user()->logisticsProfile()->first();

        abort_unless($profile, 404);

        $rider = \App\Models\RiderProfile::query()
            ->whereKey($data['rider_profile_id'])
            ->where('logistics_profile_id', $profile->id)
            ->firstOrFail();

        $batch = $payoutBatches->createBatch(
            $request->user(),
            $rider,
            $data['notes'] ?? null,
            $request,
        );

        return redirect()
            ->route('logistics.settlements.index')
            ->with('success', "Payout batch {$batch->batch_no} is ready for review.");
    }

    public function approveRiderPayoutBatch(
        Request $request,
        int $batch,
        RiderPayoutBatchService $payoutBatches,
    ): RedirectResponse {
        $approved = $payoutBatches->approveBatch($request->user(), $batch, $request);

        return redirect()
            ->route('logistics.settlements.index')
            ->with('success', "Payout batch {$approved->batch_no} was approved. Record the external payment after paying the Rider.");
    }

    public function recordRiderPayoutBatchPayment(
        Request $request,
        int $batch,
        RiderPayoutBatchService $payoutBatches,
    ): RedirectResponse {
        $data = $request->validate([
            'method' => ['required', 'in:bank_transfer,gcash,cash,other'],
            'reference' => ['nullable', 'required_unless:method,cash', 'string', 'max:150'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'payout_confirmed' => ['required', 'accepted'],
        ], [
            'payout_confirmed.accepted' => 'Confirm that the Rider already received the external payout.',
        ]);

        $paid = $payoutBatches->recordPayment(
            $request->user(),
            $batch,
            $data['method'],
            $data['method'] === 'cash' ? '' : trim((string) ($data['reference'] ?? '')),
            $data['paid_on'],
            trim($data['reason']),
            $request,
        );

        return redirect()
            ->route('logistics.settlements.index')
            ->with('success', "Payout batch {$paid->batch_no} was recorded as paid.");
    }

    public function classifyPickupEarning(
        Request $request,
        int $earning,
        RiderPickupEarningService $pickupEarnings,
    ): RedirectResponse {
        $data = $request->validate([
            'size_class' => ['required', 'string', 'max:20'],
        ]);

        $profile = $request->user()->logisticsProfile()->first();

        abort_unless($profile, 404);

        $record = RiderEarning::query()
            ->whereKey($earning)
            ->where('logistics_profile_id', $profile->id)
            ->firstOrFail();

        $pickupEarnings->classifyHeldEarning(
            $record,
            $data['size_class'],
            $request->user(),
        );

        return redirect()
            ->route('logistics.settlements.index')
            ->with('success', 'The held pickup earning was classified and is now payout-eligible.');
    }

    private function amountToMinor(string $amount): int
    {
        if (! preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount with up to two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
    }

    private function safeDownloadName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'buyer-acknowledgment';

        return Str::limit($safe, 200, '');
    }

    /** @return array<string, mixed> */
    private function layoutData(Request $request): array
    {
        $user = $request->user();
        $profile = $user->logisticsProfile()->with('sortingCenters')->first();
        $facility = $profile?->sortingCenters->firstWhere('status', 'active');
        $name = $user->name ?: 'Bearly Logistics';
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
        $pendingRiderCount = $profile
            ? AccountApplication::query()
                ->where('sponsor_logistics_profile_id', $profile->id)
                ->whereHas('requestedRole', fn ($query) => $query->where('name', UserRole::Rider->value))
                ->whereIn('status', ['submitted', 'under_review', 'needs_revision'])
                ->count()
            : 0;

        return [
            'operator' => [
                'name' => $name,
                'initials' => $initials ?: 'BL',
                'email' => $user->email ?: '',
                'role' => 'Logistics Operator',
                'business_name' => $facility?->name ?: ($profile?->display_name ?: $name),
            ],
            'pendingRiderCount' => $pendingRiderCount,
            'topNotifications' => [],
        ];
    }
}
