<?php

namespace App\Services;

use App\Models\RiderProfile;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RiderEarningsService
{
    private const PER_PAGE = 20;

    /** @var array<int, true> */
    private array $shippingSettlementsMaterializedForRider = [];

    public function __construct(private readonly ShippingSettlementService $shippingSettlements) {}

    /**
     * Reversed and cancelled rows remain visible in ledger activity, but do
     * not contribute to recorded earning totals.
     *
     * @var list<string>
     */
    private const EXCLUDED_TOTAL_STATUSES = [
        'reversed',
        'cancelled',
    ];

    /**
     * @return array{
     *     today: array{amount_minor: int, amount: string, entries: int},
     *     week: array{amount_minor: int, amount: string, entries: int},
     *     month: array{amount_minor: int, amount: string, entries: int},
     *     active_total_minor: int,
     *     active_total: string,
     *     active_entries: int,
     *     excluded_entries: int,
     * }
     */
    public function summary(RiderProfile $rider): array
    {
        $this->materializeShippingSettlements((int) $rider->id);
        $now = now();
        $base = DB::table('rider_earnings')
            ->where('rider_profile_id', $rider->id);

        $today = $this->periodSummary(
            $base,
            $now->copy()->startOfDay(),
            $now->copy()->endOfDay(),
        );
        $week = $this->periodSummary(
            $base,
            $now->copy()->startOfWeek(),
            $now->copy()->endOfWeek(),
        );
        $month = $this->periodSummary(
            $base,
            $now->copy()->startOfMonth(),
            $now->copy()->endOfMonth(),
        );

        $active = $this->activeQuery($base);
        $activeTotalMinor = (int) $active->sum('amount_minor');

        return [
            'today' => $today,
            'week' => $week,
            'month' => $month,
            'active_total_minor' => $activeTotalMinor,
            'active_total' => $this->formatMinor($activeTotalMinor),
            'active_entries' => (int) $active->count(),
            'excluded_entries' => (int) (clone $base)
                ->whereIn('status', self::EXCLUDED_TOTAL_STATUSES)
                ->count(),
        ];
    }

    /**
     * @param  array{search?: string|null, status?: string|null, type?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function paginate(
        RiderProfile $rider,
        array $filters,
    ): LengthAwarePaginator {
        $this->materializeShippingSettlements((int) $rider->id);
        $page = $this->filteredQuery($rider, $filters)
            ->orderByDesc('earnings.earned_at')
            ->orderByDesc('earnings.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->setCollection($this->presentPage($page->getCollection()));

        return $page;
    }

    /**
     * @param  array{search?: string|null, status?: string|null, type?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function exportQuery(RiderProfile $rider, array $filters): Builder
    {
        $this->materializeShippingSettlements((int) $rider->id);

        return $this->filteredQuery($rider, $filters)
            ->orderByDesc('earnings.earned_at')
            ->orderByDesc('earnings.id');
    }

    /**
     * @return list<string>
     */
    public function csvRow(object $record): array
    {
        $presented = $this->present($record);

        return array_map($this->safeCsvValue(...), [
            Carbon::parse($record->earned_at)->format('Y-m-d H:i:s'),
            $presented['job'],
            $presented['source'],
            $presented['type_label'],
            $presented['amount'],
            (string) $presented['amount_minor'],
            $presented['status_label'],
            $record->posted_at
                ? Carbon::parse($record->posted_at)->format('Y-m-d H:i:s')
                : '',
            $record->paid_at
                ? Carbon::parse($record->paid_at)->format('Y-m-d H:i:s')
                : '',
            (string) ($record->description ?? ''),
            $presented['pickup_assignment_id'],
            $presented['delivery_attempt_id'],
            $presented['payout_method'],
            $presented['payout_reference'],
            $presented['payout_paid_on'],
            $presented['payout_reason'],
        ]);
    }

    /**
     * @return list<string>
     */
    public function statusOptions(RiderProfile $rider): array
    {
        return DB::table('rider_earnings')
            ->where('rider_profile_id', $rider->id)
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->map(static fn (mixed $status): string => (string) $status)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function typeOptions(RiderProfile $rider): array
    {
        return DB::table('rider_earnings')
            ->where('rider_profile_id', $rider->id)
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->map(static fn (mixed $type): string => (string) $type)
            ->values()
            ->all();
    }

    private function periodSummary(
        Builder $base,
        Carbon $start,
        Carbon $end,
    ): array {
        $query = $this->activeQuery($base)
            ->whereBetween('earned_at', [$start, $end]);
        $amountMinor = (int) $query->sum('amount_minor');

        return [
            'amount_minor' => $amountMinor,
            'amount' => $this->formatMinor($amountMinor),
            'entries' => (int) $query->count(),
        ];
    }

    private function activeQuery(Builder $query): Builder
    {
        return (clone $query)->whereNotIn(
            'status',
            self::EXCLUDED_TOTAL_STATUSES,
        );
    }

    /**
     * @param  array{search?: string|null, status?: string|null, type?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    private function filteredQuery(
        RiderProfile $rider,
        array $filters,
    ): Builder {
        $query = DB::table('rider_earnings as earnings')
            ->leftJoin('pickup_assignments as assignments', function ($join): void {
                $join
                    ->on('assignments.id', '=', 'earnings.pickup_assignment_id')
                    ->whereColumn(
                        'assignments.rider_profile_id',
                        'earnings.rider_profile_id',
                    );
            })
            ->leftJoin(
                'pickup_requests as pickup_requests',
                'pickup_requests.id',
                '=',
                'assignments.pickup_request_id',
            )
            ->leftJoin('delivery_attempts as attempts', function ($join): void {
                $join
                    ->on('attempts.id', '=', 'earnings.delivery_attempt_id')
                    ->whereColumn(
                        'attempts.rider_profile_id',
                        'earnings.rider_profile_id',
                    );
            })
            ->leftJoin('parcels', 'parcels.id', '=', 'attempts.parcel_id')
            ->leftJoin('shipments', 'shipments.id', '=', 'parcels.shipment_id')
            ->leftJoin('shipping_settlements as linked_settlements', 'linked_settlements.id', '=', 'earnings.shipping_settlement_id')
            ->leftJoin('seller_orders as settlement_orders', 'settlement_orders.id', '=', 'linked_settlements.seller_order_id')
            ->leftJoin('rider_earning_payouts as payouts', 'payouts.rider_earning_id', '=', 'earnings.id')
            ->where('earnings.rider_profile_id', $rider->id)
            ->select([
                'earnings.id as earning_id',
                'earnings.type',
                'earnings.amount_minor',
                'earnings.status',
                'earnings.description',
                'earnings.earned_at',
                'earnings.posted_at',
                'earnings.paid_at',
                'settlement_orders.completed_at as seller_order_completed_at',
                'payouts.method as payout_method',
                'payouts.reference as payout_reference',
                'payouts.paid_on as payout_paid_on',
                'payouts.amount_minor as payout_amount_minor',
                'payouts.reason as payout_reason',
                'earnings.pickup_assignment_id',
                'earnings.delivery_attempt_id',
                'assignments.id as linked_pickup_assignment_id',
                'pickup_requests.pickup_no',
                'attempts.id as linked_delivery_attempt_id',
                'shipments.shipment_no',
            ]);

        if (filled($filters['search'] ?? null)) {
            $search = '%'.trim((string) $filters['search']).'%';

            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('pickup_requests.pickup_no', 'like', $search)
                    ->orWhere('shipments.shipment_no', 'like', $search)
                    ->orWhere('earnings.type', 'like', $search)
                    ->orWhere('earnings.status', 'like', $search)
                    ->orWhere('earnings.description', 'like', $search);
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('earnings.status', $filters['status']);
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('earnings.type', $filters['type']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('earnings.earned_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('earnings.earned_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    private function presentPage(Collection $records): Collection
    {
        return $records->map(fn (object $record): array => $this->present($record));
    }

    /**
     * @return array{
     *     id: int,
     *     earned_at: string,
     *     earned_at_iso: string,
     *     job: string,
     *     source: string,
     *     type: string,
     *     type_label: string,
     *     amount_minor: int,
     *     amount: string,
     *     status: string,
     *     status_label: string,
     *     posted_at: string,
     *     paid_at: string,
     *     description: string,
     *     pickup_assignment_id: string,
     *     delivery_attempt_id: string,
     * }
     */
    private function present(object $record): array
    {
        $earnedAt = Carbon::parse($record->earned_at);
        $pickupAssignmentId = $record->linked_pickup_assignment_id
            ? (int) $record->linked_pickup_assignment_id
            : null;
        $deliveryAttemptId = $record->linked_delivery_attempt_id
            ? (int) $record->linked_delivery_attempt_id
            : null;
        $job = filled($record->pickup_no)
            ? (string) $record->pickup_no
            : (filled($record->shipment_no)
                ? (string) $record->shipment_no
                : 'Ledger entry #'.(int) $record->earning_id);
        $source = $pickupAssignmentId !== null
            ? 'Pickup assignment #'.$pickupAssignmentId
            : ($deliveryAttemptId !== null
                ? 'Delivery attempt #'.$deliveryAttemptId
                : 'No linked pickup or delivery record');
        $returnWindowEndedAt = $record->type === 'shipping_delivery' && $record->seller_order_completed_at
            ? Carbon::parse($record->seller_order_completed_at)
                ->addDays(ReturnRefundService::RETURN_WINDOW_DAYS)
                ->endOfDay()
                ->format('M j, Y')
            : '';

        return [
            'id' => (int) $record->earning_id,
            'earned_at' => $earnedAt->format('M j, Y · g:i A'),
            'earned_at_iso' => $earnedAt->toIso8601String(),
            'job' => $job,
            'source' => $source,
            'type' => (string) $record->type,
            'type_label' => $this->label((string) $record->type),
            'amount_minor' => (int) $record->amount_minor,
            'amount' => $this->formatMinor((int) $record->amount_minor),
            'status' => (string) $record->status,
            'status_label' => $this->label((string) $record->status),
            'posted_at' => $this->formatDate($record->posted_at),
            'paid_at' => $this->formatDate($record->paid_at),
            'payout_method' => (string) ($record->payout_method ?? ''),
            'payout_reference' => (string) ($record->payout_reference ?? ''),
            'payout_paid_on' => $record->payout_paid_on
                ? Carbon::parse($record->payout_paid_on)->format('M j, Y')
                : '',
            'payout_amount_minor' => $record->payout_amount_minor === null ? null : (int) $record->payout_amount_minor,
            'payout_amount' => $record->payout_amount_minor === null
                ? ''
                : $this->formatMinor((int) $record->payout_amount_minor),
            'payout_reason' => (string) ($record->payout_reason ?? ''),
            'description' => (string) ($record->description ?? ''),
            'return_window_ended_at' => $returnWindowEndedAt,
            'pickup_assignment_id' => $pickupAssignmentId === null
                ? ''
                : (string) $pickupAssignmentId,
            'delivery_attempt_id' => $deliveryAttemptId === null
                ? ''
                : (string) $deliveryAttemptId,
        ];
    }

    private function formatDate(mixed $date): string
    {
        return $date
            ? Carbon::parse($date)->format('M j, Y · g:i A')
            : '—';
    }

    private function label(string $value): string
    {
        return Str::headline(str_replace('_', ' ', $value));
    }

    private function formatMinor(int $amountMinor): string
    {
        $sign = $amountMinor < 0 ? '−' : '';
        $absolute = abs($amountMinor);
        $whole = intdiv($absolute, 100);
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $sign.'₱'.number_format($whole).'.'.$fraction;
    }

    private function safeCsvValue(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    private function materializeShippingSettlements(int $riderProfileId): void
    {
        if (isset($this->shippingSettlementsMaterializedForRider[$riderProfileId])) {
            return;
        }

        $this->shippingSettlements->materializeEligibleSettlements(riderProfileId: $riderProfileId);
        $this->shippingSettlementsMaterializedForRider[$riderProfileId] = true;
    }
}
