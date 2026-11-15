<?php

namespace App\Services;

use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerOrderPackingConsolidationService
{
    private const MAX_INCLUDED_SELLER_ORDERS = 50;

    /**
     * Return separately checked-out Seller Orders that may be added to this
     * packing plan. A sibling order must contain only variants of a Product
     * already in the base Seller Order.
     *
     * @return EloquentCollection<int, SellerOrder>
     */
    public function candidates(User $seller, SellerOrder $base): EloquentCollection
    {
        $storeId = (int) ($seller->sellerProfile?->store?->id ?? 0);
        $base->loadMissing(['order.payments', 'items']);

        if ($storeId < 1 || $storeId !== (int) $base->store_id || ! $base->order) {
            return new EloquentCollection;
        }

        $productIds = $base->items
            ->pluck('product_id')
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->count() !== 1
            || ! $this->paymentFor($base)
            || ! $this->hasCompatibleShippingSnapshot($base, $base)) {
            return new EloquentCollection;
        }

        return SellerOrder::query()
            ->where('store_id', $storeId)
            ->where('id', '!=', $base->id)
            ->where('status', 'accepted')
            ->whereHas('order', fn ($orders) => $orders->where('buyer_id', $base->order->buyer_id))
            ->whereHas('items', fn ($items) => $items->where('product_id', $productIds->first()))
            ->whereDoesntHave('shipments', fn ($shipments) => $shipments->where('purpose', 'outbound'))
            ->with(['order.payments', 'items'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_INCLUDED_SELLER_ORDERS)
            ->get()
            ->filter(fn (SellerOrder $candidate): bool => $this->compatible($base, $candidate))
            ->values();
    }

    /**
     * Recheck selected sibling orders after the caller has locked all parent
     * Orders and Seller Orders. Never trust IDs or compatibility from the GET
     * checklist.
     *
     * @param  Collection<int, SellerOrder>  $selected
     */
    public function assertCompatible(User $seller, SellerOrder $base, Collection $selected): void
    {
        $storeId = (int) ($seller->sellerProfile?->store?->id ?? 0);
        $base->loadMissing(['order.payments', 'items']);

        if ($storeId < 1 || $storeId !== (int) $base->store_id || ! $base->order) {
            throw ValidationException::withMessages([
                'additional_seller_order_ids' => 'The base Seller Order is not available to this Store.',
            ]);
        }

        if ($selected->count() > self::MAX_INCLUDED_SELLER_ORDERS) {
            throw ValidationException::withMessages([
                'additional_seller_order_ids' => 'Select no more than 50 additional Seller Orders for one packing plan.',
            ]);
        }

        foreach ($selected as $candidate) {
            $candidate->loadMissing(['order.payments', 'items']);

            if (! $this->compatible($base, $candidate)) {
                throw ValidationException::withMessages([
                    'additional_seller_order_ids' => 'One or more selected Seller Orders no longer match this Buyer, Store, Product, destination, payment method, or shipping snapshot.',
                ]);
            }

            if ($candidate->shipments()->where('shipments.purpose', 'outbound')->exists()) {
                throw ValidationException::withMessages([
                    'additional_seller_order_ids' => "Seller Order {$candidate->seller_order_no} already has an outbound Shipment.",
                ]);
            }
        }
    }

    /** @return list<int> */
    public function normalizeIds(mixed $rawIds, int $baseSellerOrderId): array
    {
        if ($rawIds === null || $rawIds === '') {
            return [];
        }

        if (! is_array($rawIds) || count($rawIds) > self::MAX_INCLUDED_SELLER_ORDERS) {
            throw ValidationException::withMessages([
                'additional_seller_order_ids' => 'Select up to 50 additional Seller Orders.',
            ]);
        }

        $normalized = [];

        foreach ($rawIds as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || $id === $baseSellerOrderId) {
                throw ValidationException::withMessages([
                    'additional_seller_order_ids' => 'The selected Seller Order list is invalid.',
                ]);
            }
            $normalized[] = (int) $id;
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw ValidationException::withMessages([
                'additional_seller_order_ids' => 'A Seller Order can only be selected once.',
            ]);
        }

        sort($normalized);

        return $normalized;
    }

    private function compatible(SellerOrder $base, SellerOrder $candidate): bool
    {
        if ((int) $candidate->id === (int) $base->id
            || (int) $candidate->store_id !== (int) $base->store_id
            || $candidate->status !== 'accepted'
            || ! $candidate->order
            || (int) $candidate->order->buyer_id !== (int) $base->order?->buyer_id
            || ! $this->sameDestination($base, $candidate)
            || ! $this->samePaymentMethod($base, $candidate)
            || ! $this->hasCompatibleShippingSnapshot($base, $candidate)) {
            return false;
        }

        $baseProductIds = $base->items
            ->pluck('product_id')
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $candidateProductIds = $candidate->items
            ->pluck('product_id')
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique();

        return $baseProductIds->count() === 1
            && $candidate->items->isNotEmpty()
            && $candidate->items->every(fn ($item): bool => (int) $item->product_id > 0)
            && $candidateProductIds->count() === 1
            && (int) $baseProductIds->first() === (int) $candidateProductIds->first();
    }

    private function sameDestination(SellerOrder $left, SellerOrder $right): bool
    {
        $leftOrder = $left->order;
        $rightOrder = $right->order;

        if (! $leftOrder || ! $rightOrder) {
            return false;
        }

        foreach ([
            'recipient_name',
            'recipient_phone',
            'address_line',
            'barangay',
            'city_municipality',
            'province',
            'postal_code',
            'psgc_city_code',
        ] as $field) {
            if ($this->normalizedAddressPart($leftOrder->{$field}) !== $this->normalizedAddressPart($rightOrder->{$field})) {
                return false;
            }
        }

        foreach (['delivery_latitude', 'delivery_longitude'] as $field) {
            $leftCoordinate = $leftOrder->{$field};
            $rightCoordinate = $rightOrder->{$field};
            if (($leftCoordinate === null) !== ($rightCoordinate === null)) {
                return false;
            }
            if ($leftCoordinate !== null && round((float) $leftCoordinate, 6) !== round((float) $rightCoordinate, 6)) {
                return false;
            }
        }

        return true;
    }

    private function normalizedAddressPart(mixed $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value));
    }

    private function samePaymentMethod(SellerOrder $left, SellerOrder $right): bool
    {
        $leftPayment = $this->paymentFor($left);
        $rightPayment = $this->paymentFor($right);

        return $leftPayment !== null
            && $rightPayment !== null
            && in_array($leftPayment->method, ['cod', 'online'], true)
            && $leftPayment->method === $rightPayment->method;
    }

    private function paymentFor(SellerOrder $sellerOrder): mixed
    {
        $payments = $sellerOrder->order?->payments
            ?->where('status', '!=', 'cancelled')
            ->sortBy('id')
            ->values() ?? collect();

        if ($payments->count() !== 1) {
            return null;
        }

        $payment = $payments->first();

        return in_array($payment->status, ['pending', 'paid'], true) ? $payment : null;
    }

    private function hasCompatibleShippingSnapshot(SellerOrder $base, SellerOrder $candidate): bool
    {
        $baseQuote = (array) $base->shipping_quote;
        $candidateQuote = (array) $candidate->shipping_quote;
        $baseProvider = trim((string) $base->delivery_provider_key);
        $candidateProvider = trim((string) $candidate->delivery_provider_key);

        if ($baseProvider === ''
            || $baseProvider !== $candidateProvider
            || $baseProvider !== trim((string) ($baseQuote['provider_key'] ?? ''))
            || $candidateProvider !== trim((string) ($candidateQuote['provider_key'] ?? ''))) {
            return false;
        }

        foreach (['pricing_rule', 'zone_level', 'zone_classification', 'currency', 'origin', 'destination'] as $field) {
            if (! array_key_exists($field, $baseQuote)
                || ! array_key_exists($field, $candidateQuote)
                || $this->normalizeSnapshotValue($baseQuote[$field]) !== $this->normalizeSnapshotValue($candidateQuote[$field])) {
                return false;
            }
        }

        return true;
    }

    private function normalizeSnapshotValue(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            foreach ($value as $key => $part) {
                $value[$key] = $this->normalizeSnapshotValue($part);
            }

            return $value;
        }

        if (is_string($value)) {
            return Str::lower(preg_replace('/\\s+/u', ' ', trim($value)) ?? trim($value));
        }

        return $value;
    }

}
