<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\ReturnItem;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\Shipment;
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
     *     courier_daily_minor: int,
     *     courier_daily: string,
     *     courier_daily_days: int,
     * }
     */
    public function summary(RiderProfile $rider): array
    {
        $this->materializeShippingSettlements((int) $rider->id);
        $now = now();
        $courierNow = now(CourierDailyEarningService::TIMEZONE);
        $base = DB::table('rider_earnings')
            ->where('rider_profile_id', $rider->id);

        $today = $this->periodSummary(
            $base,
            $now->copy()->startOfDay(),
            $now->copy()->endOfDay(),
            $courierNow->copy()->startOfDay(),
            $courierNow->copy()->endOfDay(),
        );
        $week = $this->periodSummary(
            $base,
            $now->copy()->startOfWeek(),
            $now->copy()->endOfWeek(),
            $courierNow->copy()->startOfWeek(),
            $courierNow->copy()->endOfWeek(),
        );
        $month = $this->periodSummary(
            $base,
            $now->copy()->startOfMonth(),
            $now->copy()->endOfMonth(),
            $courierNow->copy()->startOfMonth(),
            $courierNow->copy()->endOfMonth(),
        );

        $active = $this->activeQuery($base);
        $activeTotalMinor = (int) $active->sum('amount_minor');
        $courierDailyType = (string) config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily');
        $courierDaily = (clone $active)->where('type', $courierDailyType);
        $courierDailyMinor = (int) $courierDaily->sum('amount_minor');

        $pickupType = config('bearly_rider_compensation.pickup_earning_type', 'pickup_parcel');
        $pickupBase = $this->activeQuery($base)->where('type', $pickupType);
        $pickupTotalMinor = (int) $pickupBase->sum('amount_minor');
        $pickupCompletedCount = (int) (clone $pickupBase)
            ->whereNotNull('parcel_id')
            ->distinct()
            ->count('parcel_id');
        $pickupHeldMinor = (int) (clone $base)
            ->where('type', $pickupType)
            ->where('status', RiderPickupEarningService::STATUS_HELD)
            ->sum('amount_minor');
        $pickupHeldEntries = (int) (clone $base)
            ->where('type', $pickupType)
            ->where('status', RiderPickupEarningService::STATUS_HELD)
            ->count();

        $rates = app(RiderPickupEarningService::class)->rates();
        $bulkyClass = $rates['bulky_class'];
        $pickupBulkyMinor = (int) (clone $pickupBase)
            ->where('size_class_snapshot', $bulkyClass)
            ->sum('amount_minor');
        $pickupRegularMinor = max(0, $pickupTotalMinor - $pickupBulkyMinor);

        $deliveryBase = $this->activeQuery($base)
            ->whereIn('type', ['shipping_delivery', 'return_shipping_delivery', 'delivery']);
        $deliveryTotalMinor = (int) $deliveryBase->sum('amount_minor');
        $deliveryPendingMinor = (int) (clone $deliveryBase)
            ->whereIn('status', ['pending', 'held'])
            ->sum('amount_minor');

        $payoutEligibleMinor = (int) (clone $base)
            ->where('status', 'posted')
            ->whereNull('paid_at')
            ->sum('amount_minor');
        $payoutEligibleEntries = (int) (clone $base)
            ->where('status', 'posted')
            ->whereNull('paid_at')
            ->count();
        $payoutPaidMinor = (int) (clone $base)
            ->where('status', 'paid')
            ->sum('amount_minor');

        $payoutStatus = match (true) {
            $pickupHeldEntries > 0 => 'Review required',
            $payoutEligibleEntries > 0 => 'Ready for payout',
            $deliveryPendingMinor > 0 => 'Pending eligibility',
            $payoutPaidMinor > 0 => 'Paid',
            default => 'No payout due',
        };

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

            'pickup_completed_parcels' => $pickupCompletedCount,
            'pickup_total_minor' => $pickupTotalMinor,
            'pickup_total' => $this->formatMinor($pickupTotalMinor),
            'pickup_regular_minor' => $pickupRegularMinor,
            'pickup_regular' => $this->formatMinor($pickupRegularMinor),
            'pickup_bulky_minor' => $pickupBulkyMinor,
            'pickup_bulky' => $this->formatMinor($pickupBulkyMinor),
            'pickup_held_minor' => $pickupHeldMinor,
            'pickup_held' => $this->formatMinor($pickupHeldMinor),
            'pickup_held_entries' => $pickupHeldEntries,

            'delivery_total_minor' => $deliveryTotalMinor,
            'delivery_total' => $this->formatMinor($deliveryTotalMinor),
            'delivery_pending_minor' => $deliveryPendingMinor,
            'delivery_pending' => $this->formatMinor($deliveryPendingMinor),

            'courier_daily_minor' => $courierDailyMinor,
            'courier_daily' => $this->formatMinor($courierDailyMinor),
            'courier_daily_days' => (int) (clone $courierDaily)->count(),

            'combined_total_minor' => $pickupTotalMinor + $deliveryTotalMinor + $courierDailyMinor,
            'combined_total' => $this->formatMinor($pickupTotalMinor + $deliveryTotalMinor + $courierDailyMinor),

            'payout_eligible_minor' => $payoutEligibleMinor,
            'payout_eligible' => $this->formatMinor($payoutEligibleMinor),
            'payout_eligible_entries' => $payoutEligibleEntries,
            'payout_paid_minor' => $payoutPaidMinor,
            'payout_paid' => $this->formatMinor($payoutPaidMinor),
            'payout_status' => $payoutStatus,
        ];
    }

    /**
     * @param  array{search?: string|null, status?: string|null, type?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function paginate(
        RiderProfile $rider,
        array $filters,
        BuyerCatalogService $catalog,
    ): LengthAwarePaginator {
        $this->materializeShippingSettlements((int) $rider->id);
        $page = $this->filteredQuery($rider, $filters)
            ->orderByDesc('earnings.earned_at')
            ->orderByDesc('earnings.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->setCollection($this->presentPage($page->getCollection(), $catalog));

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

    public function shippingEarningsStatusVersion(RiderProfile $rider): string
    {
        $this->materializeShippingSettlements((int) $rider->id);

        return $this->shippingSettlements->riderEarningStatusVersionForRider($rider);
    }

    /**
     * @return list<string>
     */
    public function csvRow(object $record): array
    {
        $presented = $this->present($record);

        return array_map($this->safeCsvValue(...), [
            Carbon::parse($record->earned_at)->format('Y-m-d H:i:s'),
            $presented['work_date'],
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
        Carbon $workDateStart,
        Carbon $workDateEnd,
    ): array {
        $courierDailyType = (string) config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily');
        $query = $this->activeQuery($base)
            ->where(function (Builder $dateQuery) use ($start, $end, $workDateStart, $workDateEnd, $courierDailyType): void {
                $dateQuery
                    ->where(function (Builder $courierQuery) use ($workDateStart, $workDateEnd, $courierDailyType): void {
                        $courierQuery
                            ->where('type', $courierDailyType)
                            ->whereNotNull('work_date')
                            ->whereBetween('work_date', [$workDateStart->toDateString(), $workDateEnd->toDateString()]);
                    })
                    ->orWhere(function (Builder $eventQuery) use ($start, $end, $courierDailyType): void {
                        $eventQuery
                            ->where('type', '<>', $courierDailyType)
                            ->whereBetween('earned_at', [$start, $end]);
                    });
            });
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
            ->leftJoin('seller_orders as earning_seller_orders', 'earning_seller_orders.id', '=', 'shipments.seller_order_id')
            ->leftJoin('orders as earning_orders', 'earning_orders.id', '=', 'earning_seller_orders.order_id')
            ->leftJoin('shipping_settlements as linked_settlements', 'linked_settlements.id', '=', 'earnings.shipping_settlement_id')
            ->leftJoin('seller_orders as settlement_orders', 'settlement_orders.id', '=', 'linked_settlements.seller_order_id')
            ->leftJoin('rider_earning_payouts as payouts', 'payouts.rider_earning_id', '=', 'earnings.id')
            ->leftJoin('rider_shifts as earning_shifts', 'earning_shifts.id', '=', 'earnings.rider_shift_id')
            ->leftJoin('rider_payout_batch_items as payout_batch_items', 'payout_batch_items.rider_earning_id', '=', 'earnings.id')
            ->leftJoin('rider_payout_batches as payout_batches', 'payout_batches.id', '=', 'payout_batch_items.rider_payout_batch_id')
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
                'earnings.size_class_snapshot',
                'earnings.rate_minor',
                'earnings.worked_seconds_snapshot',
                'earnings.daily_cap_minor_snapshot',
                'earnings.rider_shift_id',
                'earning_shifts.checked_in_at as shift_checked_in_at',
                'earning_shifts.checked_out_at as shift_checked_out_at',
                'payout_batches.batch_no as payout_batch_no',
                'settlement_orders.completed_at as seller_order_completed_at',
                'payouts.method as payout_method',
                'payouts.reference as payout_reference',
                'payouts.paid_on as payout_paid_on',
                'payouts.amount_minor as payout_amount_minor',
                'payouts.reason as payout_reason',
                'earnings.pickup_assignment_id',
                'earnings.delivery_attempt_id',
                'earnings.work_date',
                'earnings.linehaul_run_id',
                'assignments.id as linked_pickup_assignment_id',
                'pickup_requests.pickup_no',
                'attempts.id as linked_delivery_attempt_id',
                'shipments.id as linked_shipment_id',
                'shipments.shipment_no',
                'daily_runs.run_no as linehaul_run_no',
                'earning_orders.order_no as linked_order_no',
            ])
            ->leftJoin('linehaul_runs as daily_runs', 'daily_runs.id', '=', 'earnings.linehaul_run_id');

        if (filled($filters['search'] ?? null)) {
            $search = '%'.trim((string) $filters['search']).'%';

            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('pickup_requests.pickup_no', 'like', $search)
                    ->orWhere('shipments.shipment_no', 'like', $search)
                    ->orWhere('daily_runs.run_no', 'like', $search)
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
            $this->whereEarningDate($query, '>=', (string) $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $this->whereEarningDate($query, '<=', (string) $filters['date_to']);
        }

        return $query;
    }

    private function whereEarningDate(Builder $query, string $operator, string $date): void
    {
        $courierDailyType = (string) config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily');

        $query->where(function (Builder $dateQuery) use ($operator, $date, $courierDailyType): void {
            $dateQuery
                ->where(function (Builder $courierQuery) use ($operator, $date, $courierDailyType): void {
                    $courierQuery
                        ->where('earnings.type', $courierDailyType)
                        ->whereNotNull('earnings.work_date')
                        ->whereDate('earnings.work_date', $operator, $date);
                })
                ->orWhere(function (Builder $eventQuery) use ($operator, $date, $courierDailyType): void {
                    $eventQuery
                        ->where('earnings.type', '<>', $courierDailyType)
                        ->whereDate('earnings.earned_at', $operator, $date);
                });
        });
    }

    private function presentPage(Collection $records, BuyerCatalogService $catalog): Collection
    {
        $shipmentIds = $records
            ->pluck('linked_shipment_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $productsByShipment = $shipmentIds->isEmpty()
            ? collect()
            : Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->with([
                    'sellerOrder.items.product.images.variant',
                    'sellerOrder.items.variant',
                    'sellerOrders.items.product.images.variant',
                    'sellerOrders.items.variant',
                    'returnRequest.items.orderItem.product.images.variant',
                    'returnRequest.items.orderItem.variant',
                ])
                ->get()
                ->mapWithKeys(function (Shipment $shipment) use ($catalog): array {
                    $products = $shipment->purpose === 'return'
                        ? ($shipment->returnRequest?->items ?? collect())
                            ->map(fn (ReturnItem $returnItem): ?array => $returnItem->orderItem
                                ? $this->productPreview($returnItem->orderItem, (int) $returnItem->quantity, $catalog)
                                : null)
                            ->filter()
                            ->values()
                            ->all()
                        : $shipment->includedOrderItems()
                            ->map(fn (OrderItem $item): array => $this->productPreview($item, (int) $item->quantity, $catalog))
                            ->values()
                            ->all();

                    return [(int) $shipment->id => $products];
                });

        return $records->map(fn (object $record): array => $this->present(
            $record,
            $productsByShipment->get((int) ($record->linked_shipment_id ?? 0), []),
        ));
    }

    /** @return array{name: string, variant: string, quantity: int, image_url: string} */
    private function productPreview(OrderItem $item, int $quantity, BuyerCatalogService $catalog): array
    {
        return [
            'name' => trim((string) $item->product_name) ?: 'Purchased item',
            'variant' => trim((string) $item->variant_name),
            'quantity' => $quantity,
            'image_url' => $catalog->orderItemImageUrl($item),
        ];
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
     *     order_no: string,
     *     products: list<array{name: string, variant: string, quantity: int, image_url: string}>,
     * }
     */
    private function present(object $record, array $products = []): array
    {
        $earnedAt = Carbon::parse($record->earned_at);
        $pickupAssignmentId = $record->linked_pickup_assignment_id
            ? (int) $record->linked_pickup_assignment_id
            : null;
        $deliveryAttemptId = $record->linked_delivery_attempt_id
            ? (int) $record->linked_delivery_attempt_id
            : null;
        $linehaulRunNo = (string) ($record->linehaul_run_no ?? '');
        $workDate = $record->work_date
            ? Carbon::parse($record->work_date)
            : null;
        $job = filled($record->pickup_no)
            ? (string) $record->pickup_no
            : (filled($linehaulRunNo)
                ? $linehaulRunNo
                : (filled($record->shipment_no)
                    ? (string) $record->shipment_no
                    : 'Ledger entry #'.(int) $record->earning_id));
        $source = $pickupAssignmentId !== null
            ? 'Pickup assignment #'.$pickupAssignmentId
            : (filled($linehaulRunNo)
                ? 'Courier line-haul run '.$linehaulRunNo
                : ($deliveryAttemptId !== null
                    ? 'Delivery attempt #'.$deliveryAttemptId
                    : 'No linked pickup or delivery record'));
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
            'work_date' => $workDate?->format('M j, Y') ?? '',
            'job' => $job,
            'order_no' => (string) ($record->linked_order_no ?? ''),
            'products' => $products,
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
            'size_class' => (string) ($record->size_class_snapshot ?? ''),
            'rate_minor' => $record->rate_minor === null ? null : (int) $record->rate_minor,
            'worked_seconds' => $record->worked_seconds_snapshot === null
                ? null
                : (int) $record->worked_seconds_snapshot,
            'daily_cap_minor' => $record->daily_cap_minor_snapshot === null
                ? null
                : (int) $record->daily_cap_minor_snapshot,
            'shift_label' => $record->shift_checked_in_at
                ? 'Shift '.Carbon::parse($record->shift_checked_in_at)->timezone(RiderShift::TIMEZONE)->format('M j, Y · g:i A').' '.RiderShift::TIMEZONE_LABEL
                    .($record->shift_checked_out_at
                        ? '–'.Carbon::parse($record->shift_checked_out_at)->timezone(RiderShift::TIMEZONE)->format('g:i A').' '.RiderShift::TIMEZONE_LABEL
                        : ' (open)')
                : '',
            'rider_shift_id' => $record->rider_shift_id === null
                ? ''
                : (string) $record->rider_shift_id,
            'linehaul_run_id' => $record->linehaul_run_id === null
                ? ''
                : (string) $record->linehaul_run_id,
            'linehaul_run_no' => $linehaulRunNo,
            'work_date_label' => $workDate?->format('M j, Y') ?? '',
            'payout_batch_no' => (string) ($record->payout_batch_no ?? ''),
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
