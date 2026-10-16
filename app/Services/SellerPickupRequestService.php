<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\PickupRequest;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerPickupRequestService
{
    /** @var array<string, array{start: string, end: string, label: string}> */
    public const PICKUP_WINDOWS = [
        '10:00-12:00' => ['start' => '10:00:00', 'end' => '12:00:00', 'label' => '10:00 AM–12:00 PM'],
        '13:00-15:00' => ['start' => '13:00:00', 'end' => '15:00:00', 'label' => '1:00–3:00 PM'],
        '15:00-17:00' => ['start' => '15:00:00', 'end' => '17:00:00', 'label' => '3:00–5:00 PM'],
    ];

    /** @return Collection<int, LogisticsProfile> */
    public function activeProviders(): Collection
    {
        return LogisticsProfile::query()
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query
                ->where('status', 'active')
                ->where(fn ($roles) => $roles
                    ->where('role', UserRole::Logistics->value)
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery
                        ->where('roles.name', UserRole::Logistics->value))))
            ->with('user:id,name,status,role')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Create one Seller pickup request and its authoritative fulfillment records.
     * The submitted order IDs and provider are re-scoped under row locks.
     *
     * @param  list<int>  $sellerOrderIds
     */
    public function create(
        User $actor,
        int $logisticsProfileId,
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
            $sellerProfile = SellerProfile::query()
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            abort_unless($sellerProfile, 403, 'Your Seller account does not have a Store.');

            $store = Store::query()
                ->where('seller_profile_id', $sellerProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            $pickupAddress = Address::query()
                ->whereKey($sellerProfile->pickup_address_id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            abort_unless($pickupAddress, 409, 'Set a valid Seller pickup address before requesting collection.');

            $provider = LogisticsProfile::query()
                ->whereKey($logisticsProfileId)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query
                    ->where('status', 'active')
                    ->where(fn ($roles) => $roles
                        ->where('role', UserRole::Logistics->value)
                        ->orWhereHas('roles', fn ($roleQuery) => $roleQuery
                            ->where('roles.name', UserRole::Logistics->value))))
                ->lockForUpdate()
                ->firstOrFail();

            $targets = SellerOrder::query()
                ->where('store_id', $store->id)
                ->whereIn('id', $sellerOrderIds)
                ->orderBy('id')
                ->get(['id', 'order_id']);

            abort_unless($targets->count() === count($sellerOrderIds), 404);

            // Match the Seller Order workflow's lock order: marketplace Orders, then Seller Orders.
            $orders = Order::query()
                ->whereIn('id', $targets->pluck('order_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $sellerOrders = SellerOrder::query()
                ->where('store_id', $store->id)
                ->whereIn('id', $sellerOrderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_unless($sellerOrders->count() === count($sellerOrderIds), 404);

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

                abort_if(
                    Shipment::query()->where('seller_order_id', $sellerOrder->id)->exists(),
                    409,
                    "Seller Order {$sellerOrder->seller_order_no} already has a shipment or pickup request.",
                );

                $this->packageSnapshot($sellerOrder);
            }

            $window = self::PICKUP_WINDOWS[$pickupWindow] ?? null;
            if (! $window) {
                throw ValidationException::withMessages([
                    'pickup_window' => 'Choose one of the available pickup windows.',
                ]);
            }

            $timezone = (string) config('app.timezone');
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate, $timezone);
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
                'store_id' => $store->id,
                'logistics_profile_id' => $provider->id,
                'pickup_address_id' => $pickupAddress->id,
                'status' => 'requested',
                'requested_date' => $date->toDateString(),
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'seller_instructions' => filled($instructions) ? trim($instructions) : null,
            ]);

            $payments = Payment::query()
                ->whereIn('order_id', $orders->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('order_id');

            foreach ($sellerOrders as $sellerOrder) {
                $order = $sellerOrder->order;
                $snapshot = $this->packageSnapshot($sellerOrder);
                $isCod = $payments
                    ->get($order->id, collect())
                    ->contains(fn (Payment $payment): bool => $payment->method === 'cod' && $payment->status !== 'cancelled');

                $shipment = Shipment::query()->create([
                    'shipment_no' => $this->number('SHP'),
                    'seller_order_id' => $sellerOrder->id,
                    'logistics_profile_id' => $provider->id,
                    'origin_logistics_profile_id' => $provider->id,
                    'origin_address_id' => $pickupAddress->id,
                    'destination_address_id' => $order->shipping_address_id,
                    'destination_city_code' => $order->psgc_city_code,
                    'purpose' => 'outbound',
                    'status' => ShipmentStatus::ReadyForPickup->value,
                    'shipping_fee_minor' => (int) $sellerOrder->shipping_fee_minor,
                    'cod_amount_minor' => $isCod ? (int) $sellerOrder->total_minor : 0,
                ]);

                $waybillNo = $this->number('BRLY');
                $waybill = Waybill::query()->create([
                    'shipment_id' => $shipment->id,
                    'waybill_no' => $waybillNo,
                    'scan_token' => Str::random(64),
                    'barcode_value' => $waybillNo,
                    'piece_count' => 1,
                    'total_weight_kg' => $snapshot['weight'],
                    'length_cm' => $snapshot['length'],
                    'width_cm' => $snapshot['width'],
                    'height_cm' => $snapshot['height'],
                    'status' => 'generated',
                    'generated_by' => $actor->id,
                    'generated_at' => $now,
                ]);

                $parcel = Parcel::query()->create([
                    'shipment_id' => $shipment->id,
                    'waybill_id' => $waybill->id,
                    'parcel_no' => $this->number('PCL'),
                    'piece_sequence' => 1,
                    'weight_kg' => $snapshot['weight'],
                    'length_cm' => $snapshot['length'],
                    'width_cm' => $snapshot['width'],
                    'height_cm' => $snapshot['height'],
                    'status' => ParcelStatus::Created->value,
                    'last_event_at' => $now,
                ]);

                $pickupRequest->parcels()->attach($parcel->id, ['added_at' => $now]);

                $shipment->events()->create([
                    'parcel_id' => $parcel->id,
                    'event_type' => 'shipment_ready_for_pickup',
                    'from_status' => null,
                    'to_status' => ShipmentStatus::ReadyForPickup->value,
                    'actor_user_id' => $actor->id,
                    'source' => 'seller',
                    'notes' => "Shipment created through pickup request {$pickupRequest->pickup_no}.",
                    'metadata' => [
                        'seller_order_id' => $sellerOrder->id,
                        'pickup_request_id' => $pickupRequest->id,
                    ],
                    'occurred_at' => $now,
                ]);
            }

            return $pickupRequest->fresh([
                'logisticsProfile',
                'pickupAddress',
                'parcels.shipment.sellerOrder',
                'parcels.waybill',
            ]);
        }, attempts: 3);
    }

    /** @return array{weight: float, length: float, width: float, height: float} */
    private function packageSnapshot(SellerOrder $sellerOrder): array
    {
        $quote = (array) $sellerOrder->shipping_quote;
        foreach ([$quote['provider_key'] ?? null, $sellerOrder->delivery_provider_key] as $providerKey) {
            if ($providerKey !== null && $providerKey !== config('shipping.provider_key')) {
                throw ValidationException::withMessages([
                    'order_ids' => "{$sellerOrder->seller_order_no} uses a shipping provider that is not available for pickup requests.",
                ]);
            }
        }

        $dimensions = (array) ($quote['parcel_dimensions_cm'] ?? []);
        $weight = $quote['actual_weight_kg'] ?? null;
        $length = $dimensions['length'] ?? null;
        $width = $dimensions['width'] ?? null;
        $height = $dimensions['height'] ?? null;

        if (
            ! is_numeric($weight)
            || (float) $weight <= 0
            || ! is_numeric($length)
            || (float) $length <= 0
            || ! is_numeric($width)
            || (float) $width <= 0
            || ! is_numeric($height)
            || (float) $height <= 0
        ) {
            throw ValidationException::withMessages([
                'order_ids' => "Shipping package details are missing for {$sellerOrder->seller_order_no}. Refresh the order before requesting pickup.",
            ]);
        }

        return [
            'weight' => (float) $weight,
            'length' => (float) $length,
            'width' => (float) $width,
            'height' => (float) $height,
        ];
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.Str::upper(Str::random(20));
    }
}
