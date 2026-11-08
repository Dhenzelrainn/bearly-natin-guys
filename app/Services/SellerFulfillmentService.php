<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Parcel;
use App\Models\ParcelItem;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerFulfillmentService
{
    private const MAX_WAYBILL_PIECES = 65_535;

    public function __construct(
        private readonly PostalCodeLookup $postalCodes,
        private readonly LogisticsProviderRecommendationService $providerRecommendations,
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
        $provider = $this->activeProviderForPickupAddress($address, $lock);

        if (! $provider) {
            throw ValidationException::withMessages([
                'pickup_address' => 'No active Logistics provider serves '.$this->cityLabel($address)
                    .'. Contact an administrator before preparing this order for pickup.',
            ]);
        }

        return $provider;
    }

    /** Resolve the city's assigned active provider without treating no Rider as provider unavailability. */
    public function activeProviderForPickupAddress(Address $address, bool $lock = false): ?LogisticsProfile
    {
        try {
            $cityCode = $this->canonicalCityCode($address);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'pickup_address' => collect($exception->errors())->flatten()->first()
                    ?: 'The Seller pickup address does not have a verified municipality/city.',
            ]);
        }

        return $this->providerRecommendations->activeProviderForCity($cityCode, $lock);
    }

    /** @return Collection<int, array{id: int, name: string, location: string, distance_km: float|null, distance_label: string|null, available_rider_count: int}> */
    public function nearbyProvidersForPickupAddress(Address $address, array $excludeProviderIds = []): Collection
    {
        return $this->providerRecommendations->recommendationsForAddress($address, $excludeProviderIds);
    }

    public function activeProviderById(int $providerId, bool $lock = false): ?LogisticsProfile
    {
        return $this->providerRecommendations->activeProviderById($providerId, $lock);
    }

    public function isNearbyProviderForPickupAddress(Address $address, LogisticsProfile $provider): bool
    {
        return $this->providerRecommendations->isNearby($address, $provider);
    }

    /**
     * Create a Shipment with the city's assigned provider, or a recommended
     * active provider only when no active provider serves the Seller's city.
     */
    public function resolveProviderForPickupAddress(
        Address $address,
        ?int $selectedProviderId,
        bool $lock = false,
    ): LogisticsProfile {
        $cityProvider = $this->activeProviderForPickupAddress($address, $lock);

        if ($cityProvider) {
            if ($selectedProviderId !== null && $selectedProviderId !== (int) $cityProvider->id) {
                throw ValidationException::withMessages([
                    'logistics_profile_id' => 'The Logistics provider serving your pickup city is assigned automatically.',
                ]);
            }

            return $cityProvider;
        }

        if (! $selectedProviderId) {
            throw ValidationException::withMessages([
                'logistics_profile_id' => 'Choose a nearby active Logistics provider before creating the Shipment and Waybill.',
            ]);
        }

        $recommendedIds = $this->nearbyProvidersForPickupAddress($address)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $provider = $this->providerRecommendations->activeProviderById($selectedProviderId, $lock);

        abort_unless(
            $provider && $recommendedIds->contains((int) $provider->id),
            422,
            'Choose an active nearby Logistics provider from the current recommendations.',
        );

        return $provider;
    }

    /** @return array{id: int, name: string, location: string, distance_km: float|null, distance_label: string|null, available_rider_count: int} */
    public function providerOptionForPickupAddress(Address $address, LogisticsProfile $provider): array
    {
        return $this->providerRecommendations->optionForProvider($address, $provider);
    }

    /**
     * Build the default packing form from immutable checkout snapshots.
     * Current quotes start with one Parcel per ordered unit; legacy quotes
     * without per-SKU packaging retain one aggregate Parcel.
     *
     * @return array{items: list<array<string, mixed>>, parcels: list<array<string, mixed>>}
     */
    public function defaultPackingPlan(SellerOrder $sellerOrder): array
    {
        $sellerOrder->loadMissing('items');
        $items = $sellerOrder->items->sortBy('id')->values();

        abort_if($items->isEmpty(), 409, 'This Seller Order has no items to pack.');

        $snapshot = $this->packageSnapshot($sellerOrder);

        if (! $snapshot['legacy'] && (int) $items->sum('quantity') > self::MAX_WAYBILL_PIECES) {
            abort(409, 'This Seller Order exceeds the maximum supported number of separate parcels. Contact support before packing it.');
        }

        $itemRows = $items->map(fn (OrderItem $item): array => [
            'id' => (int) $item->id,
            'name' => (string) $item->product_name,
            'variant' => (string) $item->variant_name,
            'sku' => (string) $item->sku,
            'quantity' => (int) $item->quantity,
        ])->all();

        if ($snapshot['legacy']) {
            return [
                'items' => $itemRows,
                'parcels' => [[
                    'items' => $items->mapWithKeys(fn (OrderItem $item): array => [
                        (int) $item->id => (int) $item->quantity,
                    ])->all(),
                    'weight_kg' => round($snapshot['weight'], 3),
                    'length_cm' => round($snapshot['length'], 2),
                    'width_cm' => round($snapshot['width'], 2),
                    'height_cm' => round($snapshot['height'], 2),
                ]],
            ];
        }

        $parcels = [];

        foreach ($items as $item) {
            $unit = $snapshot['unit_packages'][(string) $item->sku] ?? null;

            abort_unless(
                is_array($unit),
                409,
                "Checkout package details for SKU {$item->sku} are unavailable. Contact support before packing this order.",
            );

            for ($unitIndex = 0; $unitIndex < (int) $item->quantity; $unitIndex++) {
                $parcels[] = [
                    'items' => [(int) $item->id => 1],
                    'weight_kg' => round($unit['weight'], 3),
                    'length_cm' => round($unit['length'], 2),
                    'width_cm' => round($unit['width'], 2),
                    'height_cm' => round($unit['height'], 2),
                ];
            }
        }

        return ['items' => $itemRows, 'parcels' => $parcels];
    }

    /**
     * Build one default plan across compatible Seller Orders. Keep each
     * Seller Order's immutable package snapshot separate, even when the same
     * SKU was ordered at different times.
     *
     * @param  Collection<int, SellerOrder>  $sellerOrders
     * @return array{items: list<array<string, mixed>>, parcels: list<array<string, mixed>>}
     */
    public function defaultPackingPlanForOrders(Collection $sellerOrders): array
    {
        $items = [];
        $parcels = [];

        foreach ($sellerOrders->sortBy('id')->values() as $sellerOrder) {
            $default = $this->defaultPackingPlan($sellerOrder);
            array_push($items, ...$default['items']);
            array_push($parcels, ...$default['parcels']);
        }

        abort_if($items === [] || $parcels === [], 409, 'The selected Seller Orders have no fulfillable items to pack.');
        abort_if(count($parcels) > self::MAX_WAYBILL_PIECES, 409, 'The combined Seller Orders exceed the supported Waybill piece limit.');

        return ['items' => $items, 'parcels' => $parcels];
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
        array $packingPlan,
        ?Collection $includedSellerOrders = null,
    ): Shipment {
        $sellerOrder->loadMissing('order');
        $order = $sellerOrder->order;

        abort_unless($order, 409, 'The marketplace order for this Seller Order is unavailable.');

        $includedSellerOrders = ($includedSellerOrders ?? new Collection([$sellerOrder]))
            ->keyBy('id')
            ->values();
        if (! $includedSellerOrders->contains(fn (SellerOrder $included): bool => (int) $included->id === (int) $sellerOrder->id)) {
            $includedSellerOrders->prepend($sellerOrder);
        }
        $includedSellerOrders = $includedSellerOrders->sortBy('id')->values();

        $orderItems = new Collection;
        $snapshots = [];
        foreach ($includedSellerOrders as $included) {
            $included->loadMissing('order');

            $existing = $included->shipments()
                ->where('shipments.purpose', 'outbound')
                ->orderBy('shipments.id')
                ->lockForUpdate()
                ->get();
            abort_if(
                $existing->isNotEmpty(),
                409,
                "Seller Order {$included->seller_order_no} already has fulfillment records. Contact support before changing its packing plan.",
            );

            $items = $included->items()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $snapshots[(int) $included->id] = $this->packageSnapshot($included, $items);
            $orderItems = $orderItems->concat($items);
        }

        $snapshot = $snapshots[(int) $sellerOrder->id];
        $pieces = $this->normalizePackingPlan($packingPlan, $orderItems);
        $codAmounts = [];
        foreach ($includedSellerOrders as $included) {
            $includedOrder = $included->order;
            abort_unless($includedOrder, 409, 'A selected marketplace order is unavailable.');

            $isCod = Payment::query()
                ->where('order_id', $includedOrder->id)
                ->where('method', 'cod')
                ->where('status', '!=', 'cancelled')
                ->exists();
            $codAmounts[] = $isCod
                ? max(0, (int) $included->total_minor
                    - (int) ($included->platform_discount_minor ?? 0)
                    - (int) ($included->campaign_platform_discount_minor ?? 0)
                    - (int) ($included->platform_shipping_discount_minor ?? 0))
                : 0;
        }
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
            'shipping_fee_minor' => (int) $includedSellerOrders->sum('shipping_fee_minor'),
            'cod_amount_minor' => array_sum($codAmounts),
        ]);

        $shipment->sellerOrders()->syncWithoutDetaching($includedSellerOrders->pluck('id')->all());

        $waybillNo = $this->number('BRLY');
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => $waybillNo,
            'scan_token' => Str::random(64),
            'barcode_value' => $waybillNo,
            'piece_count' => count($pieces),
            'total_weight_kg' => round(array_sum(array_column($pieces, 'weight')), 3),
            'length_cm' => $includedSellerOrders->count() === 1 ? $snapshot['length'] : null,
            'width_cm' => $includedSellerOrders->count() === 1 ? $snapshot['width'] : null,
            'height_cm' => $includedSellerOrders->count() === 1 ? $snapshot['height'] : null,
            'status' => 'generated',
            'generated_by' => $actor->id,
            'generated_at' => $now,
        ]);

        $parcels = collect($pieces)
            ->values()
            ->map(function (array $piece, int $index) use ($shipment, $waybill, $now): Parcel {
                $parcel = Parcel::query()->create([
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
                ]);

                foreach ($piece['items'] as $orderItemId => $quantity) {
                    ParcelItem::query()->create([
                        'parcel_id' => $parcel->id,
                        'order_item_id' => $orderItemId,
                        'quantity' => $quantity,
                    ]);
                }

                return $parcel;
            });

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
                'seller_order_ids' => $includedSellerOrders->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'waybill_no' => $waybillNo,
                'piece_count' => $parcels->count(),
            ],
            'occurred_at' => $now,
        ]);

        return $shipment->load(['waybill', 'parcels.contents.orderItem']);
    }

    /**
     * Enforce the printed-waybill checkpoint and mark the shipment ready only
     * after the Seller confirms the label was printed and attached.
     */
    public function markShipmentReady(SellerOrder $sellerOrder, User $actor): Shipment
    {
        $shipments = $sellerOrder->shipments()
            ->where('shipments.purpose', 'outbound')
            ->orderBy('shipments.id')
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
     *     legacy: bool,
     *     unit_packages: array<string, array{weight: float, length: float, width: float, height: float}>
     * }
     */
    private function packageSnapshot(SellerOrder $sellerOrder, ?Collection $orderItems = null): array
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
            'legacy' => false,
            'unit_packages' => [],
        ];

        $packageItems = $quote['package_items'] ?? null;

        if ($packageItems === null) {
            // Legacy quote snapshots did not persist per-SKU packaging. Keep
            // their one aggregate Parcel default without reading mutable SKU data.
            $snapshot['legacy'] = true;

            return $snapshot;
        }

        if (! is_array($packageItems) || ! array_is_list($packageItems) || $packageItems === []) {
            throw ValidationException::withMessages([
                'order_ids' => "Saved per-item package details are invalid for {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
            ]);
        }

        $packageQuantities = [];

        foreach ($packageItems as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'order_ids' => "Saved per-item package details are invalid for {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
                ]);
            }

            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            $sku = trim((string) ($item['sku'] ?? ''));
            $weight = $item['package_weight_kg'] ?? null;
            $dimensions = $item['package_dimensions_cm'] ?? null;

            if ($sku === '' || $quantity === false || $quantity < 1
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

            $package = [
                'weight' => round((float) $weight, 3),
                'length' => (float) $length,
                'width' => (float) $width,
                'height' => (float) $height,
            ];

            if (isset($snapshot['unit_packages'][$sku])
                && $snapshot['unit_packages'][$sku] !== $package) {
                throw ValidationException::withMessages([
                    'order_ids' => "Saved package details conflict for SKU {$sku} in {$sellerOrder->seller_order_no}. Contact support before packing this order.",
                ]);
            }

            $snapshot['unit_packages'][$sku] = $package;
            $packageQuantities[$sku] = (int) ($packageQuantities[$sku] ?? 0) + $quantity;
        }

        $orderQuantities = ($orderItems ?? $sellerOrder->items)
            ->groupBy(fn (OrderItem $item): string => (string) $item->sku)
            ->map(fn ($items): int => (int) $items->sum('quantity'))
            ->all();

        ksort($packageQuantities);
        ksort($orderQuantities);

        if ($packageQuantities !== $orderQuantities) {
            throw ValidationException::withMessages([
                'order_ids' => "Saved package quantities do not match Seller Order {$sellerOrder->seller_order_no}. Contact support before starting preparation.",
            ]);
        }

        return $snapshot;
    }

    /**
     * Verify that every immutable order-item quantity is allocated exactly
     * once and that every declared physical package has usable measurements.
     *
     * @param  array<int|string, mixed>  $submitted
     * @param  \Illuminate\Support\Collection<int, OrderItem>  $orderItems
     * @return list<array{items: array<int, int>, weight: float, length: float, width: float, height: float}>
     */
    private function normalizePackingPlan(array $submitted, $orderItems): array
    {
        if (! array_is_list($submitted) || $submitted === [] || count($submitted) > self::MAX_WAYBILL_PIECES) {
            throw ValidationException::withMessages([
                'parcels' => 'Add at least one package and stay within the supported Waybill piece limit.',
            ]);
        }

        $expected = $orderItems->mapWithKeys(fn (OrderItem $item): array => [
            (int) $item->id => (int) $item->quantity,
        ])->all();
        $allocated = array_fill_keys(array_keys($expected), 0);
        $normalized = [];

        foreach ($submitted as $index => $parcel) {
            if (! is_array($parcel) || ! is_array($parcel['items'] ?? null)) {
                throw ValidationException::withMessages([
                    "parcels.{$index}.items" => 'List the order-item quantities packed in this parcel.',
                ]);
            }

            $contents = [];

            foreach ($parcel['items'] as $rawItemId => $rawQuantity) {
                $itemId = filter_var((string) $rawItemId, FILTER_VALIDATE_INT);
                $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);

                if ($itemId === false || ! array_key_exists((int) $itemId, $expected)
                    || $quantity === false || $quantity < 0) {
                    throw ValidationException::withMessages([
                        "parcels.{$index}.items" => 'The packing plan contains an invalid order item or quantity.',
                    ]);
                }

                if ($quantity === 0) {
                    continue;
                }

                if ($allocated[(int) $itemId] > $expected[(int) $itemId] - $quantity) {
                    throw ValidationException::withMessages([
                        "parcels.{$index}.items" => 'The packing plan allocates more units than were ordered.',
                    ]);
                }

                $allocated[(int) $itemId] += $quantity;
                $contents[(int) $itemId] = (int) $quantity;
            }

            if ($contents === []) {
                throw ValidationException::withMessages([
                    "parcels.{$index}.items" => 'Every parcel must contain at least one ordered unit.',
                ]);
            }

            $weight = $this->positiveMeasurement($parcel['weight_kg'] ?? null, 3);
            $length = $this->positiveMeasurement($parcel['length_cm'] ?? null, 2);
            $width = $this->positiveMeasurement($parcel['width_cm'] ?? null, 2);
            $height = $this->positiveMeasurement($parcel['height_cm'] ?? null, 2);

            if ($weight === null || $length === null || $width === null || $height === null) {
                throw ValidationException::withMessages([
                    "parcels.{$index}.measurements" => 'Enter a positive parcel weight and all three outside dimensions.',
                ]);
            }

            $normalized[] = [
                'items' => $contents,
                'weight' => $weight,
                'length' => $length,
                'width' => $width,
                'height' => $height,
            ];
        }

        foreach ($expected as $itemId => $quantity) {
            if ($allocated[$itemId] !== $quantity) {
                throw ValidationException::withMessages([
                    'parcels' => 'Allocate every ordered unit exactly once before generating the Waybill.',
                ]);
            }
        }

        $totalWeight = array_sum(array_column($normalized, 'weight'));

        if (! is_finite($totalWeight) || $totalWeight <= 0 || $totalWeight > 9_999_999.999) {
            throw ValidationException::withMessages([
                'parcels' => 'The combined parcel weight exceeds the supported Waybill limit.',
            ]);
        }

        return $normalized;
    }

    private function positiveMeasurement(mixed $value, int $precision): ?float
    {
        if (! $this->isPositiveFiniteNumber($value)) {
            return null;
        }

        $maximum = $precision === 3 ? 9_999_999.999 : 99_999_999.99;
        $rounded = round((float) $value, $precision);

        return $rounded > 0 && $rounded <= $maximum ? $rounded : null;
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
