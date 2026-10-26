<?php

namespace App\Http\Controllers;

use App\Enums\SellerOrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\BuyerCatalogService;
use App\Services\BuyerOrderConfirmationService;
use App\Services\CampaignService;
use App\Services\ConversationService;
use App\Services\InternationalPhone;
use App\Services\PostalCodeLookup;
use App\Services\ProductPricingService;
use App\Services\ProductReviewService;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BuyerController extends Controller
{
    public function __construct(private readonly ProductPricingService $pricing) {}

    private function hasBuyerTables(): bool
    {
        return Schema::hasTable('products')
            && Schema::hasTable('stores')
            && Schema::hasTable('product_variants')
            && Schema::hasTable('carts')
            && Schema::hasTable('cart_items')
            && Schema::hasTable('wishlists');
    }

    private function hasMarketplaceTaxonomyTables(): bool
    {
        return $this->hasBuyerTables()
            && Schema::hasTable('marketplace_categories')
            && Schema::hasTable('marketplace_subcategories')
            && Schema::hasTable('marketplace_category_business_categories')
            && Schema::hasColumn('products', 'marketplace_subcategory_id');
    }

    /**
     * Buyer messaging center. Conversations and the initially selected thread
     * are loaded from persisted data; the bootstrap payload keeps the page
     * server-driven while `bearly-chat.js` handles in-place updates.
     */
    public function chat(Request $request, ConversationService $conversations): View
    {
        $buyer = $request->user();

        $inbox = $conversations->inboxForBuyer($buyer);

        $activeId = (int) $request->query('conversation', 0);
        $active = null;

        if ($activeId > 0) {
            $candidate = Conversation::query()->find($activeId);

            abort_unless(
                $candidate && $conversations->isParticipant($candidate, $buyer),
                404,
            );

            $active = $candidate;
        } elseif ($inbox !== []) {
            $active = Conversation::query()->find((int) $inbox[0]['id']);
        }

        return view('buyer.chat', [
            'chatBootstrap' => [
                'conversations' => $inbox,
                'active_id' => $active ? (int) $active->id : null,
                'thread' => $active
                    ? $conversations->threadPayload($active, $buyer, 'buyer.chat.attachment')
                    : null,
            ],
        ]);
    }

    public function profile(Request $request): View
    {
        return view('buyer.profile', [
            'wishlistItems' => $this->wishlistPayload($request->user()),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $phoneInput = trim((string) $request->input('phone'));

        try {
            $phone = $this->normalizePhone($phoneInput);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'phone' => $exception->errors()['contact_number']
                    ?? ['Enter a valid mobile number.'],
            ]);
        }

        $request->merge(['phone' => $phone]);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => [
                'nullable',
                'string',
                'max:17',
                Rule::unique('users', 'contact_number')->ignore($user->id),
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'gender' => ['nullable', 'string', 'in:Male,Female,Other,male,female,prefer_not_to_say'],
            'birthday' => [
                'nullable',
                'bail',
                'date',
                'before:today',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Carbon::parse((string) $value)->gt(today()->subYears(18))) {
                        $fail('You must be at least 18 years old.');
                    }
                },
            ],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['nullable', 'boolean'],
        ]);

        $parts = preg_split('/\s+/', trim($validated['full_name'])) ?: [];
        $firstName = trim((string) array_shift($parts));
        $lastName = trim(implode(' ', $parts));

        if ($lastName === '') {
            $lastName = (string) ($user->last_name ?: $firstName);
        }

        $gender = match (strtolower((string) ($validated['gender'] ?? ''))) {
            'male' => 'male',
            'female' => 'female',
            'other', 'prefer_not_to_say' => 'prefer_not_to_say',
            default => null,
        };

        $phone = $validated['phone'] ?? null;

        $oldPhoto = $user->profile_photo_path;

        if ($request->hasFile('photo') && ! $request->boolean('remove_photo')) {
            $newPhoto = $request->file('photo')->store('profile-photos', 'public');
        } else {
            $newPhoto = $oldPhoto;
        }

        if ($request->boolean('remove_photo')) {
            $newPhoto = null;
        }

        $user->forceFill([
            'name' => trim($firstName.' '.$lastName),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => $gender,
            'birthday' => $validated['birthday'] ?? null,
            'birth_date' => $validated['birthday'] ?? null,
            'phone' => $phone !== '' ? $phone : null,
            'contact_number' => $phone !== '' ? $phone : null,
            'profile_photo_path' => $newPhoto,
        ])->save();

        if ($oldPhoto && $oldPhoto !== $newPhoto) {
            Storage::disk('public')->delete($oldPhoto);
        }

        return response()->json([
            'data' => [
                'username' => $this->profileUsername($user),
                'fullName' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?: $user->contact_number,
                'gender' => $user->sex,
                'birthday' => optional($user->birthday)->format('Y-m-d'),
                'photo' => $user->profile_photo_path
                    ? Storage::disk('public')->url($user->profile_photo_path)
                    : '',
            ],
            'message' => 'Profile updated successfully.',
        ]);
    }

    private function profileUsername($user): string
    {
        return str_contains((string) $user->email, '@')
            ? (string) strstr((string) $user->email, '@', true)
            : strtolower(preg_replace('/\s+/', '', (string) $user->name));
    }

    public function addresses(): View
    {
        return view('buyer.addresses');
    }

    public function addressData(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->addresses()
                ->orderByDesc('is_default_shipping')
                ->latest('id')
                ->get()
                ->map(fn (Address $address) => $this->addressPayload($address))
                ->values(),
            'message' => 'Addresses retrieved successfully.',
        ]);
    }

    public function productReviewsData(Request $request, Product $product, ProductReviewService $reviews): JsonResponse
    {
        abort_unless(
            Product::query()->visibleToBuyers()->whereKey($product->getKey())->exists(),
            404
        );

        $limit = max(1, min((int) $request->query('limit', 10), 50));
        $list = $reviews->productReviews($product->id, $limit);

        return response()->json([
            'data' => [
                'summary' => $reviews->productSummary($product->id),
                'reviews' => $list->map(fn ($review): array => $reviews->publicPayload($review))->all(),
            ],
            'message' => 'Product reviews retrieved successfully.',
        ]);
    }

    public function purchaseData(Request $request, BuyerCatalogService $catalog, BuyerOrderConfirmationService $confirmation): JsonResponse
    {
        $orders = Order::query()
            ->where('buyer_id', $request->user()->id)
            ->with([
                'sellerOrders.store',
                'sellerOrders.items.product.images.variant',
                'sellerOrders.items.variant',
                'sellerOrders.shipments.events',
                'sellerOrders.returnRequests',
                'sellerOrders.activeDispute',
            ])
            ->latest('id')
            ->get()
            ->map(fn (Order $order) => $this->purchasePayload($order, $catalog, $confirmation))
            ->values();

        return response()->json([
            'data' => $orders,
            'message' => 'Purchases retrieved successfully.',
        ]);
    }

    /** @return array<string, mixed> */
    private function purchasePayload(Order $order, BuyerCatalogService $catalog, BuyerOrderConfirmationService $confirmation): array
    {
        $sellerOrders = $order->sellerOrders;
        $active = $sellerOrders->reject(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Cancelled->value);
        $outboundShipments = $active->flatMap(fn ($sellerOrder) => $sellerOrder->shipments->where('purpose', 'outbound'));
        $stores = $sellerOrders->map(fn ($sellerOrder): string => (string) ($sellerOrder->store?->name ?? ''))->filter()->unique()->values();

        if ($order->status === 'cancelled' || ($sellerOrders->isNotEmpty() && $active->isEmpty())) {
            $bucket = 'cancelled';
            $label = 'Cancelled';
        } elseif ($active->isNotEmpty() && $active->every(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Completed->value)) {
            $bucket = 'completed';
            $label = 'Completed';
        } elseif ($active->contains(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Completed->value
            || $sellerOrder->shipments->where('purpose', 'outbound')->contains(fn ($shipment): bool => in_array($shipment->status, [ShipmentStatus::Delivered->value, ShipmentStatus::OutForDelivery->value], true)))) {
            $bucket = 'to-receive';
            $label = 'To Receive';
        } elseif ($order->status === 'pending_payment') {
            $bucket = 'to-pay';
            $label = 'To Pay';
        } else {
            $bucket = 'to-ship';
            $label = 'To Ship';
        }

        $items = $sellerOrders->flatMap(fn ($sellerOrder) => $sellerOrder->items->map(function ($item) use ($catalog, $sellerOrder): array {
            $quantity = max(1, (int) $item->quantity);
            $buyerLineTotalMinor = max(
                0,
                (int) $item->subtotal_minor
                    - (int) ($item->discount_minor ?? 0)
                    - (int) ($item->platform_discount_minor ?? 0)
                    - (int) ($item->campaign_platform_discount_minor ?? 0),
            );
            $buyerUnitPriceMinor = intdiv($buyerLineTotalMinor + intdiv($quantity, 2), $quantity);

            return [
                'name' => (string) $item->product_name,
                'variation' => (string) ($item->variant_name ?: 'Standard'),
                'qty' => (int) $item->quantity,
                'order_item_id' => (int) $item->id,
                'seller_order_id' => (int) $sellerOrder->id,
                'price' => $buyerUnitPriceMinor / 100,
                'price_minor' => $buyerUnitPriceMinor,
                'line_total_minor' => $buyerLineTotalMinor,
                'image' => $catalog->orderItemImageUrl($item),
                'store_url' => $sellerOrder->store?->slug
                    ? route('stores.show', $sellerOrder->store->slug, false)
                    : null,
            ];
        }))->values()->all();

        $shop = $stores->isEmpty() ? 'Bearly Store' : $stores->first().($stores->count() > 1 ? ' +'.($stores->count() - 1).' more' : '');
        // Custom timestamp columns are not date-cast by the model, so parse
        // defensively instead of assuming a Carbon instance.
        $rawPlacedAt = $order->placed_at ?? $order->created_at;
        $placedAt = $rawPlacedAt ? Carbon::parse($rawPlacedAt) : null;
        $deliveryState = $this->purchaseDeliveryState($order);
        $deliveredAt = $this->purchaseDeliveredAt($order);
        $canConfirm = $confirmation->canConfirm($order);
        $canCancel = $order->status === 'processing'
            && $active->isNotEmpty()
            && $active->every(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Pending->value);

        return [
            'id' => (string) $order->order_no,
            'order_id' => (int) $order->id,
            'status' => $bucket,
            'statusLabel' => $label,
            'shop' => $shop,
            'date' => $placedAt?->format('M j, Y') ?? '',
            'time' => $placedAt?->format('g:i A') ?? '',
            'placed_at' => $placedAt?->toIso8601String(),
            'total' => ((int) $order->total_minor) / 100,
            'total_minor' => (int) $order->total_minor,
            'items' => $items,
            'seller_orders' => $sellerOrders->map(fn ($sellerOrder): array => [
                'id' => (int) $sellerOrder->id,
                'number' => (string) $sellerOrder->seller_order_no,
                'store' => (string) ($sellerOrder->store?->name ?? 'Seller'),
                'status' => (string) $sellerOrder->status,
                'delivery_status' => $this->sellerOrderDeliveryState($sellerOrder),
                'outbound_shipment_count' => $sellerOrder->shipments->where('purpose', 'outbound')->count(),
                'dispute_url' => $sellerOrder->activeDispute
                    ? route('buyer.disputes.show', $sellerOrder->activeDispute)
                    : route('buyer.disputes.create', $sellerOrder->id),
                'dispute_label' => $sellerOrder->activeDispute
                    ? 'View dispute '.(string) $sellerOrder->activeDispute->dispute_no
                    : 'Report a problem',
                'active_dispute_no' => $sellerOrder->activeDispute?->dispute_no,
                'return_url' => $sellerOrder->status === SellerOrderStatus::Completed->value
                    ? route('buyer.returns.create', $sellerOrder->id)
                    : null,
                'return_cases' => $sellerOrder->returnRequests->map(fn ($returnRequest): array => [
                    'number' => (string) $returnRequest->return_no,
                    'status' => (string) $returnRequest->status,
                    'url' => route('buyer.returns.show', $returnRequest->id),
                ])->values()->all(),
            ])->values()->all(),
            'paymentMethod' => 'Cash on Delivery',
            'server' => true,
            'can_confirm' => $canConfirm,
            'can_cancel' => $canCancel,
            'confirm_url' => $canConfirm ? route('checkout.confirm-receipt', $order->id) : null,
            'cancel_url' => $canCancel ? route('checkout.cancel', $order->id) : null,
            'details_url' => route('checkout.confirmation', $order->order_no),
            'trackingStatus' => $this->purchaseTrackingLabel($bucket, $deliveryState),
            'delivery_status' => $deliveryState,
            'delivered_at' => $deliveredAt?->toIso8601String(),
            'delivered_at_display' => $deliveredAt ? $deliveredAt->format('M j, Y · g:i A') : null,
            'delivery_address' => [
                'name' => (string) $order->recipient_name,
                'phone' => (string) $order->recipient_phone,
                'street' => (string) $order->address_line,
                'barangay' => (string) $order->barangay,
                'city' => (string) $order->city_municipality,
                'province' => (string) $order->province,
                'postal' => (string) ($order->postal_code ?? ''),
            ],
            'trackingEvents' => $outboundShipments
                ->flatMap(fn ($shipment) => $shipment->events->map(fn ($event): array => [
                    'label' => $this->trackingEventLabel((string) $event->event_type, (string) $event->to_status),
                    'note' => $this->buyerSafeTrackingEventNote((string) $event->event_type),
                    'date' => $event->occurred_at ? Carbon::parse($event->occurred_at)->toIso8601String() : null,
                ]))
                ->filter(fn (array $event): bool => filled($event['date']))
                ->sortBy('date')
                ->values()
                ->all(),
        ];
    }

    /**
     * Physical delivery state derived from the order's shipments, independent
     * of the purchase bucket (a delivered parcel awaiting buyer confirmation
     * is still physically delivered).
     *
     * @return 'preparing'|'in_transit'|'out_for_delivery'|'delivered'
     */
    private function purchaseDeliveryState(Order $order): string
    {
        $activeSellerOrders = $order->sellerOrders
            ->reject(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Cancelled->value)
            ->values();

        if ($activeSellerOrders->isEmpty()) {
            return 'preparing';
        }

        $outboundBySellerOrder = $activeSellerOrders->map(fn ($sellerOrder) => $sellerOrder->shipments
            ->where('purpose', 'outbound')
            ->pluck('status'));
        $statuses = $outboundBySellerOrder
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        if ($statuses->isEmpty() || $outboundBySellerOrder->contains(fn ($sellerStatuses): bool => $sellerStatuses->isEmpty())) {
            return 'preparing';
        }

        if ($outboundBySellerOrder->every(fn ($sellerStatuses): bool => $sellerStatuses->every(
            fn (string $status): bool => $status === ShipmentStatus::Delivered->value,
        ))) {
            return 'delivered';
        }

        if ($statuses->contains(ShipmentStatus::OutForDelivery->value)) {
            return 'out_for_delivery';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, [
            ShipmentStatus::PickedUp->value,
            ShipmentStatus::AtSortingCenter->value,
            ShipmentStatus::Sorted->value,
            ShipmentStatus::Dispatched->value,
        ], true))) {
            return 'in_transit';
        }

        return 'preparing';
    }

    private function purchaseDeliveredAt(Order $order): ?Carbon
    {
        if ($this->purchaseDeliveryState($order) !== 'delivered') {
            return null;
        }

        $occurredAt = $order->sellerOrders
            ->flatMap(fn ($sellerOrder) => $sellerOrder->shipments
                ->where('purpose', 'outbound')
                ->flatMap(fn ($shipment) => $shipment->events))
            ->filter(fn ($event): bool => (string) $event->to_status === ShipmentStatus::Delivered->value && $event->occurred_at !== null)
            ->map(fn ($event): Carbon => Carbon::parse($event->occurred_at))
            ->sort()
            ->last();

        return $occurredAt;
    }

    private function purchaseTrackingLabel(string $bucket, string $deliveryState): string
    {
        if ($bucket === 'cancelled') {
            return 'Cancelled';
        }

        if ($deliveryState === 'delivered') {
            return 'Delivered';
        }

        return match ($bucket) {
            'to-pay' => 'Order Placed',
            'to-ship' => 'Preparing to Ship',
            'to-receive' => match ($deliveryState) {
                'out_for_delivery' => 'Out for Delivery',
                'in_transit' => 'In Transit',
                default => 'Seller shipments in progress',
            },
            'completed' => $deliveryState === 'delivered' ? 'Delivered' : 'Delivery status needs review',
            'cancelled' => 'Cancelled',
            default => 'Order update',
        };
    }

    private function trackingEventLabel(string $eventType, string $toStatus): string
    {
        return match ($eventType) {
            'shipment_ready_for_pickup' => 'Ready for pickup',
            'parcel_picked_up', 'shipment_picked_up' => 'Picked up by Bearly Logistics',
            'received_at_center' => 'Received at sorting center',
            'parcel_sorted', 'shipment_sorted' => 'Sorted for delivery',
            'parcel_dispatched', 'shipment_dispatched' => 'Dispatched to delivery rider',
            'shipment_out_for_delivery', 'parcel_out_for_delivery' => 'Out for delivery',
            'shipment_delivered', 'parcel_delivered' => 'Delivered',
            'shipment_delivery_failed', 'parcel_delivery_failed' => 'Delivery attempt unsuccessful',
            default => Str::of($toStatus ?: $eventType)->replace(['_', '-'], ' ')->title()->toString(),
        };
    }

    private function buyerSafeTrackingEventNote(string $eventType): string
    {
        return match ($eventType) {
            'shipment_ready_for_pickup' => 'The seller prepared this parcel for pickup.',
            'parcel_picked_up', 'shipment_picked_up' => 'The parcel was picked up by Bearly Logistics.',
            'received_at_center' => 'The parcel arrived at a sorting center.',
            'parcel_sorted', 'shipment_sorted' => 'The parcel was sorted for its next delivery step.',
            'parcel_dispatched', 'shipment_dispatched' => 'The parcel left the sorting center for delivery.',
            'shipment_out_for_delivery', 'parcel_out_for_delivery' => 'The parcel is with a delivery rider.',
            'shipment_delivered', 'parcel_delivered' => 'The parcel was marked delivered.',
            'shipment_delivery_failed', 'parcel_delivery_failed' => 'The delivery attempt was unsuccessful.',
            default => '',
        };
    }

    private function sellerOrderDeliveryState($sellerOrder): string
    {
        if ($sellerOrder->status === SellerOrderStatus::Cancelled->value) {
            return 'cancelled';
        }

        $statuses = $sellerOrder->shipments
            ->where('purpose', 'outbound')
            ->pluck('status');

        if ($statuses->isEmpty()) {
            return 'preparing';
        }

        if ($statuses->every(fn (string $status): bool => $status === ShipmentStatus::Delivered->value)) {
            return 'delivered';
        }

        if ($statuses->contains(ShipmentStatus::OutForDelivery->value)) {
            return 'out_for_delivery';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, [
            ShipmentStatus::PickedUp->value,
            ShipmentStatus::AtSortingCenter->value,
            ShipmentStatus::Sorted->value,
            ShipmentStatus::Dispatched->value,
        ], true))) {
            return 'in_transit';
        }

        return 'preparing';
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $data = $this->validateAddress($request);

        $address = DB::transaction(function () use ($request, $data) {
            $user = $request->user();
            $this->lockAddressOwner($user);
            $phone = $this->normalizePhone($data['phone']);
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $user->addresses()->exists();

            if ($makeDefault) {
                $user->addresses()->update(['is_default_shipping' => false]);
            }

            return $user->addresses()->create([
                'label' => $data['label'],
                'recipient_name' => $data['name'],
                'phone' => $phone,
                'house_number' => null,
                'street' => $data['street'],
                'barangay' => $data['barangay'],
                'city_municipality' => $data['city'],
                'province' => $data['province'],
                'postal_code' => $data['postal'],
                'psgc_city_code' => $data['city_code'] ?? null,
                'is_default_shipping' => $makeDefault,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);
        });

        return response()->json([
            'data' => $this->addressPayload($address),
            'message' => 'Address added successfully.',
        ], 201);
    }

    public function updateAddress(Request $request, Address $address): JsonResponse
    {
        return $this->updateAddressRecord($request, $address);
    }

    private function updateAddressRecord(
        Request $request,
        Address $address,
        bool $allowLegacyUnverifiedNonLocationPatch = false,
    ): JsonResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 404);
        $data = $this->validateAddress($request, $allowLegacyUnverifiedNonLocationPatch);

        $address = DB::transaction(function () use (
            $request,
            $address,
            $data,
            $allowLegacyUnverifiedNonLocationPatch,
        ) {
            $user = $request->user();
            $this->lockAddressOwner($user);
            $address = $user->addresses()
                ->whereKey($address->id)
                ->lockForUpdate()
                ->firstOrFail();
            $makeDefault = (bool) ($data['is_default'] ?? false)
                || $address->is_default_shipping
                || ! $user->addresses()->where('is_default_shipping', true)->exists();

            if ($makeDefault) {
                $user->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default_shipping' => false]);
            }

            $phone = $this->normalizePhone($data['phone']);

            $attributes = [
                'label' => $data['label'],
                'recipient_name' => $data['name'],
                'phone' => $phone,
                'street' => $data['street'],
                'barangay' => $data['barangay'],
                'city_municipality' => $data['city'],
                'province' => $data['province'],
                'postal_code' => $data['postal'],
                'psgc_city_code' => $data['city_code'] ?? null,
                'is_default_shipping' => $makeDefault,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ];

            if ($allowLegacyUnverifiedNonLocationPatch) {
                // Keep a legacy null PSGC code unverified until the Buyer explicitly
                // submits a matching code with a location change.
                $address->forceFill($attributes)->saveQuietly();
            } else {
                $address->update($attributes);
            }

            return $address->fresh();
        });

        return response()->json([
            'data' => $this->addressPayload($address),
            'message' => 'Address updated successfully.',
        ]);
    }

    public function deleteAddress(Request $request, Address $address): JsonResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 404);

        DB::transaction(function () use ($request, $address) {
            $user = $request->user();
            $this->lockAddressOwner($user);
            $address = $user->addresses()
                ->whereKey($address->id)
                ->lockForUpdate()
                ->firstOrFail();
            $wasDefault = (bool) $address->is_default_shipping;
            $address->delete();

            if ($wasDefault) {
                $user->addresses()
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_default_shipping' => true]);
            }
        });

        return response()->json([
            'data' => null,
            'message' => 'Address deleted successfully.',
        ]);
    }

    public function setDefaultAddress(Request $request, Address $address): JsonResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 404);

        $address = DB::transaction(function () use ($request, $address) {
            $user = $request->user();
            $this->lockAddressOwner($user);
            $address = $user->addresses()
                ->whereKey($address->id)
                ->lockForUpdate()
                ->firstOrFail();
            $user->addresses()
                ->where('id', '!=', $address->id)
                ->update(['is_default_shipping' => false]);
            $address->update(['is_default_shipping' => true]);

            return $address->fresh();
        });

        return response()->json([
            'data' => $this->addressPayload($address),
            'message' => 'Default address updated successfully.',
        ]);
    }

    public function updateMobileAddress(Request $request, int $address): JsonResponse
    {
        return DB::transaction(function () use ($request, $address): JsonResponse {
            $user = $request->user();
            $this->lockAddressOwner($user);
            $ownedAddress = $user->addresses()
                ->whereKey($address)
                ->lockForUpdate()
                ->firstOrFail();

            $allowLegacyUnverifiedNonLocationPatch = blank($ownedAddress->psgc_city_code)
                && $request->all() !== []
                && array_diff(
                    array_keys($request->all()),
                    ['name', 'phone', 'label', 'is_default'],
                ) === [];

            $this->mergeAddressPatch($request, $ownedAddress);

            return $this->updateAddressRecord(
                $request,
                $ownedAddress,
                $allowLegacyUnverifiedNonLocationPatch,
            );
        });
    }

    public function deleteMobileAddress(Request $request, int $address): JsonResponse
    {
        return DB::transaction(function () use ($request, $address): JsonResponse {
            $user = $request->user();
            $this->lockAddressOwner($user);
            $ownedAddress = $user->addresses()
                ->whereKey($address)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->deleteAddress($request, $ownedAddress);
        });
    }

    public function setMobileDefaultAddress(Request $request, int $address): JsonResponse
    {
        return DB::transaction(function () use ($request, $address): JsonResponse {
            $user = $request->user();
            $this->lockAddressOwner($user);
            $ownedAddress = $user->addresses()
                ->whereKey($address)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->setDefaultAddress($request, $ownedAddress);
        });
    }

    private function mergeAddressPatch(Request $request, Address $address): void
    {
        $provinceChanged = $request->exists('province')
            && (string) $request->input('province') !== (string) $address->province;
        $cityChanged = $request->exists('city')
            && (string) $request->input('city') !== (string) $address->city_municipality;

        foreach ([
            'name' => $address->recipient_name,
            'phone' => $address->phone,
            'province' => $address->province,
            'city' => $address->city_municipality,
            'barangay' => $address->barangay,
            'postal' => $address->postal_code,
            'street' => $address->street,
            'label' => $address->label,
            'is_default' => (bool) $address->is_default_shipping,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ] as $key => $value) {
            if (! $request->exists($key)) {
                $request->merge([$key => $value]);
            }
        }

        if (! $request->exists('city_code')) {
            $request->merge([
                'city_code' => $provinceChanged || $cityChanged
                    ? null
                    : $address->psgc_city_code,
            ]);
        }
    }

    private function lockAddressOwner(User $user): void
    {
        DB::table('users')
            ->where('id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    private function validateAddress(
        Request $request,
        bool $allowLegacyUnverifiedNonLocationPatch = false,
    ): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'postal' => ['required', 'digits:4'],
            'street' => ['required', 'string', 'max:180'],
            'label' => ['required', 'string', 'in:Home,Work'],
            'is_default' => ['nullable', 'boolean'],
            'city_code' => $allowLegacyUnverifiedNonLocationPatch
                ? ['nullable', 'string', 'regex:/^[0-9]{10}$/D']
                : ['required', 'string', 'regex:/^[0-9]{10}$/D'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);

        if ($allowLegacyUnverifiedNonLocationPatch && blank($data['city_code'] ?? null)) {
            $data['city_code'] = null;

            return $data;
        }

        $postalCodes = app(PostalCodeLookup::class);
        $cityCode = $postalCodes->canonicalCityCode(
            $data['city_code'],
            $data['province'],
            $data['city'],
        );

        if (! $cityCode || ! $postalCodes->findByCanonicalCityCode($cityCode)) {
            throw ValidationException::withMessages([
                'city' => 'Select a municipality/city from the PSGC list before saving this address. Legacy addresses must be re-verified by selecting their city again.',
            ]);
        }

        $data['city_code'] = $cityCode;

        return $data;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone) ?: '';

        return app(InternationalPhone::class)
            ->normalize($phone, 'PH');
    }

    private function addressPayload(Address $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'name' => $address->recipient_name,
            'phone' => $address->phone,
            'province' => $address->province,
            'city' => $address->city_municipality,
            'cityCode' => $address->psgc_city_code,
            'cityVerified' => $this->addressCityVerified($address),
            'barangay' => $address->barangay,
            'postal' => $address->postal_code,
            'street' => $address->street,
            'isDefault' => (bool) $address->is_default_shipping,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ];
    }

    private function addressCityVerified(Address $address): bool
    {
        $cityCode = trim((string) $address->psgc_city_code);
        if ($cityCode === '') {
            return false;
        }

        try {
            return app(PostalCodeLookup::class)->canonicalCityCode(
                $cityCode,
                (string) $address->province,
                (string) $address->city_municipality,
            ) !== null;
        } catch (ValidationException) {
            return false;
        }
    }

    public function home(
        Request $request,
        BuyerCatalogService $catalog,
        PromotionService $promotions,
        CampaignService $campaigns,
        ProductReviewService $reviews,
    ): View|RedirectResponse {
        $dedicatedCategories = [
            'electronics-and-gadgets',
            'books-and-media',
            'pet-supplies',
            'sports-and-outdoors',
            'jewelry-and-watches',
            'kids-and-baby',
            'home-and-garden',
            'health-and-beauty',
            'food-and-gourmet',
            'furniture-and-office-equipment',
        ];

        if (in_array($request->query('category'), $dedicatedCategories, true)) {
            return redirect()->route('products.index', ['category' => $request->query('category')]);
        }

        $categories = [
            [
                'name' => 'Electronics',
                'icon' => '💻',
                'count' => '12.4k',
            ],
            [
                'name' => 'Fashion',
                'icon' => '👟',
                'count' => '8.9k',
            ],
            [
                'name' => 'Home & Living',
                'icon' => '🛋️',
                'count' => '5.6k',
            ],
            [
                'name' => 'Beauty',
                'icon' => '✨',
                'count' => '9.9k',
            ],
            [
                'name' => 'Sports',
                'icon' => '⚽',
                'count' => '4.3k',
            ],
            [
                'name' => 'Books',
                'icon' => '📚',
                'count' => '6.5k',
            ],
            [
                'name' => 'Automotive',
                'icon' => '🚗',
                'count' => '3.2k',
            ],
            [
                'name' => 'Groceries',
                'icon' => '🛒',
                'count' => '7.9k',
            ],
        ];

        $rankings = $catalog->homepageRankings($request->user());
        $rankings['popular'] = $this->withRankingReviewSummaries($rankings['popular'], $reviews);
        $rankings['top_sales'] = $this->withRankingReviewSummaries($rankings['top_sales'], $reviews);

        $featuredShops = [
            [
                'name' => 'TechHub Official',
                'logo' => '🔧',
                'image' => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.9,
                'followers' => 12400,
                'products' => 245,
                'verified' => true,
            ],
            [
                'name' => 'SneakerVault',
                'logo' => '👟',
                'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.8,
                'followers' => 8900,
                'products' => 87,
                'verified' => true,
            ],
            [
                'name' => 'HomeNest Co.',
                'logo' => '🏠',
                'image' => 'https://images.unsplash.com/photo-1618221195710-dd6b41faaea6?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.7,
                'followers' => 5600,
                'products' => 132,
                'verified' => true,
            ],
            [
                'name' => 'BeautyBox MY',
                'logo' => '💄',
                'image' => 'https://images.unsplash.com/photo-1596462502278-27bfdc403348?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.6,
                'followers' => 22000,
                'products' => 310,
                'verified' => true,
            ],
        ];

        $homeFlashDealGroups = $this->buyerFlashDealProductGroups($request, $campaigns, $catalog, 4);

        return view('buyer.Dashboard.home', [
            'categories' => $categories,
            'popularRankingProducts' => $rankings['popular'],
            'topSalesRankingProducts' => $rankings['top_sales'],
            'defaultRankingKind' => $rankings['popular'] !== [] ? 'popular' : 'top_sales',
            'featuredShops' => $featuredShops,
            'liveCatalog' => $catalog->cards(
                $catalog->publishedProducts(limit: 60),
                $request->user(),
            ),
            'activePromotions' => $promotions->publicOffers([
                PlatformPromotion::KIND_VOUCHER,
                PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
                PlatformPromotion::KIND_FREE_SHIPPING,
                PlatformPromotion::KIND_FLASH_DEAL,
                PlatformPromotion::KIND_SELLER_CAMPAIGN,
            ], true)->take(4),
            'liveCampaigns' => $campaigns->activeBuyerCampaigns()
                ->map(fn (PlatformPromotion $campaign): array => $campaigns->buyerCampaignSummaryPayload($campaign))
                ->values(),
            'liveFlashDealGroups' => $homeFlashDealGroups,
        ]);
    }

    public function topProducts(Request $request, BuyerCatalogService $catalog, ProductReviewService $reviews): View
    {
        return $this->rankingPage($request, $catalog, $reviews, 'popular');
    }

    public function topSales(Request $request, BuyerCatalogService $catalog, ProductReviewService $reviews): View
    {
        return $this->rankingPage($request, $catalog, $reviews, 'top_sales');
    }

    private function rankingPage(
        Request $request,
        BuyerCatalogService $catalog,
        ProductReviewService $reviews,
        string $rankingType,
    ): View {
        $categories = $catalog->rankingCategories($rankingType);
        $filters = $request->validate([
            'category' => [
                'nullable',
                'string',
                'max:100',
                Rule::in($categories->pluck('slug')->all()),
            ],
        ]);
        $selectedCategory = $filters['category'] ?? null;
        $rankedProducts = $catalog->rankedProductsPage(
            $request->user(),
            $rankingType,
            $selectedCategory,
        );
        $rankingCards = $rankedProducts->getCollection();
        $reviewSummaries = $reviews->productSummaries($rankingCards->map(
            fn (array $product): int => (int) $product['id'],
        )->all());
        $rankedProducts->setCollection($rankedProducts->getCollection()->map(
            fn (array $product): array => $this->withRankingReviewSummary(
                $product,
                $reviewSummaries[(int) $product['id']] ?? null,
            ),
        ));

        return view($rankingType === 'popular' ? 'buyer.top-products' : 'buyer.top-sales', [
            'rankingType' => $rankingType,
            'rankingProducts' => $rankedProducts,
            'rankingCategories' => $categories,
            'selectedRankingCategory' => $selectedCategory,
        ]);
    }

    /** @param array<int, array<string, mixed>> $products
     *  @return array<int, array<string, mixed>>
     */
    private function withRankingReviewSummaries(array $products, ProductReviewService $reviews): array
    {
        if ($products === []) {
            return [];
        }

        $summaries = $reviews->productSummaries(array_map(
            fn (array $product): int => (int) $product['id'],
            $products,
        ));

        return array_map(fn (array $product): array => $this->withRankingReviewSummary(
            $product,
            $summaries[(int) $product['id']] ?? null,
        ), $products);
    }

    /** @param array<string, mixed>|null $summary
     *  @return array<string, mixed>
     */
    private function withRankingReviewSummary(array $product, ?array $summary): array
    {
        if (($summary['count'] ?? 0) > 0) {
            $product['review_summary'] = [
                'count' => (int) $summary['count'],
                'average' => (float) $summary['average'],
            ];
        }

        return $product;
    }

    public function flashDeals(Request $request, CampaignService $campaigns, PromotionService $promotions, BuyerCatalogService $catalog): View
    {
        $flashDealGroups = $this->buyerFlashDealProductGroups($request, $campaigns, $catalog, 12);

        $otherOffers = $promotions->publicOffers([
            PlatformPromotion::KIND_VOUCHER,
            PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
            PlatformPromotion::KIND_FREE_SHIPPING,
            PlatformPromotion::KIND_SELLER_CAMPAIGN,
        ])->reject(fn (array $offer): bool => ($offer['campaign_type'] ?? null) === PlatformPromotion::CAMPAIGN_FLASH_DEAL)
            ->take(4)
            ->values();

        return view('buyer.flash-deals', [
            'flashDealGroups' => $flashDealGroups,
            'otherOffers' => $otherOffers,
        ]);
    }

    /**
     * @return array<int, array{campaign: array<string, mixed>, products: array<int, array<string, mixed>>}>
     */
    private function buyerFlashDealProductGroups(
        Request $request,
        CampaignService $campaigns,
        BuyerCatalogService $catalog,
        int $productsPerCampaign,
    ): array {
        return $campaigns->activeBuyerFlashDealCampaigns()
            ->map(function (PlatformPromotion $campaign) use ($campaigns, $catalog, $request, $productsPerCampaign): ?array {
                $products = $campaigns->buyerCampaignProductPreview($campaign, $productsPerCampaign);
                if ($products->isEmpty()) {
                    return null;
                }

                $expectedCampaignItems = $products->mapWithKeys(fn (Product $product): array => [
                    (int) $product->id => (int) $product->getAttribute('buyer_campaign_participation_item_id'),
                ]);
                $productCards = $catalog->cards($products, $request->user(), includeCampaignItemId: true);
                $productCards = array_values(array_filter($productCards, function (array $card) use ($expectedCampaignItems): bool {
                    $productId = (int) ($card['product_id'] ?? 0);
                    $campaignItemId = (int) ($card['campaign_participation_item_id'] ?? 0);

                    return ($card['price_source'] ?? null) === 'campaign'
                        && $campaignItemId > 0
                        && $campaignItemId === (int) ($expectedCampaignItems->get($productId) ?? 0);
                }));
                foreach ($productCards as &$productCard) {
                    unset($productCard['campaign_participation_item_id']);
                }
                unset($productCard);

                if ($productCards === []) {
                    return null;
                }

                return [
                    'campaign' => $campaigns->buyerCampaignPayload($campaign),
                    'products' => $productCards,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function showCampaign(
        PlatformPromotion $campaign,
        CampaignService $campaigns,
        BuyerCatalogService $catalog,
    ): View {
        $campaign = $campaigns->buyerCampaignDetail($campaign);
        $products = $campaigns->buyerCampaignProducts($campaign);

        return view('buyer.campaigns.show', [
            'campaign' => $campaigns->buyerCampaignPayload($campaign),
            'productCards' => $catalog->cards($products, request()->user()),
        ]);
    }

    public function products(Request $request, BuyerCatalogService $catalog): View
    {
        $rawSearch = $request->query('search');
        if (! is_string($rawSearch) || trim($rawSearch) === '') {
            $rawSearch = $request->query('q', '');
        }
        $searchTerm = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 120) : '';

        if ($searchTerm !== '' && $this->hasBuyerTables()) {
            return $this->searchCatalog($request, $catalog, $searchTerm);
        }

        $categoryViews = [
            'men-s-apparel' => 'buyer.Category.MenApparel.mens-apparel',
            'women-s-apparel' => 'buyer.Category.WomenApparel.womens-apparel',
            'electronics-and-gadgets' => 'buyer.Category.Electronics&Gadgets.electronics-gadgets',
            'books-and-media' => 'buyer.Category.Books&Media.books-media',
            'pet-supplies' => 'buyer.Category.Pet-Supplies.pet-supplies',
            'sports-and-outdoors' => 'buyer.Category.Sports&Outdoors.sports-outdoors',
            'jewelry-and-watches' => 'buyer.Category.Jewelry&Watches.jewelry-watches',
            'kids-and-baby' => 'buyer.Category.Kids&Baby.kids-baby',
            'home-and-garden' => 'buyer.Category.Home&Garden.home-garden',
            'health-and-beauty' => 'buyer.Category.Health&Beauty.health-beauty',
            'food-and-gourmet' => 'buyer.Category.Foods&Gourmet.foods-gourmet',
            'furniture-and-office-equipment' => 'buyer.Category.Furniture&OfficeEquipment.furniture-office',
        ];

        $category = $request->query('category');

        if (isset($categoryViews[$category])) {
            if ($this->hasMarketplaceTaxonomyTables()) {
                $marketplaceCategory = $catalog->marketplaceCategoryForSlug((string) $category);
                $marketplaceSubcategories = $marketplaceCategory
                    ? $catalog->marketplaceSubcategoriesForBuyer($marketplaceCategory)
                    : collect();
                $subcategorySlug = $request->query('sub');
                $subcategorySlug = is_string($subcategorySlug) && trim($subcategorySlug) !== ''
                    ? trim($subcategorySlug)
                    : null;
                $categoryProducts = $marketplaceCategory
                    ? $catalog->publishedProductsForMarketplaceCategory($marketplaceCategory, $subcategorySlug)
                    : null;
                $subcategorySlugs = $marketplaceSubcategories->mapWithKeys(fn ($subcategory): array => [
                    (string) $subcategory->name => (string) $subcategory->slug,
                ])->all();
                $subcategoryIds = $marketplaceSubcategories->mapWithKeys(fn ($subcategory): array => [
                    (string) $subcategory->name => (int) $subcategory->id,
                ])->all();

                return view($categoryViews[$category], [
                    'canonicalMarketplaceTaxonomy' => true,
                    'marketplaceCategory' => $marketplaceCategory,
                    'marketplaceSubcategories' => $marketplaceSubcategories,
                    'marketplaceSubcategorySlugs' => $subcategorySlugs,
                    'marketplaceSubcategoryIds' => $subcategoryIds,
                    'categoryPagination' => $categoryProducts,
                    'liveCatalog' => $categoryProducts
                        ? $catalog->cards($categoryProducts->getCollection(), $request->user())
                        : [],
                ]);
            }

            $categoryProducts = $catalog->publishedProductsPage($category);

            return view($categoryViews[$category], [
                'canonicalMarketplaceTaxonomy' => false,
                'categoryPagination' => $categoryProducts,
                'liveCatalog' => $catalog->cards($categoryProducts->getCollection(), $request->user()),
            ]);
        }

        if (! $this->hasBuyerTables()) {
            return view('buyer.Category.MenApparel.mens-apparel');
        }

        $query = Product::query()
            ->with(['store', 'variants' => fn ($variants) => $variants->where('is_active', true)])
            ->withMin('variants', 'price_minor')
            ->visibleToBuyers()
            ->acceptsCashOnDelivery();

        if ($request->category && $request->category !== 'All') {
            $query->whereHas('category', function ($categories) use ($request) {
                $categories->where('name', $request->category)
                    ->orWhere('slug', Str::slug($request->category));
            });
        }

        if ($request->search) {
            $search = trim((string) $request->search);
            $query->where(function ($products) use ($search) {
                $products->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sort = $request->sort ?? 'featured';
        switch ($sort) {
            case 'price_low':
                $query->orderBy('variants_min_price_minor', 'asc');
                break;
            case 'price_high':
                $query->orderBy('variants_min_price_minor', 'desc');
                break;
            case 'newest':
                $query->orderByDesc('created_at');
                break;
            default:
                $query->orderByDesc('published_at')->orderByDesc('id');
        }

        $products = $query->paginate(12);
        $categories = [
            'All',
            'Electronics',
            'Fashion',
            'Home & Living',
            'Beauty',
            'Sports',
            'Books',
            'Automotive',
            'Groceries',
        ];

        return view('buyer.Category.MenApparel.mens-apparel', [
            'products' => $products,
            'categories' => $categories,
            'liveCatalog' => $catalog->cards(
                $catalog->publishedProducts('men-s-apparel', limit: 60),
                $request->user(),
            ),
        ]);
    }

    private function searchCatalog(Request $request, BuyerCatalogService $catalog, string $searchTerm): View
    {
        $like = '%'.$searchTerm.'%';
        $rawCategory = $request->query('category', '');
        $categoryFilter = is_string($rawCategory) ? trim($rawCategory) : '';

        $stores = Store::query()
            ->visibleToBuyers()
            ->where('name', 'like', $like)
            ->orderByRaw('CASE WHEN name = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$searchTerm, $searchTerm.'%'])
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'slug', 'description', 'logo_path'])
            ->map(fn (Store $store): array => [
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'logo_url' => $store->logo_path
                    ? Storage::disk('public')->url($store->logo_path)
                    : null,
            ])
            ->all();

        $productQuery = Product::query()
            ->with([
                'category',
                'subcategory',
                'store',
                'images' => fn ($images) => $images->with('variant')->orderBy('position'),
                'variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('position'),
            ])
            ->visibleToBuyers()
            ->acceptsCashOnDelivery()
            ->where(function ($matches) use ($like) {
                $matches->where('products.name', 'like', $like)
                    ->orWhere('products.description', 'like', $like)
                    ->orWhereHas('category', fn ($categories) => $categories
                        ->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like))
                    ->orWhereHas('store', fn ($stores) => $stores
                        ->visibleToBuyers()
                        ->where('name', 'like', $like));
            });

        if ($categoryFilter !== '' && strtolower($categoryFilter) !== 'all') {
            $catalog->filterByBuyerCategory($productQuery, $categoryFilter);
        }

        $products = $productQuery
            ->orderByRaw('CASE WHEN products.name = ? THEN 0 WHEN products.name LIKE ? THEN 1 ELSE 2 END', [$searchTerm, $searchTerm.'%'])
            ->orderByDesc('products.published_at')
            ->orderByDesc('products.id')
            ->paginate(12)
            ->withQueryString();

        return view('buyer.search.index', [
            'searchTerm' => $searchTerm,
            'stores' => $stores,
            'products' => $products,
            'productCards' => $catalog->cards($products->getCollection(), $request->user()),
        ]);
    }

    public function showProduct(Product $product, BuyerCatalogService $catalog, ProductReviewService $reviews): View
    {
        $product->load('store');

        if (! $this->hasBuyerTables() ||
            ! Product::query()->visibleToBuyers()
                ->acceptsCashOnDelivery()
                ->whereKey($product->getKey())
                ->exists()) {
            abort(404);
        }

        $product->load([
            'category',
            'subcategory',
            'store.sellerProfile.pickupAddress',
            'variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('position'),
            'images' => fn ($images) => $images->with('variant')->orderBy('position'),
        ]);

        $relatedProducts = $catalog->cards(
            $catalog->relatedProducts($product, 4),
            request()->user(),
        );
        $productCard = $catalog->card($product);
        $detailVariantSpecs = $product->variants
            ->mapWithKeys(fn (ProductVariant $variant): array => [(int) $variant->id => [
                'sku' => (string) $variant->sku,
                'stock' => (int) $variant->available_stock,
                'weight_kg' => $variant->package_weight_kg,
                'length_cm' => $variant->package_length_cm,
                'width_cm' => $variant->package_width_cm,
                'height_cm' => $variant->package_height_cm,
            ]])
            ->all();
        $store = $product->store;

        return view('buyer.products.show', [
            'product' => $product,
            'productCard' => $productCard,
            'relatedProducts' => $relatedProducts,
            'reviewSummary' => $reviews->productSummary($product->id),
            'productReviews' => $reviews->productReviews($product->id, 50)
                ->map(fn ($review): array => $reviews->publicPayload($review))
                ->all(),
            'detailVariantSpecs' => $detailVariantSpecs,
            'storeReviewSummary' => $reviews->storeSummary((int) $store->id),
            'storeProductCount' => $catalog->publishedProductsQuery(null, $store)->count(),
            'storeLogoUrl' => $store->logo_path
                ? Storage::disk('public')->url($store->logo_path)
                : null,
            'storeProvince' => trim((string) ($store->sellerProfile?->pickupAddress?->province ?? '')),
        ]);
    }

    public function showStore(Request $request, Store $store, BuyerCatalogService $catalog, ProductReviewService $reviews): View
    {
        abort_unless(
            Store::query()->visibleToBuyers()->whereKey($store->getKey())->exists(),
            404,
        );

        $store->load([
            'sellerProfile.user',
            'sellerProfile.approvedCategory',
            'promotionalBanners' => fn ($banners) => $banners->where('is_active', true),
        ]);
        $storeSubcategories = $catalog->storeSubcategoriesForBuyer($store);
        $selectedSubcategoryId = null;

        if ($request->filled('subcategory')) {
            $requestedSubcategory = (string) $request->query('subcategory');

            if (! ctype_digit($requestedSubcategory)) {
                abort(404);
            }

            $selectedSubcategoryId = (int) $requestedSubcategory;

            if (! $storeSubcategories->contains('id', $selectedSubcategoryId)) {
                abort(404);
            }
        }

        $storeProductsQuery = $catalog->publishedProductsQuery(null, $store);
        $publishedProductCount = (clone $storeProductsQuery)->count();

        if ($selectedSubcategoryId !== null) {
            $storeProductsQuery->where('subcategory_id', $selectedSubcategoryId);
        }

        $storeProductsPage = $storeProductsQuery
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();
        $products = $storeProductsPage->getCollection();
        $productReviewSummaries = $reviews->productSummaries($products->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $storeReviewSummary = $reviews->storeSummary((int) $store->id);
        $promoBanners = $store->promotionalBanners
            ->map(fn ($banner): array => [
                'path' => $banner->image_path,
                'url' => Storage::disk('public')->url($banner->image_path),
                'title' => $banner->title ?? '',
                'link' => $this->safeStoreLink($banner->link),
            ])
            ->values()
            ->all();

        if ($promoBanners === [] && filled($store->promo_banner_path)) {
            $promoBanners[] = [
                'path' => $store->promo_banner_path,
                'url' => Storage::disk('public')->url($store->promo_banner_path),
                'title' => $store->promo_banner_title ?? '',
                'link' => $this->safeStoreLink($store->promo_banner_link),
            ];
        }

        $category = $store->sellerProfile?->approvedCategory?->name
            ?: $store->sellerProfile?->user?->business_category
            ?: 'Marketplace Seller';
        $productCards = $catalog->cards($products, $request->user(), true);
        $productCards = array_map(function (array $product) use ($productReviewSummaries): array {
            $product['review_summary'] = $productReviewSummaries[(int) $product['id']] ?? [
                'count' => 0,
                'average' => 0.0,
            ];

            return $product;
        }, $productCards);

        return view('buyer.stores.show', [
            'store' => $store,
            'category' => $category,
            'logoUrl' => $store->logo_path
                ? Storage::disk('public')->url($store->logo_path)
                : null,
            'coverUrl' => $store->banner_path
                ? Storage::disk('public')->url($store->banner_path)
                : null,
            'promoBanners' => $promoBanners,
            'storeSubcategories' => $storeSubcategories,
            'selectedSubcategoryId' => $selectedSubcategoryId,
            'publishedProductCount' => $publishedProductCount,
            'productPaginator' => $storeProductsPage,
            'storeReviewSummary' => $storeReviewSummary,
            'products' => $productCards,
        ]);
    }

    private function safeStoreLink(?string $link): ?string
    {
        $link = trim((string) $link);

        if ($link === '' || filter_var($link, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $link
            : null;
    }

    public function cart(Request $request, BuyerCatalogService $catalog): View
    {
        $cart = $this->currentCart($request->user(), false);
        $wishlistProductIds = Wishlist::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('product', fn ($query) => $query->visibleToBuyers())
            ->pluck('product_id')
            ->map(fn ($productId): int => (int) $productId)
            ->values()
            ->all();

        return view('buyer.cart', [
            'cartItems' => $cart ? $this->cartPayload($cart, $catalog) : [],
            'wishlistProductIds' => $wishlistProductIds,
        ]);
    }

    public function cartData(Request $request, BuyerCatalogService $catalog): JsonResponse
    {
        $cartState = $this->cartState($request->user(), $catalog);

        return response()->json([
            'data' => $cartState,
            'message' => 'Cart retrieved successfully.',
        ]);
    }

    public function addToCart(Request $request, BuyerCatalogService $catalog): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'quantity' => 'required|integer|min:1',
        ]);

        if (empty($data['product_variant_id']) && empty($data['product_id'])) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Choose a product variation before adding the item to your cart.',
            ]);
        }

        $item = DB::transaction(function () use ($request, $data) {
            $variantQuery = ProductVariant::query()
                ->with('product.store')
                ->where('is_active', true);

            if (! empty($data['product_variant_id'])) {
                $variantQuery->whereKey($data['product_variant_id']);
            } else {
                $variantQuery->where('product_id', $data['product_id'])
                    ->orderBy('position');
            }

            $variant = $variantQuery->lockForUpdate()->firstOrFail();

            if (
                ! empty($data['product_id'])
                && (int) $variant->product_id !== (int) $data['product_id']
            ) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'The selected variation does not belong to this product.',
                ]);
            }

            $product = Product::query()
                ->visibleToBuyers()
                ->whereKey($variant->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $product->acceptsCashOnDelivery()) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'This product does not accept the currently supported Cash on Delivery checkout.',
                ]);
            }

            $cart = $this->currentCart($request->user());
            $existingItem = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();
            $quantity = (int) ($existingItem?->quantity ?? 0) + (int) $data['quantity'];

            if ($quantity > $variant->available_stock) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$variant->available_stock} item(s) are currently available.",
                ]);
            }

            if ($existingItem) {
                $existingItem->update(['quantity' => $quantity]);

                return $existingItem->fresh([
                    'variant.product.store.sellerProfile.user',
                    'variant.product.variants' => fn ($variants) => $variants->where('is_active', true),
                    'variant.product.images.variant',
                ]);
            }

            return $cart->items()->create([
                'product_variant_id' => $variant->id,
                'quantity' => (int) $data['quantity'],
                'selected' => true,
            ])->load([
                'variant.product.store.sellerProfile.user',
                'variant.product.variants' => fn ($variants) => $variants->where('is_active', true),
                'variant.product.images.variant',
            ]);
        });

        return response()->json([
            'data' => array_merge(
                $this->cartState($request->user(), $catalog),
                ['item' => $this->cartItemPayload($item, $catalog)],
            ),
            'message' => 'Product added to cart',
        ]);
    }

    public function buyNow(Request $request, BuyerCatalogService $catalog): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $item = DB::transaction(function () use ($request, $data) {
            $variant = ProductVariant::query()
                ->with('product.store')
                ->where('is_active', true)
                ->whereKey($data['product_variant_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $product = Product::query()
                ->visibleToBuyers()
                ->whereKey($variant->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $product->acceptsCashOnDelivery()) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'This product does not accept the currently supported Cash on Delivery checkout.',
                ]);
            }

            if ((int) $data['quantity'] > $variant->available_stock) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$variant->available_stock} item(s) are currently available.",
                ]);
            }

            $cart = $this->currentCart($request->user());
            $existingItem = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($existingItem) {
                $existingItem->update([
                    'quantity' => (int) $data['quantity'],
                    'selected' => true,
                ]);

                return $existingItem->fresh([
                    'variant.product.store',
                    'variant.product.images.variant',
                ]);
            }

            return $cart->items()->create([
                'product_variant_id' => $variant->id,
                'quantity' => (int) $data['quantity'],
                'selected' => true,
            ])->load([
                'variant.product.store',
                'variant.product.images.variant',
            ]);
        });

        return response()->json([
            'data' => [
                'item' => $this->cartItemPayload($item, $catalog),
                'cart_count' => $this->cartCount($request->user()),
            ],
            'message' => 'Product prepared for single-item checkout.',
        ]);
    }

    public function updateCart(Request $request, CartItem $cartItem, BuyerCatalogService $catalog): JsonResponse
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1']);
        $cart = $this->currentCart($request->user(), false);
        abort_unless($cart && (int) $cartItem->cart_id === (int) $cart->id, 404);

        $cartItem = DB::transaction(function () use ($cart, $cartItem, $data) {
            $item = $cart->items()
                ->with([
                    'variant.product.store.sellerProfile.user',
                    'variant.product.variants' => fn ($variants) => $variants->where('is_active', true),
                    'variant.product.images.variant',
                ])
                ->lockForUpdate()
                ->findOrFail($cartItem->id);

            abort_unless($item->variant && $item->variant->is_active, 404);
            $product = Product::query()
                ->visibleToBuyers()
                ->whereKey($item->variant->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $product->acceptsCashOnDelivery()) {
                throw ValidationException::withMessages([
                    'quantity' => 'This product is no longer available for the currently supported Cash on Delivery checkout. Remove it from your cart.',
                ]);
            }

            if ((int) $data['quantity'] > $item->variant->available_stock) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$item->variant->available_stock} item(s) are currently available.",
                ]);
            }
            $item->update(['quantity' => (int) $data['quantity']]);

            return $item->fresh([
                'variant.product.store.sellerProfile.user',
                'variant.product.variants' => fn ($variants) => $variants->where('is_active', true),
                'variant.product.images.variant',
            ]);
        });

        return response()->json([
            'data' => array_merge(
                $this->cartState($request->user(), $catalog),
                ['item' => $this->cartItemPayload($cartItem, $catalog)],
            ),
            'message' => 'Cart updated',
        ]);
    }

    public function removeFromCart(
        Request $request,
        CartItem $cartItem,
        BuyerCatalogService $catalog,
    ): JsonResponse {
        $cart = $this->currentCart($request->user(), false);
        abort_unless($cart && (int) $cartItem->cart_id === (int) $cart->id, 404);
        DB::transaction(function () use ($cart, $cartItem): void {
            $cart->items()->whereKey($cartItem->id)->delete();
        });

        return response()->json([
            'data' => $this->cartState($request->user(), $catalog),
            'message' => 'Item removed from cart',
        ]);
    }

    public function clearCart(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $this->currentCart($request->user(), false)?->items()->delete();
        });

        return response()->json([
            'data' => ['cart_count' => 0],
            'message' => 'Cart cleared',
        ]);
    }

    public function wishlist(Request $request): View
    {
        return view('buyer.wishlist', [
            'wishlistItems' => $this->wishlistPayload($request->user()),
        ]);
    }

    public function toggleWishlist(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        Product::query()
            ->with('store')
            ->whereKey($data['product_id'])
            ->visibleToBuyers()
            ->firstOrFail();

        $isWishlisted = DB::transaction(function () use ($request, $data): bool {
            $wishlistItem = Wishlist::where('user_id', $request->user()->id)
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if ($wishlistItem) {
                $wishlistItem->delete();

                return false;
            }

            Wishlist::create([
                'user_id' => $request->user()->id,
                'product_id' => $data['product_id'],
            ]);

            return true;
        });

        return response()->json([
            'data' => [
                'is_wishlisted' => $isWishlisted,
                'wishlist_count' => Wishlist::where('user_id', $request->user()->id)->count(),
            ],
            'message' => $isWishlisted ? 'Product saved to wishlist.' : 'Product removed from wishlist.',
        ]);
    }

    private function currentCart($user, bool $create = true): ?Cart
    {
        $query = Cart::query()
            ->where('user_id', $user->id)
            ->where('status', 'active');

        if (! $create) {
            return $query->latest('id')->first();
        }

        // The schema has no unique user/status index for active carts.
        // Serialize cart creation so parallel adds cannot create two active carts.
        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

        return $query->first() ?? Cart::firstOrCreate([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    private function cartCount($user): int
    {
        $cart = $this->currentCart($user, false);

        return $cart
            ? (int) $cart->items()->sum('quantity')
            : 0;
    }

    private function cartState($user, BuyerCatalogService $catalog): array
    {
        $cart = $this->currentCart($user, false);
        $items = $cart ? $this->cartPayload($cart, $catalog) : [];

        return [
            'items' => $items,
            'cart_count' => (int) array_sum(array_column($items, 'quantity')),
            'subtotal_minor' => (int) array_sum(array_column($items, 'line_subtotal_minor')),
            'currency' => 'PHP',
        ];
    }

    private function cartPayload(Cart $cart, BuyerCatalogService $catalog): array
    {
        return $cart->items()
            ->with([
                'variant.product.store',
                'variant.product.store.sellerProfile.user',
                'variant.product.variants' => fn ($variants) => $variants->where('is_active', true),
                'variant.product.images.variant',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (CartItem $item) => $this->cartItemPayload($item, $catalog))
            ->values()
            ->all();
    }

    private function cartItemPayload(CartItem $item, BuyerCatalogService $catalog): array
    {
        $variant = $item->variant;
        $product = $variant?->product;
        $price = $product && $variant
            ? $this->pricing->priceForVariant($product, $variant, (int) $item->quantity)
            : null;
        $quantity = (int) $item->quantity;
        $unitPriceMinor = (int) ($price['unit_price_minor'] ?? $variant?->price_minor ?? 0);
        $availableStock = 0;
        $store = $product?->store;
        $sellerProfile = $store?->sellerProfile;
        $hasActiveVariants = $product && $product->relationLoaded('variants')
            ? $product->variants->isNotEmpty()
            : (bool) $product?->variants()->where('is_active', true)->exists();
        $productIsVisible = $product
            && $product->product_status === 'active'
            && $product->compliance_status === 'clear'
            && $product->removed_at === null
            && $hasActiveVariants
            && $store?->publication_status === 'published'
            && $sellerProfile?->standing_status === 'good_standing'
            && $sellerProfile?->user?->status === 'active';
        $variantIsActive = (bool) $variant?->is_active;

        if ($productIsVisible && $variantIsActive) {
            $availableStock = max(0, (int) $variant->available_stock);
        }

        $isAvailable = $productIsVisible
            && $variantIsActive
            && $availableStock >= $quantity;
        $imageRecord = $productIsVisible && $product && $variant
            ? $catalog->orderImageRecord($product, $variant)
            : null;
        $imageUrl = $catalog->publicImageUrl($imageRecord);
        $productData = $product
            ? ['id' => (int) $product->id, 'name' => (string) $product->name]
            : null;
        $variantData = $variant
            ? [
                'id' => (int) $variant->id,
                'name' => (string) $variant->name,
                'options' => (array) ($variant->options ?? []),
            ]
            : null;
        $storeName = $productIsVisible ? $store?->name : null;

        return [
            'id' => $item->id,
            'product_id' => $product?->id,
            'product_variant_id' => $variant?->id,
            'product' => $productData,
            'variant' => $variantData,
            'name' => $product?->name ?? 'Product',
            'variant_name' => $variant?->name ?? '',
            'options' => $variant?->options ?? [],
            'price_minor' => $unitPriceMinor,
            'unit_price_minor' => $unitPriceMinor,
            'line_subtotal_minor' => $unitPriceMinor * $quantity,
            'regular_price_minor' => (int) ($price['regular_unit_price_minor'] ?? $variant?->price_minor ?? 0),
            'discount_percent' => (int) ($price['discount_percent'] ?? 0),
            'campaign_name' => $price['campaign_name'] ?? null,
            'quantity' => $quantity,
            'selected' => (bool) $item->selected,
            'image' => $imageUrl ?? '',
            'image_url' => $imageUrl,
            'available_stock' => $availableStock,
            'in_stock' => $availableStock > 0,
            'is_available' => $isAvailable,
            'store_name' => $storeName,
            'seller_name' => $product?->store?->name ?? 'Bearly seller',
            'orderable' => $product?->acceptsCashOnDelivery() ?? false,
            'unavailable_reason' => $product && ! $product->acceptsCashOnDelivery()
                ? 'This item is no longer orderable with the currently supported Cash on Delivery checkout. Remove it before checkout.'
                : null,
        ];
    }

    private function wishlistPayload($user): array
    {
        $hasMarketplaceTaxonomy = $this->hasMarketplaceTaxonomyTables();
        $wishlist = Wishlist::query()
            ->where('user_id', $user->id)
            ->whereHas('product', function ($products) {
                $products->visibleToBuyers();
            })
            ->with([
                'product.store',
                'product.category',
                'product.images',
                'product.variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('position'),
            ]);

        if ($hasMarketplaceTaxonomy) {
            $wishlist->with([
                'product.marketplaceSubcategory.marketplaceCategory',
                'product.category.marketplaceCategories' => fn ($categories) => $categories
                    ->where('marketplace_categories.is_active', true),
            ]);
        }

        return $wishlist
            ->latest('id')
            ->get()
            ->map(function (Wishlist $item) use ($hasMarketplaceTaxonomy): array {
                $product = $item->product;
                $image = $product?->images?->firstWhere('is_primary', true)
                    ?: $product?->images?->first();
                $variant = $product?->variants?->first(fn (ProductVariant $variant): bool => $variant->available_stock > 0)
                    ?: $product?->variants?->first();
                $marketplaceSubcategory = $product && $hasMarketplaceTaxonomy
                    ? $product->marketplaceSubcategory
                    : null;
                $marketplaceCategory = $marketplaceSubcategory?->is_active
                    && $marketplaceSubcategory->marketplaceCategory?->is_active
                    ? $marketplaceSubcategory->marketplaceCategory
                    : null;
                if (! $marketplaceCategory && $hasMarketplaceTaxonomy) {
                    $legacyCategory = $product?->category;
                    $mappedCategories = $legacyCategory?->marketplaceCategories;
                    if ($legacyCategory?->is_active && $mappedCategories?->count() === 1) {
                        $marketplaceCategory = $mappedCategories->first();
                    }
                }
                $imagePath = (string) ($image?->path ?? '');
                $imageDisk = (string) ($image?->disk ?: 'public');
                $price = $product && $variant
                    ? $this->pricing->priceForVariant($product, $variant)
                    : null;

                return [
                    'id' => $item->id,
                    'product_id' => $product?->id,
                    'product_variant_id' => $variant?->id,
                    'name' => $product?->name ?? 'Product',
                    'price_minor' => (int) ($price['unit_price_minor'] ?? $variant?->price_minor ?? 0),
                    'regular_price_minor' => (int) ($price['regular_unit_price_minor'] ?? $variant?->price_minor ?? 0),
                    'discount_percent' => (int) ($price['discount_percent'] ?? 0),
                    'category' => $marketplaceCategory?->name ?? $product?->category?->name ?? 'Product',
                    'category_slug' => $hasMarketplaceTaxonomy
                        ? $marketplaceCategory?->slug
                        : $this->buyerCategorySlug($product?->category),
                    'orderable' => $product?->acceptsCashOnDelivery() ?? false,
                    'stock' => (int) $product?->variants?->sum(fn (ProductVariant $candidate): int => $candidate->available_stock),
                    'image' => $imagePath === ''
                        ? ''
                        : (Str::startsWith($imagePath, ['http://', 'https://', '/'])
                            ? $imagePath
                            : Storage::disk($imageDisk)->url($imagePath)),
                    'seller_name' => $product?->store?->name ?? 'Bearly seller',
                ];
            })
            ->values()
            ->all();
    }
}
