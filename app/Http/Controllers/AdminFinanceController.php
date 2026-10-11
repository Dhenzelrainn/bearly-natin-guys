<?php

namespace App\Http\Controllers;

use App\Models\CodCashCollection;
use App\Models\Order;
use App\Models\PlatformCommission;
use App\Models\Refund;
use App\Models\RefundCashPayout;
use App\Models\SellerPayout;
use App\Models\SellerTransaction;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\CodCashCollectionService;
use App\Services\RefundCashPayoutService;
use App\Services\SellerPayoutService;
use Carbon\CarbonInterface;
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

class AdminFinanceController extends Controller
{
    public function commissions(Request $request): View
    {
        $rows = PlatformCommission::query()
            ->with(['sellerOrder.order', 'sellerOrder.store', 'refund'])
            ->latest('calculated_at')
            ->get();

        $ledger = $rows
            ->map(function (PlatformCommission $commission): array {
                $grossMinor = $this->recognizedCommissionBaseMinor($commission);
                $commissionMinor = $this->recognizedCommissionMinor($commission);

                return [
                    'date' => ($commission->finalized_at ?? $commission->calculated_at ?? $commission->created_at)->format('M j, Y'),
                    'order' => $commission->sellerOrder?->order?->order_no ?? $commission->sellerOrder?->seller_order_no ?? '—',
                    'seller' => $commission->sellerOrder?->store?->name ?? '—',
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

    public function transactions(Request $request): View
    {
        $rows = SellerTransaction::query()
            ->with([
                'sellerProfile.store',
                'sellerOrder.order.buyer',
                'sellerOrder.store',
                'payment',
                'refund',
                'commission.refund',
            ])
            ->latest()
            ->get();

        $transactions = $rows->map(function (SellerTransaction $transaction): array {
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
        SellerPayoutService $sellerPayouts,
        CodCashCollectionService $codCash,
        RefundCashPayoutService $refundCash,
    ): View {
        $payments = SellerPayout::query()
            ->with(['sellerProfile.store', 'items'])
            ->latest('period_end')
            ->get()
            ->map(fn (SellerPayout $payout): array => $this->mapPayout($payout))
            ->values();

        $eligibleSellers = $sellerPayouts->eligibleBySeller();
        $codCollections = $codCash->codCollectionsForFinance();
        $refundsReadyForCashDispatch = $refundCash->refundsReadyForCashDispatch();
        $refundCashPayouts = $refundCash->refundPayoutsForFinance();

        return view('admin.finance.payments', $this->base($request) + compact(
            'payments',
            'eligibleSellers',
            'codCollections',
            'refundsReadyForCashDispatch',
            'refundCashPayouts',
        ));
    }

    public function reconcileCodCash(
        Request $request,
        CodCashCollection $collection,
        CodCashCollectionService $codCash,
    ): RedirectResponse {
        $data = $request->validate([
            'received_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $codCash->reconcileAtFinance($request->user(), (int) $collection->id, $this->amountToMinor($data['received_amount']), trim($data['reference']));

        return back()->with('success', "COD cash {$collection->collection_no} was reconciled to the Finance receipt reference.");
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
            'external_payout_confirmed' => ['required', 'accepted'],
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(['gcash', 'bank_transfer', 'cash', 'other'])],
            'reference' => ['required', 'string', 'max:150'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'external_payout_confirmed.accepted' => 'Confirm that the external Seller payout has already happened.',
            'receipt.required' => 'Upload the external transfer receipt or Seller acknowledgment.',
        ]);
        /** @var UploadedFile $receipt */
        $receipt = $data['receipt'];
        $recorded = $sellerPayouts->markPaid(
            $request->user(),
            (int) $payout->id,
            $this->amountToMinor($data['actual_amount']),
            $data['method'],
            trim($data['reference']),
            $data['paid_on'],
            trim($data['reason']),
            $receipt,
            $request,
        );

        return back()->with('success', "{$recorded->payout_no} was recorded as externally paid. Bearly did not initiate a transfer.");
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

    public function reports(Request $request): View
    {
        $paidOrders = Order::query()
            ->with(['payments' => fn ($query) => $query->where('status', 'paid')->whereNotNull('paid_at')])
            ->whereHas('payments', fn ($query) => $query->where('status', 'paid')->whereNotNull('paid_at'))
            ->get();
        $completedRefunds = Refund::query()->completed()->with('returnRequest.store')->get();
        $commissions = PlatformCommission::query()
            ->with(['sellerOrder.store', 'refund'])
            ->get();
        $payouts = SellerPayout::query()->with('sellerProfile.store')->latest('period_end')->get();
        $refunds = Refund::query()->with(['order', 'returnRequest.store', 'sellerRecovery'])->latest()->get();

        $grossSalesMinor = (int) $paidOrders->sum('total_minor');
        $refundMinor = (int) $completedRefunds->sum('amount_minor');
        $netSalesMinor = max(0, $grossSalesMinor - $refundMinor);
        $commissionMinor = (int) $commissions->sum(fn (PlatformCommission $row) => $this->recognizedCommissionMinor($row));
        $paidOrderCount = $paidOrders->count();
        $averageOrderMinor = $paidOrderCount > 0 ? (int) round($netSalesMinor / $paidOrderCount) : 0;

        $reportKpis = [
            ['label' => 'Net Sales', 'value' => $this->money($netSalesMinor), 'note' => 'Paid orders less completed refunds'],
            ['label' => 'Orders', 'value' => number_format($paidOrderCount), 'note' => 'Orders with a captured payment'],
            ['label' => 'Platform Commission', 'value' => $this->money($commissionMinor), 'note' => $this->commissionRate().'% configured rate'],
            ['label' => 'Avg. Order Value', 'value' => $this->money($averageOrderMinor), 'note' => 'Net sales per paid order'],
        ];

        $salesSeries = $this->monthlyNetSales($paidOrders, $completedRefunds);
        $topSellers = $this->topSellers($commissions);
        $settlementRows = $payouts->map(fn (SellerPayout $payout): array => [
            'seller' => $this->payoutSellerName($payout),
            'period' => $this->period($payout->period_start, $payout->period_end),
            'net' => $this->money((int) $payout->net_minor),
            'status' => $this->payoutDisplayStatus($payout),
        ])->values();
        $refundRows = $refunds->map(fn (Refund $refund): array => [
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
        ])->values();
        $orderMix = $this->orderMix($paidOrders);
        $reportStart = now()->startOfYear()->format('Y-m-d');
        $reportEnd = now()->format('Y-m-d');
        $salesTrendLabel = $paidOrderCount > 0 ? 'Live data' : 'No data';

        return view('admin.finance.reports', $this->base($request) + compact(
            'reportKpis',
            'salesSeries',
            'topSellers',
            'settlementRows',
            'refundRows',
            'orderMix',
            'paidOrderCount',
            'reportStart',
            'reportEnd',
            'salesTrendLabel',
        ));
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
            && $transaction->payment?->status === 'paid'
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
            'status' => $this->payoutDisplayStatus($payout),
            'item_count' => $payout->items->count(),
            'method' => $payout->external_method,
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

    private function monthlyNetSales(Collection $paidOrders, Collection $completedRefunds): array
    {
        $year = now()->year;
        $series = array_fill(1, 12, 0);

        foreach ($paidOrders as $order) {
            $paidAt = $order->payments->sortBy('paid_at')->first()?->paid_at;
            if ($paidAt?->year === $year) {
                $series[$paidAt->month] += (int) $order->total_minor;
            }
        }

        foreach ($completedRefunds as $refund) {
            if ($refund->completed_at?->year === $year) {
                $series[$refund->completed_at->month] -= (int) $refund->amount_minor;
            }
        }

        return array_map(fn (int $minor): float => max(0, $minor) / 100, array_values($series));
    }

    private function topSellers(Collection $commissions): Collection
    {
        return $commissions
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
                ];
            })
            ->filter(fn (array $row): bool => $row['sales_minor'] !== 0 || $row['commission_minor'] !== 0)
            ->sortByDesc('sales_minor')
            ->take(10)
            ->values();
    }

    private function orderMix(Collection $paidOrders): array
    {
        $total = $paidOrders->count();
        if ($total === 0) {
            return ['marketplace' => 0, 'repeat' => 0, 'promo' => 0];
        }

        $buyerCounts = $paidOrders->countBy('buyer_id');
        $counts = ['marketplace' => 0, 'repeat' => 0, 'promo' => 0];
        foreach ($paidOrders as $order) {
            if ((int) $order->discount_minor > 0) {
                $counts['promo']++;
            } elseif (($buyerCounts[$order->buyer_id] ?? 0) > 1) {
                $counts['repeat']++;
            } else {
                $counts['marketplace']++;
            }
        }

        $mix = array_map(fn (int $count): int => (int) round(($count / $total) * 100), $counts);
        $mix['marketplace'] += 100 - array_sum($mix);

        return $mix;
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
