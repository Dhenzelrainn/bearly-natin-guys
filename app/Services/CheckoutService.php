<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly ShippingQuoteService $shipping,
        private readonly BuyerCatalogService $catalog,
        private readonly InventoryReservationService $reservations,
        private readonly PostalCodeLookup $postalCodes,
        private readonly NotificationService $notifications,
    ) {}

    public function quote(User $buyer, array $cartItemIds, int $addressId): array
    {
        [$destination, $groups] = $this->prepare($buyer, $cartItemIds, $addressId, false);
        $totals = $this->totals($destination, $groups);
        $groups = $totals['_groups'];
        unset($totals['_groups']);
        $context = $this->quoteContext($buyer, $destination, $groups, $totals, $cartItemIds);
        $expiresAt = now()->addMinutes(10);

        $totals['items'] = $context['items'];
        $totals['delivery_address'] = [
            'id' => (int) $destination->id,
            'name' => $context['address']['recipient_name'],
            'phone' => $context['address']['phone'],
            'street' => trim(implode(' ', array_filter([
                $context['address']['house_number'],
                $context['address']['street'],
            ]))),
            'barangay' => $context['address']['barangay'],
            'city' => $context['address']['city_municipality'],
            'province' => $context['address']['province'],
            'postal' => $context['address']['postal_code'],
            'isDefault' => (bool) $destination->is_default_shipping,
        ];
        $totals['quote_token'] = Crypt::encryptString(json_encode([
            'buyer_id' => $buyer->id,
            'address_id' => $destination->id,
            'cart_item_ids' => $context['cart_item_ids'],
            'context_hash' => $this->contextHash($context),
            'expires_at' => $expiresAt->timestamp,
        ], JSON_THROW_ON_ERROR));
        $totals['quote_expires_at'] = $expiresAt->toIso8601String();

        return $totals;
    }

    public function place(
        User $buyer,
        array $cartItemIds,
        int $addressId,
        string $requestKey,
        string $quoteToken,
        ?string $sellerNote = null,
    ): Order {
        $existing = $this->existingRequest($buyer, $requestKey);
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($buyer, $cartItemIds, $addressId, $requestKey, $quoteToken, $sellerNote): Order {
                User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
                $existing = $this->existingRequest($buyer, $requestKey, true);
                if ($existing) {
                    return $existing;
                }

                [$destination, $groups, $cartItems] = $this->prepare($buyer, $cartItemIds, $addressId, true);
                $existing = $this->existingRequest($buyer, $requestKey, true);
                if ($existing) {
                    return $existing;
                }
                $totals = $this->totals($destination, $groups, true);
                $groups = $totals['_groups'];
                unset($totals['_groups']);
                $this->assertQuoteIsCurrent(
                    $buyer,
                    $destination,
                    $groups,
                    $totals,
                    $cartItems->pluck('id')->all(),
                    $quoteToken,
                );
                $now = now();
                $createdSellerOrders = [];

                $order = Order::query()->create([
                    'order_no' => $this->number('BRY'),
                    'buyer_id' => $buyer->id,
                    'shipping_address_id' => $destination->id,
                    'recipient_name' => $destination->recipient_name,
                    'recipient_phone' => $destination->phone,
                    'address_line' => trim(implode(' ', array_filter([
                        trim((string) $destination->house_number),
                        trim((string) $destination->street),
                    ]))),
                    'barangay' => $destination->barangay,
                    'city_municipality' => $destination->city_municipality,
                    'province' => $destination->province,
                    'postal_code' => $destination->postal_code,
                    'psgc_city_code' => $destination->psgc_city_code,
                    'currency' => 'PHP',
                    'subtotal_minor' => $totals['subtotal_minor'],
                    'shipping_fee_minor' => $totals['shipping_fee_minor'],
                    'discount_minor' => 0,
                    'total_minor' => $totals['total_minor'],
                    'status' => 'processing',
                    'payment_status' => 'unpaid',
                    'checkout_request_key' => $requestKey,
                    'placed_at' => $now,
                ]);

                foreach ($groups as $storeId => $group) {
                    $sellerOrder = SellerOrder::query()->create([
                        'seller_order_no' => $this->number('SO'),
                        'order_id' => $order->id,
                        'store_id' => $storeId,
                        'status' => 'pending',
                        'subtotal_minor' => $group['subtotal_minor'],
                        'discount_minor' => 0,
                        'shipping_fee_minor' => $group['quote']['shipping_fee_minor'],
                        'total_minor' => $group['subtotal_minor'] + $group['quote']['shipping_fee_minor'],
                        'seller_note' => $sellerNote,
                        'delivery_provider_key' => $group['quote']['provider_key'],
                        'shipping_quote' => $group['quote'],
                    ]);

                    $createdSellerOrders[] = $sellerOrder;

                    foreach ($group['items'] as $line) {
                        /** @var ProductVariant $variant */
                        $variant = $line['variant'];
                        $newReserved = (int) $variant->stock_reserved + $line['quantity'];
                        $productImage = $this->catalog->orderImageRecord($line['product'], $variant);
                        $category = $line['product']->category;

                        $variant->update(['stock_reserved' => $newReserved]);
                        InventoryMovement::query()->create([
                            'variant_id' => $variant->id,
                            'type' => 'order_reserved',
                            'quantity_delta' => 0,
                            'balance_after' => (int) $variant->stock_on_hand,
                            'reserved_delta' => $line['quantity'],
                            'reserved_balance_after' => $newReserved,
                            'reference_type' => Order::class,
                            'reference_id' => $order->id,
                            'reason' => "Reserved {$line['quantity']} unit(s) for {$order->order_no}",
                            'created_by' => $buyer->id,
                            'occurred_at' => $now,
                        ]);

                        $sellerOrder->items()->create([
                            'product_id' => $line['product']->id,
                            'product_variant_id' => $variant->id,
                            'product_name' => $line['product']->name,
                            'variant_name' => $variant->name,
                            'sku' => $variant->sku,
                            'options' => $variant->options,
                            'product_image_disk' => $productImage?->disk ?: ($productImage ? 'public' : null),
                            'product_image_path' => $productImage?->path,
                            'category_id_snapshot' => $category?->id,
                            'category_name_snapshot' => $category?->name,
                            'unit_price_minor' => (int) $variant->price_minor,
                            'quantity' => $line['quantity'],
                            'discount_minor' => 0,
                            'subtotal_minor' => $line['subtotal_minor'],
                        ]);
                    }
                }

                Payment::query()->create([
                    'payment_no' => $this->number('PAY'),
                    'order_id' => $order->id,
                    'method' => 'cod',
                    'provider' => null,
                    'amount_minor' => $totals['total_minor'],
                    'status' => 'pending',
                    'initiated_at' => $now,
                    'metadata' => ['collection_method' => 'cash_on_delivery'],
                ]);

                CartItem::query()->whereIn('id', $cartItems->pluck('id'))->delete();

                $this->notifySellersOfNewOrder($order, $createdSellerOrders, $buyer);

                return $order->fresh(['sellerOrders.items', 'payments']);
            }, attempts: 3);
        } catch (QueryException $exception) {
            // A concurrent retry may win the unique buyer/request-key index.
            $existing = $this->existingRequest($buyer, $requestKey);
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  list<SellerOrder>  $sellerOrders
     */
    private function notifySellersOfNewOrder(Order $order, array $sellerOrders, User $buyer): void
    {
        foreach ($sellerOrders as $sellerOrder) {
            $sellerUser = $sellerOrder->store?->sellerProfile?->user;

            if (! $sellerUser) {
                continue;
            }

            $total = '₱'.number_format(((int) $sellerOrder->total_minor) / 100, 2);

            $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
                eventKey: 'seller_order_updates',
                title: 'New order received',
                body: "{$sellerOrder->seller_order_no} ({$total}) was placed by {$buyer->name}.",
                actionUrl: route('seller.orders'),
                type: 'success',
                meta: ['order_id' => (int) $order->id, 'seller_order_id' => (int) $sellerOrder->id],
            ));
        }
    }

    /**
     * @param  Collection<int, SellerOrder>  $sellerOrders
     */
    private function notifySellersOfCancellation(Order $order, Collection $sellerOrders, User $buyer, string $reason): void
    {
        foreach ($sellerOrders as $sellerOrder) {
            $sellerUser = $sellerOrder->store?->sellerProfile?->user;

            if (! $sellerUser) {
                continue;
            }

            $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
                eventKey: 'seller_order_cancelled',
                title: 'Order cancelled by buyer',
                body: "{$buyer->name} cancelled {$sellerOrder->seller_order_no} before it was confirmed. Reason: {$reason}.",
                actionUrl: route('seller.orders'),
                type: 'warning',
                meta: ['order_id' => (int) $order->id, 'seller_order_id' => (int) $sellerOrder->id],
            ));
        }
    }

    public function cancel(User $buyer, int $orderId, string $reason): Order
    {
        return DB::transaction(function () use ($buyer, $orderId, $reason): Order {
            $order = Order::query()
                ->where('buyer_id', $buyer->id)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();
            $sellerOrders = $order->sellerOrders()->orderBy('id')->lockForUpdate()->get();
            $activeSellerOrders = $sellerOrders->reject(fn (SellerOrder $sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Cancelled->value
            );

            if ($activeSellerOrders->isEmpty() || $activeSellerOrders->contains(fn (SellerOrder $sellerOrder): bool => $sellerOrder->status !== SellerOrderStatus::Pending->value)) {
                abort(409, 'This order can no longer be cancelled because a Seller has started processing it.');
            }

            $cancelledAt = now();

            foreach ($activeSellerOrders as $sellerOrder) {
                $this->reservations->releaseForBuyerCancellation($sellerOrder, $order, $buyer, $reason);
                $previousStatus = (string) $sellerOrder->status;
                $sellerOrder->update([
                    'status' => SellerOrderStatus::Cancelled->value,
                    'cancelled_at' => $cancelledAt,
                ]);
                SellerOrderStatusHistory::query()->create([
                    'seller_order_id' => $sellerOrder->id,
                    'from_status' => $previousStatus,
                    'to_status' => SellerOrderStatus::Cancelled->value,
                    'actor_user_id' => $buyer->id,
                    'reason' => $reason,
                    'occurred_at' => $cancelledAt,
                ]);
            }

            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'cancelled',
                'cancelled_at' => $cancelledAt,
                'cancel_reason' => $reason,
            ]);
            $order->payments()->where('status', 'pending')->update(['status' => 'cancelled']);

            $this->notifySellersOfCancellation($order, $activeSellerOrders, $buyer, $reason);

            return $order->fresh(['sellerOrders.items', 'payments']);
        });
    }

    private function prepare(User $buyer, array $cartItemIds, int $addressId, bool $lock): array
    {
        $cartItemIds = collect($cartItemIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        if ($cartItemIds->isEmpty() || $cartItemIds->contains(fn (int $id): bool => $id < 1)) {
            throw ValidationException::withMessages([
                'cart_item_ids' => 'Select at least one saved cart item to continue.',
            ]);
        }

        $cartQuery = Cart::query()
            ->where('user_id', $buyer->id)
            ->where('status', 'active')
            ->latest('id');
        if ($lock) {
            $cartQuery->lockForUpdate();
        }
        $cart = $cartQuery->first();

        if (! $cart) {
            throw ValidationException::withMessages(['cart_item_ids' => 'Your saved cart is empty.']);
        }

        $itemsQuery = CartItem::query()
            ->where('cart_id', $cart->id)
            ->whereIn('id', $cartItemIds)
            ->orderBy('id');
        if ($lock) {
            $itemsQuery->lockForUpdate();
        }
        $cartItems = $itemsQuery->get();

        if ($cartItems->count() !== $cartItemIds->count()) {
            throw ValidationException::withMessages([
                'cart_item_ids' => 'One or more selected cart items are no longer available in your cart. Refresh and try again.',
            ]);
        }

        $variantIds = $cartItems->pluck('product_variant_id')->unique()->values();
        $variantsQuery = ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id');
        if ($lock) {
            $variantsQuery->lockForUpdate();
        }
        $variants = $variantsQuery->get()->keyBy('id');

        $productIds = $variants->pluck('product_id')->unique()->values();
        $productsQuery = Product::query()
            ->visibleToBuyers()
            ->with(['store.sellerProfile.pickupAddress', 'images.variant', 'category'])
            ->whereIn('id', $productIds)
            ->orderBy('id');
        if ($lock) {
            $productsQuery->lockForUpdate();
        }
        $products = $productsQuery->get()->keyBy('id');

        if ($lock && $products->isNotEmpty()) {
            $profileIds = $products
                ->map(fn (Product $product) => $product->store?->seller_profile_id)
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $profiles = SellerProfile::query()
                ->whereIn('id', $profileIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $stores = Store::query()
                ->whereIn('id', $products->pluck('store_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sellerUsers = User::query()
                ->whereIn('id', $profiles->pluck('user_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $addressIds = $profiles->pluck('pickup_address_id')
                ->filter()
                ->merge([$addressId])
                ->unique()
                ->sort()
                ->values();
            $lockedAddresses = Address::query()
                ->whereIn('id', $addressIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($products as $product) {
                $store = $stores->get($product->store_id);
                $profile = $store ? $profiles->get($store->seller_profile_id) : null;
                $seller = $profile ? $sellerUsers->get($profile->user_id) : null;
                if ($profile) {
                    $profile->setRelation('pickupAddress', $lockedAddresses->get($profile->pickup_address_id));
                }
                if ($store) {
                    $store->setRelation('sellerProfile', $profile);
                    $product->setRelation('store', $store);
                }

                if (! $store
                    || $store->publication_status !== 'published'
                    || ! $profile
                    || $profile->standing_status !== 'good_standing'
                    || ! $seller
                    || $seller->status !== 'active') {
                    throw ValidationException::withMessages([
                        'cart_item_ids' => "{$product->name} is no longer available for Buyer checkout. Refresh your cart and try again.",
                    ]);
                }
            }

            $destination = $lockedAddresses->get($addressId);
            if (! $destination || (int) $destination->user_id !== (int) $buyer->id) {
                throw ValidationException::withMessages(['address_id' => 'Choose one of your saved delivery addresses.']);
            }
        }

        if (! $lock) {
            $destination = Address::query()
                ->where('user_id', $buyer->id)
                ->whereKey($addressId)
                ->first();
        } elseif (! isset($destination)) {
            $destination = Address::query()
                ->where('user_id', $buyer->id)
                ->whereKey($addressId)
                ->lockForUpdate()
                ->first();
        }
        if (! $destination) {
            throw ValidationException::withMessages(['address_id' => 'Choose one of your saved delivery addresses.']);
        }

        $destinationCityCode = $this->postalCodes->canonicalCityCode(
            $destination->psgc_city_code,
            (string) $destination->province,
            (string) $destination->city_municipality,
        );

        if (! $destinationCityCode) {
            throw ValidationException::withMessages([
                'address_id' => 'Update this delivery address with a valid municipality/city before checkout.',
            ]);
        }

        $destination->setAttribute('psgc_city_code', $destinationCityCode);

        $groups = [];
        foreach ($cartItems as $cartItem) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($cartItem->product_variant_id);
            $product = $variant ? $products->get($variant->product_id) : null;

            if (! $variant || ! $product || ! $variant->is_active) {
                throw ValidationException::withMessages([
                    'cart_item_ids' => 'A selected product is no longer available. Remove it from your cart and try again.',
                ]);
            }

            $quantity = (int) $cartItem->quantity;
            if ($quantity < 1 || $quantity > $variant->available_stock) {
                throw ValidationException::withMessages([
                    'cart_item_ids' => "Only {$variant->available_stock} unit(s) of {$product->name} are currently available.",
                ]);
            }

            $packageFields = [
                $variant->package_weight_kg,
                $variant->package_length_cm,
                $variant->package_width_cm,
                $variant->package_height_cm,
            ];
            if (in_array(null, $packageFields, true) || min(array_map('floatval', $packageFields)) <= 0) {
                throw ValidationException::withMessages([
                    'shipping' => "Shipping package details are incomplete for {$product->name} ({$variant->sku}). Ask the Seller to update the SKU's packaged weight and dimensions.",
                ]);
            }

            $paymentMethods = array_values(array_intersect((array) $product->payment_methods, ['cod', 'online']));
            if ($paymentMethods !== [] && ! in_array('cod', $paymentMethods, true)) {
                throw ValidationException::withMessages([
                    'payment_method' => "{$product->name} does not accept Cash on Delivery.",
                ]);
            }

            $store = $product->store;
            if (! $store || ! $store->sellerProfile || ! $store->sellerProfile->pickupAddress) {
                throw ValidationException::withMessages([
                    'shipping' => "{$product->name}'s Store needs a valid pickup address before checkout.",
                ]);
            }

            $storeId = (int) $store->id;
            $unitPriceMinor = (int) $variant->price_minor;
            if ($unitPriceMinor < 0 || $unitPriceMinor > intdiv(PHP_INT_MAX, $quantity)) {
                throw ValidationException::withMessages([
                    'cart_item_ids' => "The current price for {$product->name} exceeds the supported order total.",
                ]);
            }
            $lineSubtotal = $unitPriceMinor * $quantity;
            $currentStoreSubtotal = (int) ($groups[$storeId]['subtotal_minor'] ?? 0);
            if ($currentStoreSubtotal > PHP_INT_MAX - $lineSubtotal) {
                throw ValidationException::withMessages([
                    'cart_item_ids' => 'The selected items exceed the supported order total.',
                ]);
            }

            $line = [
                'cart_item_id' => (int) $cartItem->id,
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'subtotal_minor' => $lineSubtotal,
            ];
            $groups[$storeId] ??= ['store' => $store, 'items' => [], 'subtotal_minor' => 0];
            $groups[$storeId]['items'][] = $line;
            $groups[$storeId]['subtotal_minor'] += $line['subtotal_minor'];
        }

        return [$destination, $groups, $cartItems];
    }

    private function totals(Address $destination, array $groups, bool $lockConfiguration = false): array
    {
        $subtotal = 0;
        $shippingFee = 0;

        if ($lockConfiguration && $groups !== []) {
            $this->shipping->lockQuoteConfiguration();
        }

        foreach ($groups as &$group) {
            if ($subtotal > PHP_INT_MAX - $group['subtotal_minor']) {
                throw ValidationException::withMessages([
                    'cart_item_ids' => 'The selected items exceed the supported order total.',
                ]);
            }
            $subtotal += $group['subtotal_minor'];
            $group['quote'] = $this->shipping->quote(
                $group['store'],
                $destination,
                new Collection($group['items']),
                $lockConfiguration,
            );
            $sellerShipping = (int) $group['quote']['shipping_fee_minor'];
            if ($shippingFee > PHP_INT_MAX - $sellerShipping) {
                throw ValidationException::withMessages([
                    'shipping' => 'The combined shipping estimate exceeds the supported order total.',
                ]);
            }
            $shippingFee += $sellerShipping;
        }
        unset($group);

        if ($subtotal > PHP_INT_MAX - $shippingFee) {
            throw ValidationException::withMessages([
                'shipping' => 'The checkout total exceeds the supported order total.',
            ]);
        }

        return [
            'subtotal_minor' => $subtotal,
            'shipping_fee_minor' => $shippingFee,
            'discount_minor' => 0,
            'total_minor' => $subtotal + $shippingFee,
            'seller_orders' => array_values(array_map(static fn (array $group): array => [
                'store_name' => $group['store']->name,
                'item_count' => array_sum(array_column($group['items'], 'quantity')),
                'subtotal_minor' => $group['subtotal_minor'],
                'shipping_fee_minor' => $group['quote']['shipping_fee_minor'],
                'quote' => $group['quote'],
            ], $groups)),
            '_groups' => $groups,
        ];
    }

    private function quoteContext(
        User $buyer,
        Address $destination,
        array $groups,
        array $totals,
        array $cartItemIds,
    ): array {
        $items = [];
        $sellerOrders = [];

        foreach ($groups as $storeId => $group) {
            foreach ($group['items'] as $line) {
                /** @var ProductVariant $variant */
                $variant = $line['variant'];
                $items[] = [
                    'cart_item_id' => (int) $line['cart_item_id'],
                    'store_id' => (int) $storeId,
                    'product_id' => (int) $line['product']->id,
                    'product_name' => (string) $line['product']->name,
                    'variant_id' => (int) $variant->id,
                    'variant_name' => (string) $variant->name,
                    'sku' => (string) $variant->sku,
                    'options' => $variant->options ?? [],
                    'quantity' => (int) $line['quantity'],
                    'unit_price_minor' => (int) $variant->price_minor,
                    'subtotal_minor' => (int) $line['subtotal_minor'],
                    'seller_name' => (string) $group['store']->name,
                ];
            }

            $quote = $group['quote'];
            usort($quote['package_items'], static fn (array $left, array $right): int => [$left['sku'], $left['quantity']] <=> [$right['sku'], $right['quantity']]
            );
            $sellerOrders[] = [
                'store_id' => (int) $storeId,
                'store_name' => (string) $group['store']->name,
                'subtotal_minor' => (int) $group['subtotal_minor'],
                'shipping_fee_minor' => (int) $quote['shipping_fee_minor'],
                'quote' => $quote,
            ];
        }

        usort($items, static fn (array $left, array $right): int => $left['cart_item_id'] <=> $right['cart_item_id']);
        usort($sellerOrders, static fn (array $left, array $right): int => $left['store_id'] <=> $right['store_id']);

        return [
            'buyer_id' => (int) $buyer->id,
            'address_id' => (int) $destination->id,
            'address' => [
                'recipient_name' => (string) $destination->recipient_name,
                'phone' => (string) $destination->phone,
                'house_number' => (string) ($destination->house_number ?? ''),
                'street' => (string) $destination->street,
                'barangay' => (string) $destination->barangay,
                'city_municipality' => (string) $destination->city_municipality,
                'province' => (string) $destination->province,
                'postal_code' => $destination->postal_code,
                'psgc_city_code' => $destination->psgc_city_code,
                'psgc_province_code' => $destination->psgc_province_code,
            ],
            'cart_item_ids' => collect($cartItemIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all(),
            'items' => $items,
            'totals' => [
                'subtotal_minor' => (int) $totals['subtotal_minor'],
                'shipping_fee_minor' => (int) $totals['shipping_fee_minor'],
                'discount_minor' => (int) $totals['discount_minor'],
                'total_minor' => (int) $totals['total_minor'],
            ],
            'seller_orders' => $sellerOrders,
        ];
    }

    private function contextHash(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function assertQuoteIsCurrent(
        User $buyer,
        Address $destination,
        array $groups,
        array $totals,
        array $cartItemIds,
        string $quoteToken,
    ): void {
        try {
            $accepted = json_decode(Crypt::decryptString($quoteToken), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $accepted = null;
        }

        $context = $this->quoteContext($buyer, $destination, $groups, $totals, $cartItemIds);
        $valid = is_array($accepted)
            && (int) ($accepted['buyer_id'] ?? 0) === (int) $buyer->id
            && (int) ($accepted['address_id'] ?? 0) === (int) $destination->id
            && (int) ($accepted['expires_at'] ?? 0) >= now()->timestamp
            && ($accepted['cart_item_ids'] ?? null) === $context['cart_item_ids']
            && is_string($accepted['context_hash'] ?? null)
            && hash_equals($accepted['context_hash'], $this->contextHash($context));

        if (! $valid) {
            throw ValidationException::withMessages([
                'quote_token' => 'Your checkout quote expired or its prices, delivery details, or shipping terms changed. Review the updated quote before placing the order.',
            ]);
        }
    }

    private function existingRequest(User $buyer, string $requestKey, bool $lock = false): ?Order
    {
        $query = Order::query()
            ->where('buyer_id', $buyer->id)
            ->where('checkout_request_key', $requestKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->with(['sellerOrders.items', 'payments'])->first();
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
    }
}
