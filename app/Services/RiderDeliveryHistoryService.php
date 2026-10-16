<?php

namespace App\Services;

use App\Models\RiderProfile;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RiderDeliveryHistoryService
{
    private const PER_PAGE = 20;

    /**
     * @param  array{search?: string|null, outcome?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function summary(RiderProfile $rider): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $monthlyAttempts = DB::table('delivery_attempts as attempts')
            ->join('parcels', 'parcels.id', '=', 'attempts.parcel_id')
            ->join('shipments', 'shipments.id', '=', 'parcels.shipment_id')
            ->where('attempts.rider_profile_id', $rider->id)
            ->where('shipments.purpose', 'outbound')
            ->whereBetween('attempts.attempted_at', [$monthStart, $monthEnd]);

        $completedThisMonth = (clone $monthlyAttempts)
            ->where('attempts.outcome', 'delivered')
            ->count();

        $outboundAttemptsThisMonth = (clone $monthlyAttempts)->count();

        $failedAttemptsThisMonth = DB::table('delivery_attempts')
            ->where('rider_profile_id', $rider->id)
            ->where('outcome', 'failed')
            ->whereBetween('attempted_at', [$monthStart, $monthEnd])
            ->count();

        $returned = DB::table('delivery_attempts as attempts')
            ->join('parcels', 'parcels.id', '=', 'attempts.parcel_id')
            ->join('shipments', 'shipments.id', '=', 'parcels.shipment_id')
            ->where('attempts.rider_profile_id', $rider->id)
            ->where('attempts.outcome', 'delivered')
            ->where('shipments.purpose', 'return')
            ->count();

        $cancelled = $this->cancelledRowsQuery((int) $rider->id)->count();

        return [
            'completed_this_month' => $completedThisMonth,
            'failed_attempts_this_month' => $failedAttemptsThisMonth,
            'return_deliveries' => $returned,
            'cancelled_assignments' => $cancelled,
            'delivery_rate' => $outboundAttemptsThisMonth > 0
                ? number_format(($completedThisMonth / $outboundAttemptsThisMonth) * 100, 1).'%'
                : '—',
            'delivery_rate_description' => $outboundAttemptsThisMonth > 0
                ? 'Successful outbound attempts this month'
                : 'No outbound attempts this month',
        ];
    }

    /**
     * @param  array{search?: string|null, outcome?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function paginate(
        RiderProfile $rider,
        array $filters,
        BuyerCatalogService $catalog,
    ): LengthAwarePaginator
    {
        $page = $this->filteredQuery($rider, $filters)
            ->orderByDesc('occurred_at')
            ->orderByDesc('record_id')
            ->orderByDesc('parcel_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->setCollection($this->presentPage($page->getCollection(), $catalog));

        return $page;
    }

    /**
     * @param  array{search?: string|null, outcome?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function exportQuery(RiderProfile $rider, array $filters): Builder
    {
        return $this->filteredQuery($rider, $filters)
            ->orderByDesc('occurred_at')
            ->orderByDesc('record_id')
            ->orderByDesc('parcel_id');
    }

    /**
     * @return list<string>
     */
    public function csvRow(object $record): array
    {
        $outcome = $this->statusLabel((string) $record->history_status);
        $earning = $record->earning_minor === null
            ? 'Not recorded'
            : $this->formatMinor((int) $record->earning_minor);

        return array_map($this->safeCsvValue(...), [
            Carbon::parse($record->occurred_at)->format('Y-m-d H:i:s'),
            (string) $record->shipment_no,
            (string) $record->parcel_no,
            (string) $record->recipient_name,
            (string) $record->service_area,
            $outcome,
            $earning,
            (string) ($record->batch_no ?? ''),
            $record->attempt_no === null ? '' : (string) $record->attempt_no,
        ]);
    }

    /**
     * @param  array{search?: string|null, outcome?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    private function filteredQuery(RiderProfile $rider, array $filters): Builder
    {
        $query = DB::query()->fromSub(
            $this->attemptRowsQuery((int) $rider->id)
                ->unionAll($this->cancelledRowsQuery((int) $rider->id)),
            'rider_history'
        );

        if (filled($filters['search'] ?? null)) {
            $search = '%'.trim((string) $filters['search']).'%';

            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('shipment_no', 'like', $search)
                    ->orWhere('parcel_no', 'like', $search)
                    ->orWhere('recipient_name', 'like', $search)
                    ->orWhere('service_area', 'like', $search)
                    ->orWhere('batch_no', 'like', $search);
            });
        }

        if (filled($filters['outcome'] ?? null)) {
            $query->where('history_status', $filters['outcome']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('occurred_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('occurred_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    private function attemptRowsQuery(int $riderProfileId): Builder
    {
        $earningTotals = DB::table('rider_earnings')
            ->select('delivery_attempt_id')
            ->selectRaw('SUM(amount_minor) as earning_minor')
            ->where('rider_profile_id', $riderProfileId)
            ->whereNotNull('delivery_attempt_id')
            ->whereNotIn('status', ['reversed', 'cancelled'])
            ->groupBy('delivery_attempt_id');

        return DB::table('delivery_attempts as attempts')
            ->join('parcels', 'parcels.id', '=', 'attempts.parcel_id')
            ->join('shipments', 'shipments.id', '=', 'parcels.shipment_id')
            ->join('seller_orders', 'seller_orders.id', '=', 'shipments.seller_order_id')
            ->leftJoin('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->leftJoin('stores', 'stores.id', '=', 'seller_orders.store_id')
            ->leftJoin('addresses as destination_addresses', 'destination_addresses.id', '=', 'shipments.destination_address_id')
            ->leftJoin('dispatch_batches as batches', 'batches.id', '=', 'attempts.dispatch_batch_id')
            ->leftJoin('sorting_zones as zones', 'zones.id', '=', 'batches.sorting_zone_id')
            ->leftJoinSub(
                $earningTotals,
                'earning_totals',
                fn ($join) => $join->on('earning_totals.delivery_attempt_id', '=', 'attempts.id')
            )
            ->where('attempts.rider_profile_id', $riderProfileId)
            ->select([
                DB::raw("'attempt' as record_type"),
                'attempts.id as record_id',
                'attempts.id as attempt_id',
                'attempts.dispatch_batch_id as batch_id',
                'parcels.id as parcel_id',
                'attempts.attempted_at as occurred_at',
                'attempts.attempt_no',
                'parcels.parcel_no',
                'shipments.id as shipment_id',
                'shipments.shipment_no',
                'shipments.purpose',
                'batches.batch_no',
                'attempts.failure_reason',
                'earning_totals.earning_minor',
            ])
            ->selectRaw("CASE WHEN attempts.outcome = 'delivered' AND shipments.purpose = 'return' THEN 'returned' WHEN attempts.outcome = 'delivered' THEN 'delivered' ELSE attempts.outcome END as history_status")
            ->selectRaw("CASE WHEN shipments.purpose = 'return' THEN COALESCE(NULLIF(stores.name, ''), 'Seller') ELSE COALESCE(NULLIF(orders.recipient_name, ''), 'Recipient') END as recipient_name")
            ->selectRaw("COALESCE(NULLIF(zones.name, ''), CASE WHEN shipments.purpose = 'return' THEN COALESCE(NULLIF(destination_addresses.city_municipality, ''), NULLIF(destination_addresses.province, '')) ELSE COALESCE(NULLIF(orders.city_municipality, ''), NULLIF(orders.province, '')) END, 'Area unavailable') as service_area");
    }

    private function cancelledRowsQuery(int $riderProfileId): Builder
    {
        return DB::table('dispatch_batches as batches')
            ->join('dispatch_batch_parcels', 'dispatch_batch_parcels.dispatch_batch_id', '=', 'batches.id')
            ->join('parcels', 'parcels.id', '=', 'dispatch_batch_parcels.parcel_id')
            ->join('shipments', 'shipments.id', '=', 'parcels.shipment_id')
            ->join('seller_orders', 'seller_orders.id', '=', 'shipments.seller_order_id')
            ->leftJoin('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->leftJoin('stores', 'stores.id', '=', 'seller_orders.store_id')
            ->leftJoin('addresses as destination_addresses', 'destination_addresses.id', '=', 'shipments.destination_address_id')
            ->leftJoin('sorting_zones as zones', 'zones.id', '=', 'batches.sorting_zone_id')
            ->where('batches.rider_profile_id', $riderProfileId)
            ->where('batches.status', 'cancelled')
            ->whereNotExists(function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('delivery_attempts as prior_attempts')
                    ->whereColumn('prior_attempts.dispatch_batch_id', 'batches.id')
                    ->whereColumn('prior_attempts.parcel_id', 'parcels.id')
                    ->whereColumn('prior_attempts.rider_profile_id', 'batches.rider_profile_id');
            })
            ->select([
                DB::raw("'cancelled' as record_type"),
                'batches.id as record_id',
                DB::raw('NULL as attempt_id'),
                'batches.id as batch_id',
                'parcels.id as parcel_id',
                DB::raw('COALESCE(batches.cancelled_at, batches.updated_at, batches.created_at) as occurred_at'),
                DB::raw('NULL as attempt_no'),
                'parcels.parcel_no',
                'shipments.id as shipment_id',
                'shipments.shipment_no',
                'shipments.purpose',
                'batches.batch_no',
                DB::raw('NULL as failure_reason'),
                DB::raw('NULL as earning_minor'),
                DB::raw("'cancelled' as history_status"),
            ])
            ->selectRaw("CASE WHEN shipments.purpose = 'return' THEN COALESCE(NULLIF(stores.name, ''), 'Seller') ELSE COALESCE(NULLIF(orders.recipient_name, ''), 'Recipient') END as recipient_name")
            ->selectRaw("COALESCE(NULLIF(zones.name, ''), CASE WHEN shipments.purpose = 'return' THEN COALESCE(NULLIF(destination_addresses.city_municipality, ''), NULLIF(destination_addresses.province, '')) ELSE COALESCE(NULLIF(orders.city_municipality, ''), NULLIF(orders.province, '')) END, 'Area unavailable') as service_area");
    }

    private function presentPage(
        \Illuminate\Support\Collection $records,
        BuyerCatalogService $catalog,
    ): \Illuminate\Support\Collection
    {
        $parcelIds = $records->pluck('parcel_id')->unique()->values();
        $shipmentIds = $records->pluck('shipment_id')->unique()->values();
        $attemptIds = $records
            ->where('record_type', 'attempt')
            ->pluck('attempt_id')
            ->filter()
            ->unique()
            ->values();

        $productsByShipment = $shipmentIds->isEmpty()
            ? collect()
            : Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->with([
                    'sellerOrder.items.product.images.variant',
                    'sellerOrder.items.variant',
                ])
                ->get()
                ->mapWithKeys(function (Shipment $shipment) use ($catalog): array {
                    $products = ($shipment->sellerOrder?->items ?? collect())
                        ->map(fn (OrderItem $item): array => [
                            'name' => $item->product_name ?: 'Unnamed product',
                            'variant' => trim((string) $item->variant_name),
                            'quantity' => (int) $item->quantity,
                            'image_url' => $catalog->orderItemImageUrl($item),
                        ])
                        ->values()
                        ->all();

                    return [$shipment->id => $products];
                });

        $eventsByParcel = $parcelIds->isEmpty()
            ? collect()
            : ShipmentEvent::query()
                ->whereIn('parcel_id', $parcelIds)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get([
                    'id',
                    'parcel_id',
                    'event_type',
                    'from_status',
                    'to_status',
                    'source',
                    'notes',
                    'occurred_at',
                ])
                ->groupBy('parcel_id');

        $proofsByAttempt = $attemptIds->isEmpty()
            ? collect()
            : DB::table('delivery_proofs')
                ->whereIn('delivery_attempt_id', $attemptIds)
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->get([
                    'delivery_attempt_id',
                    'recipient_name',
                ])
                ->groupBy('delivery_attempt_id');

        return $records->map(function (object $record) use ($eventsByParcel, $proofsByAttempt, $productsByShipment): array {
            $timeline = ($eventsByParcel->get($record->parcel_id) ?? collect())
                ->map(function (ShipmentEvent $event): array {
                    $transition = $event->from_status && $event->to_status
                        ? $this->statusLabel($event->from_status).' → '.$this->statusLabel($event->to_status)
                        : null;

                    return [
                        'time' => $event->occurred_at?->format('M j, Y · g:i A') ?? 'Time unavailable',
                        'title' => ucwords(str_replace('_', ' ', $event->event_type)),
                        'detail' => collect([$transition, $event->notes])->filter()->implode(' · '),
                        'source' => filled($event->source) ? ucfirst($event->source) : 'System',
                        'sort_at' => $event->occurred_at?->getTimestamp() ?? 0,
                    ];
                })
                ->values();

            if ($record->record_type === 'cancelled') {
                $timeline->push([
                    'time' => Carbon::parse($record->occurred_at)->format('M j, Y · g:i A'),
                    'title' => 'Dispatch assignment cancelled',
                    'detail' => 'Batch '.($record->batch_no ?: '—').' was cancelled before a Rider delivery attempt.',
                    'source' => 'Logistics',
                    'sort_at' => Carbon::parse($record->occurred_at)->getTimestamp(),
                ]);
            } elseif ($timeline->isEmpty()) {
                $timeline->push([
                    'time' => Carbon::parse($record->occurred_at)->format('M j, Y · g:i A'),
                    'title' => 'Delivery attempt recorded',
                    'detail' => $record->failure_reason ?: $this->statusLabel((string) $record->history_status),
                    'source' => 'Rider',
                    'sort_at' => Carbon::parse($record->occurred_at)->getTimestamp(),
                ]);
            }

            $timeline = $timeline
                ->sortBy('sort_at')
                ->map(fn (array $event): array => [
                    'time' => $event['time'],
                    'title' => $event['title'],
                    'detail' => $event['detail'],
                    'source' => $event['source'],
                ])
                ->values()
                ->all();

            $proofRecipient = $record->attempt_id
                ? ($proofsByAttempt->get($record->attempt_id)?->first()?->recipient_name ?? null)
                : null;

            return [
                'key' => $record->record_type === 'attempt'
                    ? 'attempt-'.$record->attempt_id
                    : 'cancelled-'.$record->batch_id.'-'.$record->parcel_id,
                'date' => Carbon::parse($record->occurred_at)->format('M j, Y'),
                'timestamp' => Carbon::parse($record->occurred_at)->format('M j, Y · g:i A'),
                'id' => (string) $record->shipment_no,
                'parcel_no' => (string) $record->parcel_no,
                'products' => $productsByShipment->get($record->shipment_id, []),
                'customer' => (string) $record->recipient_name,
                'area' => (string) $record->service_area,
                'status' => (string) $record->history_status,
                'status_label' => $this->statusLabel((string) $record->history_status),
                'earning' => $record->earning_minor === null
                    ? 'Not recorded'
                    : $this->formatMinor((int) $record->earning_minor),
                'batch_no' => (string) ($record->batch_no ?? '—'),
                'attempt' => $record->attempt_no === null
                    ? 'No delivery attempt'
                    : 'Attempt '.(int) $record->attempt_no,
                'proof_recipient' => $proofRecipient ?: 'Not recorded',
                'failure_reason' => (string) ($record->failure_reason ?? ''),
                'timeline' => $timeline,
            ];
        });
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'delivered' => 'Delivered',
            'returned' => 'Returned',
            'failed' => 'Failed attempt',
            'cancelled' => 'Canceled',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }

    private function formatMinor(int $amountMinor): string
    {
        return '₱'.number_format($amountMinor / 100, 2);
    }

    private function safeCsvValue(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }
}
