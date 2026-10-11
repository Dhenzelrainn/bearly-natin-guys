<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\PickupRequest;
use App\Models\Refund;
use App\Models\ReturnEvidence;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Services\BuyerCatalogService;
use App\Services\SellerIdentityService;
use App\Services\SellerOrderTrackingService;
use App\Services\SellerOrderWorkflowService;
use App\Services\SellerPickupRequestService;
use App\Services\ReturnRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerWorkflowController extends Controller
{
    private const COMMISSION_RATE = 0.10;

    private function seller(): array
    {
        return app(
            SellerIdentityService::class
        )->current();
    }

    private function notifications(): array
    {
        return [
            ['title' => '2 new orders need confirmation', 'time' => '5 minutes ago', 'type' => 'warning'],
            ['title' => 'Classic Linen Shirt is low in stock', 'time' => '42 minutes ago', 'type' => 'danger'],
            ['title' => '2 parcels are ready for pickup request', 'time' => '1 hour ago', 'type' => 'success'],
        ];
    }

    private function money(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    private function financeSnapshot(): array
    {
        $grossSales = 128450.00;
        $sellerDiscounts = 4430.00;
        $refunds = 1299.00;
        $commissionBaseBeforeReversal = $grossSales - $sellerDiscounts;
        $commissionEarnedBeforeReversal = $commissionBaseBeforeReversal * self::COMMISSION_RATE;
        $commissionReversal = $refunds * self::COMMISSION_RATE;
        $netCommission = $commissionEarnedBeforeReversal - $commissionReversal;
        $netCommissionableSales = $grossSales - $sellerDiscounts - $refunds;
        $netEarnings = $grossSales - $sellerDiscounts - $refunds - $netCommission;
        $totalDeductions = $sellerDiscounts + $refunds + $netCommission;

        return compact(
            'grossSales',
            'sellerDiscounts',
            'refunds',
            'commissionBaseBeforeReversal',
            'commissionEarnedBeforeReversal',
            'commissionReversal',
            'netCommission',
            'netCommissionableSales',
            'netEarnings',
            'totalDeductions'
        );
    }

    private function persistedOrdersData(
        Request $request,
        BuyerCatalogService $catalog,
        SellerOrderTrackingService $tracking,
    ): array
    {
        $storeId = $request->user()->sellerProfile?->store?->id;
        if (! $storeId) {
            return [];
        }

        return SellerOrder::query()
            ->where('store_id', $storeId)
            ->with([
                'order.payments',
                'items.product.images.variant',
                'items.variant',
                'statusHistory.actor',
                'shipments.events',
            ])
            ->latest('created_at')
            ->get()
            ->map(function (SellerOrder $sellerOrder) use ($catalog, $tracking): array {
                $order = $sellerOrder->order;
                $status = (string) $sellerOrder->status;
                $trackingState = $tracking->snapshot($sellerOrder);
                $payment = $order?->payments?->first();
                $paymentLabel = $payment?->method === 'cod'
                    ? ($status === 'cancelled'
                        ? 'Cash on Delivery · Seller Order cancelled'
                        : match ($payment->status) {
                            'cancelled' => 'Cash on Delivery · Cancelled',
                            'paid' => 'Cash on Delivery · Collected',
                            default => 'Cash on Delivery · Unpaid',
                        })
                    : Str::headline((string) ($payment?->method ?? 'Payment pending'));
                $items = $sellerOrder->items;
                $quantity = (int) $items->sum('quantity');
                $itemDetail = $items->map(function ($item): string {
                    $name = trim($item->product_name.' · '.$item->variant_name, ' ·');

                    return $name.((int) $item->quantity > 1 ? ' × '.$item->quantity : '');
                })->implode(' + ');
                $itemRows = $items->map(function ($item) use ($catalog): array {
                    $imagePath = trim((string) $item->product_image_path);
                    $imageDisk = (string) ($item->product_image_disk ?: 'public');

                    if ($imagePath === '' && $item->product && $item->variant) {
                        $image = $catalog->orderImageRecord($item->product, $item->variant);
                        $imagePath = trim((string) ($image?->path ?? ''));
                        $imageDisk = (string) ($image?->disk ?: 'public');
                    }

                    return [
                        'name' => $item->product_name,
                        'variant' => $item->variant_name,
                        'sku' => $item->sku,
                        'quantity' => (int) $item->quantity,
                        'unit_price_minor' => (int) $item->unit_price_minor,
                        'unit_price' => number_format(((int) $item->unit_price_minor) / 100, 2, '.', ''),
                        'subtotal_minor' => (int) $item->subtotal_minor,
                        'image' => $this->sellerOrderImageUrl($imagePath, $imageDisk),
                    ];
                })->values();
                $address = $order ? implode(', ', array_filter([
                    $order->recipient_name,
                    $order->address_line,
                    $order->barangay,
                    $order->city_municipality,
                    $order->province,
                    $order->postal_code,
                ])) : '';
                $createdAt = $sellerOrder->created_at;
                $dateKey = $createdAt?->isToday() ? 'today' : 'upcoming';
                $deadline = $createdAt
                    ? ($createdAt->isToday() ? 'Today · '.$createdAt->format('g:i A') : $createdAt->format('M j, Y'))
                    : '—';

                return [
                    'id' => '#'.$sellerOrder->seller_order_no,
                    'customer' => (string) ($order?->recipient_name ?? 'Buyer'),
                    'items' => $quantity.' '.($quantity === 1 ? 'item' : 'items'),
                    'item_detail' => $itemDetail ?: 'Order items',
                    'item_rows' => $itemRows->all(),
                    'payment' => $paymentLabel,
                    'payment_key' => $payment?->method === 'cod' ? 'cod' : 'paid',
                    'total' => '₱'.number_format(((int) $sellerOrder->total_minor) / 100, 2),
                    'deadline' => $deadline,
                    'date_key' => $dateKey,
                    'urgent' => false,
                    'status' => $trackingState['status_label'],
                    'status_detail' => $trackingState['status_detail'],
                    'canonical_status' => $trackingState['canonical_status'],
                    'status_key' => $trackingState['status_key'],
                    'tone' => $trackingState['tone'],
                    'responsibility' => $trackingState['responsibility'],
                    'next_step' => $trackingState['next_step'],
                    'action' => match ($status) {
                        'pending', 'placed' => 'Confirm Order',
                        'accepted' => 'Start Preparing',
                        'packing' => 'Mark Ready for Pickup',
                        default => 'View Details',
                    },
                    'transition_action' => match ($status) {
                        'pending', 'placed' => 'confirm',
                        'accepted' => 'start_preparing',
                        'packing' => 'mark_ready',
                        default => null,
                    },
                    'transition_url' => route('seller.orders.status', ['sellerOrder' => $sellerOrder->id]),
                    'tracking_url' => route('seller.orders.tracking', ['sellerOrder' => $sellerOrder->id]),
                    'status_history' => $trackingState['timeline_history'],
                    'tracking_updated_at' => $trackingState['updated_at'],
                    'action_url' => null,
                    'order_date' => $createdAt?->format('M j, Y g:i A') ?? '',
                    'address' => $address,
                    'sku' => $items->count() === 1 ? (string) $items->first()->sku : '',
                    'product_image' => (string) ($itemRows->first()['image'] ?? ''),
                ];
            })
            ->all();
    }

    private function sellerOrderImageUrl(string $path, string $disk): string
    {
        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['http://', 'https://', '/'])
            ? $path
            : Storage::disk($disk)->url($path);
    }

    public function dashboard(
        Request $request,
        BuyerCatalogService $catalog,
        SellerOrderTrackingService $tracking,
    ): View
    {
        $finance = $this->financeSnapshot();
        $orders = collect($this->persistedOrdersData($request, $catalog, $tracking));

        $newOrders = $orders->where('status_key', 'new')->count();
        $toPrepare = $orders->where('status_key', 'to-prepare')->count();
        $readyPickup = $orders->where('status_key', 'ready-pickup')->count();
        $waybillToPrint = 0;

        return view('seller.Dashboard.dashboard', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dashboard' => [
                'updated_at' => '5:42 PM',
                'action_count' => $newOrders + $toPrepare + $readyPickup + $waybillToPrint,
                'pickup_time' => '3:00 PM',
                'actions' => [
                    ['count' => $newOrders, 'label' => 'new orders', 'detail' => 'Review and confirm stock', 'action' => 'Review', 'icon' => 'clipboard-list', 'tone' => 'amber', 'target' => route('seller.orders.new')],
                    ['count' => $toPrepare, 'label' => 'orders to prepare', 'detail' => 'Confirmed / preparing', 'action' => 'Prepare', 'icon' => 'package', 'tone' => 'olive', 'target' => route('seller.orders.prepare')],
                    ['count' => $waybillToPrint, 'label' => 'waybill to print', 'detail' => 'Required before ready for pickup', 'action' => 'Print', 'icon' => 'printer', 'tone' => 'brown', 'target' => route('seller.fulfillment.waybills')],
                    ['count' => $readyPickup, 'label' => 'parcels ready for pickup', 'detail' => 'Submit to logistics', 'action' => 'Arrange pickup', 'icon' => 'truck', 'tone' => 'green', 'target' => route('seller.fulfillment.pickups')],
                ],
            ],
            'stats' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'change' => '+12.5% this month', 'icon' => 'philippine-peso', 'tone' => 'gold'],
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'change' => 'After discounts, refunds & commission', 'icon' => 'package-check', 'tone' => 'olive'],
                ['label' => 'Platform Commission', 'value' => $this->money($finance['netCommission']), 'change' => '10% after refund reversal', 'icon' => 'badge-percent', 'tone' => 'brown'],
                ['label' => 'Fulfillment Rate', 'value' => '96.8%', 'change' => '+1.4% vs last month', 'icon' => 'circle-check-big', 'tone' => 'olive'],
            ],
            'sales' => [9800, 13400, 13800, 18100, 22800, 15700, 24100],
            'topProducts' => [
                ['name' => 'Classic Linen Shirt', 'sku' => 'CLS-LINEN-SHIRT', 'sold' => 42, 'revenue' => '₱54,600', 'percent' => 100, 'icon' => 'shirt'],
                ['name' => 'Canvas Tote Bag', 'sku' => 'CNV-TOTE-BAG', 'sold' => 31, 'revenue' => '₱27,869', 'percent' => 74, 'icon' => 'shopping-bag'],
                ['name' => 'Everyday Sneakers', 'sku' => 'EV-SNKRS-WHT', 'sold' => 18, 'revenue' => '₱23,382', 'percent' => 43, 'icon' => 'footprints'],
            ],
            'payoutSummary' => [
                ['label' => 'Gross completed sales', 'value' => $this->money($finance['grossSales'])],
                ['label' => 'Seller discounts + refunds', 'value' => '−'.$this->money($finance['sellerDiscounts'] + $finance['refunds'])],
                ['label' => 'Platform commission (10%)', 'value' => '−'.$this->money($finance['netCommission'])],
                ['label' => 'Net earnings', 'value' => $this->money($finance['netEarnings'])],
            ],
            'pickupSummary' => [
                'time' => '3:00 PM',
                'date' => 'Today · Laguna route',
                'ready' => $readyPickup,
                'not_ready' => $toPrepare + $newOrders,
            ],
            'sellerHealth' => [
                'status' => 'Good standing',
                'score' => '96 / 100',
                'summary' => 'No category violations or account warnings. One customer case is still open.',
                'items' => [
                    ['label' => 'Product compliance', 'value' => '0 violations', 'note' => 'All active products match the registered business category.', 'tone' => 'success', 'icon' => 'shield-check'],
                    ['label' => 'Open disputes', 'value' => '1 case', 'note' => 'Respond before the case deadline to protect your seller record.', 'tone' => 'warning', 'icon' => 'messages-square'],
                    ['label' => 'Response rate', 'value' => '91%', 'note' => 'Buyer messages answered within the expected response window.', 'tone' => 'info', 'icon' => 'message-circle-reply'],
                    ['label' => 'Fulfillment reliability', 'value' => '96.8%', 'note' => 'Orders prepared and handed over within seller deadlines.', 'tone' => 'success', 'icon' => 'package-check'],
                ],
            ],
        ]);
    }

    public function orders(
        Request $request,
        BuyerCatalogService $catalog,
        SellerOrderTrackingService $tracking,
    ): View
    {
        $orders = $this->persistedOrdersData($request, $catalog, $tracking);
        $allowed = ['all', 'new', 'to-prepare', 'ready-pickup', 'shipped', 'completed', 'cancelled-returned'];
        $requestedStatus = (string) $request->query('status', 'all');
        $defaultStatus = in_array($requestedStatus, $allowed, true) ? $requestedStatus : 'all';
        $count = fn (string $status) => collect($orders)->where('status_key', $status)->count();

        return view('seller.Orders.orders', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'defaultOrderStatus' => $defaultStatus,
            'orderQueue' => [
                ['key' => 'new', 'label' => 'New orders', 'count' => $count('new'), 'note' => 'Awaiting Seller review', 'icon' => 'clipboard-list', 'tone' => 'gold'],
                ['key' => 'to-prepare', 'label' => 'To prepare', 'count' => $count('to-prepare'), 'note' => 'Confirmed and preparing', 'icon' => 'package', 'tone' => 'olive'],
                ['key' => 'waybill', 'label' => 'Waybill to print', 'count' => 0, 'note' => 'Waybill workflow is not connected yet', 'icon' => 'printer', 'tone' => 'blue'],
                ['key' => 'ready-pickup', 'label' => 'Ready for pickup', 'count' => $count('ready-pickup'), 'note' => 'Schedule collection with Logistics', 'icon' => 'truck', 'tone' => 'brown'],
            ],
            'orderTabs' => [
                ['key' => 'all', 'label' => 'All Orders', 'count' => count($orders)],
                ['key' => 'new', 'label' => 'New', 'count' => $count('new')],
                ['key' => 'to-prepare', 'label' => 'To Prepare', 'count' => $count('to-prepare')],
                ['key' => 'ready-pickup', 'label' => 'Ready for Pickup', 'count' => $count('ready-pickup')],
                ['key' => 'shipped', 'label' => 'Shipped', 'count' => $count('shipped')],
                ['key' => 'completed', 'label' => 'Completed', 'count' => $count('completed')],
                ['key' => 'cancelled-returned', 'label' => 'Cancelled / Returned', 'count' => $count('cancelled-returned')],
            ],
            'orders' => $orders,
        ]);
    }

    public function updateOrderStatus(
        Request $request,
        string $sellerOrder,
        SellerOrderWorkflowService $workflow,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['confirm', 'start_preparing', 'mark_ready', 'cancel'])],
            'reason' => ['nullable', 'string', 'required_if:action,cancel', 'min:5', 'max:1000'],
        ]);

        $sellerOrder = $workflow->transition(
            $request->user(),
            (int) $sellerOrder,
            $validated['action'],
            $validated['reason'] ?? null,
        );

        $message = match ($validated['action']) {
            'confirm' => 'Order confirmed. You can now prepare it.',
            'start_preparing' => 'Order moved to preparation.',
            'mark_ready' => 'Order marked ready for pickup.',
            'cancel' => 'Seller Order cancelled and its reserved stock released.',
        };
        $statusFilter = match ($sellerOrder->status) {
            'pending' => 'new',
            'accepted', 'packing' => 'to-prepare',
            'ready_for_pickup' => 'ready-pickup',
            'cancelled' => 'cancelled-returned',
            default => 'all',
        };
        $redirectTo = route('seller.orders', ['status' => $statusFilter]);

        $request->session()->flash('success', $message);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'seller_order_no' => $sellerOrder->seller_order_no,
                    'status' => $sellerOrder->status,
                    'redirect_to' => $redirectTo,
                ],
                'message' => $message,
            ]);
        }

        return redirect()->to($redirectTo);
    }

    public function orderTracking(
        Request $request,
        string $sellerOrder,
        SellerOrderTrackingService $tracking,
    ): JsonResponse {
        $storeId = $request->user()->sellerProfile?->store?->id;
        abort_unless($storeId, 404, 'Seller Store not found.');

        $sellerOrder = SellerOrder::query()
            ->where('store_id', $storeId)
            ->with([
                'statusHistory.actor',
                'shipments.events',
            ])
            ->findOrFail($sellerOrder);

        return response()->json([
            'data' => $tracking->snapshot($sellerOrder),
            'message' => 'Seller Order tracking updated.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function waybills(): View
    {
        return view('seller.fulfillment.waybills', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Ready to Print', 'value' => '1', 'note' => 'Preparing order is packed', 'icon' => 'printer', 'tone' => 'gold'],
                ['label' => 'Printed Today', 'value' => '2', 'note' => 'Ready for pickup', 'icon' => 'file-check-2', 'tone' => 'olive'],
                ['label' => 'Reprint Required', 'value' => '0', 'note' => 'No damaged labels', 'icon' => 'refresh-cw', 'tone' => 'brown'],
                ['label' => 'Pickup Cutoff', 'value' => '2:30 PM', 'note' => 'For today’s collection', 'icon' => 'clock-3', 'tone' => 'warning'],
            ],
            'waybills' => [
                ['order' => '#BR-1057', 'tracking' => 'Pending generation', 'customer' => 'Carlo Reyes', 'destination' => 'Calamba, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '1.0 kg', 'size' => '34 × 24 × 13 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Ready to Print', 'status_key' => 'ready', 'action' => 'Print Waybill'],
                ['order' => '#BR-1056', 'tracking' => 'BRLY-784231', 'customer' => 'Ana Cruz', 'destination' => 'Los Baños, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.9 kg', 'size' => '30 × 22 × 10 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Printed', 'status_key' => 'printed', 'action' => 'View Label'],
                ['order' => '#BR-1055', 'tracking' => 'BRLY-784230', 'customer' => 'Sofia Mendoza', 'destination' => 'Cabuyao, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.5 kg', 'size' => '24 × 18 × 7 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Printed', 'status_key' => 'printed', 'action' => 'View Label'],
            ],
            'history' => [
                ['time' => 'Today · 1:08 PM', 'order' => '#BR-1056', 'tracking' => 'BRLY-784231', 'action' => 'Waybill printed', 'actor' => 'Bea Rivera'],
                ['time' => 'Today · 12:52 PM', 'order' => '#BR-1055', 'tracking' => 'BRLY-784230', 'action' => 'Waybill printed', 'actor' => 'Bea Rivera'],
            ],
        ]);
    }

    public function pickupRequests(Request $request, SellerPickupRequestService $pickupService): View
    {
        $user = $request->user();
        $sellerProfile = SellerProfile::query()
            ->where('user_id', $user->id)
            ->with('pickupAddress')
            ->first();
        $store = $sellerProfile?->store;

        abort_unless($sellerProfile && $store, 404, 'Seller Store not found.');

        $eligibleOrders = SellerOrder::query()
            ->where('store_id', $store->id)
            ->where('status', 'ready_for_pickup')
            ->whereDoesntHave('shipments')
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->with('order')
            ->orderBy('ready_at')
            ->orderBy('id')
            ->get()
            ->map(function (SellerOrder $sellerOrder): array {
                $quote = (array) $sellerOrder->shipping_quote;
                $weight = $quote['actual_weight_kg'] ?? null;
                $order = $sellerOrder->order;

                return [
                    'id' => (int) $sellerOrder->id,
                    'order' => '#'.$sellerOrder->seller_order_no,
                    'packages' => 1,
                    'weight' => is_numeric($weight) && (float) $weight > 0
                        ? number_format((float) $weight, 3).' kg actual weight'
                        : 'Package details unavailable',
                    'destination' => $order
                        ? implode(', ', array_filter([
                            $order->city_municipality,
                            $order->province,
                        ]))
                        : 'Destination unavailable',
                ];
            });

        $pickupRequests = PickupRequest::query()
            ->where('store_id', $store->id)
            ->with([
                'logisticsProfile',
                'pickupAddress',
                'latestAssignment.riderProfile.user',
                'parcels.waybill',
                'parcels.shipment.sellerOrder',
            ])
            ->latest('created_at')
            ->get();

        $requests = $pickupRequests
            ->map(function (PickupRequest $pickupRequest): array {
                $parcels = $pickupRequest->parcels
                    ->filter(fn ($parcel): bool => (int) $parcel->shipment?->logistics_profile_id === (int) $pickupRequest->logistics_profile_id
                        && (int) $parcel->shipment?->sellerOrder?->store_id === (int) $pickupRequest->store_id)
                    ->values();
                $sellerOrders = $parcels
                    ->map(fn ($parcel) => $parcel->shipment?->sellerOrder)
                    ->filter()
                    ->unique('id')
                    ->values();
                [$status, $statusKey] = $this->sellerPickupStatus($pickupRequest);
                $start = $pickupRequest->window_start;
                $end = $pickupRequest->window_end;
                $date = $pickupRequest->requested_date;
                $dateKey = $date?->isToday()
                    ? 'today'
                    : ($date?->isTomorrow() ? 'tomorrow' : 'other');

                return [
                    'id' => $pickupRequest->pickup_no,
                    'orders' => $sellerOrders->count().' '.($sellerOrders->count() === 1 ? 'order' : 'orders'),
                    'order_numbers' => $sellerOrders->pluck('seller_order_no')->implode(', '),
                    'packages' => $parcels->count(),
                    'provider' => $pickupRequest->logisticsProfile?->display_name ?? 'Provider unavailable',
                    'schedule' => ($date?->format('M j, Y') ?? 'Date unavailable').' · '.($start?->format('g:i A') ?? '—').'–'.($end?->format('g:i A') ?? '—'),
                    'date_key' => $dateKey,
                    'address' => $this->sellerPickupAddressLabel($pickupRequest->pickupAddress),
                    'rider' => $pickupRequest->latestAssignment?->riderProfile?->user?->name ?? 'Awaiting assignment',
                    'instructions' => $pickupRequest->seller_instructions ?: 'No pickup instructions.',
                    'status' => $status,
                    'status_key' => $statusKey,
                ];
            })
            ->values();

        return view('seller.fulfillment.pickups', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'eligibleOrders' => $eligibleOrders->all(),
            'requests' => $requests->all(),
            'providers' => $pickupService->activeProviders(),
            'pickupAddress' => $this->sellerPickupAddressLabel($sellerProfile->pickupAddress),
            'pickupPhone' => $sellerProfile->pickupAddress?->phone,
        ]);
    }

    public function storePickupRequest(Request $request, SellerPickupRequestService $pickupService): RedirectResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:30'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'logistics_profile_id' => ['required', 'integer', 'min:1'],
            'requested_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pickup_window' => ['required', 'string', Rule::in(array_keys(SellerPickupRequestService::PICKUP_WINDOWS))],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $pickupRequest = $pickupService->create(
            $request->user(),
            (int) $validated['logistics_profile_id'],
            array_map('intval', $validated['order_ids']),
            $validated['requested_date'],
            $validated['pickup_window'],
            $validated['instructions'] ?? null,
        );

        return redirect()
            ->route('seller.fulfillment.pickups')
            ->with('success', "{$pickupRequest->pickup_no} submitted to Logistics. They will verify the request and assign a Rider.");
    }

    /** @return array{0: string, 1: string} */
    private function sellerPickupStatus(PickupRequest $pickupRequest): array
    {
        return match ($pickupRequest->status) {
            'requested' => ['Pending Approval', 'pending'],
            'verified' => ['Verified · Awaiting Rider', 'pending'],
            'scheduled' => $pickupRequest->latestAssignment?->status === 'picked_up'
                ? ['Rider Collected', 'picked-up']
                : ['Rider Assigned', 'assigned'],
            'completed' => ['Received at Sorting Center', 'picked-up'],
            'cancelled' => ['Rejected', 'cancelled'],
            'failed' => ['Pickup Failed', 'cancelled'],
            default => [Str::headline($pickupRequest->status), 'pending'],
        };
    }

    private function sellerPickupAddressLabel(?Address $address): string
    {
        if (! $address) {
            return 'Pickup address not set';
        }

        $street = trim(implode(' ', array_filter([
            trim((string) $address->house_number),
            trim((string) $address->street),
        ])));

        return implode(', ', array_filter([
            trim((string) $address->recipient_name),
            $street,
            trim((string) $address->barangay),
            trim((string) $address->city_municipality),
            trim((string) $address->province),
            trim((string) $address->postal_code),
        ]));
    }

    public function shipmentTracking(): View
    {
        return view('seller.fulfillment.tracking', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Sorting / Routed', 'value' => '2', 'note' => 'At center or already sorted', 'icon' => 'scan-line', 'tone' => 'info'],
                ['label' => 'Out for Delivery', 'value' => '1', 'note' => 'With delivery rider', 'icon' => 'bike', 'tone' => 'gold'],
                ['label' => 'Delivered', 'value' => '1', 'note' => 'Waiting buyer confirmation', 'icon' => 'badge-check', 'tone' => 'olive'],
                ['label' => 'Needs Attention', 'value' => '1', 'note' => 'Delivery exception', 'icon' => 'triangle-alert', 'tone' => 'warning'],
            ],
            'shipments' => [
                ['tracking' => 'BRLY-784228', 'order' => '#BR-1054', 'customer' => 'Jamie Lim', 'destination' => 'Biñan, Laguna', 'rider' => 'Pickup rider: Paolo Reyes', 'latest' => 'Parcel received at Santa Rosa Sorting Center', 'updated' => 'Today · 2:18 PM', 'eta' => 'Sep 12', 'status' => 'At Sorting Center', 'status_key' => 'sorting', 'canonical_status' => 'AT_SORTING_CENTER'],
                ['tracking' => 'BRLY-784218', 'order' => '#BR-1053', 'customer' => 'Miguel Garcia', 'destination' => 'Calamba, Laguna', 'rider' => 'Delivery rider assignment pending', 'latest' => 'Parcel sorted for Calamba delivery area', 'updated' => 'Today · 1:42 PM', 'eta' => 'Sep 12', 'status' => 'Sorted', 'status_key' => 'transit', 'canonical_status' => 'SORTED'],
                ['tracking' => 'BRLY-784188', 'order' => '#BR-1052', 'customer' => 'Liam Reyes', 'destination' => 'Los Baños, Laguna', 'rider' => 'Paolo Reyes', 'latest' => 'Rider is delivering the parcel', 'updated' => 'Today · 9:15 AM', 'eta' => 'Today', 'status' => 'Out for Delivery', 'status_key' => 'out-delivery', 'canonical_status' => 'OUT_FOR_DELIVERY'],
                ['tracking' => 'BRLY-784176', 'order' => '#BR-1051', 'customer' => 'Kim Chua', 'destination' => 'Calamba, Laguna', 'rider' => 'John Ramos', 'latest' => 'Delivered to customer; awaiting buyer confirmation', 'updated' => 'Today · 10:05 AM', 'eta' => 'Delivered', 'status' => 'Delivered', 'status_key' => 'delivered', 'canonical_status' => 'DELIVERED'],
                ['tracking' => 'BRLY-784160', 'order' => '#BR-1049', 'customer' => 'Daniel Tan', 'destination' => 'Pagsanjan, Laguna', 'rider' => 'Alex Santos', 'latest' => 'Delivery failed: customer unavailable', 'updated' => 'Yesterday · 5:40 PM', 'eta' => 'Reschedule', 'status' => 'Delivery Failed', 'status_key' => 'failed', 'canonical_status' => 'DELIVERY_FAILED'],
            ],
        ]);
    }

    public function returns(Request $request): View
    {
        $store = $request->user()->sellerProfile?->store;
        abort_unless($store, 404);

        $returnRequests = ReturnRequest::query()
            ->where('store_id', $store->id)
            ->with(['order', 'buyer', 'sellerOrder', 'items.orderItem', 'evidence', 'events.actor', 'refunds.commissions', 'refunds.sellerTransactions', 'refunds.sellerRecovery.events.sellerPayout', 'returnShipment.waybill', 'returnShipment.events'])
            ->latest('submitted_at')
            ->latest('id')
            ->get();
        $cases = $returnRequests->map(fn (ReturnRequest $case): array => $this->returnCaseData($case))->all();
        $completedThisMonthMinor = Refund::query()
            ->whereHas('returnRequest', fn ($query) => $query->where('store_id', $store->id))
            ->where('status', 'completed')
            ->whereBetween('completed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount_minor');
        $countFor = fn (array $statuses): int => $returnRequests->whereIn('status', $statuses)->count();

        return view('seller.Orders.returns-refunds.returns', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Action Required', 'value' => (string) $countFor(['awaiting_seller']), 'note' => 'Seller response required', 'icon' => 'triangle-alert', 'tone' => 'warning'],
                ['label' => 'Under Review', 'value' => (string) $countFor(['under_review', 'awaiting_evidence']), 'note' => 'Admin review or evidence requested', 'icon' => 'scan-search', 'tone' => 'neutral'],
                ['label' => 'Return in Progress', 'value' => (string) $countFor(['return_pickup_requested', 'return_in_transit', 'awaiting_inspection']), 'note' => 'Bearly Logistics and Seller inspection', 'icon' => 'package-open', 'tone' => 'info'],
                ['label' => 'Refunded This Month', 'value' => $this->money((int) $completedThisMonthMinor / 100), 'note' => 'Externally paid and recorded', 'icon' => 'badge-check', 'tone' => 'success'],
            ],
            'tabs' => [
                ['key' => 'all', 'label' => 'All Cases', 'count' => $returnRequests->count()],
                ['key' => 'action-required', 'label' => 'Action Required', 'count' => $countFor(['awaiting_seller'])],
                ['key' => 'under-review', 'label' => 'Under Review', 'count' => $countFor(['under_review', 'awaiting_evidence'])],
                ['key' => 'return-shipping', 'label' => 'Return Shipping', 'count' => $countFor(['return_pickup_requested', 'return_in_transit', 'awaiting_inspection'])],
                ['key' => 'refund-pending', 'label' => 'Refund Pending', 'count' => $countFor(['refund_pending'])],
                ['key' => 'resolved', 'label' => 'Resolved', 'count' => $countFor(['resolved', 'rejected'])],
            ],
            'returns' => $cases,
        ]);
    }

    public function returnDetails(Request $request, string $caseId, BuyerCatalogService $catalog): View
    {
        $store = $request->user()->sellerProfile?->store;
        abort_unless($store, 404);
        $returnRequest = ReturnRequest::query()
            ->where('store_id', $store->id)
            ->where('return_no', strtoupper($caseId))
            ->with(['order', 'buyer', 'sellerOrder', 'items.orderItem.product.images.variant', 'items.orderItem.variant', 'evidence', 'events.actor', 'refunds.commissions', 'refunds.sellerTransactions', 'refunds.sellerRecovery.events.sellerPayout', 'returnShipment.waybill', 'returnShipment.events'])
            ->firstOrFail();
        $case = $this->returnCaseData($returnRequest, $catalog);

        return view('seller.Orders.returns-refunds.return-details', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'case' => $case,
        ]);
    }

    public function respondToReturn(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'response' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['required', 'array', 'min:1', 'max:5'],
            'evidence.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,mp4,mov', 'max:10240'],
        ]);

        $case = $returns->sellerRespond(
            $request->user(),
            $returnRequest,
            trim($data['response']),
            array_values($request->file('evidence', [])),
        );

        return redirect()
            ->route('seller.orders.returns.show', $case->return_no)
            ->with('success', 'Your response and evidence were submitted for Admin review.');
    }

    public function inspectReturn(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.condition_code' => ['required', Rule::in(['sellable', 'damaged', 'opened', 'missing_parts'])],
            'items.*.resolution' => ['required', Rule::in(['restock', 'quarantine', 'dispose'])],
        ]);

        $case = $returns->inspectReturn($request->user(), $returnRequest, $data['items']);

        return redirect()
            ->route('seller.orders.returns.show', $case->return_no)
            ->with('success', 'Inspection was recorded. Admin can now record the approved external refund payout.');
    }

    public function addReturnEvidence(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,mp4,mov', 'max:10240'],
        ]);
        $files = array_values($request->file('evidence', []));
        if ($files === [] && ! filled($data['note'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['evidence' => 'Add a note or at least one evidence file.']);
        }

        $case = $returns->addSellerEvidence($request->user(), $returnRequest, $data['note'] ?? null, $files);

        return redirect()->route('seller.orders.returns.show', $case->return_no)->with('success', 'Additional Seller evidence was submitted to Admin.');
    }

    public function downloadReturnEvidence(Request $request, ReturnEvidence $evidence)
    {
        $this->ensureSellerCanAccessReturnEvidence($request, $evidence);

        $name = $this->returnEvidenceFilename($evidence);

        return response()->download(
            Storage::disk('local')->path($evidence->file_path),
            Str::limit($name, 200, ''),
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function viewReturnEvidence(Request $request, ReturnEvidence $evidence): StreamedResponse
    {
        $this->ensureSellerCanAccessReturnEvidence($request, $evidence);

        $mimeType = strtolower((string) $evidence->mime_type);
        abort_unless(in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
            'video/mp4',
            'video/quicktime',
        ], true), 404);

        return Storage::disk('local')->response(
            $evidence->file_path,
            Str::limit($this->returnEvidenceFilename($evidence), 200, ''),
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            ],
            'inline',
        );
    }

    private function ensureSellerCanAccessReturnEvidence(Request $request, ReturnEvidence $evidence): void
    {
        $storeId = $request->user()->sellerProfile?->store?->id;
        abort_unless($storeId && (int) $evidence->returnRequest?->store_id === (int) $storeId, 404);
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);
    }

    private function returnEvidenceFilename(ReturnEvidence $evidence): string
    {
        return preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $evidence->original_name))) ?: 'return-evidence';
    }

    /** @return array<string, mixed> */
    private function returnCaseData(ReturnRequest $case, ?BuyerCatalogService $catalog = null): array
    {
        $items = $case->items;
        $evidenceCount = $case->evidence->count();
        $requestType = $case->request_type === 'refund_only' ? 'Refund Only' : 'Return & Refund';
        $status = match ($case->status) {
            'awaiting_seller' => ['Action Required', 'action-required', 'warning'],
            'under_review', 'awaiting_evidence' => ['Under Review', 'under-review', 'review'],
            'return_pickup_requested', 'return_in_transit', 'awaiting_inspection' => ['Return in Progress', 'return-shipping', 'shipping'],
            'refund_pending' => ['Refund Payout Pending', 'refund-pending', 'review'],
            'resolved' => ['Resolved', 'resolved', 'resolved'],
            'rejected' => ['Rejected', 'resolved', 'warning'],
            default => [Str::headline($case->status), 'under-review', 'neutral'],
        };
        $refund = $case->refunds->sortByDesc('id')->first();
        $evidenceRequestEvent = $case->events->where('event_type', 'additional_evidence_requested')->last();
        $sellerEvidenceSubmitted = $evidenceRequestEvent && $case->events->contains(
            fn ($event): bool => (int) $event->id > (int) $evidenceRequestEvent->id
                && $event->event_type === 'seller_evidence_submitted',
        );
        $commissionReversal = (int) ($refund?->commissions->where('type', 'refund_reversal')->sum('amount_minor') ?? 0);
        $sellerRecovery = $refund?->sellerRecovery;
        $legacySellerDebit = abs((int) ($refund?->sellerTransactions->where('type', 'refund_reversal')->sum('amount_minor') ?? 0));
        $sellerRecoveryMinor = (int) ($sellerRecovery?->amount_minor ?? $legacySellerDebit);
        $sellerRecoveredMinor = (int) ($sellerRecovery?->recovered_amount_minor ?? 0);
        $sellerRecoveryOutstandingMinor = max(0, $sellerRecoveryMinor - $sellerRecoveredMinor);
        $commissionEffect = $refund?->status === 'completed'
            ? ($refund->financially_responsible_party === 'platform'
                ? 'Platform-funded refund; no Seller commission reversal'
                : ($commissionReversal > 0 ? '+'.$this->money($commissionReversal / 100).' commission reversed' : 'No commission reversal recorded'))
            : 'No finalized financial adjustment';
        $earningsEffect = $refund?->status === 'completed'
            ? ($refund->financially_responsible_party === 'platform'
                ? 'No Seller recovery; Platform is responsible'
                : ($sellerRecoveryMinor > 0
                    ? $this->money($sellerRecoveryOutstandingMinor / 100).' recoverable from Seller'.($sellerRecoveredMinor > 0 ? ' · '.$this->money($sellerRecoveredMinor / 100).' recovered' : ' · not automatically deducted')
                    : 'No Seller recovery recorded'))
            : 'No finalized financial adjustment';
        $productNames = $items->map(fn ($item): string => trim(
            (string) ($item->orderItem?->product_name ?? 'Purchased item')
            .' · '.(string) ($item->orderItem?->variant_name ?? 'Standard')
            .' × '.(int) $item->quantity
        ))->all();
        $deadline = $case->status === 'awaiting_seller'
            ? ($case->response_due_at?->format('M j, Y · g:i A') ?? 'Response due date not recorded')
            : ($case->seller_responded_at?->format('M j, Y · g:i A') ?? 'Seller response not yet submitted');

        return [
            'database_id' => (int) $case->id,
            'id' => $case->return_no,
            'order' => $case->order?->order_no ?? 'Order unavailable',
            'seller_order' => $case->sellerOrder?->seller_order_no ?? '—',
            'customer' => $case->buyer?->name ?? 'Buyer unavailable',
            'product' => $productNames ? implode('; ', $productNames) : 'Purchased item unavailable',
            'items' => $items->map(fn ($item): array => [
                'id' => (int) $item->id,
                'name' => $item->orderItem?->product_name ?? 'Purchased item',
                'variant' => $item->orderItem?->variant_name ?? 'Standard',
                'sku' => $item->orderItem?->sku ?? '—',
                'image' => $catalog && $item->orderItem
                    ? $catalog->orderItemImageUrl($item->orderItem)
                    : null,
                'quantity' => (int) $item->quantity,
                'amount' => $this->money((int) $item->refund_amount_minor / 100),
                'condition_code' => $item->condition_code,
                'resolution' => $item->resolution,
            ])->all(),
            'request' => $requestType,
            'reason' => Str::headline($case->reason_code),
            'amount' => $this->money((int) $case->requested_amount_minor / 100),
            'submitted' => $case->submitted_at?->format('M j, Y · g:i A') ?? '—',
            'deadline' => $deadline,
            'status' => $status[0],
            'status_raw' => $case->status,
            'evidence_requested_from' => (string) ($evidenceRequestEvent?->metadata['requested_from'] ?? 'both'),
            'seller_evidence_submitted' => $sellerEvidenceSubmitted,
            'status_key' => $status[1],
            'tone' => $status[2],
            'evidence' => $evidenceCount.' '.Str::plural('file', $evidenceCount),
            'evidence_files' => $case->evidence->map(fn ($evidence): array => [
                'id' => (int) $evidence->id,
                'name' => $evidence->original_name,
                'type' => $evidence->type,
                'mime_type' => strtolower((string) $evidence->mime_type),
                'is_image' => str_starts_with(strtolower((string) $evidence->mime_type), 'image/'),
                'size' => (int) ($evidence->size_bytes ?? 0),
                'view_url' => route('seller.orders.returns.evidence.view', $evidence->id),
            ])->all(),
            'buyer_note' => $case->buyer_note ?: 'No additional buyer note was provided.',
            'seller_response' => $case->seller_response ?: 'No Seller response submitted yet.',
            'resolution' => $case->resolution ?: 'No final resolution has been recorded.',
            'action' => $case->status === 'awaiting_seller' ? 'Respond' : 'View case',
            'tracking' => $requestType === 'Refund Only'
                ? 'Not required'
                : ($case->returnShipment?->waybill?->waybill_no ?: 'Awaiting Admin approval'),
            'return_status' => $case->returnShipment?->status,
            'shipment_events' => ($case->returnShipment?->events ?? collect())->map(fn ($event): array => [
                'label' => Str::headline(str_replace(['shipment_', 'parcel_'], '', $event->event_type)),
                'note' => $event->notes,
                'at' => $event->occurred_at?->format('M j, Y · g:i A') ?? '—',
            ])->all(),
            'commission_effect' => $commissionEffect,
            'earnings_effect' => $earningsEffect,
            'refund' => $refund ? [
                'id' => (int) $refund->id,
                'number' => $refund->refund_no,
                'amount' => $this->money((int) $refund->amount_minor / 100),
                'payout_amount' => $refund->payout_amount_minor !== null
                    ? $this->money((int) $refund->payout_amount_minor / 100)
                    : null,
                'status' => $refund->status,
                'method' => $refund->method,
                'reference' => $refund->provider_reference,
                'paid_on' => $refund->external_paid_on?->format('M j, Y'),
                'financially_responsible_party' => $refund->financially_responsible_party,
                'financial_responsibility_reason' => $refund->financial_responsibility_reason,
                'seller_recovery' => $sellerRecovery ? [
                    'amount_minor' => (int) $sellerRecovery->amount_minor,
                    'recovered_amount_minor' => (int) $sellerRecovery->recovered_amount_minor,
                    'outstanding_minor' => max(0, (int) $sellerRecovery->amount_minor - (int) $sellerRecovery->recovered_amount_minor),
                    'status' => $sellerRecovery->status,
                    'events' => $sellerRecovery->events->map(fn ($event): array => [
                        'method' => $event->method,
                        'amount_minor' => (int) $event->amount_minor,
                        'reference' => $event->external_reference,
                        'recovered_on' => $event->recovered_on?->format('M j, Y') ?? '—',
                        'payout_no' => $event->sellerPayout?->payout_no,
                    ])->all(),
                ] : null,
            ] : null,
            'events' => $case->events->map(fn ($event): array => [
                'type' => Str::headline($event->event_type),
                'actor' => $event->actor?->name ?? 'Bearly',
                'note' => $event->note,
                'at' => $event->created_at?->format('M j, Y · g:i A') ?? '—',
                'metadata' => $event->metadata ?? [],
            ])->all(),
        ];
    }

    private function returnCases(): array
    {
        return [
            ['id' => 'RR-2041', 'order' => '#BR-1046', 'customer' => 'Maria Santos', 'product' => 'Classic Linen Shirt · Olive / M', 'sku' => 'CLS-OLV-M', 'request' => 'Return & Refund', 'reason' => 'Wrong size received', 'amount' => '₱1,299', 'submitted' => 'Today · 9:20 AM', 'deadline' => '1h 42m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '3 photos', 'buyer_note' => 'The tag says Medium, but the actual fit and measurements are smaller than the listed size.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created', 'commission_effect' => 'No adjustment yet', 'earnings_effect' => 'No adjustment yet'],
            ['id' => 'RR-2040', 'order' => '#BR-1045', 'customer' => 'Paolo Ramos', 'product' => 'Canvas Tote Bag · Natural', 'sku' => 'CTB-NAT-OS', 'request' => 'Refund Only', 'reason' => 'Missing item', 'amount' => '₱899', 'submitted' => 'Today · 8:05 AM', 'deadline' => '3h 10m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '1 unboxing video', 'buyer_note' => 'The parcel arrived sealed, but the tote bag was not inside the package.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created', 'commission_effect' => 'No adjustment yet', 'earnings_effect' => 'No adjustment yet'],
            ['id' => 'RR-2038', 'order' => '#BR-1044', 'customer' => 'Carlo Reyes', 'product' => 'Everyday Sneakers · White / 39', 'sku' => 'ES-WHT-39', 'request' => 'Return & Refund', 'reason' => 'Item not as described', 'amount' => '₱1,780', 'submitted' => 'Yesterday · 4:10 PM', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '4 photos', 'buyer_note' => 'The color and sole pattern do not match the product listing.', 'seller_response' => 'Requested platform review; warehouse packing photo was attached.', 'resolution' => 'Platform reviewing both parties’ evidence', 'action' => 'View Case', 'tracking' => 'Not yet created', 'commission_effect' => 'Pending platform decision', 'earnings_effect' => 'Pending platform decision'],
            ['id' => 'RR-2035', 'order' => '#BR-1041', 'customer' => 'Sofia Mendoza', 'product' => 'Classic Linen Shirt · Cream / L', 'sku' => 'CLS-CRM-L', 'request' => 'Refund Only', 'reason' => 'Damaged item', 'amount' => '₱1,299', 'submitted' => 'Aug 30, 2026', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '2 photos', 'buyer_note' => 'There is a visible tear near the left sleeve seam.', 'seller_response' => 'Accepted buyer evidence and approved refund without return.', 'resolution' => 'Refund approval being processed', 'action' => 'View Case', 'tracking' => 'Not required', 'commission_effect' => 'Will reverse if refund is finalized', 'earnings_effect' => 'Will reverse if refund is finalized'],
            ['id' => 'RR-2031', 'order' => '#BR-1036', 'customer' => 'Jamie Lim', 'product' => 'Everyday Sneakers · Black / 40', 'sku' => 'ES-BLK-40', 'request' => 'Return & Refund', 'reason' => 'Damaged during delivery', 'amount' => '₱1,780', 'submitted' => 'Aug 29, 2026', 'deadline' => 'Return by Sep 16', 'status' => 'Return Shipping', 'status_key' => 'return-shipping', 'tone' => 'shipping', 'evidence' => '3 photos', 'buyer_note' => 'The shoe box and right shoe were crushed when delivered.', 'seller_response' => 'Return approved after reviewing courier damage evidence.', 'resolution' => 'Return parcel is in transit to seller', 'action' => 'Track Return', 'tracking' => 'BRLY-RET-20431', 'commission_effect' => 'Pending return completion', 'earnings_effect' => 'Pending return completion'],
            ['id' => 'RR-2025', 'order' => '#BR-1047', 'customer' => 'Anne Cruz', 'product' => 'Classic Linen Shirt · Olive / M', 'sku' => 'CLS-OLV-M', 'request' => 'Refund Only', 'reason' => 'Seller approved refund', 'amount' => '₱1,299', 'submitted' => 'Sep 7, 2026', 'deadline' => 'Closed Sep 8', 'status' => 'Refunded', 'status_key' => 'resolved', 'tone' => 'resolved', 'evidence' => '2 photos', 'buyer_note' => 'The item arrived with a manufacturing defect.', 'seller_response' => 'Refund approved. No item return required.', 'resolution' => '₱1,299 returned to buyer via GCash', 'action' => 'View Details', 'tracking' => 'Not required', 'commission_effect' => '+₱129.90 commission reversal', 'earnings_effect' => '−₱1,169.10 seller earnings reversal'],
        ];
    }

    private function financeTransactionRows(): array
    {
        return [
            ['date' => 'Sep 10, 2026', 'order' => '#BR-1050', 'gross' => '₱1,299.00', 'discount' => '₱0.00', 'refund' => '₱0.00', 'commissionable' => '₱1,299.00', 'commission' => '−₱129.90', 'commission_reversal' => '—', 'net' => '₱1,169.10', 'status' => 'Available', 'status_key' => 'available', 'note' => 'Order completed; 10% commission finalized.'],
            ['date' => 'Sep 11, 2026', 'order' => '#BR-1051', 'gross' => '₱1,780.00', 'discount' => '₱0.00', 'refund' => '₱0.00', 'commissionable' => '₱1,780.00', 'commission' => 'Estimated −₱178.00', 'commission_reversal' => '—', 'net' => 'Estimated ₱1,602.00', 'status' => 'Pending confirmation', 'status_key' => 'pending', 'note' => 'Delivered; waits for buyer confirmation before settlement.'],
            ['date' => 'Sep 11, 2026', 'order' => '#BR-1052', 'gross' => '₱899.00', 'discount' => '₱90.00', 'refund' => '₱0.00', 'commissionable' => '₱809.00', 'commission' => 'Estimated −₱80.90', 'commission_reversal' => '—', 'net' => 'Estimated ₱728.10', 'status' => 'Pending', 'status_key' => 'pending', 'note' => 'Out for delivery; commission is not final yet.'],
            ['date' => 'Sep 8, 2026', 'order' => '#BR-1047', 'gross' => '₱1,299.00', 'discount' => '₱0.00', 'refund' => '−₱1,299.00', 'commissionable' => '₱0.00', 'commission' => '₱0.00', 'commission_reversal' => '+₱129.90', 'net' => '₱0.00', 'status' => 'Reversed', 'status_key' => 'reversed', 'note' => 'Full refund reversed the seller earnings and platform commission.'],
            ['date' => 'Sep 8, 2026', 'order' => '#BR-1048', 'gross' => '₱1,299.00', 'discount' => '₱0.00', 'refund' => 'Cancelled', 'commissionable' => '₱0.00', 'commission' => '₱0.00', 'commission_reversal' => '—', 'net' => '₱0.00', 'status' => 'Cancelled', 'status_key' => 'cancelled', 'note' => 'Cancelled before completion; no commission is charged.'],
        ];
    }

    public function financeEarnings(): View
    {
        $finance = $this->financeSnapshot();

        return view('seller.Finance.earnings', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'commissionRate' => self::COMMISSION_RATE * 100,
            'summary' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'note' => 'Completed sales before deductions'],
                ['label' => 'Seller Discounts + Refunds', 'value' => '−'.$this->money($finance['sellerDiscounts'] + $finance['refunds']), 'note' => 'Seller-funded discounts and finalized refunds'],
                ['label' => 'Platform Commission', 'value' => '−'.$this->money($finance['netCommission']), 'note' => '10% after refund commission reversal'],
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'note' => 'Amount attributable to the seller'],
            ],
            'finance' => $finance,
            'recentTransactions' => array_slice($this->financeTransactionRows(), 0, 4),
        ]);
    }

    public function financeTransactions(): View
    {
        return view('seller.Finance.transactions', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'transactions' => $this->financeTransactionRows(),
            'commissionRate' => self::COMMISSION_RATE * 100,
        ]);
    }

    public function reportsOverview(): View
    {
        $finance = $this->financeSnapshot();

        return view('seller.Reports.overview', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'period' => 'Aug 1 – Aug 31, 2026',
            'metrics' => [
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'change' => '+10.8%', 'context' => 'after deductions', 'primary' => true],
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'change' => '+12.5%', 'context' => 'vs July'],
                ['label' => 'Orders', 'value' => '91', 'change' => '+8.3%', 'context' => 'vs July'],
                ['label' => 'Average Order', 'value' => '₱1,411', 'change' => '+3.9%', 'context' => 'vs July'],
            ],
            'salesTrend' => [14200, 17850, 19600, 18100, 16900, 20700, 21100],
            'salesLabels' => ['Aug 1–4', 'Aug 5–9', 'Aug 10–14', 'Aug 15–19', 'Aug 20–24', 'Aug 25–28', 'Aug 29–31'],
            'financialBridge' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'negative' => false],
                ['label' => 'Seller-funded Discounts', 'value' => '−'.$this->money($finance['sellerDiscounts']), 'negative' => true],
                ['label' => 'Refunds', 'value' => '−'.$this->money($finance['refunds']), 'negative' => true],
                ['label' => 'Platform Commission (10%)', 'value' => '−'.$this->money($finance['netCommission']), 'negative' => true],
            ],
            'insights' => [
                ['title' => 'Best seller', 'value' => 'Classic Linen Shirt · 38 sold', 'detail' => 'Your top product by unit volume this month.', 'icon' => 'shirt'],
                ['title' => 'Strongest period', 'value' => 'Aug 25–31 · ₱41,800', 'detail' => 'This period captured the highest sales.', 'icon' => 'trending-up'],
                ['title' => 'Refund rate', 'value' => '1.0% · Stable', 'detail' => 'Full refunds reverse the related platform commission.', 'icon' => 'rotate-ccw'],
            ],
            'products' => [
                ['name' => 'Classic Linen Shirt', 'units' => 38, 'revenue' => '₱52,440.00', 'share' => 40.8, 'icon' => 'shirt'],
                ['name' => 'Canvas Tote Bag', 'units' => 29, 'revenue' => '₱34,018.00', 'share' => 26.5, 'icon' => 'shopping-bag'],
                ['name' => 'Everyday Sneakers', 'units' => 24, 'revenue' => '₱25,320.00', 'share' => 19.7, 'icon' => 'footprints'],
            ],
        ]);
    }

    public function financialReport(Request $request): View
    {
        $finance = $this->financeSnapshot();

        $from = (string) $request->query('from', '2026-08-01');
        $to = (string) $request->query('to', '2026-08-31');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '2026-08-01';
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '2026-08-31';
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $commissionPercent = $finance['totalDeductions'] > 0 ? ($finance['netCommission'] / $finance['totalDeductions']) * 100 : 0;
        $discountPercent = $finance['totalDeductions'] > 0 ? ($finance['sellerDiscounts'] / $finance['totalDeductions']) * 100 : 0;
        $refundPercent = $finance['totalDeductions'] > 0 ? ($finance['refunds'] / $finance['totalDeductions']) * 100 : 0;

        return view('seller.Reports.financial', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dateRange' => ['from' => $from, 'to' => $to],
            'summary' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'note' => 'Before deductions'],
                ['label' => 'Commissionable Sales', 'value' => $this->money($finance['netCommissionableSales']), 'note' => 'After seller discounts and finalized refunds'],
                ['label' => 'Platform Commission', 'value' => $this->money($finance['netCommission']), 'note' => '10% after reversal'],
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'note' => 'Seller earnings after deductions'],
            ],
            'currentStatement' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales'])],
                ['label' => 'Seller-funded Discounts', 'value' => '−'.$this->money($finance['sellerDiscounts'])],
                ['label' => 'Refunds', 'value' => '−'.$this->money($finance['refunds'])],
                ['label' => 'Platform Commission (10%)', 'value' => '−'.$this->money($finance['netCommission'])],
            ],
            'statementNetEarnings' => $this->money($finance['netEarnings']),
            'statementTotalDeductions' => $this->money($finance['totalDeductions']),
            'deductionRate' => number_format(($finance['totalDeductions'] / $finance['grossSales']) * 100, 1).'%',
            'deductions' => [
                ['label' => 'Platform Commission', 'value' => $this->money($finance['netCommission']), 'percent' => round($commissionPercent, 1), 'tone' => 'brown'],
                ['label' => 'Seller Discounts', 'value' => $this->money($finance['sellerDiscounts']), 'percent' => round($discountPercent, 1), 'tone' => 'gold'],
                ['label' => 'Refunds', 'value' => $this->money($finance['refunds']), 'percent' => round($refundPercent, 1), 'tone' => 'olive'],
            ],
            'rows' => [
                ['period' => 'August 2026', 'gross' => '₱128,450.00', 'discounts' => '₱4,430.00', 'refunds' => '₱1,299.00', 'commission' => '₱12,272.10', 'net' => '₱110,448.90', 'status' => 'Processing'],
                ['period' => 'July 2026', 'gross' => '₱112,800.00', 'discounts' => '₱3,820.00', 'refunds' => '₱0', 'commission' => '₱10,898.00', 'net' => '₱98,082.00', 'status' => 'Paid'],
                ['period' => 'June 2026', 'gross' => '₱106,240.00', 'discounts' => '₱3,140.00', 'refunds' => '₱899.00', 'commission' => '₱10,220.10', 'net' => '₱91,980.90', 'status' => 'Paid'],
            ],
        ]);
    }
}
