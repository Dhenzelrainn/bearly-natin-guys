<?php

namespace App\Services;

use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\PickupRequest;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Notifications\WorkflowNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerPickupRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SellerFulfillmentService $fulfillment,
    ) {}

    /** @var array<string, array{start: string, end: string, label: string}> */
    public const PICKUP_WINDOWS = [
        '10:00-12:00' => ['start' => '10:00:00', 'end' => '12:00:00', 'label' => '10:00 AM–12:00 PM'],
        '13:00-15:00' => ['start' => '13:00:00', 'end' => '15:00:00', 'label' => '1:00–3:00 PM'],
        '15:00-17:00' => ['start' => '15:00:00', 'end' => '17:00:00', 'label' => '3:00–5:00 PM'],
    ];

    /**
     * Create one Seller pickup request using the authoritative Shipment,
     * Waybill, and Parcel records already created when preparation started.
     * Submitted order IDs and the provider are re-scoped under row locks.
     *
     * @param  list<int>  $sellerOrderIds
     */
    public function create(
        User $actor,
        ?int $logisticsProfileId,
        array $sellerOrderIds,
        string $requestedDate,
        string $pickupWindow,
        ?string $instructions = null,
    ): PickupRequest {
        return DB::transaction(function () use (
            $actor,
            $logisticsProfileId,
            $sellerOrderIds,
            $requestedDate,
            $pickupWindow,
            $instructions,
        ): PickupRequest {
            $storeId = (int) ($actor->sellerProfile?->store?->id ?? 0);

            abort_unless($storeId > 0, 403, 'Your Seller account does not have a Store.');

            $targets = SellerOrder::query()
                ->where('store_id', $storeId)
                ->whereIn('id', $sellerOrderIds)
                ->orderBy('id')
                ->get(['id', 'order_id']);

            abort_unless($targets->count() === count($sellerOrderIds), 404);

            $selectedMemberships = DB::table('shipment_seller_orders')
                ->join('shipments', 'shipments.id', '=', 'shipment_seller_orders.shipment_id')
                ->join('seller_orders', 'seller_orders.id', '=', 'shipment_seller_orders.seller_order_id')
                ->whereIn('shipment_seller_orders.seller_order_id', $sellerOrderIds)
                ->where('shipments.purpose', 'outbound')
                ->orderBy('shipment_seller_orders.seller_order_id')
                ->orderBy('shipments.id')
                ->get([
                    'shipment_seller_orders.shipment_id',
                    'seller_orders.id as seller_order_id',
                    'seller_orders.order_id',
                    'seller_orders.store_id',
                ]);
            $selectedMembershipsByOrder = $selectedMemberships->groupBy('seller_order_id');
            foreach ($sellerOrderIds as $sellerOrderId) {
                if ($selectedMembershipsByOrder->get($sellerOrderId, collect())->count() !== 1) {
                    throw ValidationException::withMessages([
                        'order_ids' => 'Each selected Seller Order must belong to exactly one outbound Shipment.',
                    ]);
                }
            }

            $shipmentIds = $selectedMemberships->pluck('shipment_id')->unique()->sort()->values();
            $membershipStubs = DB::table('shipment_seller_orders')
                ->join('seller_orders', 'seller_orders.id', '=', 'shipment_seller_orders.seller_order_id')
                ->whereIn('shipment_seller_orders.shipment_id', $shipmentIds)
                ->orderBy('shipment_seller_orders.shipment_id')
                ->orderBy('seller_orders.id')
                ->get([
                    'shipment_seller_orders.shipment_id',
                    'seller_orders.id as seller_order_id',
                    'seller_orders.order_id',
                    'seller_orders.store_id',
                ]);
            abort_unless($membershipStubs->isNotEmpty()
                && $membershipStubs->every(fn ($member): bool => (int) $member->store_id === $storeId),
                409,
                'A selected Shipment includes Seller Orders outside this Store.');

            // Match the Seller Order workflow's lock order: marketplace Orders,
            // then every Seller Order in the selected Shipments, then Shipment.
            $orders = Order::query()
                ->whereIn('id', $membershipStubs->pluck('order_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            abort_unless($orders->count() === $membershipStubs->pluck('order_id')->unique()->count(), 409);

            $sellerOrders = SellerOrder::query()
                ->where('store_id', $storeId)
                ->whereIn('id', $membershipStubs->pluck('seller_order_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_unless($sellerOrders->count() === $membershipStubs->pluck('seller_order_id')->unique()->count(), 409);

            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->where('purpose', 'outbound')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            abort_unless($shipments->count() === $shipmentIds->count(), 409);

            $currentMembershipIds = DB::table('shipment_seller_orders')
                ->whereIn('shipment_id', $shipmentIds)
                ->orderBy('shipment_id')
                ->orderBy('seller_order_id')
                ->get(['shipment_id', 'seller_order_id'])
                ->groupBy('shipment_id');

            foreach ($membershipStubs->groupBy('shipment_id') as $shipmentId => $members) {
                $expectedIds = $members->pluck('seller_order_id')->map(fn ($id): int => (int) $id)->values()->all();
                $currentIds = $currentMembershipIds->get($shipmentId, collect())
                    ->pluck('seller_order_id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();
                abort_unless($currentIds === $expectedIds, 409, 'The Shipment membership changed while pickup was being requested.');
            }

            $pickupAddress = $this->fulfillment->pickupAddressFor($actor, lock: true);

            abort_unless(
                $pickupAddress,
                409,
                'Set a valid Seller pickup address before requesting collection.',
            );

            $cityProvider = $this->fulfillment->activeProviderForPickupAddress($pickupAddress, lock: true);
            $provider = $logisticsProfileId !== null
                ? $this->fulfillment->activeProviderById($logisticsProfileId, lock: true)
                : $cityProvider;

            if (! $provider) {
                throw ValidationException::withMessages([
                    'logistics_profile_id' => 'Choose an active Logistics provider before requesting collection.',
                ]);
            }

            foreach ($shipments as $shipment) {
                $memberStubs = $membershipStubs->where('shipment_id', $shipment->id)->values();
                $shipmentSellerOrders = $memberStubs
                    ->map(fn ($member) => $sellerOrders->get($member->seller_order_id))
                    ->filter()
                    ->values();

                abort_unless($shipmentSellerOrders->count() === $memberStubs->count(), 409);

                foreach ($shipmentSellerOrders as $sellerOrder) {
                    $order = $orders->get($sellerOrder->order_id);
                    abort_unless($order, 404);
                    $sellerOrder->setRelation('order', $order);

                    abort_unless(
                        $sellerOrder->status === 'ready_for_pickup'
                            && $order->status !== 'cancelled',
                        409,
                        "Seller Order {$sellerOrder->seller_order_no} is not ready for pickup.",
                    );
                }

                abort_unless(
                    $shipment->status === ShipmentStatus::ReadyForPickup->value,
                    409,
                    'A selected Shipment is not ready for pickup.',
                );

                $waybill = Waybill::query()
                    ->where('shipment_id', $shipment->id)
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $waybill,
                    409,
                    'A selected Shipment is missing its Waybill. Contact support.',
                );
                abort_if(
                    $waybill->voided_at || $waybill->status === 'voided',
                    409,
                    'A selected Shipment has a voided Waybill. Contact support.',
                );
                abort_unless(
                    $waybill->printed_at,
                    409,
                    'Print and confirm every selected Waybill in Fulfillment → Waybills before requesting pickup.',
                );

                $parcels = $shipment->parcels()
                    ->orderBy('piece_sequence')
                    ->lockForUpdate()
                    ->get();

                abort_unless(
                    $parcels->isNotEmpty()
                        && $parcels->count() === (int) $waybill->piece_count
                        && $parcels->every(fn ($parcel): bool => (int) $parcel->waybill_id === (int) $waybill->id)
                        && $parcels->pluck('piece_sequence')->map(fn ($sequence): int => (int) $sequence)->all() === range(1, (int) $waybill->piece_count),
                    409,
                    'A selected Waybill piece count or parcel record does not match. Contact support.',
                );

                $rejectedRequests = PickupRequest::query()
                    ->where('store_id', $storeId)
                    ->where('status', 'cancelled')
                    ->whereNotNull('cancellation_reason')
                    ->whereHas('parcels', fn ($query) => $query
                        ->whereIn('parcels.id', $parcels->pluck('id')))
                    ->with(['parcels' => fn ($query) => $query
                        ->whereIn('parcels.id', $parcels->pluck('id'))])
                    ->lockForUpdate()
                    ->get();
                $shipmentParcelIds = $parcels->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values();
                $rejectedProviderIds = $rejectedRequests
                    ->pluck('logistics_profile_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values();
                $rejectedCurrentProviderRequest = $rejectedRequests->first(
                    fn (PickupRequest $rejectedRequest): bool => (int) $rejectedRequest->logistics_profile_id === (int) $shipment->logistics_profile_id
                        && $rejectedRequest->parcels->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all() === $shipmentParcelIds->all(),
                );

                if ((int) $provider->id !== (int) $shipment->logistics_profile_id) {
                    if (! $rejectedCurrentProviderRequest) {
                        throw ValidationException::withMessages([
                            'logistics_profile_id' => 'A Shipment can be reassigned only after its current Logistics provider rejects the pickup request.',
                        ]);
                    }
                    abort_if(
                        $rejectedProviderIds->contains((int) $provider->id),
                        409,
                        'Choose a different active provider; this provider has already rejected this Shipment.',
                    );
                    abort_unless(
                        ($cityProvider && (int) $cityProvider->id === (int) $provider->id)
                            || $this->fulfillment->isNearbyProviderForPickupAddress($pickupAddress, $provider),
                        422,
                        'Choose an active nearby Logistics provider from the current recommendations.',
                    );

                    $previousProviderId = (int) $shipment->logistics_profile_id;
                    $shipment->update(['logistics_profile_id' => $provider->id]);
                    $shipment->events()->create([
                        'parcel_id' => $parcels->first()?->id,
                        'event_type' => 'shipment_logistics_provider_reassigned',
                        'from_status' => $shipment->status,
                        'to_status' => $shipment->status,
                        'actor_user_id' => $actor->id,
                        'source' => 'seller',
                        'notes' => 'Seller selected a new Logistics provider after the previous provider rejected pickup.',
                        'metadata' => [
                            'from_logistics_profile_id' => $previousProviderId,
                            'to_logistics_profile_id' => (int) $provider->id,
                            'rejected_pickup_request_id' => (int) $rejectedCurrentProviderRequest->id,
                            'waybill_no' => (string) $waybill->waybill_no,
                        ],
                        'occurred_at' => now(),
                    ]);
                } else {
                    abort_if(
                        $rejectedProviderIds->contains((int) $provider->id),
                        409,
                        'Choose another active Logistics provider after a rejection.',
                    );
                }

                $hasActivePickup = PickupRequest::query()
                    ->whereIn('status', ['requested', 'verified', 'scheduled'])
                    ->whereHas('parcels', fn ($query) => $query
                        ->whereIn('parcels.id', $parcels->pluck('id')))
                    ->lockForUpdate()
                    ->exists();

                abort_if(
                    $hasActivePickup,
                    409,
                    'A selected Shipment is already included in an active pickup request.',
                );

            }

            $window = self::PICKUP_WINDOWS[$pickupWindow] ?? null;

            if (! $window) {
                throw ValidationException::withMessages([
                    'pickup_window' => 'Choose one of the available pickup windows.',
                ]);
            }

            $timezone = (string) config('app.timezone');
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate, $timezone);

            if (! $date) {
                throw ValidationException::withMessages([
                    'requested_date' => 'Choose a valid pickup date.',
                ]);
            }

            $windowStart = $date->setTimeFromTimeString($window['start']);
            $windowEnd = $date->setTimeFromTimeString($window['end']);

            if ($windowStart->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
                throw ValidationException::withMessages([
                    'pickup_window' => 'Choose a pickup window that has not already passed.',
                ]);
            }

            $now = now();
            $pickupRequest = PickupRequest::query()->create([
                'pickup_no' => $this->number('PU'),
                'store_id' => $storeId,
                'logistics_profile_id' => $provider->id,
                'pickup_address_id' => $pickupAddress->id,
                'status' => 'requested',
                'requested_date' => $date->toDateString(),
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'seller_instructions' => filled($instructions) ? trim($instructions) : null,
            ]);

            $parcels = $shipments
                ->flatMap(fn (Shipment $shipment): Collection => $shipment->parcels()
                    ->orderBy('piece_sequence')
                    ->get())
                ->values();

            foreach ($parcels as $parcel) {
                $pickupRequest->parcels()->attach($parcel->id, ['added_at' => $now]);
            }

            $this->notifyProviderOfPickup($pickupRequest, $storeId, $requestedDate);

            return $pickupRequest->fresh([
                'logisticsProfile',
                'pickupAddress',
                'parcels.shipment.sellerOrder',
                'parcels.shipment.sellerOrders',
                'parcels.waybill',
            ]);
        }, attempts: 3);
    }

    private function notifyProviderOfPickup(PickupRequest $pickupRequest, int $storeId, string $requestedDate): void
    {
        $providerUser = $pickupRequest->logisticsProfile?->user;
        $store = Store::query()->find($storeId);

        if (! $providerUser || ! $store) {
            return;
        }

        $this->notifications->sendAfterCommit($providerUser, new WorkflowNotification(
            eventKey: 'logistics_pickup_requested',
            title: 'New pickup request',
            body: "Pickup {$pickupRequest->pickup_no} from {$store->name} was scheduled for {$requestedDate}.",
            actionUrl: route('logistics.pickups.index'),
            type: 'info',
            meta: ['pickup_request_id' => (int) $pickupRequest->id],
        ));
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.strtoupper(Str::random(20));
    }
}
