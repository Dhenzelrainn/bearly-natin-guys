<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\OrderItem;
use App\Models\PlatformCommission;
use App\Models\Refund;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class SellerEarningsService
{
    public function __construct(private readonly BuyerCatalogService $catalog) {}

    /** Ledger states that are included in recognized Finance totals. */
    private const RECOGNIZED_STATUSES = ['Available', 'Posted', 'Withdrawn', 'Reversed'];

    private const ARCHIVE_MONTHS = 6;

    public function dashboardFor(?SellerProfile $sellerProfile): array
    {
        if (! $sellerProfile) {
            return [
                'finance' => $this->emptyFinanceSummary(),
                'commission_rate' => $this->commissionRate(),
                'recent_transactions' => collect(),
                'settlements' => collect(),
            ];
        }

        $ledger = $this->ledgerQuery($sellerProfile)->get();

        return [
            'finance' => $this->summarize($ledger),
            'commission_rate' => $this->commissionRate(),
            'recent_transactions' => $this->ledgerQuery($sellerProfile, withProducts: true)
                ->limit(4)
                ->get()
                ->map(fn (SellerTransaction $transaction): array => $this->mapTransaction($transaction, withProducts: true))
                ->values(),
            'settlements' => $this->settlementsFor($sellerProfile),
        ];
    }

    public function transactionsFor(?SellerProfile $sellerProfile): Collection
    {
        if (! $sellerProfile) {
            return collect();
        }

        return $this->ledgerQuery($sellerProfile, withProducts: true)
            ->get()
            ->map(fn (SellerTransaction $transaction): array => $this->mapTransaction($transaction, withProducts: true))
            ->values();
    }

    public function commissionRate(): float|int
    {
        $rate = (float) (SystemSetting::query()->where('key', 'commission_rate')->value('value') ?? 10);

        return fmod($rate, 1.0) === 0.0 ? (int) $rate : $rate;
    }

    private function ledgerQuery(SellerProfile $sellerProfile, bool $withProducts = false): Builder
    {
        $query = SellerTransaction::query()
            ->where('seller_profile_id', $sellerProfile->id)
            ->where(function (Builder $transactions) use ($sellerProfile): void {
                $transactions
                    ->whereNull('seller_order_id')
                    ->orWhereHas('sellerOrder.store', fn (Builder $stores) => $stores->where('seller_profile_id', $sellerProfile->id));
            })
            ->with([
                'sellerOrder.order',
                'payment',
                'refund',
                'commission.refund',
                'walletTransactions',
                'payoutItem.payout',
            ]);

        if ($withProducts) {
            $query->with([
                'sellerOrder.items.product.subcategory',
                'sellerOrder.items.product.images.variant',
                'sellerOrder.items.variant',
            ]);
        }

        return $query
            ->orderByRaw('COALESCE(posted_at, created_at) DESC')
            ->orderByDesc('id');
    }

    private function summarize(Collection $transactions): array
    {
        $summary = $this->emptyFinanceSummary();

        foreach ($transactions as $transaction) {
            $isRefund = $this->isRefund($transaction);
            $status = $this->displayStatus($transaction, $isRefund);
            $recognized = in_array($status, self::RECOGNIZED_STATUSES, true);

            if (! $recognized) {
                continue;
            }

            $summary['net_earnings_minor'] += (int) $transaction->amount_minor;
            $summary['net_commission_minor'] += $this->recognizedCommissionMinor($transaction->commission, $isRefund);

            if ($isRefund) {
                $summary['refunds_minor'] += abs((int) ($transaction->refund?->amount_minor ?? 0));

                continue;
            }

            $discountMinor = (int) ($transaction->sellerOrder?->discount_minor ?? 0);
            $commissionBaseMinor = $this->recognizedCommissionBaseMinor($transaction->commission)
                ?: (int) ($transaction->sellerOrder?->total_minor ?? 0);

            $summary['gross_sales_minor'] += $commissionBaseMinor + $discountMinor;
            $summary['seller_discounts_minor'] += $discountMinor;
        }

        $summary['commissionable_sales_minor'] = $summary['gross_sales_minor']
            - $summary['seller_discounts_minor']
            - $summary['refunds_minor'];
        $summary['total_deductions_minor'] = $summary['seller_discounts_minor']
            + $summary['refunds_minor']
            + $summary['net_commission_minor'];

        return $summary;
    }

    private function emptyFinanceSummary(): array
    {
        return [
            'gross_sales_minor' => 0,
            'seller_discounts_minor' => 0,
            'refunds_minor' => 0,
            'commissionable_sales_minor' => 0,
            'net_commission_minor' => 0,
            'net_earnings_minor' => 0,
            'total_deductions_minor' => 0,
        ];
    }

    private function mapTransaction(SellerTransaction $transaction, bool $withProducts = false): array
    {
        $isRefund = $this->isRefund($transaction);
        $status = $this->displayStatus($transaction, $isRefund);
        $recognized = in_array($status, self::RECOGNIZED_STATUSES, true);
        $discountMinor = $isRefund ? 0 : (int) ($transaction->sellerOrder?->discount_minor ?? 0);
        $commissionBaseMinor = $this->recognizedCommissionBaseMinor($transaction->commission)
            ?: (int) ($transaction->sellerOrder?->total_minor ?? 0);
        $grossMinor = $isRefund ? 0 : $commissionBaseMinor + $discountMinor;
        $refundMinor = $isRefund && $recognized
            ? abs((int) ($transaction->refund?->amount_minor ?? 0))
            : 0;
        $commissionMinor = ! $isRefund && $recognized
            ? $this->recognizedCommissionMinor($transaction->commission, false)
            : 0;
        $commissionReversalMinor = $isRefund && $recognized
            ? abs($this->recognizedCommissionMinor($transaction->commission, true))
            : 0;
        $date = $transaction->posted_at ?? $transaction->created_at;

        $row = [
            'id' => $transaction->transaction_no,
            'date' => $date?->format('M j, Y') ?? 'Date unavailable',
            'order' => $transaction->sellerOrder?->order?->order_no
                ?? $transaction->sellerOrder?->seller_order_no
                ?? 'Order unavailable',
            'gross_minor' => $grossMinor,
            'discount_minor' => $discountMinor,
            'refund_minor' => $refundMinor,
            'commissionable_minor' => $grossMinor - $discountMinor - $refundMinor,
            'commission_minor' => $commissionMinor,
            'commission_reversal_minor' => $commissionReversalMinor,
            'net_minor' => $recognized ? (int) $transaction->amount_minor : 0,
            'recognized' => $recognized,
            'status' => $status,
            'status_key' => match ($status) {
                'Available' => 'available',
                'Posted' => 'posted',
                'Withdrawn' => 'withdrawn',
                'Reversed' => 'reversed',
                'Failed' => 'cancelled',
                default => 'pending',
            },
            'note' => match ($status) {
                'Available' => 'Released to your Wallet; available for withdrawal.',
                'Posted' => 'Posted to the Finance ledger; settlement eligibility is still in progress.',
                'Withdrawn' => 'Included in a historical Seller settlement batch.',
                'Reversed' => 'Completed refund; seller earnings were reversed.',
                'Failed' => 'This transaction was cancelled or voided.',
                default => $isRefund
                    ? 'The refund is not final yet; this entry remains pending.'
                    : 'Waiting for payment confirmation and finance-ledger posting.',
            },
            'items' => [],
        ];

        if ($withProducts) {
            $row['items'] = $transaction->sellerOrder?->items
                ?->map(fn (OrderItem $item): array => [
                    'name' => $item->product_name,
                    'variant' => $item->variant_name,
                    'quantity' => (int) $item->quantity,
                    'subcategory' => $item->product
                        ? ($item->product->subcategory?->name ?: 'No subcategory assigned')
                        : 'Subcategory unavailable',
                    'image' => $this->catalog->orderItemImageUrl($item),
                    'image_url' => $this->catalog->orderItemImageUrl($item),
                ])
                ->values()
                ->all() ?? [];
        }

        return $row;
    }

    private function settlementsFor(SellerProfile $sellerProfile): Collection
    {
        return SellerPayout::query()
            ->where('seller_profile_id', $sellerProfile->id)
            ->latest('period_end')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (SellerPayout $payout): array {
                $status = match (strtolower($payout->status)) {
                    'paid', 'completed' => $payout->paid_at ? 'Paid' : 'Processing',
                    'processing', 'in_progress' => 'Processing',
                    'on_hold', 'held', 'hold' => 'On Hold',
                    default => 'Pending',
                };
                $hasReceipt = $status === 'Paid'
                    && $payout->receipt_disk === 'local'
                    && filled($payout->receipt_path)
                    && Storage::disk('local')->exists($payout->receipt_path);
                $method = $status === 'Paid'
                    ? match ($payout->external_method) {
                        'gcash' => 'GCash',
                        'bank_transfer' => 'Bank transfer',
                        'cash' => 'Cash',
                        'other' => 'Other',
                        default => null,
                    }
                : null;

                return [
                    'id' => (int) $payout->id,
                    'payout_no' => $payout->payout_no,
                    'period' => $payout->period_start->format('M j, Y').' – '.$payout->period_end->format('M j, Y'),
                    'net_minor' => (int) $payout->net_minor,
                    'status' => $status,
                    'status_key' => match ($status) {
                        'Paid' => 'available',
                        'Processing' => 'processing',
                        'On Hold' => 'on-hold',
                        default => 'pending',
                    },
                    'method' => $method,
                    'reference' => $status === 'Paid' ? $payout->reference : null,
                    'reference_label' => $payout->external_method === 'cash' ? 'Cash acknowledgment' : 'Payment reference',
                    'next_step' => match ($status) {
                        'Paid' => $hasReceipt
                            ? 'Paid. Download your payout proof.'
                            : 'Finance marked this paid. Ask Finance for your proof.',
                        'Processing' => 'Finance is checking this payout. Check back later.',
                        'On Hold' => 'This payout is on hold. Ask Admin Finance what to do next.',
                        default => 'Admin Finance still needs to pay you. You do not need to do anything yet.',
                    },
                    'paid_on' => $status === 'Paid' ? $payout->paid_at?->format('M j, Y') : null,
                    'has_receipt' => $hasReceipt,
                ];
            })
            ->values();
    }

    private function isRefund(SellerTransaction $transaction): bool
    {
        $type = strtolower($transaction->type);

        return $transaction->refund_id !== null
            || str_contains($type, 'refund')
            || str_contains($type, 'reversal');
    }

    private function displayStatus(SellerTransaction $transaction, bool $isRefund): string
    {
        $status = strtolower($transaction->status);

        if (in_array($status, ['failed', 'void', 'cancelled'], true)) {
            return 'Failed';
        }

        $posted = in_array($status, ['posted', 'completed', 'paid'], true)
            && $transaction->posted_at !== null;

        if ($isRefund) {
            return $posted && $transaction->refund?->isCompleted() ? 'Reversed' : 'Pending';
        }

        if (! $posted
            || $transaction->payment?->status !== 'paid'
            || $transaction->payment?->paid_at === null) {
            return 'Pending';
        }

        // "Available" is only true when the Wallet eligibility checks released
        // the earnings. Ledger posting alone must never present funds as
        // withdrawable.
        if ($transaction->walletTransactions->contains(
            fn ($walletTransaction): bool => $walletTransaction->type === 'sale_earning_available',
        )) {
            return 'Available';
        }

        $payout = $transaction->payoutItem?->payout;
        if ($payout
            && $payout->paid_at !== null
            && in_array(strtolower((string) $payout->status), ['paid', 'completed'], true)) {
            return 'Withdrawn';
        }

        return 'Posted';
    }

    private function recognizedCommissionBaseMinor(?PlatformCommission $commission): int
    {
        if (! $commission || $this->isCommissionReversal($commission)) {
            return 0;
        }

        return $this->isFinalizedCommission($commission) ? (int) $commission->base_amount_minor : 0;
    }

    private function recognizedCommissionMinor(?PlatformCommission $commission, bool $isRefund): int
    {
        if (! $commission) {
            return 0;
        }

        if ($this->isCommissionReversal($commission)) {
            return $isRefund && $commission->refund?->isCompleted()
                ? -abs((int) $commission->amount_minor)
                : 0;
        }

        return ! $isRefund && $this->isFinalizedCommission($commission)
            ? (int) $commission->amount_minor
            : 0;
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

    /* -----------------------------------------------------------------
     * Shared report period helpers
     * ----------------------------------------------------------------- */

    /**
     * Resolve a validated inclusive date range.
     *
     * Invalid or missing input falls back to the current calendar month so
     * the report pages always render a deterministic period. Reversed ranges
     * are normalized instead of failing.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function reportRange(mixed $from, mixed $to): array
    {
        $start = $this->parseDate(is_string($from) ? $from : null) ?? CarbonImmutable::now()->startOfMonth();
        $end = $this->parseDate(is_string($to) ? $to : null) ?? CarbonImmutable::now()->endOfMonth();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return ['from' => $start, 'to' => $end];
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Full calendar months compare against the previous calendar month;
     * custom ranges compare against the immediately preceding equal window.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function previousRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->isStartOfMonth() && $to->isEndOfMonth() && $from->isSameMonth($to)) {
            $previousEnd = $from->subDay();

            return ['from' => $previousEnd->startOfMonth(), 'to' => $previousEnd->endOfMonth()];
        }

        $days = max(1, (int) $from->diffInDays($to) + 1);
        $previousEnd = $from->subDay();

        return ['from' => $previousEnd->subDays($days - 1), 'to' => $previousEnd];
    }

    public function rangeLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isStartOfMonth() && $to->isEndOfMonth() && $from->isSameMonth($to)) {
            return $from->format('F Y');
        }

        if ($from->isSameDay($to)) {
            return $from->format('M j, Y');
        }

        if ($from->isSameYear($to)) {
            return $from->format('M j').' – '.$to->format('M j, Y');
        }

        return $from->format('M j, Y').' – '.$to->format('M j, Y');
    }

    /**
     * Resolve the Dashboard period shortcut.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, key: string}
     */
    public function dashboardPeriodRange(mixed $period): array
    {
        $now = CarbonImmutable::now();
        $key = is_string($period) ? strtolower($period) : 'this_month';

        return match ($key) {
            'last_month' => [
                'from' => $now->subMonthNoOverflow()->startOfMonth(),
                'to' => $now->subMonthNoOverflow()->endOfMonth(),
                'key' => 'last_month',
            ],
            'last_7' => [
                'from' => $now->subDays(6)->startOfDay(),
                'to' => $now->endOfDay(),
                'key' => 'last_7',
            ],
            'last_30' => [
                'from' => $now->subDays(29)->startOfDay(),
                'to' => $now->endOfDay(),
                'key' => 'last_30',
            ],
            default => [
                'from' => $now->startOfMonth(),
                'to' => $now->endOfMonth(),
                'key' => 'this_month',
            ],
        };
    }

    /** Percentage change between two integer amounts; null when there is no baseline. */
    public function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /* -----------------------------------------------------------------
     * Financial Report (Finance ledger basis: posted_at)
     * ----------------------------------------------------------------- */

    /**
     * Financial Report data: recognized ledger totals for the period,
     * deduction split, statement status, and a monthly archive.
     */
    public function financialReportFor(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $transactions = $this->ledgerInRange($sellerProfile, $from, $to);
        $summary = $this->summarize($transactions);
        $totalDeductionsMinor = $summary['total_deductions_minor'];

        $deductions = [
            ['key' => 'commission', 'label' => 'Platform Commission', 'minor' => $summary['net_commission_minor']],
            ['key' => 'discounts', 'label' => 'Seller Discounts', 'minor' => $summary['seller_discounts_minor']],
            ['key' => 'refunds', 'label' => 'Refunds', 'minor' => $summary['refunds_minor']],
        ];

        // A net-commission credit (finalized reversals exceeding the
        // period's commission) is not a deduction: it must never render a
        // negative share or a negative-width bar. Shares are therefore
        // taken from the positive deductions only; the all-positive case
        // keeps the exact same percentages as before.
        $positiveDeductionsMinor = 0;

        foreach ($deductions as $deduction) {
            if ($deduction['minor'] > 0) {
                $positiveDeductionsMinor += $deduction['minor'];
            }
        }

        foreach ($deductions as $index => $deduction) {
            $isCredit = $deduction['minor'] < 0;
            $deductions[$index]['is_credit'] = $isCredit;
            $deductions[$index]['percent'] = ! $isCredit && $positiveDeductionsMinor > 0
                ? round($deduction['minor'] / $positiveDeductionsMinor * 100, 1)
                : 0.0;
        }

        return [
            'summary' => $summary,
            'deductions' => $deductions,
            // The rate is undefined - not zero - whenever there is no positive
            // gross-sales denominator to measure against (for example, a
            // refund-only period); callers render an unavailable marker.
            'deduction_rate' => $summary['gross_sales_minor'] > 0
                ? round($totalDeductionsMinor / $summary['gross_sales_minor'] * 100, 1)
                : null,
            'statement_status' => $this->statementStatus($transactions),
            'archive' => $this->financialArchive($sellerProfile, $to),
            'commission_rate' => $this->commissionRate(),
        ];
    }

    public function ledgerSummaryFor(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->summarize($this->ledgerInRange($sellerProfile, $from, $to));
    }

    private function ledgerInRange(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if (! $sellerProfile) {
            return collect();
        }

        return $this->ledgerQuery($sellerProfile)
            ->whereNotNull('posted_at')
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();
    }

    /**
     * Status from the Finance ledger plus Wallet eligibility only.
     * Legacy payout rows are never presented as the new withdrawal status.
     *
     * @return array{label: string, key: string}
     */
    private function statementStatus(Collection $transactions): array
    {
        $sales = $transactions->filter(
            fn (SellerTransaction $transaction): bool => ! $this->isRefund($transaction)
                && in_array($this->displayStatus($transaction, false), self::RECOGNIZED_STATUSES, true),
        );

        if ($sales->isEmpty()) {
            $hasReversal = $transactions->contains(
                fn (SellerTransaction $transaction): bool => $this->isRefund($transaction)
                    && in_array($this->displayStatus($transaction, true), self::RECOGNIZED_STATUSES, true),
            );

            return $hasReversal
                ? ['label' => 'Reversed', 'key' => 'reversed']
                : ['label' => 'No activity', 'key' => 'none'];
        }

        $statuses = $sales
            ->map(fn (SellerTransaction $transaction): string => $this->displayStatus($transaction, false))
            ->unique();

        if ($statuses->count() === 1) {
            return match ($statuses->first()) {
                'Available' => ['label' => 'Available', 'key' => 'available'],
                'Withdrawn' => ['label' => 'Withdrawn', 'key' => 'withdrawn'],
                default => ['label' => 'Posted', 'key' => 'posted'],
            };
        }

        return ['label' => 'Posted', 'key' => 'posted'];
    }

    /**
     * Monthly financial history. Months without any recognized ledger
     * activity are omitted instead of showing fabricated zeros.
     */
    private function financialArchive(?SellerProfile $sellerProfile, CarbonImmutable $to): array
    {
        if (! $sellerProfile) {
            return [];
        }

        $firstMonth = $to->startOfMonth()->subMonths(self::ARCHIVE_MONTHS - 1);
        $transactions = $this->ledgerQuery($sellerProfile)
            ->whereNotNull('posted_at')
            ->whereBetween('posted_at', [$firstMonth->startOfDay(), $to->endOfMonth()])
            ->get();

        $rows = [];

        for ($month = $firstMonth; $month->lessThanOrEqualTo($to->startOfMonth()); $month = $month->addMonth()) {
            $monthStart = $month->startOfMonth();
            $monthEnd = $month->endOfMonth();
            $group = $transactions->filter(
                fn (SellerTransaction $transaction): bool => $transaction->posted_at !== null
                    && $transaction->posted_at->greaterThanOrEqualTo($monthStart)
                    && $transaction->posted_at->lessThanOrEqualTo($monthEnd),
            );

            $summary = $this->summarize($group);

            if ($summary['gross_sales_minor'] === 0
                && $summary['refunds_minor'] === 0
                && $summary['net_earnings_minor'] === 0) {
                continue;
            }

            $rows[] = [
                'key' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('F Y'),
                'gross_minor' => $summary['gross_sales_minor'],
                'commission_minor' => $summary['net_commission_minor'],
                'discounts_minor' => $summary['seller_discounts_minor'],
                'refunds_minor' => $summary['refunds_minor'],
                'net_minor' => $summary['net_earnings_minor'],
                'status' => $this->statementStatus($group),
            ];
        }

        return array_reverse($rows);
    }

    /* -----------------------------------------------------------------
     * Sales & Performance (completed Seller Order basis: completed_at)
     * ----------------------------------------------------------------- */

    /**
     * Completed seller orders attributed to the period by completion date.
     * Cancelled seller orders never appear in completed sales metrics.
     */
    private function sellerOrdersInRange(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if (! $sellerProfile) {
            return collect();
        }

        return SellerOrder::query()
            ->where('status', SellerOrderStatus::Completed->value)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereHas('store', fn (Builder $stores) => $stores->where('seller_profile_id', $sellerProfile->id))
            ->with(['items.product.subcategory', 'items.product.images.variant', 'items.variant'])
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Completed refunds attributed to the period by refund completion date.
     */
    private function sellerRefundsInRange(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if (! $sellerProfile) {
            return collect();
        }

        return Refund::query()
            ->completed()
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereHas(
                'returnRequest.store',
                fn (Builder $stores) => $stores->where('seller_profile_id', $sellerProfile->id),
            )
            ->with(['returnRequest.items.orderItem.product.subcategory', 'returnRequest.items.orderItem.variant'])
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Seller orders do not cast completed_at, so hydrated rows expose the raw
     * database value. Normalize it the same way SellerWalletService does.
     */
    private function sellerOrderCompletedAt(SellerOrder $sellerOrder): ?CarbonImmutable
    {
        return $sellerOrder->completed_at === null
            ? null
            : CarbonImmutable::parse($sellerOrder->completed_at);
    }

    /**
     * Product sales from the authoritative Seller Order snapshot.
     *
     * The seller-order subtotal is the product value captured at checkout:
     * it excludes the shipping fee (total = subtotal + shipping − discount)
     * and keeps seller-funded discounts as a separate deduction line, so
     * Gross/Net Sales, averages, and buckets never include shipping.
     */
    private function salesTotals(Collection $sellerOrders, Collection $refunds): array
    {
        $grossMinor = 0;
        $discountsMinor = 0;
        $units = 0;

        foreach ($sellerOrders as $sellerOrder) {
            $grossMinor += (int) $sellerOrder->subtotal_minor;
            $discountsMinor += (int) $sellerOrder->discount_minor;
            $units += (int) $sellerOrder->items->sum('quantity');
        }

        $refundsMinor = (int) $refunds->sum(fn (Refund $refund): int => abs((int) $refund->amount_minor));
        $orders = $sellerOrders->count();

        return [
            'gross_minor' => $grossMinor,
            'discounts_minor' => $discountsMinor,
            'refunds_minor' => $refundsMinor,
            'orders' => $orders,
            'units' => $units,
            'average_order_minor' => $orders > 0 ? intdiv($grossMinor, $orders) : 0,
            'net_minor' => $grossMinor - $discountsMinor - $refundsMinor,
        ];
    }

    /**
     * Split the period into equal buckets for charts and period rows.
     */
    private function bucketizeSales(
        Collection $sellerOrders,
        Collection $refunds,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $bucketCount,
    ): array {
        $days = max(1, (int) $from->diffInDays($to) + 1);
        $bucketCount = max(1, min($bucketCount, $days));
        $base = intdiv($days, $bucketCount);
        $remainder = $days % $bucketCount;

        $buckets = [];
        $cursor = $from->startOfDay();
        $maxGrossMinor = 0;

        for ($index = 0; $index < $bucketCount; $index++) {
            $size = $base + ($index < $remainder ? 1 : 0);
            $start = $cursor;
            $end = $cursor->addDays($size - 1)->endOfDay();
            $cursor = $end->addDay()->startOfDay();

            $totals = $this->salesTotals(
                $sellerOrders->filter(fn (SellerOrder $sellerOrder): bool => ($completedAt = $this->sellerOrderCompletedAt($sellerOrder)) !== null
                    && $completedAt->greaterThanOrEqualTo($start)
                    && $completedAt->lessThanOrEqualTo($end)),
                $refunds->filter(fn (Refund $refund): bool => $refund->completed_at !== null
                    && $refund->completed_at->greaterThanOrEqualTo($start)
                    && $refund->completed_at->lessThanOrEqualTo($end)),
            );

            $totals['label'] = $start->isSameDay($end)
                ? $start->format('M j')
                : $start->format('M j').'–'.$end->format('M j');
            $totals['start'] = $start->toDateString();
            $totals['end'] = $end->toDateString();

            $maxGrossMinor = max($maxGrossMinor, $totals['gross_minor']);
            $buckets[] = $totals;
        }

        foreach ($buckets as $index => $bucket) {
            $buckets[$index]['height'] = $maxGrossMinor > 0 && $bucket['gross_minor'] > 0
                ? max(4, (int) round($bucket['gross_minor'] / $maxGrossMinor * 100))
                : 0;
        }

        return $buckets;
    }

    /**
     * Per-product sales from immutable order-item snapshots.
     *
     * Product identity prefers the stored product_id; deleted products keep
     * their snapshot name/SKU. Subcategory is presentation metadata read live
     * from the product relation with an explicit fallback when it is gone.
     * Item values exclude seller-order shipping, so product shares are based
     * on the product subtotal total.
     */
    private function productBreakdown(Collection $sellerOrders, Collection $refunds): array
    {
        $products = [];

        $collect = function (OrderItem $item) use (&$products): string {
            $key = $item->product_id
                ? 'product:'.$item->product_id
                : 'snapshot:'.mb_strtolower(trim((string) $item->product_name)).'|'.mb_strtolower(trim((string) $item->sku));

            if (! isset($products[$key])) {
                $products[$key] = [
                    'key' => $key,
                    'product_id' => $item->product_id ? (int) $item->product_id : null,
                    'name' => (string) $item->product_name,
                    'variant' => (string) ($item->variant_name ?: 'Standard'),
                    'sku' => (string) ($item->sku ?: 'No SKU'),
                    'subcategory' => $this->subcategoryLabel($item),
                    'image_url' => $this->catalog->orderItemImageUrl($item),
                    'units' => 0,
                    'gross_minor' => 0,
                    'discounts_minor' => 0,
                    'refunds_minor' => 0,
                    'net_minor' => 0,
                ];
            } elseif ($this->subcategoryRank($this->subcategoryLabel($item)) > $this->subcategoryRank($products[$key]['subcategory'])) {
                $products[$key]['subcategory'] = $this->subcategoryLabel($item);
            }

            return $key;
        };

        foreach ($sellerOrders as $sellerOrder) {
            foreach ($sellerOrder->items as $item) {
                $key = $collect($item);
                $products[$key]['units'] += (int) $item->quantity;
                $products[$key]['gross_minor'] += (int) $item->subtotal_minor;
                $products[$key]['discounts_minor'] += (int) $item->discount_minor;
            }
        }

        // Refunds are attributed to the refunded item even when the original
        // order completed outside the selected period.
        foreach ($refunds as $refund) {
            foreach ($refund->returnRequest?->items ?? collect() as $returnItem) {
                $item = $returnItem->orderItem;

                if (! $item) {
                    continue;
                }

                $key = $collect($item);
                $products[$key]['refunds_minor'] += abs((int) $returnItem->refund_amount_minor);
            }
        }

        foreach ($products as $key => $product) {
            $products[$key]['net_minor'] = $product['gross_minor'] - $product['discounts_minor'] - $product['refunds_minor'];
        }

        return array_values($products);
    }

    private function subcategoryLabel(OrderItem $item): string
    {
        if (! $item->product_id) {
            return 'Subcategory unavailable';
        }

        return $item->product?->subcategory?->name ?: 'No subcategory assigned';
    }

    private function subcategoryRank(string $label): int
    {
        return match ($label) {
            'Subcategory unavailable' => 0,
            'No subcategory assigned' => 1,
            default => 2,
        };
    }

    private function sortProducts(array $products, string $sort): array
    {
        usort($products, function (array $a, array $b) use ($sort): int {
            if ($sort === 'units') {
                return [$b['units'], $b['gross_minor']] <=> [$a['units'], $a['gross_minor']];
            }

            return [$b['net_minor'], $b['units']] <=> [$a['net_minor'], $a['units']];
        });

        $totalGrossMinor = (int) array_sum(array_column($products, 'gross_minor'));

        foreach ($products as $index => $product) {
            $products[$index]['rank'] = $index + 1;
            $products[$index]['share'] = $totalGrossMinor > 0
                ? round($product['gross_minor'] / $totalGrossMinor * 100, 1)
                : 0.0;
        }

        return $products;
    }

    /**
     * Sales & Performance report data.
     */
    public function salesReportFor(
        ?SellerProfile $sellerProfile,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $sort = 'net',
    ): array {
        $sort = $sort === 'units' ? 'units' : 'net';

        $sellerOrders = $this->sellerOrdersInRange($sellerProfile, $from, $to);
        $refunds = $this->sellerRefundsInRange($sellerProfile, $from, $to);
        $totals = $this->salesTotals($sellerOrders, $refunds);

        $previous = $this->previousRange($from, $to);
        $previousTotals = $this->salesTotals(
            $this->sellerOrdersInRange($sellerProfile, $previous['from'], $previous['to']),
            $this->sellerRefundsInRange($sellerProfile, $previous['from'], $previous['to']),
        );

        return [
            'totals' => $totals,
            'previous_totals' => $previousTotals,
            'change' => [
                'gross' => $this->percentChange($totals['gross_minor'], $previousTotals['gross_minor']),
                'orders' => $this->percentChange($totals['orders'], $previousTotals['orders']),
                'units' => $this->percentChange($totals['units'], $previousTotals['units']),
                'average_order' => $this->percentChange($totals['average_order_minor'], $previousTotals['average_order_minor']),
            ],
            'momentum' => $this->bucketizeSales($sellerOrders, $refunds, $from, $to, 4),
            'products' => $this->sortProducts($this->productBreakdown($sellerOrders, $refunds), $sort),
            'sort' => $sort,
            'previous_period' => $previous,
        ];
    }

    /**
     * Reports Overview data: metrics, ledger bridge, trend, insights, products.
     */
    public function overviewFor(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sellerOrders = $this->sellerOrdersInRange($sellerProfile, $from, $to);
        $refunds = $this->sellerRefundsInRange($sellerProfile, $from, $to);
        $totals = $this->salesTotals($sellerOrders, $refunds);

        $previous = $this->previousRange($from, $to);
        $previousTotals = $this->salesTotals(
            $this->sellerOrdersInRange($sellerProfile, $previous['from'], $previous['to']),
            $this->sellerRefundsInRange($sellerProfile, $previous['from'], $previous['to']),
        );

        $ledger = $this->ledgerSummaryFor($sellerProfile, $from, $to);
        $previousLedger = $this->ledgerSummaryFor($sellerProfile, $previous['from'], $previous['to']);

        return [
            'totals' => $totals,
            'previous_totals' => $previousTotals,
            'ledger' => $ledger,
            'previous_ledger' => $previousLedger,
            'change' => [
                'gross' => $this->percentChange($totals['gross_minor'], $previousTotals['gross_minor']),
                'orders' => $this->percentChange($totals['orders'], $previousTotals['orders']),
                'average_order' => $this->percentChange($totals['average_order_minor'], $previousTotals['average_order_minor']),
                'net_earnings' => $this->percentChange($ledger['net_earnings_minor'], $previousLedger['net_earnings_minor']),
                'commission' => $this->percentChange($ledger['net_commission_minor'], $previousLedger['net_commission_minor']),
                'refunds' => $this->percentChange($totals['refunds_minor'], $previousTotals['refunds_minor']),
            ],
            'trend' => $this->bucketizeSales($sellerOrders, $refunds, $from, $to, 7),
            'products' => $this->sortProducts($this->productBreakdown($sellerOrders, $refunds), 'net'),
            'refund_rate' => $totals['gross_minor'] > 0
                ? round($totals['refunds_minor'] / $totals['gross_minor'] * 100, 1)
                : null,
            'previous_refund_rate' => $previousTotals['gross_minor'] > 0
                ? round($previousTotals['refunds_minor'] / $previousTotals['gross_minor'] * 100, 1)
                : null,
            'previous_period' => $previous,
        ];
    }

    /* -----------------------------------------------------------------
     * Dashboard
     * ----------------------------------------------------------------- */

    /**
     * Dashboard finance/report widgets from the same shared read model so
     * Dashboard and Reports numbers match for the same period.
     */
    public function dashboardFinanceFor(
        ?SellerProfile $sellerProfile,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $interval = 'daily',
    ): array {
        $interval = $interval === 'weekly' ? 'weekly' : 'daily';

        $sellerOrders = $this->sellerOrdersInRange($sellerProfile, $from, $to);
        $refunds = $this->sellerRefundsInRange($sellerProfile, $from, $to);
        $totals = $this->salesTotals($sellerOrders, $refunds);

        $previous = $this->previousRange($from, $to);
        $previousTotals = $this->salesTotals(
            $this->sellerOrdersInRange($sellerProfile, $previous['from'], $previous['to']),
            $this->sellerRefundsInRange($sellerProfile, $previous['from'], $previous['to']),
        );

        $ledger = $this->ledgerSummaryFor($sellerProfile, $from, $to);
        $previousLedger = $this->ledgerSummaryFor($sellerProfile, $previous['from'], $previous['to']);

        return [
            'sales' => $totals,
            'previous_sales' => $previousTotals,
            'ledger' => $ledger,
            'previous_ledger' => $previousLedger,
            'change' => [
                'sales' => $this->percentChange($totals['gross_minor'], $previousTotals['gross_minor']),
                'orders' => $this->percentChange($totals['orders'], $previousTotals['orders']),
                'net_earnings' => $this->percentChange($ledger['net_earnings_minor'], $previousLedger['net_earnings_minor']),
                'commission' => $this->percentChange($ledger['net_commission_minor'], $previousLedger['net_commission_minor']),
            ],
            'series' => $this->salesSeries($sellerOrders, $from, $to, $interval),
            'products' => array_slice($this->sortProducts($this->productBreakdown($sellerOrders, $refunds), 'net'), 0, 5),
            'interval' => $interval,
            'previous_period' => $previous,
        ];
    }

    /**
     * Gross completed sales per day (or week) for the Dashboard chart.
     */
    private function salesSeries(
        Collection $sellerOrders,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $interval,
    ): array {
        $step = $interval === 'weekly' ? 7 : 1;
        $dayCount = max(1, (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1);
        $bucketCount = (int) ceil($dayCount / $step);
        $limit = $to->endOfDay();
        $series = [];

        for ($index = 0; $index < $bucketCount; $index++) {
            $start = $from->startOfDay()->addDays($index * $step);
            $end = $start->addDays($step - 1)->endOfDay();

            if ($end->greaterThan($limit)) {
                $end = $limit;
            }

            $grossMinor = 0;

            foreach ($sellerOrders as $sellerOrder) {
                $completedAt = $this->sellerOrderCompletedAt($sellerOrder);

                if ($completedAt !== null
                    && $completedAt->greaterThanOrEqualTo($start)
                    && $completedAt->lessThanOrEqualTo($end)) {
                    $grossMinor += (int) $sellerOrder->subtotal_minor;
                }
            }

            $series[] = [
                'label' => $start->format('M j'),
                'value_minor' => $grossMinor,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ];
        }

        return $series;
    }

    /**
     * Fulfillment rate for the seller-order cohort created in the period.
     *
     * Numerator: seller orders completed in the cohort.
     * Denominator: completed + cancelled seller orders (finalized only).
     * Still-open orders are excluded until they reach a final state, and
     * "rate" is null when no order has been finalized yet.
     *
     * @return array{rate: ?float, previous_rate: ?float, completed: int, cancelled: int, open: int}
     */
    public function fulfillmentRateFor(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cohort = $this->fulfillmentCohort($sellerProfile, $from, $to);
        $previous = $this->previousRange($from, $to);
        $cohort['previous_rate'] = $this->fulfillmentCohort($sellerProfile, $previous['from'], $previous['to'])['rate'];

        return $cohort;
    }

    /**
     * @return array{rate: ?float, completed: int, cancelled: int, open: int}
     */
    private function fulfillmentCohort(?SellerProfile $sellerProfile, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $sellerProfile) {
            return ['rate' => null, 'completed' => 0, 'cancelled' => 0, 'open' => 0];
        }

        $counts = SellerOrder::query()
            ->whereHas('store', fn (Builder $stores) => $stores->where('seller_profile_id', $sellerProfile->id))
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed', [SellerOrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [SellerOrderStatus::Cancelled->value])
            ->first();

        $total = (int) ($counts->total ?? 0);
        $completed = (int) ($counts->completed ?? 0);
        $cancelled = (int) ($counts->cancelled ?? 0);
        $finalized = $completed + $cancelled;

        return [
            'rate' => $finalized > 0 ? round($completed / $finalized * 100, 1) : null,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'open' => max(0, $total - $finalized),
        ];
    }
}
