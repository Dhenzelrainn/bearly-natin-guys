<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerFulfillmentService
{
    private const MAX_WAYBILL_PIECES = 65_535;

    public function __construct(
        private readonly PostalCodeLookup $postalCodes,
    ) {}

    /**
     * Resolve the authenticated Seller's own pickup address.
     */
    public function pickupAddressFor(User $actor, bool $lock = false): ?Address
    {
        $profileQuery = SellerProfile::query()
            ->where('user_id', $actor->id);

        if ($lock) {
            $profileQuery->lockForUpdate();
        }

        $sellerProfile = $profileQuery->first();

        if (! $sellerProfile?->pickup_address_id) {
            return null;
        }

        $addressQuery = Address::query()
            ->whereKey($sellerProfile->pickup_address_id)
            ->where('user_id', $actor->id);

        if ($lock) {
            $addressQuery->lockForUpdate();
        }

        return $addressQuery->first();
    }

    /**
     * Resolve the one active Logistics provider serving the canonical city of
     * the Seller pickup address. Client-submitted provider IDs are never used
     * to select the provider.
     */
    public function providerForPickupAddress(Address $address, bool $lock = false): LogisticsProfile
    {
        try {
            $cityCode = $this->canonicalCityCode($address);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'pickup_address' => collect($exception->errors())->flatten()->first()
                    ?: 'The Seller pickup address does not have a verified municipality/city.',
            ]);
        }

        $providerQuery = LogisticsProfile::query()
            ->where('psgc_city_code', $cityCode)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query
                ->where('status', AccountStatus::Active->value)
                ->where(fn ($roles) => $roles
                    ->where('role', UserRole::Logistics->value)
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery
                        ->where('roles.name', UserRole::Logistics->value))));

        if ($lock) {
            $providerQuery->lockForUpdate();
        }

        $provider = $providerQuery->first();

        if (! $provider) {
            throw ValidationException::withMessages([
                'pickup_address' => 'No active Logistics provider serves '.$this->cityLabel($address)
                    .'. Contact an administrator before preparing this order for pickup.',
            ]);
        }

        return $provider;
    }

    /**
     * Create the authoritative fulfillment records when preparation starts.
     * Callers must already hold the marketplace Order and Seller Order locks.
     */
    public function createShipmentFor(
        SellerOrder $sellerOrder,
        User $actor,
        LogisticsProfile $provider,
        Address $pickupAddress,
    ): Shipment {
        $sellerOrder->loadMissing('order');
        $order = $sellerOrder->order;

        abort_unless($order, 409, 'The marketplace order for this Seller Order is unavailable.');

        $existing = Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('purpose', 'outbound')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        abort_if(
            $existing->count() > 1,
            409,
            "Seller Order {$sellerOrder->seller_order_no} has multiple fulfillment shipments. Contact support before continuing.",
        );

        if ($existing->isNotEmpty()) {
            $shipment = $existing->first();
            $hasFulfillmentRecords = $shipment->waybill()->exists()
                && $shipment->parcels()->exists();

            abort_unless(
                $hasFulfillmentRecords,
                409,
                "Seller Order {$sellerOrder->seller_order_no} has incomplete fulfillment records. Contact support before continuing.",
            );

            return $shipment->load(['waybill', 'parcels']);
        }

        $snapshot = $this->packageSnapshot($sellerOrder);
        $isCod = Payment::query()
            ->where('order_id', $order->id)
            ->where('method', 'cod')
            ->where('status', '!=', 'cancelled')
            ->exists();
        $now = now();
        $shipment = Shipment::query()->create([
            'shipment_no' => $this->number('SHP'),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider->id,
            'origin_logistics_profile_id' => $provider->id,
            'origin_address_id' => $pickupAddress->id,
            'destination_address_id' => $order->shipping_address_id,
            'destination_city_code' => $order->psgc_city_code,
            'purpose' => 'outbound',
            'status' => ShipmentStatus::Pending->value,
            'shipping_fee_minor' => (int) $sellerOrder->shipping_fee_minor,
            'cod_amount_minor' => $isCod
                ? max(0, (int) $sellerOrder->total_minor
                    - (int) ($sellerOrder->platform_discount_minor ?? 0)
                    - (int) ($sellerOrder->campaign_platform_discount_minor ?? 0)
                    - (int) ($sellerOrder->platform_shipping_discount_minor ?? 0))
                : 0,
        ]);

        $waybillNo = $this->number('BRLY');
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => $waybillNo,
            'scan_token' => Str::random(64),
            'barcode_value' => $waybillNo,
            'piece_count' => count($snapshot['pieces']),
            'total_weight_kg' => $snapshot['weight'],
            'length_cm' => $snapshot['length'],
            'width_cm' => $snapshot['width'],
            'height_cm' => $snapshot['height'],
            'status' => 'generated',
            'generated_by' => $actor->id,
            'generated_at' => $now,
        ]);

        $parcels = collect($snapshot['pieces'])
            ->values()
            ->map(fn (array $piece, int $index): Parcel => Parcel::query()->create([
                'shipment_id' => $shipment->id,
                'waybill_id' => $waybill->id,
                'parcel_no' => $this->number('PCL'),
                'piece_sequence' => $index + 1,
                'weight_kg' => $piece['weight'],
                'length_cm' => $piece['length'],
                'width_cm' => $piece['width'],
                'height_cm' => $piece['height'],
                'status' => ParcelStatus::Created->value,
                'last_event_at' => $now,
            ]));

        $shipment->events()->create([
            'parcel_id' => $parcels->first()?->id,
            'event_type' => 'waybill_generated',
            'from_status' => null,
            'to_status' => ShipmentStatus::Pending->value,
            'actor_user_id' => $actor->id,
            'source' => 'seller',
            'notes' => 'Shipment and waybill generated when the Seller started preparing the order.',
            'metadata' => [
                'seller_order_id' => $sellerOrder->id,
                'waybill_no' => $waybillNo,
                'piece_count' => $parcels->count(),
            ],
            'occurred_at' => $now,
        ]);

        return $shipment->load(['waybill', 'parcels']);
    }

    /**
     * Enforce the printed-waybill checkpoint and mark the shipment ready only
     * after the Seller confirms the label was printed and attached.
     */
    public function markShipmentReady(SellerOrder $sellerOrder, User $actor): Shipment
    {
        $shipments = Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('purpose', 'outbound')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        abort_unless(
            $shipments->count() === 1,
            409,
            "Seller Order {$sellerOrder->seller_order_no} does not have one authoritative fulfillment shipment. Start preparation again or contact support.",
        );

        $shipment = $shipments->first();
        $waybill = Waybill::query()
            ->where('shipment_id', $shipment->id)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $waybill,
            409,
            'The waybill for this order is missing. Contact support before marking it ready.',
        );
        abort_if(
            $waybill->voided_at || $waybill->status === 'voided',
            409,
            'This waybill is voided. Contact support before marking the order ready.',
        );
        abort_unless(
            $waybill->printed_at,
            409,
            'Download, print, and confirm the waybill in Fulfillment → Waybills before marking this order ready for pickup.',
        );
        $parcels = $shipment->parcels()
            ->orderBy('piece_sequence')
            ->lockForUpdate()
            ->get();

        abort_unless(
            $parcels->isNotEmpty(),
            409,
            'The parcel records for this order are missing. Contact support before marking it ready.',
        );

        $expectedPieceSequences = range(1, (int) $waybill->piece_count);
        abort_unless(
            $parcels->count() === (int) $waybill->piece_count
                && $parcels->every(fn (Parcel $parcel): bool => (int) $parcel->waybill_id === (int) $waybill->id)
                && $parcels->pluck('piece_sequence')->map(fn ($sequence): int => (int) $sequence)->all() === $expectedPieceSequences,
            409,
            'The waybill piece count or parcel links do not match the registered parcel records. Contact support before marking it ready.',
        );

        if ($shipment->status === ShipmentStatus::ReadyForPickup->value) {
            return $shipment->load(['waybill', 'parcels']);
        }

        abort_unless(
            $shipment->status === ShipmentStatus::Pending->value,
            409,
            'The shipment is not in a state that can be marked ready for pickup.',
        );

        $now = now();
        $shipment->update(['status' => ShipmentStatus::ReadyForPickup->value]);
        $shipment->events()->create([
            'parcel_id' => $parcels->first()?->id,
            'event_type' => 'shipment_ready_for_pickup',
            'from_status' => ShipmentStatus::Pending->value,
            'to_status' => ShipmentStatus::ReadyForPickup->value,
            'actor_user_id' => $actor->id,
            'source' => 'seller',
            'notes' => 'Seller confirmed packing and the printed waybill.',
            'metadata' => [
                'seller_order_id' => $sellerOrder->id,
                'waybill_no' => $waybill->waybill_no,
            ],
            'occurred_at' => $now,
        ]);

        return $shipment->load(['waybill', 'parcels']);
    }

    /**
     * @return array{
     *     weight: float,
     *     length: float,
     *     width: float,
     *     height: float,
     *     pieces: list<array{weight: float, length: float, width: float, height: float}>
     * }
     */
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

        if (! $this->isPositiveFiniteNumber($weight)
            || ! $this->isPositiveFiniteNumber($length)
            || ! $this->isPositiveFiniteNumber($width)
            || ! $this->isPositiveFiniteNumber($height)) {
            throw ValidationException::withMessages([
                'order_ids' => "Shipping package details are missing for {$sellerOrder->seller_order_no}. Refresh the order before starting preparation.",
            ]);
        }

        $snapshot = [
            'weight' => (float) $weight,
            'length' => (float) $length,
            'width' => (float) $width,
            'height' => (float) $height,
        ];

        $packageItems = $quote['package_items'] ?? null;

        if ($packageItems === null) {
            // Legacy quote snapshots did not persist per-unit packaging.
            // Preserve their existing single-parcel behavior without reading
            // mutable Product Variant data.
            $snapshot['pieces'] = [$snapshot];

            return $snapshot;
        }

        if (! is_array($packageItems) || ! array_is_list($packageItems) || $packageItems === []) {
            throw ValidationException::withMessages([
                'order_ids' => "Saved per-item package details are invalid for {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
            ]);
        }

        $pieces = [];
        $pieceCount = 0;

        foreach ($packageItems as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'order_ids' => "Saved per-item package details are invalid for {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
                ]);
            }

            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            $weight = $item['package_weight_kg'] ?? null;
            $dimensions = $item['package_dimensions_cm'] ?? null;

            if ($quantity === false || $quantity < 1
                || ! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight <= 0
                || ! is_array($dimensions)) {
                throw ValidationException::withMessages([
                    'order_ids' => "Saved per-item package details are incomplete for {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
                ]);
            }

            $length = $dimensions['length'] ?? null;
            $width = $dimensions['width'] ?? null;
            $height = $dimensions['height'] ?? null;

            if (! $this->isPositiveFiniteNumber($length)
                || ! $this->isPositiveFiniteNumber($width)
                || ! $this->isPositiveFiniteNumber($height)) {
                throw ValidationException::withMessages([
                    'order_ids' => "Saved per-item package dimensions are incomplete for {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
                ]);
            }

            if ($pieceCount > self::MAX_WAYBILL_PIECES - $quantity) {
                throw ValidationException::withMessages([
                    'order_ids' => "{$sellerOrder->seller_order_no} exceeds the maximum supported Waybill piece count.",
                ]);
            }

            $pieceCount += $quantity;

            for ($unit = 0; $unit < $quantity; $unit++) {
                $pieces[] = [
                    'weight' => round((float) $weight, 3),
                    'length' => (float) $length,
                    'width' => (float) $width,
                    'height' => (float) $height,
                ];
            }
        }

        $orderQuantity = (int) $sellerOrder->items()->sum('quantity');

        if ($pieceCount !== $orderQuantity) {
            throw ValidationException::withMessages([
                'order_ids' => "Saved package quantities do not match Seller Order {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
            ]);
        }

        $snapshot['pieces'] = $pieces;

        return $snapshot;
    }

    private function isPositiveFiniteNumber(mixed $value): bool
    {
        return is_numeric($value)
            && is_finite((float) $value)
            && (float) $value > 0;
    }

    private function canonicalCityCode(Address $address): string
    {
        $cityCode = $this->postalCodes->canonicalCityCode(
            filled($address->psgc_city_code) ? trim((string) $address->psgc_city_code) : null,
            (string) $address->province,
            (string) $address->city_municipality,
        );

        if (! filled($cityCode)) {
            throw ValidationException::withMessages([
                'pickup_address' => 'The Seller pickup address does not have a verified municipality/city. Update the pickup address before continuing.',
            ]);
        }

        return trim((string) $cityCode);
    }

    private function cityLabel(Address $address): string
    {
        $label = trim(implode(', ', array_filter([
            trim((string) $address->city_municipality),
            trim((string) $address->province),
        ])));

        return $label !== '' ? $label : 'the Seller pickup city';
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.Str::upper(Str::random(20));
    }
}
