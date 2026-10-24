<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductImage;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Services\BuyerCatalogService;
use App\Services\BuyerOrderConfirmationService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class MobileBuyerOrderController extends Controller
{
    public function index(Request $request, BuyerCatalogService $catalog): JsonResponse
    {
        return $this->safely(function () use ($request, $catalog): JsonResponse {
            $validated = $request->validate([
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            ]);
            $perPage = (int) ($validated['per_page'] ?? 20);

            $orders = Order::query()
                ->where('buyer_id', $request->user()->id)
                ->with($this->historyRelations())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage)
                ->appends(['per_page' => $perPage]);

            $orders->setCollection($orders->getCollection()->map(
                fn (Order $order): array => $this->historyOrder($order, $catalog),
            ));

            return response()->json([
                'data' => $orders->items(),
                'links' => [
                    'first' => $orders->url(1),
                    'last' => $orders->url($orders->lastPage()),
                    'prev' => $orders->previousPageUrl(),
                    'next' => $orders->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'from' => $orders->firstItem(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'to' => $orders->lastItem(),
                    'total' => $orders->total(),
                ],
                'message' => 'Orders retrieved successfully.',
            ]);
        });
    }

    public function show(
        Request $request,
        int $order,
        BuyerCatalogService $catalog,
        BuyerOrderConfirmationService $confirmation,
    ): JsonResponse {
        return $this->safely(function () use ($request, $order, $catalog, $confirmation): JsonResponse {
            $model = $this->ownedOrder($request, $order)
                ->with($this->detailRelations((int) $request->user()->id))
                ->firstOrFail();

            $receiptConfirmed = $confirmation->isConfirmed($model);

            return response()->json([
                'data' => [
                    ...$this->historyOrder($model, $catalog),
                    'platform_discount_minor' => (int) ($model->platform_discount_minor ?? 0),
                    'campaign_platform_discount_minor' => (int) ($model->campaign_platform_discount_minor ?? 0),
                    'platform_shipping_discount_minor' => (int) ($model->platform_shipping_discount_minor ?? 0),
                    'delivery_address' => [
                        'recipient_name' => (string) $model->recipient_name,
                        'recipient_phone' => (string) $model->recipient_phone,
                        'address_line' => (string) $model->address_line,
                        'barangay' => (string) $model->barangay,
                        'city_municipality' => (string) $model->city_municipality,
                        'province' => (string) $model->province,
                        'postal_code' => $model->postal_code,
                    ],
                    'payments' => $model->payments->map(fn ($payment): array => [
                        'method' => (string) $payment->method,
                        'status' => (string) $payment->status,
                        'amount_minor' => (int) $payment->amount_minor,
                        'paid_at' => $this->iso($payment->paid_at),
                    ])->values()->all(),
                    'promotions' => $model->promotionRedemptions->map(fn ($redemption): array => [
                        'name' => (string) $redemption->name,
                        'kind' => (string) $redemption->kind,
                        'status' => (string) $redemption->status,
                        'discount_minor' => (int) $redemption->discount_minor,
                        'shipping_discount_minor' => (int) $redemption->shipping_discount_minor,
                        'allocations' => $redemption->allocations->map(fn ($allocation): array => [
                            'seller_order_id' => (int) $allocation->seller_order_id,
                            'amount_minor' => (int) $allocation->amount_minor,
                        ])->values()->all(),
                    ])->values()->all(),
                    'seller_orders' => $model->sellerOrders->map(
                        fn (SellerOrder $sellerOrder): array => $this->sellerOrderDetail($sellerOrder, $catalog, (int) $request->user()->id),
                    )->values()->all(),
                    'buyer_actions' => [
                        'can_confirm_receipt' => $confirmation->canConfirm($model),
                        'receipt_confirmed' => $receiptConfirmed,
                        'receipt_confirmed_at' => $receiptConfirmed ? $this->iso($model->completed_at) : null,
                    ],
                ],
                'message' => 'Order retrieved successfully.',
            ]);
        });
    }

    public function tracking(
        Request $request,
        int $order,
        BuyerOrderConfirmationService $confirmation,
    ): JsonResponse {
        return $this->safely(function () use ($request, $order, $confirmation): JsonResponse {
            $model = $this->ownedOrder($request, $order)
                ->with($this->trackingRelations())
                ->firstOrFail();
            $receiptConfirmed = $confirmation->isConfirmed($model);

            $sellerOrders = $model->sellerOrders->map(
                fn (SellerOrder $sellerOrder): array => [
                    'id' => (int) $sellerOrder->id,
                    'order_reference' => (string) $sellerOrder->seller_order_no,
                    'status' => (string) $sellerOrder->status,
                    'status_history' => $sellerOrder->statusHistory
                        ->sort(fn ($left, $right): int => [Carbon::parse($left->occurred_at)->getTimestamp(), $left->id]
                            <=> [Carbon::parse($right->occurred_at)->getTimestamp(), $right->id])
                        ->values()
                        ->map(fn ($history): array => [
                            'from_status' => $history->from_status,
                            'to_status' => (string) $history->to_status,
                            'occurred_at' => $this->iso($history->occurred_at),
                        ])->values()->all(),
                    'shipments' => $sellerOrder->shipments->map(
                        fn (Shipment $shipment): array => $this->trackingShipment($shipment),
                    )->values()->all(),
                ],
            )->values()->all();
            $shipmentCount = collect($sellerOrders)->sum(fn (array $sellerOrder): int => count($sellerOrder['shipments']));

            return response()->json([
                'data' => [
                    'order_id' => (int) $model->id,
                    'order_reference' => (string) $model->order_no,
                    'order_status' => (string) $model->status,
                    'payment_status' => (string) $model->payment_status,
                    'placed_at' => $this->iso($model->placed_at ?? $model->created_at),
                    'tracking_state' => $shipmentCount === 0 ? 'pending' : 'available',
                    'shipment_count' => $shipmentCount,
                    'buyer_receipt_confirmed' => $receiptConfirmed,
                    'buyer_receipt_confirmed_at' => $receiptConfirmed ? $this->iso($model->completed_at) : null,
                    'seller_orders' => $sellerOrders,
                ],
                'message' => 'Order tracking retrieved successfully.',
            ]);
        });
    }

    private function ownedOrder(Request $request, int $order): Builder
    {
        return Order::query()->where('buyer_id', $request->user()->id)->whereKey($order);
    }

    private function historyRelations(): array
    {
        return [
            'sellerOrders' => fn ($query) => $query->orderBy('id')->with([
                'store:id,name',
                'items' => fn ($items) => $items->orderBy('id'),
                'shipments' => fn ($shipments) => $shipments
                    ->orderBy('id')
                    ->with('waybill:id,shipment_id,waybill_no'),
            ]),
            'payments' => fn ($query) => $query->orderBy('id'),
        ];
    }

    private function detailRelations(int $buyerId): array
    {
        return [
            ...$this->historyRelations(),
            'promotionRedemptions' => fn ($query) => $query->orderBy('id')->with([
                'allocations' => fn ($allocations) => $allocations->orderBy('id'),
            ]),
            'sellerOrders.returnRequests' => fn ($query) => $query
                ->where('buyer_id', $buyerId)
                ->orderBy('id')
                ->with(['refunds' => fn ($refunds) => $refunds->orderBy('id')]),
        ];
    }

    private function trackingRelations(): array
    {
        return [
            'sellerOrders' => fn ($query) => $query->orderBy('id')->with([
                'statusHistory' => fn ($history) => $history
                    ->reorder()
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->limit(100),
                'shipments' => fn ($shipments) => $shipments
                    ->where('purpose', 'outbound')
                    ->orderBy('id')
                    ->with([
                        'waybill:id,shipment_id,waybill_no',
                        'events' => fn ($events) => $events
                            ->reorder()
                            ->orderByDesc('occurred_at')
                            ->orderByDesc('id')
                            ->limit(100)
                            ->with('parcel:id,parcel_no'),
                        'parcels' => fn ($parcels) => $parcels->orderBy('id')->with([
                            'pickupRequests.assignments' => fn ($assignments) => $assignments
                                ->reorder()
                                ->orderByDesc('id')
                                ->limit(50),
                            'deliveryAttempts' => fn ($attempts) => $attempts
                                ->reorder()
                                ->orderByDesc('attempt_no')
                                ->orderByDesc('id')
                                ->limit(50),
                        ]),
                    ]),
            ]),
        ];
    }

    private function historyOrder(Order $order, BuyerCatalogService $catalog): array
    {
        $sellerOrders = $order->sellerOrders;
        $items = $sellerOrders->flatMap(fn (SellerOrder $sellerOrder) => $sellerOrder->items);
        $codPayment = $order->payments->first(fn ($payment): bool => $payment->method === 'cod');
        $previewUrl = null;

        foreach ($items as $item) {
            $previewUrl = $this->snapshotImageUrl($item, $catalog);
            if ($previewUrl !== null) {
                break;
            }
        }

        return [
            'id' => (int) $order->id,
            'order_reference' => (string) $order->order_no,
            'created_at' => $this->iso($order->created_at),
            'placed_at' => $this->iso($order->placed_at ?? $order->created_at),
            'status' => (string) $order->status,
            'payment_status' => (string) $order->payment_status,
            'currency' => (string) ($order->currency ?: 'PHP'),
            'subtotal_minor' => (int) $order->subtotal_minor,
            'shipping_fee_minor' => (int) $order->shipping_fee_minor,
            'discount_minor' => (int) $order->discount_minor,
            'total_minor' => (int) $order->total_minor,
            'item_count' => (int) $items->sum(fn ($item): int => (int) $item->quantity),
            'preview_image_url' => $previewUrl,
            'cod_payment' => $codPayment ? [
                'status' => (string) $codPayment->status,
                'amount_minor' => (int) $codPayment->amount_minor,
                'paid_at' => $this->iso($codPayment->paid_at),
            ] : null,
            'seller_orders' => $sellerOrders->map(fn (SellerOrder $sellerOrder): array => $this->sellerOrderSummary($sellerOrder))
                ->values()->all(),
        ];
    }

    private function sellerOrderSummary(SellerOrder $sellerOrder): array
    {
        return [
            'id' => (int) $sellerOrder->id,
            'order_reference' => (string) $sellerOrder->seller_order_no,
            'store_id' => (int) $sellerOrder->store_id,
            'store_name' => $sellerOrder->store?->name,
            'status' => (string) $sellerOrder->status,
            'item_count' => (int) $sellerOrder->items->sum(fn ($item): int => (int) $item->quantity),
            'line_count' => $sellerOrder->items->count(),
            'subtotal_minor' => (int) $sellerOrder->subtotal_minor,
            'discount_minor' => (int) $sellerOrder->discount_minor,
            'shipping_fee_minor' => (int) $sellerOrder->shipping_fee_minor,
            'total_minor' => (int) $sellerOrder->total_minor,
            'shipments' => $sellerOrder->shipments->where('purpose', 'outbound')->map(fn (Shipment $shipment): array => [
                'id' => (int) $shipment->id,
                'shipment_reference' => (string) $shipment->shipment_no,
                'status' => (string) $shipment->status,
                'waybill_reference' => $shipment->waybill?->waybill_no,
                'estimated_delivery_at' => $this->iso($shipment->estimated_delivery_at),
                'delivered_at' => $this->iso($shipment->delivered_at),
            ])->values()->all(),
        ];
    }

    private function sellerOrderDetail(SellerOrder $sellerOrder, BuyerCatalogService $catalog, int $buyerId): array
    {
        return [
            ...$this->sellerOrderSummary($sellerOrder),
            'platform_discount_minor' => (int) ($sellerOrder->platform_discount_minor ?? 0),
            'campaign_platform_discount_minor' => (int) ($sellerOrder->campaign_platform_discount_minor ?? 0),
            'platform_shipping_discount_minor' => (int) ($sellerOrder->platform_shipping_discount_minor ?? 0),
            'items' => $sellerOrder->items->map(fn ($item): array => [
                'id' => (int) $item->id,
                'product_name' => (string) $item->product_name,
                'variant_name' => (string) $item->variant_name,
                'sku' => (string) $item->sku,
                'options' => is_array($item->options) ? $item->options : [],
                'product_image_url' => $this->snapshotImageUrl($item, $catalog),
                'quantity' => (int) $item->quantity,
                'unit_price_minor' => (int) $item->unit_price_minor,
                'subtotal_minor' => (int) $item->subtotal_minor,
                'seller_discount_minor' => (int) $item->discount_minor,
                'platform_discount_minor' => (int) ($item->platform_discount_minor ?? 0),
                'campaign_platform_discount_minor' => (int) ($item->campaign_platform_discount_minor ?? 0),
                'total_minor' => max(0, (int) $item->subtotal_minor
                    - (int) $item->discount_minor
                    - (int) ($item->platform_discount_minor ?? 0)
                    - (int) ($item->campaign_platform_discount_minor ?? 0)),
            ])->values()->all(),
            'returns' => $sellerOrder->returnRequests
                ->where('buyer_id', $buyerId)
                ->map(fn ($return): array => [
                    'return_reference' => (string) $return->return_no,
                    'request_type' => (string) $return->request_type,
                    'status' => (string) $return->status,
                    'requested_amount_minor' => (int) $return->requested_amount_minor,
                    'submitted_at' => $this->iso($return->submitted_at),
                    'resolved_at' => $this->iso($return->resolved_at),
                    'refunds' => $return->refunds->map(fn ($refund): array => [
                        'status' => (string) $refund->status,
                        'amount_minor' => (int) $refund->amount_minor,
                        'completed_at' => $this->iso($refund->completed_at),
                    ])->values()->all(),
                ])->values()->all(),
        ];
    }

    private function trackingShipment(Shipment $shipment): array
    {
        $events = [];
        foreach ($shipment->events as $event) {
            $events[] = [
                'event_id' => (int) $event->id,
                'scope' => $event->parcel_id === null ? 'shipment' : 'parcel',
                'event_type' => (string) $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => (string) $event->to_status,
                'parcel_reference' => $event->parcel?->parcel_no,
                'occurred_at' => $this->iso($event->occurred_at),
                '_sort_time' => Carbon::parse($event->occurred_at)->getTimestamp(),
            ];
        }

        usort($events, fn (array $left, array $right): int => [$left['_sort_time'], $left['event_id']] <=> [$right['_sort_time'], $right['event_id']]);
        $events = array_map(static function (array $event): array {
            unset($event['_sort_time']);

            return $event;
        }, $events);

        $assignments = $shipment->parcels
            ->flatMap(fn ($parcel) => $parcel->pickupRequests->flatMap(fn ($pickup) => $pickup->assignments))
            ->unique('id')
            ->sortBy('id')
            ->values()
            ->map(fn ($assignment): array => [
                'status' => (string) $assignment->status,
                'assigned_at' => $this->iso($assignment->assigned_at),
                'accepted_at' => $this->iso($assignment->accepted_at),
                'arrived_at' => $this->iso($assignment->arrived_at),
                'picked_up_at' => $this->iso($assignment->picked_up_at),
                'completed_at' => $this->iso($assignment->completed_at),
            ])->all();

        $attempts = $shipment->parcels
            ->flatMap(fn ($parcel) => $parcel->deliveryAttempts->map(fn ($attempt) => [
                'parcel_reference' => (string) $parcel->parcel_no,
                'attempt_no' => (int) $attempt->attempt_no,
                'outcome' => (string) $attempt->outcome,
                'attempted_at' => $this->iso($attempt->attempted_at),
                'next_attempt_at' => $this->iso($attempt->next_attempt_at),
            ]))
            ->sortBy(fn (array $attempt): array => [$attempt['attempted_at'] ?? '', $attempt['parcel_reference'], $attempt['attempt_no']])
            ->values()
            ->all();

        return [
            'id' => (int) $shipment->id,
            'shipment_reference' => (string) $shipment->shipment_no,
            'purpose' => (string) $shipment->purpose,
            'status' => (string) $shipment->status,
            'waybill_reference' => $shipment->waybill?->waybill_no,
            'estimated_delivery_at' => $this->iso($shipment->estimated_delivery_at),
            'delivered_at' => $this->iso($shipment->delivered_at),
            'events' => $events,
            'pickup_assignments' => $assignments,
            'delivery_attempts' => $attempts,
        ];
    }

    private function snapshotImageUrl(OrderItem $item, BuyerCatalogService $catalog): ?string
    {
        $path = trim((string) $item->product_image_path);
        $disk = trim((string) $item->product_image_disk);

        if ($path === '' || $disk === '') {
            return null;
        }

        return $catalog->publicImageUrl(new ProductImage(['path' => $path, 'disk' => $disk]));
    }

    private function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toIso8601String();
    }

    private function safely(Closure $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (HttpExceptionInterface|ModelNotFoundException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Mobile Buyer order read failed.', [
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'message' => 'The order information could not be retrieved right now.',
                'error' => ['code' => 'buyer_order_read_failed'],
            ], 500);
        }
    }
}
