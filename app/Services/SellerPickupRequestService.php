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

            // Match the Seller Order workflow's lock order: marketplace Orders,
            // then Seller Orders, before resolving the city-based provider.
            $orders = Order::query()
                ->whereIn('id', $targets->pluck('order_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $sellerOrders = SellerOrder::query()
                ->where('store_id', $storeId)
                ->whereIn('id', $sellerOrderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_unless($sellerOrders->count() === count($sellerOrderIds), 404);

            $pickupAddress = $this->fulfillment->pickupAddressFor($actor, lock: true);

            abort_unless(
                $pickupAddress,
                409,
                'Set a valid Seller pickup address before requesting collection.',
            );

            $provider = $this->fulfillment->providerForPickupAddress($pickupAddress, lock: true);

            if (
                $logisticsProfileId !== null
                && (int) $logisticsProfileId !== (int) $provider->id
            ) {
                throw ValidationException::withMessages([
                    'logistics_profile_id' => 'The submitted Logistics provider does not serve the Seller pickup city. Refresh the page and try again.',
                ]);
            }

            /** @var Collection<int, Shipment> $shipments */
            $shipments = collect();

            foreach ($sellerOrders as $sellerOrder) {
                $order = $orders->get($sellerOrder->order_id);
                abort_unless($order, 404);
                $sellerOrder->setRelation('order', $order);

                abort_unless(
                    $sellerOrder->status === 'ready_for_pickup'
                        && $order->status !== 'cancelled',
                    409,
                    "Seller Order {$sellerOrder->seller_order_no} is not ready for pickup.",
                );

                $orderShipments = Shipment::query()
                    ->where('seller_order_id', $sellerOrder->id)
                    ->where('purpose', 'outbound')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                abort_unless(
                    $orderShipments->count() === 1,
                    409,
                    "Seller Order {$sellerOrder->seller_order_no} does not have one authoritative shipment. Start preparation again or contact support.",
                );

                $shipment = $orderShipments->first();

                abort_unless(
                    $shipment->status === ShipmentStatus::ReadyForPickup->value
                        && (int) $shipment->logistics_profile_id === (int) $provider->id
                        && (int) $shipment->origin_logistics_profile_id === (int) $provider->id,
                    409,
                    "The shipment for {$sellerOrder->seller_order_no} is not ready with the Logistics provider serving your pickup city.",
                );

                $waybill = Waybill::query()
                    ->where('shipment_id', $shipment->id)
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $waybill,
                    409,
                    "The waybill for {$sellerOrder->seller_order_no} is missing. Contact support.",
                );
                abort_if(
                    $waybill->voided_at || $waybill->status === 'voided',
                    409,
                    "The waybill for {$sellerOrder->seller_order_no} is voided. Contact support.",
                );
                abort_unless(
                    $waybill->printed_at,
                    409,
                    "Print and confirm the waybill for {$sellerOrder->seller_order_no} in Fulfillment → Waybills before requesting pickup.",
                );

                $parcels = $shipment->parcels()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                abort_unless(
                    $parcels->isNotEmpty(),
                    409,
                    "The parcel records for {$sellerOrder->seller_order_no} are missing. Contact support.",
                );

                $hasActivePickup = PickupRequest::query()
                    ->whereIn('status', ['requested', 'verified', 'scheduled'])
                    ->whereHas('parcels', fn ($query) => $query
                        ->whereIn('parcels.id', $parcels->pluck('id')))
                    ->lockForUpdate()
                    ->exists();

                abort_if(
                    $hasActivePickup,
                    409,
                    "Seller Order {$sellerOrder->seller_order_no} is already included in an active pickup request.",
                );

                $shipments->put($shipment->id, $shipment);
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
                    ->orderBy('id')
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
