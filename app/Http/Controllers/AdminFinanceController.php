<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CodCashCollection;
use App\Models\PlatformCommission;
use App\Models\Refund;
use App\Models\RefundCashPayout;
use App\Models\SellerPayout;
use App\Models\SellerTransaction;
use App\Models\ShippingSettlement;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\BuyerCatalogService;
use App\Services\CodCashCollectionService;
use App\Services\RefundCashPayoutService;
use App\Services\SellerEarningsService;
use App\Services\SellerPayoutService;
use App\Services\ShippingSettlementService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminFinanceController extends Controller
{
    public function commissions(Request $request, BuyerCatalogService $catalog): View
    {
        $rows = PlatformCommission::query()
            ->with([
                'sellerOrder.order',
                'sellerOrder.store',
                'sellerOrder.items.product.images.variant',
                'sellerOrder.items.variant',
                'refund',
            ])
            ->latest('calculated_at')
            ->get();

        $ledger = $rows
            ->map(function (PlatformCommission $commission) use ($catalog): array {
                $grossMinor = $this->recognizedCommissionBaseMinor($commission);
                $commissionMinor = $this->recognizedCommissionMinor($commission);

                return [
                    'date' => ($commission->finalized_at ?? $commission->calculated_at ?? $commission->created_at)->format('M j, Y'),
                    'order' => $commission->sellerOrder?->order?->order_no ?? $commission->sellerOrder?->seller_order_no ?? '—',
                    'seller' => $commission->sellerOrder?->store?->name ?? '—',
                    'items' => ($commission->sellerOrder?->items ?? collect())
                        ->map(fn ($item): array => [
                            'name' => $item->product_name ?: 'Unnamed product',
                            'variant' => trim((string) $item->variant_name),
                            'quantity' => (int) $item->quantity,
                            'image_url' => $catalog->orderItemImageUrl($item),
                        ])
                        ->values()
                        ->all(),
                    'gross' => $grossMinor / 100,
                    'commission' => $commissionMinor / 100,
                    'sellerNet' => ($grossMinor - $commissionMinor) / 100,
                ];
            })
            ->filter(fn (array $row): bool => $row['gross'] !== 0.0 || $row['commission'] !== 0.0)
            ->values();

        $rate = $this->commissionRate();
        $commissionSummary = [
            'gross' => $ledger->sum('gross'),
            'commission' => $ledger->sum('commission'),
            'sellerNet' => $ledger->sum('sellerNet'),
        ];

        $ledgerDates = $rows->map(fn (PlatformCommission $row) => $row->finalized_at ?? $row->calculated_at ?? $row->created_at);
        $dateRangeStart = $ledgerDates->min()?->format('Y-m-d') ?? now()->startOfYear()->format('Y-m-d');
        $dateRangeEnd = $ledgerDates->max()?->format('Y-m-d') ?? now()->format('Y-m-d');

        return view('admin.finance.commissions', $this->base($request) + compact(
            'ledger',
            'rate',
            'commissionSummary',
            'dateRangeStart',
            'dateRangeEnd',
        ));
    }

    public function transactions(Request $request, BuyerCatalogService $catalog): View
    {
        $rows = SellerTransaction::query()
            ->with([
                'sellerProfile.store',
                'sellerOrder.order.buyer',
                'sellerOrder.store',
                'sellerOrder.items.product.images.variant',
                'sellerOrder.items.variant',
                'sellerOrder.order.promotionRedemptions.allocations',
                'payment',
                'refund',
                'commission.refund',
            ])
            ->latest()
            ->get();

        $transactions = $rows->map(function (SellerTransaction $transaction) use ($catalog): array {
            $isRefund = $transaction->refund_id !== null
                || str_contains(strtolower($transaction->type), 'refund')
                || str_contains(strtolower($transaction->type), 'reversal');
            $status = $this->transactionDisplayStatus($transaction, $isRefund);
            $isRecognized = in_array($status, ['Completed', 'Refunded'], true);
            $commissionMinor = $isRecognized && $transaction->commission
                ? $this->recognizedCommissionMinor($transaction->commission)
                : 0;
            $grossMinor = $isRefund
                ? 0
                : ($transaction->commission?->base_amount_minor ?? $transaction->sellerOrder?->total_minor ?? 0);

            return [
                'id' => $transaction->transaction_no,
                'date' => ($transaction->posted_at ?? $transaction->created_at)->format('Y-m-d'),
                'order' => $transaction->sellerOrder?->order?->order_no ?? '—',
                'seller' => $transaction->sellerOrder?->store?->name
                    ?? $transaction->sellerProfile?->store?->name
                    ?? $transaction->sellerProfile?->legal_business_name
                    ?? '—',
                'buyer' => $transaction->sellerOrder?->order?->buyer?->name ?? '—',
                'items' => $transaction->sellerOrder?->items
                    ?->map(fn ($item): array => [
                        'name' => $item->product_name ?: 'Unnamed product',
                        'variant' => trim((string) $item->variant_name),
                        'quantity' => (int) $item->quantity,
                        'image_url' => $catalog->orderItemImageUrl($item),
                    ])
                    ->values()
                    ->all() ?? [],
                'platform_promotions' => ($transaction->sellerOrder?->order?->promotionRedemptions ?? collect())
                    ->filter(fn ($redemption): bool => $redemption->platform_promotion_id !== null)
                    ->map(function ($redemption) use ($transaction): array {
                        $allocations = $redemption->allocations
                            ->where('seller_order_id', $transaction->seller_order_id);
                        $discountMinor = (int) $allocations
                            ->whereNotNull('order_item_id')
                            ->sum(fn ($allocation): int => max(0, (int) $allocation->amount_minor - (int) $allocation->released_amount_minor));
                        $shippingDiscountMinor = (int) $allocations
                            ->whereNull('order_item_id')
                            ->sum(fn ($allocation): int => max(0, (int) $allocation->amount_minor - (int) $allocation->released_amount_minor));
                        $releasedDiscountMinor = (int) $allocations
                            ->whereNotNull('order_item_id')
                            ->sum('released_amount_minor');
                        $releasedShippingMinor = (int) $allocations
                            ->whereNull('order_item_id')
                            ->sum('released_amount_minor');

                        return [
                            'name' => (string) $redemption->name,
                            'kind' => (string) $redemption->kind,
                            'status' => (string) $redemption->status,
                            'discount_minor' => $discountMinor,
                            'shipping_discount_minor' => $shippingDiscountMinor,
                            'released_discount_minor' => $releasedDiscountMinor,
                            'released_shipping_discount_minor' => $releasedShippingMinor,
                        ];
                    })
                    ->filter(fn (array $promotion): bool => $promotion['discount_minor'] > 0
                        || $promotion['shipping_discount_minor'] > 0
                        || $promotion['released_discount_minor'] > 0
                        || $promotion['released_shipping_discount_minor'] > 0)
                    ->values()
                    ->all(),
                'method' => str($transaction->refund?->method ?? $transaction->payment?->method ?? 'system')->replace('_', ' ')->title()->toString(),
                'gross' => $grossMinor / 100,
                'refund' => $status === 'Refunded' ? abs((int) ($transaction->refund?->amount_minor ?? 0)) / 100 : 0,
                'status' => $status,
                'commission' => $commissionMinor / 100,
                'sellerNet' => $isRecognized ? $transaction->amount_minor / 100 : 0,
            ];
        })->values();

        $rate = $this->commissionRate();
        $dateRangeStart = $rows->min(fn (SellerTransaction $row) => $row->posted_at ?? $row->created_at)?->format('Y-m-d')
            ?? now()->startOfYear()->format('Y-m-d');
        $dateRangeEnd = $rows->max(fn (SellerTransaction $row) => $row->posted_at ?? $row->created_at)?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        return view('admin.finance.transactions', $this->base($request) + compact(
            'transactions',
            'rate',
            'dateRangeStart',
            'dateRangeEnd',
        ));
    }

    public function payments(
        Request $request,
        CodCashCollectionService $codCash,
        RefundCashPayoutService $refundCash,
        BuyerCatalogService $catalog,
    ): View {
        $payments = SellerPayout::query()
            ->with(['sellerProfile.store', 'items'])
            ->latest('period_end')
            ->get()
            ->map(fn (SellerPayout $payout): array => $this->mapPayout($payout))
            ->values();

        $codCollections = $codCash->codCollectionsForFinance()
            ->each(function (CodCashCollection $collection) use ($catalog): void {
                $collection->setAttribute(
                    'order_item_previews',
                    ($collection->sellerOrder?->items ?? collect())
                        ->map(fn ($item): array => [
                            'name' => $item->product_name ?: 'Unnamed product',
                            'variant' => trim((string) $item->variant_name),
                            'quantity' => (int) $item->quantity,
                            'image_url' => $catalog->orderItemImageUrl($item),
                        ])
                        ->values()
                        ->all(),
                );
            });
        $refundsReadyForCashDispatch = $refundCash->refundsReadyForCashDispatch();
        $refundCashPayouts = $refundCash->refundPayoutsForFinance();
        $codCashVersion = $codCash->statusVersion($codCollections);

        return view('admin.finance.payments', $this->base($request) + compact(
            'payments',
            'codCollections',
            'codCashVersion',
            'refundsReadyForCashDispatch',
            'refundCashPayouts',
        ));
    }

    public function codCashStatusVersion(CodCashCollectionService $codCash): JsonResponse
    {
        return response()->json([
            'data' => ['version' => $codCash->statusVersion()],
            'message' => 'COD cash status version loaded.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function shippingSettlements(
        Request $request,
        ShippingSettlementService $shippingSettlementService,
    ): View {
        return view('admin.finance.shipping-settlements', $this->base($request) + [
            'shippingSettlements' => $shippingSettlementService->financeSettlements(),
        ]);
    }

    public function reconcileCodCash(
        Request $request,
        CodCashCollection $collection,
        CodCashCollectionService $codCash,
    ): RedirectResponse {
        $data = $request->validate([
            'received_amount' => ['required', 'string', 'regex:/^(?:\d{1,8}|\d{1,3},\d{3}|\d{1,2}(?:,\d{3}){2})(?:\.\d{1,2})?$/'],
            'cash_received_confirmed' => ['required', 'accepted'],
        ], [
            'received_amount.regex' => 'Enter an amount with valid thousands separators and up to two decimal places.',
            'cash_received_confirmed.accepted' => 'Confirm Finance physically received and counted this cash before reconciling.',
        ]);
        $reconciled = $codCash->reconcileAtFinance(
            $request->user(),
            (int) $collection->id,
            $this->amountToMinor(str_replace(',', '', $data['received_amount'])),
        );

        return back()->with('success', "COD cash {$reconciled->collection_no} was reconciled. Bearly Finance receipt {$reconciled->finance_reference} is ready to print.");
    }

    public function recordShippingSettlement(
        Request $request,
        ShippingSettlement $settlement,
        ShippingSettlementService $shippingSettlements,
    ): RedirectResponse {
        $data = $request->validate([
            'settlement_id' => ['required', 'integer'],
            'remittance_confirmed' => ['required', 'accepted'],
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(['bank_transfer', 'gcash', 'cash', 'other'])],
            'reference' => ['required', 'string', 'max:150'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'remittance_confirmed.accepted' => 'Confirm that Finance has already sent the exact shipping allocation to Logistics.',
            'actual_amount.regex' => 'Enter the actual amount with up to two decimal places.',
        ]);

        if ((int) $data['settlement_id'] !== (int) $settlement->id) {
            throw ValidationException::withMessages(['settlement_id' => 'The selected shipping settlement changed. Refresh and try again.']);
        }

        $recorded = $shippingSettlements->recordLogisticsRemittance(
            $request->user(),
            (int) $settlement->id,
            $this->amountToMinor($data['actual_amount']),
            $data['method'],
            trim($data['reference']),
            $data['paid_on'],
            trim($data['reason']),
            $request,
        );

        return back()->with('success', "Shipping allocation {$recorded->settlement_no} was recorded as remitted to Logistics.");
    }

    public function printCodCashReceipt(Request $request, CodCashCollection $collection, AuditService $audit): Response
    {
        abort_unless($collection->status === 'reconciled'
            && filled($collection->finance_reference)
            && $collection->reconciled_at !== null, 404);

        $collection->load([
            'order',
            'sellerOrder.store',
            'logisticsProfile',
            'riderProfile.user',
            'financeReconciler',
        ]);
        $audit->record(
            action: 'cod_cash.finance_receipt_viewed',
            module: 'Finance',
            subject: $collection,
            description: "Bearly Finance cash receipt {$collection->finance_reference} viewed for {$collection->collection_no}.",
            newValues: ['finance_reference' => $collection->finance_reference],
            severity: 'info',
            request: $request,
        );

        return response()
            ->view('admin.finance.cod-cash-receipt', compact('collection'))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function generateSellerPayout(Request $request, SellerPayoutService $sellerPayouts): RedirectResponse
    {
        $data = $request->validate([
            'seller_profile_id' => ['required', 'integer', 'exists:seller_profiles,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $payout = $sellerPayouts->generateForSeller($request->user(), (int) $data['seller_profile_id'], trim($data['reason']), $request);

        return back()->with('success', "Seller settlement {$payout->payout_no} was generated for ₱".number_format((int) $payout->net_minor / 100, 2).'. No payout was sent.');
    }

    public function recordSellerPayout(Request $request, SellerPayout $payout, SellerPayoutService $sellerPayouts): RedirectResponse
    {
        $data = $request->validate([
            'seller_payout_confirmed' => ['required', 'accepted'],
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(['bank_transfer'])],
            'receiving_bank_name' => ['required', 'string', 'max:120'],
            'reference' => ['required', 'string', 'max:150'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'seller_payout_confirmed.required' => 'Confirm that the Seller has already received the full payout.',
            'seller_payout_confirmed.accepted' => 'Confirm that the Seller has already received the full payout.',
            'reference.required' => 'Enter the actual bank-transfer reference.',
            'receipt.required' => 'Upload proof of the completed bank transfer.',
        ]);
        /** @var UploadedFile $receipt */
        $receipt = $data['receipt'];
        $recorded = $sellerPayouts->markPaid(
            $request->user(),
            (int) $payout->id,
            $this->amountToMinor($data['actual_amount']),
            $data['method'],
            trim($data['receiving_bank_name']),
            trim((string) ($data['reference'] ?? '')),
            $data['paid_on'],
            trim($data['reason']),
            $receipt,
            $request,
        );

        return back()->with('success', "{$recorded->payout_no} is recorded as paid after Finance reviewed the bank-transfer proof. Bearly did not send the transfer.");
    }

    public function printSellerCashAcknowledgment(): Response
    {
        abort(404, 'Direct cash acknowledgments are no longer part of Seller Wallet withdrawals.');
    }

    public function issueRefundCash(
        Request $request,
        Refund $refund,
        RefundCashPayoutService $refundCash,
    ): RedirectResponse {
        $request->validate([
            'cash_issued_confirmed' => ['required', 'accepted'],
        ], [
            'cash_issued_confirmed.accepted' => 'Confirm that Finance has physically handed the refund cash to Logistics.',
        ]);
        $payout = $refundCash->issueToLogistics($request->user(), (int) $refund->id, $request);

        return back()->with('success', "Cash handoff recorded. Reference: {$payout->finance_issue_reference}. Refund cash {$payout->payout_no} is awaiting Logistics receipt; Bearly did not move cash.");
    }

    public function completeRefundCash(
        Request $request,
        RefundCashPayout $payout,
        RefundCashPayoutService $refundCash,
    ): RedirectResponse {
        $data = $request->validate([
            'buyer_receipt_verified' => ['required', 'accepted'],
            'completion_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'buyer_receipt_verified.accepted' => 'Confirm that the Logistics-verified Buyer acknowledgment is genuine and matches the recorded refund.',
        ]);
        $completed = $refundCash->completeAtFinance($request->user(), (int) $payout->id, trim($data['completion_reason']), $request);

        return back()->with('success', "Refund {$completed->refund?->refund_no} was completed after Buyer receipt verification.");
    }

    public function downloadRefundCashAcknowledgment(Request $request, RefundCashPayout $payout, AuditService $audit): BinaryFileResponse
    {
        abort_unless($payout->status === 'awaiting_finance_completion'
            && $payout->acknowledgment_disk === 'local'
            && filled($payout->acknowledgment_path)
            && Storage::disk('local')->exists($payout->acknowledgment_path), 404);
        $audit->record(
            action: 'refund_cash.acknowledgment_downloaded',
            module: 'Finance',
            subject: $payout,
            description: "Signed Buyer acknowledgment reviewed by Finance for {$payout->payout_no}.",
            newValues: ['original_name' => $payout->acknowledgment_original_name],
            severity: 'info',
            request: $request,
        );

        return response()->download(
            Storage::disk('local')->path($payout->acknowledgment_path),
            $this->safeDownloadName($payout->acknowledgment_original_name ?: 'buyer-acknowledgment'),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function downloadSellerPayoutReceipt(Request $request, SellerPayout $payout, AuditService $audit): BinaryFileResponse
    {
        abort_unless($payout->status === 'paid'
            && $payout->receipt_disk === 'local'
            && filled($payout->receipt_path)
            && Storage::disk('local')->exists($payout->receipt_path), 404);

        $audit->record(
            action: 'seller_payout.receipt_downloaded',
            module: 'Finance',
            subject: $payout,
            description: "Receipt downloaded for Seller payout {$payout->payout_no}.",
            newValues: ['receipt_original_name' => $payout->receipt_original_name],
            severity: 'info',
            request: $request,
        );

        return response()->download(
            Storage::disk('local')->path($payout->receipt_path),
            $this->safeDownloadName($payout->receipt_original_name ?: 'seller-payout-receipt'),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /** Report types selectable from the Admin Reports page. */
    private const REPORT_TYPES = [
        'sales_summary' => 'Sales Summary',
        'sales_seller' => 'Sales by Seller',
        'sales_category' => 'Sales by Category',
        'commission' => 'Commission Report',
        'settlement' => 'Seller Settlement Report',
        'refund' => 'Refund Report',
    ];

    public function reports(Request $request, SellerEarningsService $earnings): View
    {
        return view('admin.finance.reports', $this->base($request) + $this->reportData($request, $earnings));
    }

    /**
     * CSV download for the currently selected report type and filters. Uses
     * the exact dataset the page renders; nothing is re-derived from the DOM.
     */
    public function exportReports(Request $request, SellerEarningsService $earnings): StreamedResponse
    {
        $report = $this->reportData($request, $earnings);
        $filename = 'bearly-'.$report['reportKey'].'-'.$report['dateRange']['from'].'-to-'.$report['dateRange']['to'].'.csv';

        return $this->csvDownload($filename, function ($stream) use ($report): void {
            $write = function (array $cells) use ($stream): void {
                fputcsv($stream, $cells, ',', '"', '\\');
            };

            $write(['Bearly Admin Financial Report']);
            $write(['Report type', $report['reportLabel']]);
            $write(['Report period', $report['dateRange']['label']]);
            $write(['Basis', $report['basisNote']]);

            if ($report['filterLabel'] !== null) {
                $write(['Filter', $report['filterLabel']]);
            }

            $write([]);
            $write(['Summary metrics']);
            $write(['Metric', 'Value', 'Note']);
            foreach ($report['reportKpis'] as $metric) {
                $write([$metric['label'], $metric['value'], $metric['note']]);
            }

            $write([]);
            $write(['Report details']);
            $write($report['exportColumns']);
            foreach ($report['exportRows'] as $cells) {
                $write($cells);
            }
        });
    }

    /**
     * Build the validated report payload shared by the page and the export.
     *
     * @return array<string, mixed>
     */
    private function reportData(Request $request, SellerEarningsService $earnings): array
    {
        $reportType = (string) $request->query('type', 'sales_summary');

        if (! array_key_exists($reportType, self::REPORT_TYPES)) {
            throw ValidationException::withMessages(['type' => 'Choose a valid report type.']);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'seller' => ['nullable', 'integer', Rule::exists('seller_profiles', 'id')],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ]);

        $fromInput = $validated['from'] ?? now()->startOfYear()->format('Y-m-d');
        $toInput = $validated['to'] ?? now()->format('Y-m-d');

        if ($fromInput > $toInput) {
            throw ValidationException::withMessages([
                'to' => 'The end date must be on or after the start date.',
            ]);
        }

        $range = $earnings->reportRange($fromInput, $toInput);
        $selectedSeller = $reportType === 'sales_seller' && isset($validated['seller']) ? (int) $validated['seller'] : null;
        $selectedCategory = $reportType === 'sales_category' && isset($validated['category']) ? (int) $validated['category'] : null;

        $report = [
            'reportKey' => $reportType,
            'reportLabel' => self::REPORT_TYPES[$reportType],
            'reportTypes' => self::REPORT_TYPES,
            'dateRange' => [
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'label' => $earnings->rangeLabel($range['from'], $range['to']),
            ],
            'selectedSeller' => $selectedSeller,
            'selectedCategory' => $selectedCategory,
            'filterLabel' => null,
            'sellerOptions' => $reportType === 'sales_seller'
                ? Store::query()
                    ->with('sellerProfile:id,legal_business_name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Store $store): array => [
                        'id' => (int) $store->seller_profile_id,
                        'label' => $store->name,
                    ])
                    ->unique('id')
                    ->values()
                    ->all()
                : [],
            'categoryOptions' => $reportType === 'sales_category'
                ? Category::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Category $category): array => [
                        'id' => (int) $category->id,
                        'label' => $category->name,
                    ])
                    ->all()
                : [],
        ];

        if ($selectedSeller !== null) {
            $sellerName = Store::query()->where('seller_profile_id', $selectedSeller)->value('name');
            $report['filterLabel'] = 'Seller: '.($sellerName ?? '#'.$selectedSeller);
        }

        if ($selectedCategory !== null) {
            $categoryName = Category::query()->whereKey($selectedCategory)->value('name');
            $report['filterLabel'] = 'Category: '.($categoryName ?? '#'.$selectedCategory);
        }

        $report += match ($reportType) {
            'sales_summary' => $this->salesSummaryData($earnings, $range['from'], $range['to']),
            'sales_seller' => $this->salesSellerData($earnings, $range['from'], $range['to'], $selectedSeller),
            'sales_category' => $this->salesCategoryData($earnings, $range['from'], $range['to'], $selectedCategory),
            'commission' => $this->commissionReportData($range['from'], $range['to']),
            'settlement' => $this->settlementReportData($range['from'], $range['to']),
            'refund' => $this->refundReportData($range['from'], $range['to']),
        };

        $report['exportUrl'] = route('admin.reports.export', array_filter([
            'type' => $reportType,
            'from' => $report['dateRange']['from'],
            'to' => $report['dateRange']['to'],
            'seller' => $selectedSeller,
            'category' => $selectedCategory,
        ], fn ($value): bool => $value !== null && $value !== ''));

        return $report;
    }

    /**
     * Sales Summary: marketplace KPIs, period trend, top categories, and the
     * supporting per-period table.
     *
     * @return array<string, mixed>
     */
    private function salesSummaryData(SellerEarningsService $earnings, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summary = $earnings->marketplaceSalesSummaryFor($from, $to);
        $totals = $summary['totals'];

        return [
            'basisNote' => 'Sales basis: completed seller orders by completion date. Product values use each order item’s checkout snapshot (shipping fees excluded) and refunds use their completion date — not the Finance commission ledger.',
            'reportKpis' => [
                ['label' => 'Gross Product Sales', 'value' => $this->money((int) $totals['gross_minor']), 'note' => 'Completed seller orders, shipping excluded'],
                ['label' => 'Net Sales', 'value' => $this->money((int) $totals['net_minor']), 'note' => 'After item discounts and completed refunds'],
                ['label' => 'Completed Seller Orders', 'value' => number_format((int) $totals['seller_orders']), 'note' => 'One checkout may split across sellers'],
                ['label' => 'Units Sold', 'value' => number_format((int) $totals['units']), 'note' => 'Items in completed seller orders'],
            ],
            'chartHasData' => (int) $totals['gross_minor'] > 0,
            'salesChart' => collect($summary['trend'])->map(fn (array $bucket): array => [
                'label' => $bucket['label'],
                'axis' => CarbonImmutable::parse($bucket['start'])->format('M j'),
                'net_minor' => (int) $bucket['net_minor'],
            ])->values()->all(),
            'topCategories' => collect($summary['top_categories'])->map(fn (array $row): array => [
                'label' => $row['label'],
                'gross' => $this->money((int) $row['gross_minor']),
                'share' => (float) $row['share'],
            ])->values()->all(),
            'summaryRows' => collect($summary['trend'])->map(fn (array $bucket): array => [
                'label' => $bucket['label'],
                'orders' => number_format((int) $bucket['seller_orders']),
                'gross' => $this->money((int) $bucket['gross_minor']),
                'discounts' => $this->money((int) $bucket['discounts_minor']),
                'refunds' => $this->money((int) $bucket['refunds_minor']),
                'net' => $this->money((int) $bucket['net_minor']),
                'units' => number_format((int) $bucket['units']),
            ])->all(),
            'exportColumns' => ['Period', 'Seller orders', 'Gross product sales', 'Discounts', 'Completed refunds', 'Net sales', 'Units'],
            'exportRows' => collect($summary['trend'])->map(fn (array $bucket): array => [
                $bucket['start'].' to '.$bucket['end'],
                (string) $bucket['seller_orders'],
                $this->decimal((int) $bucket['gross_minor']),
                $this->decimal((int) $bucket['discounts_minor']),
                $this->decimal((int) $bucket['refunds_minor']),
                $this->decimal((int) $bucket['net_minor']),
                (string) $bucket['units'],
            ])->all(),
        ];
    }

    /**
     * Sales by Seller: one row per Store (one Store per Seller profile).
     *
     * @return array<string, mixed>
     */
    private function salesSellerData(
        SellerEarningsService $earnings,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $sellerProfileId,
    ): array {
        $data = $earnings->marketplaceSalesBySellerFor($from, $to, $sellerProfileId);
        $totals = $data['totals'];

        return [
            'basisNote' => 'Sales basis: completed seller orders by completion date, grouped by Store (one Store per Seller). Refunds are attributed to the Store on their completed return case.',
            'reportKpis' => [
                ['label' => 'Gross Product Sales', 'value' => $this->money((int) $totals['gross_minor']), 'note' => 'Completed seller orders, shipping excluded'],
                ['label' => 'Net Sales', 'value' => $this->money((int) $totals['net_minor']), 'note' => 'After item discounts and completed refunds'],
                ['label' => 'Completed Seller Orders', 'value' => number_format((int) $totals['seller_orders']), 'note' => 'One checkout may split across sellers'],
                ['label' => 'Units Sold', 'value' => number_format((int) $totals['units']), 'note' => 'Items in completed seller orders'],
            ],
            'sellerRows' => collect($data['rows'])->map(function (array $row): array {
                $legal = $row['legal'];

                return [
                    'name' => $row['seller'],
                    'meta' => $legal !== null && $legal !== '' && $legal !== $row['seller'] ? $legal : 'Seller store',
                    'gross' => $this->money((int) $row['gross_minor']),
                    'discounts' => $this->money((int) $row['discounts_minor']),
                    'refunds' => $this->money((int) $row['refunds_minor']),
                    'net' => $this->money((int) $row['net_minor']),
                    'orders' => number_format((int) $row['orders']),
                    'units' => number_format((int) $row['units']),
                    'share' => (float) $row['share'],
                ];
            })->all(),
            'exportColumns' => ['Seller', 'Gross product sales', 'Discounts', 'Completed refunds', 'Net sales', 'Seller orders', 'Units', 'Share of gross %'],
            'exportRows' => collect($data['rows'])->map(fn (array $row): array => [
                $row['seller'],
                $this->decimal((int) $row['gross_minor']),
                $this->decimal((int) $row['discounts_minor']),
                $this->decimal((int) $row['refunds_minor']),
                $this->decimal((int) $row['net_minor']),
                (string) $row['orders'],
                (string) $row['units'],
                number_format((float) $row['share'], 1),
            ])->all(),
        ];
    }

    /**
     * Sales by Category: one row per purchase-time category snapshot.
     *
     * @return array<string, mixed>
     */
    private function salesCategoryData(
        SellerEarningsService $earnings,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $categoryId,
    ): array {
        $data = $earnings->marketplaceSalesByCategoryFor($from, $to, $categoryId);
        $totals = $data['totals'];

        return [
            'basisNote' => 'Sales basis: completed seller orders by completion date. Categories come from the marketplace category captured on each order item at checkout; older items without a snapshot are reported as “Category not recorded”, never from the product’s current category.',
            'reportKpis' => [
                ['label' => 'Gross Product Sales', 'value' => $this->money((int) $totals['gross_minor']), 'note' => 'Completed seller orders, shipping excluded'],
                ['label' => 'Net Sales', 'value' => $this->money((int) $totals['net_minor']), 'note' => 'After item discounts and completed refunds'],
                ['label' => 'Orders', 'value' => number_format((int) $totals['orders']), 'note' => 'Distinct marketplace orders in this dataset'],
                ['label' => 'Units Sold', 'value' => number_format((int) $totals['units']), 'note' => 'Items in completed seller orders'],
            ],
            'categoryRows' => collect($data['rows'])->map(fn (array $row): array => [
                'label' => $row['label'],
                'recorded' => $row['category_id'] !== null,
                'gross' => $this->money((int) $row['gross_minor']),
                'discounts' => $this->money((int) $row['discounts_minor']),
                'refunds' => $this->money((int) $row['refunds_minor']),
                'net' => $this->money((int) $row['net_minor']),
                'orders' => number_format((int) $row['orders']),
                'units' => number_format((int) $row['units']),
                'share' => (float) $row['share'],
            ])->all(),
            'exportColumns' => ['Category', 'Gross product sales', 'Discounts', 'Completed refunds (item-attributed)', 'Net sales', 'Orders', 'Units', 'Share of gross %'],
            'exportRows' => collect($data['rows'])->map(fn (array $row): array => [
                $row['label'],
                $this->decimal((int) $row['gross_minor']),
                $this->decimal((int) $row['discounts_minor']),
                $this->decimal((int) $row['refunds_minor']),
                $this->decimal((int) $row['net_minor']),
                (string) $row['orders'],
                (string) $row['units'],
                number_format((float) $row['share'], 1),
            ])->all(),
        ];
    }

    /**
     * Commission Report: Finance commission ledger rows finalized in the
     * selected period (recognized sales and completed reversals).
     *
     * @return array<string, mixed>
     */
    private function commissionReportData(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $commissions = PlatformCommission::query()
            ->with(['sellerOrder.store', 'refund'])
            ->whereNotNull('finalized_at')
            ->whereBetween('finalized_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        $commissionableMinor = (int) $commissions->sum(fn (PlatformCommission $row): int => $this->recognizedCommissionBaseMinor($row));
        $commissionMinor = (int) $commissions->sum(fn (PlatformCommission $row): int => $this->recognizedCommissionMinor($row));
        $topSellers = $this->topSellers($commissions);

        return [
            'basisNote' => 'Basis: commission ledger rows finalized in the selected period, including refund reversals once the refund is completed. Commissionable totals come from the Finance ledger — this is not the Sales by Seller report.',
            'reportKpis' => [
                ['label' => 'Commissionable Sales', 'value' => $this->money($commissionableMinor), 'note' => 'Recognized commission bases'],
                ['label' => 'Platform Commission', 'value' => $this->money($commissionMinor), 'note' => $this->commissionRate().'% configured rate after reversal'],
                ['label' => 'Sellers', 'value' => number_format($topSellers->count()), 'note' => 'Stores with recognized entries'],
                ['label' => 'Finalized Entries', 'value' => number_format($commissions->count()), 'note' => 'Commission and reversal rows'],
            ],
            'topSellers' => $topSellers,
            'exportColumns' => ['Seller', 'Commissionable sales', 'Platform commission', 'Share of commissionable %'],
            'exportRows' => $topSellers->map(fn (array $row): array => [
                $row['seller'],
                $this->decimal((int) $row['sales_minor']),
                $this->decimal((int) $row['commission_minor']),
                number_format((float) $row['share'], 1),
            ])->values()->all(),
        ];
    }

    /**
     * Seller Settlement Report: payouts whose settlement period overlaps the
     * selected dates.
     *
     * @return array<string, mixed>
     */
    private function settlementReportData(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $payouts = SellerPayout::query()
            ->with('sellerProfile.store')
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->orderBy('period_end')
            ->orderBy('id')
            ->get();

        $paidCount = $payouts->filter(fn (SellerPayout $payout): bool => $this->payoutDisplayStatus($payout) === 'Paid')->count();

        return [
            'basisNote' => 'Basis: seller payout periods overlapping the selected dates. Net payouts are the recorded settlement totals for those periods.',
            'reportKpis' => [
                ['label' => 'Payouts', 'value' => number_format($payouts->count()), 'note' => 'Settlement periods in range'],
                ['label' => 'Net Payout', 'value' => $this->money((int) $payouts->sum('net_minor')), 'note' => 'Recorded net settlement total'],
                ['label' => 'Paid', 'value' => number_format($paidCount), 'note' => 'Payments already recorded'],
                ['label' => 'Not Yet Paid', 'value' => number_format($payouts->count() - $paidCount), 'note' => 'Pending, processing, or on hold'],
            ],
            'settlementRows' => $payouts->map(fn (SellerPayout $payout): array => [
                'seller' => $this->payoutSellerName($payout),
                'period' => $this->period($payout->period_start, $payout->period_end),
                'net' => $this->money((int) $payout->net_minor),
                'status' => $this->payoutDisplayStatus($payout),
            ])->values()->all(),
            'exportColumns' => ['Seller', 'Period', 'Net payout', 'Status'],
            'exportRows' => $payouts->map(fn (SellerPayout $payout): array => [
                $this->payoutSellerName($payout),
                $this->period($payout->period_start, $payout->period_end),
                $this->decimal((int) $payout->net_minor),
                $this->payoutDisplayStatus($payout),
            ])->values()->all(),
        ];
    }

    /**
     * Refund Report: refund cases created in the selected period.
     *
     * @return array<string, mixed>
     */
    private function refundReportData(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $refunds = Refund::query()
            ->with(['order', 'returnRequest.store', 'sellerRecovery'])
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->latest('id')
            ->get();

        $completedCount = $refunds->filter(fn (Refund $refund): bool => $refund->isCompleted())->count();

        return [
            'basisNote' => 'Basis: refund cases created in the selected period. A case’s completion date may fall outside the selected dates.',
            'reportKpis' => [
                ['label' => 'Refund Cases', 'value' => number_format($refunds->count()), 'note' => 'Cases created in the period'],
                ['label' => 'Refund Amount', 'value' => $this->money((int) $refunds->sum(fn (Refund $refund): int => (int) ($refund->payout_amount_minor ?? $refund->amount_minor))), 'note' => 'Recorded payout amount per case'],
                ['label' => 'Completed', 'value' => number_format($completedCount), 'note' => 'Refunds completed'],
                ['label' => 'In Progress', 'value' => number_format($refunds->count() - $completedCount), 'note' => 'Awaiting approval, processing, or failed'],
            ],
            'refundRows' => $refunds->map(fn (Refund $refund): array => [
                'case' => $refund->refund_no,
                'order' => $refund->order?->order_no ?? '—',
                'seller' => $refund->returnRequest?->store?->name ?? '—',
                'amount' => $this->money((int) ($refund->payout_amount_minor ?? $refund->amount_minor)),
                'status' => str($refund->status)->replace('_', ' ')->title()->toString(),
                'responsible_party' => $refund->financially_responsible_party
                    ? str($refund->financially_responsible_party)->title()->toString()
                    : 'Not recorded',
                'seller_recovery' => $refund->sellerRecovery
                    ? $this->money(max(0, (int) $refund->sellerRecovery->amount_minor - (int) $refund->sellerRecovery->recovered_amount_minor)).' outstanding'
                    : 'None',
            ])->values()->all(),
            'exportColumns' => ['Case', 'Order', 'Seller', 'Amount', 'Status', 'Financially responsible', 'Seller recovery'],
            'exportRows' => $refunds->map(fn (Refund $refund): array => [
                $refund->refund_no,
                $refund->order?->order_no ?? '—',
                $refund->returnRequest?->store?->name ?? '—',
                $this->decimal((int) ($refund->payout_amount_minor ?? $refund->amount_minor)),
                str($refund->status)->replace('_', ' ')->title()->toString(),
                $refund->financially_responsible_party
                    ? str($refund->financially_responsible_party)->title()->toString()
                    : 'Not recorded',
                $refund->sellerRecovery
                    ? $this->decimal(max(0, (int) $refund->sellerRecovery->amount_minor - (int) $refund->sellerRecovery->recovered_amount_minor)).' outstanding'
                    : 'None',
            ])->values()->all(),
        ];
    }

    /**
     * @param  callable(resource): void  $writer
     */
    private function csvDownload(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            $writer($stream);
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function recognizedCommissionBaseMinor(PlatformCommission $commission): int
    {
        if ($this->isCommissionReversal($commission)) {
            return $commission->refund?->isCompleted() ? -abs((int) $commission->base_amount_minor) : 0;
        }

        return $this->isFinalizedCommission($commission) ? (int) $commission->base_amount_minor : 0;
    }

    private function recognizedCommissionMinor(PlatformCommission $commission): int
    {
        if ($this->isCommissionReversal($commission)) {
            return $commission->refund?->isCompleted() ? -abs((int) $commission->amount_minor) : 0;
        }

        return $this->isFinalizedCommission($commission) ? (int) $commission->amount_minor : 0;
    }

    private function isCommissionReversal(PlatformCommission $commission): bool
    {
        $type = strtolower($commission->type);

        return $commission->refund_id !== null
            || str_contains($type, 'refund')
            || str_contains($type, 'reversal');
    }

    private function isFinalizedCommission(PlatformCommission $commission): bool
    {
        return $commission->reversed_at === null
            && in_array(strtolower($commission->status), ['finalized', 'posted', 'completed'], true)
            && $commission->finalized_at !== null;
    }

    private function transactionDisplayStatus(SellerTransaction $transaction, bool $isRefund): string
    {
        $status = strtolower($transaction->status);

        if (in_array($status, ['failed', 'void', 'cancelled'], true)) {
            return 'Failed';
        }

        $posted = in_array($status, ['posted', 'completed', 'paid'], true) && $transaction->posted_at !== null;

        if ($isRefund) {
            return $posted && $transaction->refund?->isCompleted() ? 'Refunded' : 'Pending';
        }

        return $posted
            && in_array($transaction->payment?->status, ['paid', 'partially_refunded', 'refunded'], true)
            && $transaction->payment?->paid_at !== null
                ? 'Completed'
                : 'Pending';
    }

    private function mapPayout(SellerPayout $payout): array
    {
        return [
            'id' => $payout->payout_no,
            'record_id' => (int) $payout->id,
            'seller' => $this->payoutSellerName($payout),
            'period' => $this->period($payout->period_start, $payout->period_end),
            'gross' => $payout->gross_minor / 100,
            'commission' => $payout->commission_minor / 100,
            'adjustments' => $payout->adjustment_minor / 100,
            'net' => $payout->net_minor / 100,
            'due' => $payout->due_at?->format('Y-m-d'),
            'paid' => $payout->paid_at?->format('Y-m-d'),
            'reference' => $payout->reference ?: '—',
            'receiving_bank_name' => $payout->receiving_bank_name,
            'status' => $this->payoutDisplayStatus($payout),
            'item_count' => $payout->items->count(),
            'method' => match ($payout->external_method) {
                'gcash' => 'GCash',
                'bank_transfer' => 'Bank transfer',
                'cash' => 'Cash',
                'other' => 'Other',
                default => null,
            },
            'has_receipt' => filled($payout->receipt_path),
        ];
    }

    private function payoutSellerName(SellerPayout $payout): string
    {
        return $payout->sellerProfile?->store?->name
            ?? $payout->sellerProfile?->legal_business_name
            ?? '—';
    }

    private function payoutDisplayStatus(SellerPayout $payout): string
    {
        return match (strtolower($payout->status)) {
            'paid', 'completed' => $payout->paid_at !== null ? 'Paid' : 'Processing',
            'processing', 'in_progress' => 'Processing',
            'on_hold', 'held', 'hold' => 'On Hold',
            default => 'Pending',
        };
    }

    private function topSellers(Collection $commissions): Collection
    {
        $rows = $commissions
            ->groupBy(fn (PlatformCommission $row) => $row->sellerOrder?->store?->name ?? '—')
            ->map(function (Collection $rows, string $seller): array {
                $salesMinor = (int) $rows->sum(fn (PlatformCommission $row) => $this->recognizedCommissionBaseMinor($row));
                $commissionMinor = (int) $rows->sum(fn (PlatformCommission $row) => $this->recognizedCommissionMinor($row));

                return [
                    'seller' => $seller,
                    'sales' => $this->money(max(0, $salesMinor)),
                    'commission' => $this->money($commissionMinor),
                    'sales_minor' => $salesMinor,
                    'commission_minor' => $commissionMinor,
                    'share' => 0.0,
                ];
            })
            ->filter(fn (array $row): bool => $row['sales_minor'] !== 0 || $row['commission_minor'] !== 0)
            ->sortByDesc('sales_minor')
            ->take(10)
            ->values();

        $positiveBase = (int) $rows->sum(fn (array $row): int => max(0, $row['sales_minor']));

        return $rows->map(function (array $row) use ($positiveBase): array {
            $row['share'] = $positiveBase > 0 ? round(max(0, $row['sales_minor']) / $positiveBase * 100, 1) : 0.0;

            return $row;
        })->values();
    }

    private function period(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($start->isSameMonth($end)) {
            return $start->format('M j').'–'.$end->format('j, Y');
        }

        return $start->format('M j').'–'.$end->format('M j, Y');
    }

    private function commissionRate(): float|int
    {
        $rate = (float) (SystemSetting::query()->where('key', 'commission_rate')->value('value') ?? 10);

        return fmod($rate, 1.0) === 0.0 ? (int) $rate : $rate;
    }

    private function money(int $minor): string
    {
        $prefix = $minor < 0 ? '−' : '';

        return $prefix.'₱'.number_format(abs($minor) / 100, 2);
    }

    /**
     * Plain decimal money for CSV cells (no currency symbol).
     */
    private function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').number_format(abs($minor) / 100, 2);
    }

    private function amountToMinor(string $amount): int
    {
        if (! preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount with no more than two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
    }

    private function safeDownloadName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'seller-payout-receipt';

        return Str::limit($safe, 200, '');
    }

    private function base(Request $request): array
    {
        $admin = $request->user();

        return [
            'admin' => [
                'name' => $admin->name,
                'role' => 'Admin',
                'email' => $admin->email,
                'initials' => collect(explode(' ', $admin->name))
                    ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
                    ->take(2)
                    ->implode(''),
            ],
            'topNotifications' => [],
        ];
    }
}
