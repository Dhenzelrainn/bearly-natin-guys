<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\PickupRequest;
use App\Models\Refund;
use App\Models\ReturnEvidence;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Waybill;
use App\Services\AuditService;
use App\Services\BuyerCatalogService;
use App\Services\ReturnRefundService;
use App\Services\SellerEarningsService;
use App\Services\SellerFulfillmentService;
use App\Services\SellerIdentityService;
use App\Services\SellerInventoryService;
use App\Services\SellerNotificationService;
use App\Services\SellerOrderTrackingService;
use App\Services\SellerOrderWorkflowService;
use App\Services\SellerPickupRequestService;
use App\Services\SellerWalletService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerWorkflowController extends Controller
{
    private function seller(): array
    {
        return app(
            SellerIdentityService::class
        )->current();
    }

    private function notifications(): array
    {
        return app(SellerNotificationService::class)->recentFor(auth()->user());
    }

    private function money(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    /* -----------------------------------------------------------------
     * Shared report presentation helpers
     * ----------------------------------------------------------------- */

    private function changeLabel(?float $percent): string
    {
        if ($percent === null) {
            return 'No prior-period data';
        }

        if ($percent > 0.05) {
            return '↑ '.number_format($percent, 1).'%';
        }

        if ($percent < -0.05) {
            return '↓ '.number_format(abs($percent), 1).'%';
        }

        return '0.0%';
    }

    /**
     * @return array{change: string, context: string, icon: string}
     */
    private function metricChange(?float $percent, string $previousLabel): array
    {
        if ($percent === null) {
            return ['change' => '—', 'context' => 'no prior-period data', 'icon' => 'minus'];
        }

        return [
            'change' => ($percent > 0.05 ? '+' : ($percent < -0.05 ? '−' : '')).number_format(abs($percent), 1).'%',
            'context' => 'vs '.$previousLabel,
            'icon' => $percent > 0.05 ? 'arrow-up-right' : ($percent < -0.05 ? 'arrow-down-right' : 'minus'),
        ];
    }

    /**
     * Chart geometry for money series in viewBox coordinates.
     * Money stays in minor units; only pixel coordinates are derived.
     *
     * @param  array<int, array{label: string, value_minor: int}>  $series
     * @return array{points: array<int, array{0: int, 1: int}>, polyline: string, area: string, has_data: bool, y_axis_points: array<int, array{label: string, y: int}>, label_points: array<int, array{label: string, x: int}>, details: array<int, array{x: int, y: int, label: string, value: string}>}
     */
    private function chartGeometry(
        array $series,
        int $xStart,
        int $xEnd,
        int $yTop,
        int $yBottom,
        int $yLabelCount,
        int $labelLimit,
    ): array {
        $values = array_map(fn (array $point): int => max(0, (int) ($point['value_minor'] ?? 0)), $series);
        $count = count($values);
        $maxValue = $count > 0 ? max($values) : 0;
        $intervals = max(1, $yLabelCount - 1);
        $axisMax = max(1, $this->niceChartStep($maxValue, $intervals) * $intervals);

        $points = [];

        if ($count === 1) {
            $y = $this->chartY($values[0], $axisMax, $yTop, $yBottom);
            $points = [[$xStart, $y], [$xEnd, $y]];
        } elseif ($count > 1) {
            $span = $xEnd - $xStart;

            foreach ($values as $index => $value) {
                $points[] = [
                    $xStart + (int) round($index * $span / ($count - 1)),
                    $this->chartY($value, $axisMax, $yTop, $yBottom),
                ];
            }
        }

        $yAxisPoints = [];

        for ($index = 0; $index < $yLabelCount; $index++) {
            $value = (int) round($axisMax * ($yLabelCount - 1 - $index) / $intervals);

            // Axis label with the plot coordinate it marks so the seller sales
            // charts can align labels and gridlines on the real scale.
            $yAxisPoints[] = [
                'label' => number_format(intdiv($value, 100)),
                'y' => $this->chartY($value, $axisMax, $yTop, $yBottom),
            ];
        }

        $details = [];

        foreach ($points as $index => $point) {
            $bucket = $series[min($index, max(0, $count - 1))] ?? [];

            $details[] = [
                'x' => $point[0],
                'y' => $point[1],
                'label' => (string) ($bucket['label'] ?? ''),
                'value' => $this->moneyFromMinor((int) ($bucket['value_minor'] ?? 0)),
            ];
        }

        return [
            'points' => $points,
            'polyline' => implode(' ', array_map(fn (array $point): string => $point[0].','.$point[1], $points)),
            'area' => $points === []
                ? ''
                : 'M'.implode(' L', array_map(fn (array $point): string => $point[0].' '.$point[1], $points))
                    .' L'.$xEnd.' '.$yBottom.' L'.$xStart.' '.$yBottom.' Z',
            'has_data' => $maxValue > 0,
            'y_axis_points' => $yAxisPoints,
            'label_points' => $this->chartLabelPoints($series, $points, $labelLimit),
            // Tooltip metadata aligned with `points`: real bucket labels and
            // centavo-exact amounts rendered in the chart view markup.
            'details' => $details,
        ];
    }

    private function chartY(int $valueMinor, int $axisMaxMinor, int $yTop, int $yBottom): int
    {
        $ratio = $axisMaxMinor > 0 ? min(1, $valueMinor / $axisMaxMinor) : 0;

        return (int) round($yBottom - ($ratio * ($yBottom - $yTop)));
    }

    private function niceChartStep(int $maxValueMinor, int $intervals): int
    {
        if ($maxValueMinor <= 0) {
            return 100;
        }

        $raw = $maxValueMinor / max(1, $intervals);
        $magnitude = 10 ** (int) floor(log10(max(1, $raw)));

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            $candidate = (int) round($magnitude * $factor);

            if ($candidate >= $raw) {
                return $candidate;
            }
        }

        return (int) round($magnitude * 10);
    }

    /**
     * Sampled x-axis labels with the plot coordinate of the point they mark.
     *
     * @param  array<int, array{label: string, value_minor: int}>  $series
     * @param  array<int, array{0: int, 1: int}>  $points
     * @return array<int, array{label: string, x: int}>
     */
    private function chartLabelPoints(array $series, array $points, int $limit): array
    {
        $count = count($series);

        if ($count <= 0 || $points === []) {
            return [];
        }

        $indexes = $count <= $limit
            ? range(0, $count - 1)
            : array_map(fn (int $step): int => (int) round($step * ($count - 1) / ($limit - 1)), range(0, $limit - 1));

        $picked = [];

        foreach ($indexes as $index) {
            $label = (string) ($series[$index]['label'] ?? '');

            // Sampled steps can land on the same bucket; keep one label for it.
            if ($count > $limit && $picked !== [] && end($picked)['label'] === $label) {
                continue;
            }

            $picked[] = [
                'label' => $label,
                'x' => $points[$index][0],
            ];
        }

        return $picked;
    }

    private function dashboardChart(array $series): array
    {
        // Geometry is laid out for the 720x260 viewBox rendered by the seller
        // dashboard chart (resources/views/seller/Dashboard/dashboard.blade.php).
        return $this->chartGeometry($series, xStart: 30, xEnd: 690, yTop: 26, yBottom: 234, yLabelCount: 5, labelLimit: 7);
    }

    private function reportChart(array $series): array
    {
        // The reports overview trend shares the dashboard viewBox (720x260)
        // and treatment: the same gridlines, crosshair, and tooltip hooks,
        // rendered server-side from the selected period's real gross buckets.
        return $this->chartGeometry($series, xStart: 30, xEnd: 690, yTop: 26, yBottom: 234, yLabelCount: 5, labelLimit: 7);
    }

    private function commissionRateLabel(float|int $commissionRate): string
    {
        return rtrim(rtrim(number_format((float) $commissionRate, 2, '.', ''), '0'), '.');
    }

    private function decimalMinor(int $amountMinor): string
    {
        $sign = $amountMinor < 0 ? '-' : '';
        $absolute = abs($amountMinor);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function csvChange(?float $percent): string
    {
        if ($percent === null) {
            return '—';
        }

        return ($percent > 0.05 ? '+' : ($percent < -0.05 ? '−' : '')).number_format(abs($percent), 1).'%';
    }

    private function csvDownload(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            $writer($stream);
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function persistedOrdersData(
        Request $request,
        BuyerCatalogService $catalog,
        SellerOrderTrackingService $tracking,
    ): array {
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
        SellerEarningsService $earnings,
        SellerInventoryService $inventory,
    ): View {
        $period = $earnings->dashboardPeriodRange($request->query('period'));
        $profile = $request->user()->sellerProfile;
        $storeId = $profile?->store?->id;

        $orders = collect($this->persistedOrdersData($request, $catalog, $tracking));
        $newOrders = $orders->where('status_key', 'new')->count();
        $toPrepare = $orders->where('status_key', 'to-prepare')->count();
        $readyPickup = $orders->where('status_key', 'ready-pickup')->count();

        $awaitingReturns = $storeId
            ? ReturnRequest::query()->where('store_id', $storeId)->where('status', 'awaiting_seller')->count()
            : 0;
        $openCases = $storeId
            ? ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereNotIn('status', ['resolved', 'rejected'])
                ->count()
            : 0;

        $inventoryAttention = $inventory->inventoryFor($request->user())['inventoryAttention'];

        $finance = $earnings->dashboardFinanceFor(
            $profile,
            $period['from'],
            $period['to'],
            (string) $request->query('interval', 'daily'),
        );
        $fulfillment = $earnings->fulfillmentRateFor($profile, $period['from'], $period['to']);
        $commissionRateLabel = $this->commissionRateLabel($earnings->commissionRate());
        $salesChange = $finance['change']['sales'];
        $netChange = $finance['change']['net_earnings'];

        return view('seller.Dashboard.dashboard', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'periodKey' => $period['key'],
            'periodLabel' => $earnings->rangeLabel($period['from'], $period['to']),
            'dashboardActions' => [
                ['count' => $newOrders, 'label' => 'Orders to Confirm', 'detail' => 'Review and confirm stock', 'icon' => 'shopping-cart', 'route' => route('seller.orders.new')],
                ['count' => $inventoryAttention['low'] + $inventoryAttention['out'], 'label' => 'Low-Stock Products', 'detail' => 'At or below stock threshold', 'icon' => 'package', 'route' => route('seller.inventory')],
                ['count' => $awaitingReturns, 'label' => 'Return Requests', 'detail' => 'Awaiting your response', 'icon' => 'rotate-ccw', 'route' => route('seller.orders.returns')],
                ['count' => $readyPickup, 'label' => 'Orders Ready for Pickup', 'detail' => 'Ready for courier collection', 'icon' => 'truck', 'route' => route('seller.fulfillment.pickups')],
            ],
            'performanceCards' => [
                ['label' => 'Total Sales', 'value' => $this->moneyFromMinor($finance['sales']['gross_minor']), 'change' => $this->changeLabel($salesChange)],
                ['label' => 'Total Orders', 'value' => number_format($finance['sales']['orders']), 'change' => $this->changeLabel($finance['change']['orders'])],
                ['label' => 'Net Earnings', 'value' => $this->moneyFromMinor($finance['ledger']['net_earnings_minor']), 'change' => $this->changeLabel($netChange)],
                ['label' => 'Platform Commission', 'value' => $this->moneyFromMinor($finance['ledger']['net_commission_minor']), 'change' => $this->changeLabel($finance['change']['commission'])],
            ],
            'salesChart' => $this->dashboardChart($finance['series']),
            'salesTotal' => $this->moneyFromMinor($finance['sales']['gross_minor']),
            'salesChange' => $this->changeLabel($salesChange),
            'salesChangeNote' => $salesChange === null ? 'no prior-period sales to compare' : 'from previous period',
            'chartInterval' => $finance['interval'],
            'dashboardProducts' => collect($finance['products'])->map(fn (array $product): array => [
                'name' => $product['name'],
                'variant' => $product['variant'],
                'sold' => $product['units'],
                'revenue' => $this->moneyFromMinor($product['gross_minor']),
                'image_url' => $product['image_url'],
                'icon' => 'package',
            ])->values(),
            'earningsTotal' => $this->moneyFromMinor($finance['ledger']['net_earnings_minor']),
            'earningsChange' => $this->changeLabel($netChange),
            'earningsBreakdown' => [
                ['label' => 'Total Sales', 'value' => $this->moneyFromMinor($finance['ledger']['gross_sales_minor'])],
                ['label' => 'Platform Commission ('.$commissionRateLabel.'%)', 'value' => $this->deductionMoney($finance['ledger']['net_commission_minor'])],
                ['label' => 'Discounts & Refunds', 'value' => $this->deductionMoney($finance['ledger']['seller_discounts_minor'] + $finance['ledger']['refunds_minor'])],
            ],
            'earningsNet' => $this->moneyFromMinor($finance['ledger']['net_earnings_minor']),
            'pickupSummary' => $this->dashboardPickupSummary($storeId, $readyPickup),
            'healthRows' => $this->dashboardHealthRows($profile, $fulfillment, $inventoryAttention, $openCases),
        ]);
    }

    /**
     * Fulfillment-rate labels follow the documented formula:
     * completed ÷ (completed + cancelled) Seller Orders created in the period;
     * open orders are excluded and "—" is shown until an order is finalized.
     */
    private function dashboardHealthRows(
        ?SellerProfile $profile,
        array $fulfillment,
        array $inventoryAttention,
        int $openCases,
    ): array {
        $standing = (string) ($profile?->standing_status ?? 'good_standing');
        $lowStockCount = (int) $inventoryAttention['low'] + (int) $inventoryAttention['out'];

        $rate = $fulfillment['rate'];
        $cancellationRate = ($fulfillment['completed'] + $fulfillment['cancelled']) > 0
            ? round($fulfillment['cancelled'] / ($fulfillment['completed'] + $fulfillment['cancelled']) * 100, 1)
            : null;

        return [
            [
                'label' => 'Account Status',
                'value' => Str::headline($standing),
                'tone' => $standing === 'good_standing' ? 'success' : 'warning',
            ],
            [
                'label' => 'Order Fulfillment Rate',
                'value' => $rate === null ? '—' : number_format($rate, 1).'%',
                'tone' => $rate === null ? 'neutral' : ($rate >= 90 ? 'success' : 'warning'),
            ],
            [
                'label' => 'Cancellation Rate',
                'value' => $cancellationRate === null ? '—' : number_format($cancellationRate, 1).'%',
                'tone' => $cancellationRate === null ? 'neutral' : ($cancellationRate <= 10 ? 'success' : 'warning'),
            ],
            [
                'label' => 'Product Stock Level',
                'value' => $lowStockCount > 0
                    ? $lowStockCount.' low-stock '.($lowStockCount === 1 ? 'item' : 'items')
                    : 'All products above threshold',
                'tone' => $lowStockCount > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Open Cases',
                'value' => $openCases > 0 ? $openCases.' open '.($openCases === 1 ? 'case' : 'cases') : 'No open cases',
                'tone' => $openCases > 0 ? 'warning' : 'success',
            ],
        ];
    }

    /**
     * @return array{count: int, has_schedule: bool, date_label: ?string, time_label: ?string}
     */
    private function dashboardPickupSummary(?int $storeId, int $readyCount): array
    {
        $next = $storeId
            ? PickupRequest::query()
                ->where('store_id', $storeId)
                ->whereIn('status', ['requested', 'verified', 'scheduled'])
                ->whereDate('requested_date', '>=', now()->toDateString())
                ->orderBy('requested_date')
                ->orderBy('window_start')
                ->with('logisticsProfile')
                ->first()
            : null;

        if (! $next) {
            return [
                'count' => $readyCount,
                'has_schedule' => false,
                'date_label' => null,
                'time_label' => null,
            ];
        }

        $date = $next->requested_date;
        $start = $next->window_start;
        $end = $next->window_end;

        return [
            'count' => $readyCount,
            'has_schedule' => true,
            'date_label' => $date?->isToday()
                ? 'Today'
                : ($date?->isTomorrow() ? 'Tomorrow' : ($date?->format('M j, Y') ?? 'Scheduled')),
            'time_label' => $start && $end
                ? $start->format('g:i A').'–'.$end->format('g:i A').' · '.($next->logisticsProfile?->display_name ?? 'Logistics provider')
                : 'Window to be confirmed',
        ];
    }

    public function orders(
        Request $request,
        BuyerCatalogService $catalog,
        SellerOrderTrackingService $tracking,
    ): View {
        $orders = $this->persistedOrdersData($request, $catalog, $tracking);
        $allowed = ['all', 'new', 'to-prepare', 'ready-pickup', 'shipped', 'completed', 'cancelled-returned'];
        $requestedStatus = (string) $request->query('status', 'all');
        $defaultStatus = in_array($requestedStatus, $allowed, true) ? $requestedStatus : 'all';
        $count = fn (string $status) => collect($orders)->where('status_key', $status)->count();
        $storeId = (int) ($request->user()->sellerProfile?->store?->id ?? 0);
        $waybillCount = $storeId > 0
            ? Waybill::query()
                ->whereNull('printed_at')
                ->whereNull('voided_at')
                ->where('status', '!=', 'voided')
                ->whereHas('shipment', fn ($query) => $query
                    ->where('purpose', 'outbound')
                    ->whereHas('sellerOrder', fn ($sellerOrders) => $sellerOrders
                        ->where('store_id', $storeId)))
                ->count()
            : 0;

        return view('seller.Orders.orders', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'defaultOrderStatus' => $defaultStatus,
            'orderQueue' => [
                ['key' => 'new', 'label' => 'New orders', 'count' => $count('new'), 'note' => 'Awaiting Seller review', 'icon' => 'clipboard-list', 'tone' => 'gold'],
                ['key' => 'to-prepare', 'label' => 'To prepare', 'count' => $count('to-prepare'), 'note' => 'Confirmed and preparing', 'icon' => 'package', 'tone' => 'olive'],
                ['key' => 'waybill', 'label' => 'Waybill to print', 'count' => $waybillCount, 'note' => 'Print and attach before marking ready', 'icon' => 'printer', 'tone' => 'blue'],
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
            'start_preparing' => 'Order moved to preparation. Print and attach its waybill, then confirm it in Fulfillment → Waybills.',
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
        $redirectTo = $validated['action'] === 'start_preparing'
            ? route('seller.fulfillment.waybills', ['status' => 'ready'])
            : route('seller.orders', ['status' => $statusFilter]);

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

    public function pickupRequests(
        Request $request,
        SellerFulfillmentService $fulfillment,
        BuyerCatalogService $catalog,
    ): View {
        $user = $request->user();
        $sellerProfile = SellerProfile::query()
            ->where('user_id', $user->id)
            ->with('pickupAddress')
            ->first();
        $store = $sellerProfile?->store;

        abort_unless($sellerProfile && $store, 404, 'Seller Store not found.');

        $provider = null;
        $providerError = null;

        if (! $sellerProfile->pickupAddress) {
            $providerError = 'Add a valid pickup address to your Seller profile before scheduling collection.';
        } else {
            try {
                $provider = $fulfillment->providerForPickupAddress($sellerProfile->pickupAddress);
            } catch (ValidationException $exception) {
                $providerError = collect($exception->errors())->flatten()->first();
            }
        }

        $eligibleOrders = collect();

        if ($provider) {
            $eligibleOrders = SellerOrder::query()
                ->where('store_id', $store->id)
                ->where('status', 'ready_for_pickup')
                ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->whereHas('shipments', fn ($query) => $query
                    ->where('purpose', 'outbound')
                    ->where('logistics_profile_id', $provider->id)
                    ->where('status', ShipmentStatus::ReadyForPickup->value)
                    ->whereHas('waybill', fn ($waybills) => $waybills
                        ->whereNotNull('printed_at')
                        ->whereNull('voided_at')
                        ->where('status', '!=', 'voided'))
                    ->whereHas('parcels'))
                ->whereDoesntHave('shipments.parcels.pickupRequests', fn ($query) => $query
                    ->whereIn('pickup_requests.status', ['requested', 'verified', 'scheduled']))
                ->with([
                    'order',
                    'shipments' => fn ($query) => $query
                        ->where('purpose', 'outbound')
                        ->with(['waybill', 'parcels']),
                ])
                ->orderBy('ready_at')
                ->orderBy('id')
                ->get()
                ->map(function (SellerOrder $sellerOrder): array {
                    $quote = (array) $sellerOrder->shipping_quote;
                    $shipment = $sellerOrder->shipments->first();
                    $parcel = $shipment?->parcels->first();
                    $weight = $parcel?->weight_kg ?? ($quote['actual_weight_kg'] ?? null);
                    $order = $sellerOrder->order;
                    $packages = max(1, (int) ($shipment?->parcels->count() ?? 0));

                    return [
                        'id' => (int) $sellerOrder->id,
                        'order' => '#'.$sellerOrder->seller_order_no,
                        'packages' => $packages,
                        'weight' => is_numeric($weight) && (float) $weight > 0
                            ? number_format((float) $weight, 3).' kg actual weight'
                            : 'Package details unavailable',
                        'waybill' => $shipment?->waybill?->waybill_no ?? 'Waybill unavailable',
                        'destination' => $order
                            ? implode(', ', array_filter([
                                $order->city_municipality,
                                $order->province,
                            ]))
                            : 'Destination unavailable',
                    ];
                });
        }

        $pickupRequests = PickupRequest::query()
            ->where('store_id', $store->id)
            ->with([
                'logisticsProfile',
                'pickupAddress',
                'activeAssignment.riderProfile.user',
                'activeAssignment.riderProfile.currentZone.sortingCenter',
                'activeAssignment.riderProfile.homeSortingCenter',
                'parcels.waybill',
                'parcels.shipment.sellerOrder.items.product.images.variant',
                'parcels.shipment.sellerOrder.items.variant',
            ])
            ->latest('created_at')
            ->get();

        $requests = $pickupRequests
            ->map(function (PickupRequest $pickupRequest) use ($catalog): array {
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
                $rider = $pickupRequest->activeAssignment?->riderProfile;

                return [
                    'id' => $pickupRequest->pickup_no,
                    'orders' => $sellerOrders->count().' '.($sellerOrders->count() === 1 ? 'order' : 'orders'),
                    'order_numbers' => $sellerOrders->pluck('seller_order_no')->implode(', '),
                    'products' => $sellerOrders
                        ->flatMap(fn (SellerOrder $sellerOrder) => $sellerOrder->items->map(fn ($item): array => [
                            'name' => $item->product_name ?: 'Unnamed product',
                            'variant' => trim((string) $item->variant_name),
                            'quantity' => (int) $item->quantity,
                            'image_url' => $catalog->orderItemImageUrl($item),
                        ]))
                        ->values()
                        ->all(),
                    'packages' => $parcels->count(),
                    'provider' => $pickupRequest->logisticsProfile?->display_name ?? 'Provider unavailable',
                    'schedule' => ($date?->format('M j, Y') ?? 'Date unavailable').' · '.($start?->format('g:i A') ?? '—').'–'.($end?->format('g:i A') ?? '—'),
                    'date_key' => $dateKey,
                    'address' => $this->sellerPickupAddressLabel($pickupRequest->pickupAddress),
                    'rider' => $rider?->user?->name ?? 'Awaiting assignment',
                    'rider_location' => $rider?->serviceLocationLabel() ?? 'Awaiting assignment',
                    'rider_profile_id' => $rider?->id,
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
            'provider' => $provider,
            'providerError' => $providerError,
            'pickupAddress' => $this->sellerPickupAddressLabel($sellerProfile->pickupAddress),
            'pickupPhone' => $sellerProfile->pickupAddress?->phone,
        ]);
    }

    public function storePickupRequest(Request $request, SellerPickupRequestService $pickupService): RedirectResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:30'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'logistics_profile_id' => ['nullable', 'integer', 'min:1'],
            'requested_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pickup_window' => ['required', 'string', Rule::in(array_keys(SellerPickupRequestService::PICKUP_WINDOWS))],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $pickupRequest = $pickupService->create(
            $request->user(),
            isset($validated['logistics_profile_id'])
                ? (int) $validated['logistics_profile_id']
                : null,
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
            'scheduled' => $pickupRequest->activeAssignment === null
                ? ['Awaiting Rider Assignment', 'pending']
                : ($pickupRequest->activeAssignment->status === 'picked_up'
                    ? ['Rider Collected', 'picked-up']
                    : ['Rider Assigned', 'assigned']),
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

    public function shipmentTracking(
        Request $request,
        SellerOrderTrackingService $tracking,
        BuyerCatalogService $catalog,
    ): View {
        $storeId = (int) ($request->user()->sellerProfile?->store?->id ?? 0);
        abort_unless($storeId > 0, 404, 'Seller Store not found.');

        // Outbound shipments only: return-shipment tracking lives on the
        // return case pages. Pre-handover stages (pending_waybill,
        // ready_for_pickup, pickup_assigned) stay on the Pickup Requests
        // page until logistics actually receives the parcel; this page
        // starts at rider handover.
        $shipments = Shipment::query()
            ->where('purpose', 'outbound')
            ->whereIn('status', $this->trackedShipmentStatuses())
            ->whereHas('sellerOrder', fn ($query) => $query->where('store_id', $storeId))
            ->with([
                'waybill',
                'sellerOrder.order.buyer',
                'sellerOrder.items.product.images.variant',
                'sellerOrder.items.variant',
                'parcels.pickupRequests.latestAssignment.riderProfile.user',
                'parcels.dispatchBatches' => fn ($query) => $query
                    ->where('dispatch_batches.status', '!=', 'cancelled')
                    ->orderByDesc('dispatch_batches.dispatched_at')
                    ->orderByDesc('dispatch_batches.id'),
                'parcels.dispatchBatches.riderProfile.user',
                'events' => fn ($query) => $query->orderBy('occurred_at')->orderBy('id'),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $rows = $shipments
            ->map(fn (Shipment $shipment): array => $this->trackingRow($shipment, $tracking, $catalog))
            ->values();

        $countFor = fn (string $statusKey): int => $rows->where('status_key', $statusKey)->count();

        return view('seller.fulfillment.tracking', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Sorting / Routed', 'value' => (string) ($countFor('sorting') + $countFor('transit')), 'note' => 'At a hub or moving between centers', 'icon' => 'scan-line', 'tone' => 'info'],
                ['label' => 'Out for Delivery', 'value' => (string) $countFor('out-delivery'), 'note' => 'With a delivery rider', 'icon' => 'bike', 'tone' => 'gold'],
                ['label' => 'Delivered', 'value' => (string) $countFor('delivered'), 'note' => 'Recorded by logistics', 'icon' => 'badge-check', 'tone' => 'olive'],
                ['label' => 'Needs Attention', 'value' => (string) $countFor('failed'), 'note' => 'Exception or returning to Store', 'icon' => 'triangle-alert', 'tone' => 'warning'],
            ],
            'shipments' => $rows->all(),
        ]);
    }

    /**
     * Persisted shipment statuses that belong on the Seller tracking page:
     * everything from rider handover onward. Pre-handover stages remain on
     * Fulfillment → Pickup Requests until logistics takes custody.
     *
     * @return array<int, string>
     */
    private function trackedShipmentStatuses(): array
    {
        return [
            ShipmentStatus::PickedUp->value,
            ShipmentStatus::AtSortingCenter->value,
            ShipmentStatus::HandoffPending->value,
            ShipmentStatus::Sorted->value,
            ShipmentStatus::Dispatched->value,
            ShipmentStatus::OutForDelivery->value,
            ShipmentStatus::Delivered->value,
            ShipmentStatus::DeliveryFailed->value,
            ShipmentStatus::ReturnToSender->value,
            ShipmentStatus::Cancelled->value,
            // Persisted by AdminFulfillmentController::override() but absent
            // from the ShipmentStatus enum. Tracked by literal value so those
            // existing shipments stay visible; no new persisted status is
            // introduced and the admin override remains untouched.
            'in_transit',
            'exception',
        ];
    }

    /**
     * Seller-facing label and existing UI tab bucket for a persisted
     * ShipmentStatus value. Buckets are limited to the tabs the page already
     * renders (sorting, transit, out-delivery, delivered, failed) so the
     * frontend filter keeps working without new status vocabulary.
     *
     * @return array{0: string, 1: string}
     */
    private function trackingStatusDisplay(string $status): array
    {
        return match ($status) {
            ShipmentStatus::PickedUp->value => ['Picked Up', 'transit'],
            ShipmentStatus::HandoffPending->value => ['In Transit', 'transit'],
            ShipmentStatus::AtSortingCenter->value => ['At Sorting Center', 'sorting'],
            ShipmentStatus::Sorted->value => ['Sorted', 'transit'],
            ShipmentStatus::Dispatched->value => ['Dispatched to Rider', 'transit'],
            ShipmentStatus::OutForDelivery->value => ['Out for Delivery', 'out-delivery'],
            ShipmentStatus::Delivered->value => ['Delivered', 'delivered'],
            ShipmentStatus::DeliveryFailed->value => ['Delivery Failed', 'failed'],
            ShipmentStatus::ReturnToSender->value => ['Returning to Seller', 'failed'],
            ShipmentStatus::Cancelled->value => ['Shipment Cancelled', 'failed'],
            // Admin-override-only persisted statuses (not in the enum).
            // Buckets match the Admin fulfillment metrics: in_transit counts
            // as transit, exception as failed.
            'in_transit' => ['In Transit', 'transit'],
            'exception' => ['Exception', 'failed'],
            default => [Str::headline($status), 'failed'],
        };
    }

    /**
     * One tracking table row from persisted shipment, waybill, order, and
     * event records. No rider, ETA, or scan is invented when the database
     * has no authoritative source for it.
     *
     * @return array<string, mixed>
     */
    private function trackingRow(
        Shipment $shipment,
        SellerOrderTrackingService $tracking,
        BuyerCatalogService $catalog,
    ): array {
        $sellerOrder = $shipment->sellerOrder;
        $order = $sellerOrder?->order;
        $waybillNo = (string) ($shipment->waybill?->waybill_no ?: $shipment->shipment_no);
        $orderLabel = $sellerOrder ? '#'.$sellerOrder->seller_order_no : 'Order unavailable';
        $customer = (string) ($order?->recipient_name ?: $order?->buyer?->name ?: 'Recipient');
        $destination = implode(', ', array_filter([
            $order?->city_municipality,
            $order?->province,
        ], fn ($part): bool => filled($part))) ?: 'Destination unavailable';
        [$statusLabel, $statusKey] = $this->trackingStatusDisplay((string) $shipment->status);
        $latestEvent = $shipment->events->last();
        $latestLabel = $latestEvent
            ? $tracking->shipmentEventLabel((string) $latestEvent->event_type)
            : 'No tracking scans recorded yet';
        $rider = $this->trackingRider($shipment);

        // Parcel identity from the immutable purchase snapshot: the cover
        // item's image plus the item count. Never fabricated when the order
        // has no items.
        $items = $sellerOrder?->items ?? collect();
        $coverItem = $items->first();
        $coverName = $coverItem
            ? (trim((string) $coverItem->product_name) ?: 'Unnamed product')
            : 'Product details unavailable';
        $coverImage = $coverItem ? $catalog->orderItemImageUrl($coverItem) : '';

        $events = $shipment->events
            ->reverse()
            ->map(fn ($event): array => [
                'label' => $tracking->shipmentEventLabel((string) $event->event_type),
                'note' => trim((string) ($event->notes ?? '')),
                'time' => $this->trackingTimeLabel($event->occurred_at),
            ])
            ->values()
            ->all();

        // estimated_delivery_at is not cast on the model and no current flow
        // writes it, so parse defensively and fall back honestly.
        $estimatedDelivery = $shipment->estimated_delivery_at;

        return [
            'tracking' => $waybillNo,
            'order' => $orderLabel,
            'customer' => $customer,
            'destination' => $destination,
            'rider_name' => $rider['name'],
            'rider_role' => $rider['role'],
            'latest' => $latestLabel,
            'updated' => $latestEvent ? $this->trackingTimeLabel($latestEvent->occurred_at) : '—',
            'eta' => $estimatedDelivery ? Carbon::parse($estimatedDelivery)->format('M j, Y') : 'Not provided',
            'status' => $statusLabel,
            'status_key' => $statusKey,
            'canonical_status' => Str::upper((string) $shipment->status),
            'product_name' => $coverName,
            'product_image' => $coverImage,
            'item_count' => $items->count(),
            'search' => Str::lower(implode(' ', array_filter([
                $waybillNo,
                $orderLabel,
                $customer,
                $destination,
                $rider['name'],
                $statusLabel,
                $latestLabel,
                $items->pluck('product_name')->implode(' '),
            ]))),
            'events' => $events,
        ];
    }

    /**
     * Current responsible rider from persisted records only: the latest
     * non-cancelled delivery dispatch batch wins, otherwise the pickup rider
     * who collected the parcel. Null when no rider is assigned.
     *
     * @return array{name: ?string, role: ?string}
     */
    private function trackingRider(Shipment $shipment): array
    {
        $deliveryBatch = $shipment->parcels
            ->flatMap(fn ($parcel) => $parcel->dispatchBatches)
            ->sortByDesc('id')
            ->first();

        $deliveryRider = $deliveryBatch?->riderProfile?->user?->name;

        if ($deliveryRider) {
            return ['name' => $deliveryRider, 'role' => 'Delivery rider'];
        }

        $pickupAssignment = $shipment->parcels
            ->flatMap(fn ($parcel) => $parcel->pickupRequests->map(
                fn ($pickupRequest) => $pickupRequest->latestAssignment,
            ))
            ->filter()
            ->reject(fn ($assignment): bool => $assignment->status === 'cancelled')
            ->sortByDesc('id')
            ->first();

        $pickupRider = $pickupAssignment?->riderProfile?->user?->name;

        if ($pickupRider) {
            return ['name' => $pickupRider, 'role' => 'Pickup rider'];
        }

        return ['name' => null, 'role' => null];
    }

    private function trackingTimeLabel(?CarbonInterface $occurredAt): string
    {
        if ($occurredAt === null) {
            return '—';
        }

        if ($occurredAt->isToday()) {
            return 'Today · '.$occurredAt->format('g:i A');
        }

        if ($occurredAt->isYesterday()) {
            return 'Yesterday · '.$occurredAt->format('g:i A');
        }

        return $occurredAt->format('M j, Y · g:i A');
    }

    public function returns(Request $request, BuyerCatalogService $catalog): View
    {
        $store = $request->user()->sellerProfile?->store;
        abort_unless($store, 404);

        $returnRequests = ReturnRequest::query()
            ->where('store_id', $store->id)
            ->with(['order', 'buyer', 'sellerOrder', 'items.orderItem.product.images.variant', 'items.orderItem.variant', 'evidence', 'events.actor', 'refunds.commissions', 'refunds.sellerTransactions', 'refunds.sellerRecovery.events.sellerPayout', 'returnShipment.waybill', 'returnShipment.events'])
            ->latest('submitted_at')
            ->latest('id')
            ->get();
        $cases = $returnRequests->map(fn (ReturnRequest $case): array => $this->returnCaseData($case, $catalog))->all();
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
            throw ValidationException::withMessages(['evidence' => 'Add a note or at least one evidence file.']);
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
        $sellerRecoveryOutstandingMinor = $sellerRecovery?->outstandingMinor() ?? 0;
        $walletOffset = $sellerRecovery?->events->contains(fn ($event): bool => $event->method === 'wallet_offset') ?? false;
        $commissionEffect = $refund?->status === 'completed'
            ? ($refund->financially_responsible_party === 'platform'
                ? 'Platform-funded refund; no Seller commission reversal'
                : ($commissionReversal > 0 ? '+'.$this->money($commissionReversal / 100).' commission reversed' : 'No commission reversal recorded'))
            : 'No finalized financial adjustment';
        $earningsEffect = $refund?->status === 'completed'
            ? ($refund->financially_responsible_party === 'platform'
                ? 'No Seller recovery; Platform is responsible'
                : ($sellerRecoveryMinor > 0
                    ? ($sellerRecoveryOutstandingMinor > 0
                        ? $this->money($sellerRecoveryOutstandingMinor / 100).' recoverable from Seller'.($sellerRecoveredMinor > 0 ? ' · '.$this->money($sellerRecoveredMinor / 100).' recovered' : ' · not yet deducted')
                        : ($walletOffset ? 'Deducted automatically from Seller Wallet' : $this->money($sellerRecoveredMinor / 100).' recovered'))
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
                'image_url' => $catalog && $item->orderItem
                    ? $catalog->orderItemImageUrl($item->orderItem)
                    : '',
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
                    'outstanding_minor' => $sellerRecovery->outstandingMinor(),
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

    public function financeEarnings(
        Request $request,
        SellerEarningsService $earnings,
        SellerWalletService $wallets,
    ): View {
        $sellerProfile = $request->user()->sellerProfile;

        if ($sellerProfile) {
            // Idempotent: only releases eligible sale_earning_available rows
            // into the wallet and never changes wallet eligibility itself.
            $wallets->refreshForSeller($sellerProfile);
        }

        $snapshot = $earnings->dashboardFor($sellerProfile);
        $finance = $snapshot['finance'];
        $commissionRate = $snapshot['commission_rate'];

        return view('seller.Finance.earnings', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'commissionRate' => $commissionRate,
            'summary' => [
                ['label' => 'Gross Sales', 'value' => $this->moneyFromMinor($finance['gross_sales_minor']), 'note' => 'Finalized seller-order sales before discounts'],
                ['label' => 'Seller Discounts + Refunds', 'value' => $this->moneyFromMinor(-$finance['seller_discounts_minor'] - $finance['refunds_minor']), 'note' => 'Posted seller-funded discounts and completed refunds'],
                ['label' => 'Platform Commission', 'value' => $this->moneyFromMinor(-$finance['net_commission_minor']), 'note' => 'Finalized commission less completed refund reversals'],
                ['label' => 'Net Earnings', 'value' => $this->moneyFromMinor($finance['net_earnings_minor']), 'note' => 'Posted seller ledger balance before payout batches'],
            ],
            'finance' => $finance,
            'recentTransactions' => $snapshot['recent_transactions'],
            'settlements' => $snapshot['settlements'],
        ]);
    }

    public function financeTransactions(Request $request, SellerEarningsService $earnings): View
    {
        return view('seller.Finance.transactions', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'transactions' => $earnings->transactionsFor($request->user()->sellerProfile),
            'commissionRate' => $earnings->commissionRate(),
        ]);
    }

    public function downloadSellerPayoutReceipt(
        Request $request,
        SellerPayout $payout,
        AuditService $audit,
    ): BinaryFileResponse {
        $sellerProfileId = (int) ($request->user()->sellerProfile?->id ?? 0);
        abort_unless($sellerProfileId > 0 && (int) $payout->seller_profile_id === $sellerProfileId, 404);
        abort_unless(in_array(strtolower($payout->status), ['paid', 'completed'], true)
            && $payout->paid_at !== null
            && $payout->receipt_disk === 'local'
            && filled($payout->receipt_path)
            && Storage::disk('local')->exists($payout->receipt_path), 404);

        $audit->record(
            action: 'seller_payout.receipt_downloaded_by_seller',
            module: 'Finance',
            subject: $payout,
            description: "Seller downloaded the verified payout proof for {$payout->payout_no}.",
            newValues: ['payout_no' => $payout->payout_no],
            severity: 'info',
            request: $request,
        );

        $originalName = basename(str_replace('\\', '/', (string) $payout->receipt_original_name));
        $downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $originalName) ?: 'seller-payout-receipt';

        $response = response()->download(
            Storage::disk('local')->path($payout->receipt_path),
            Str::limit($downloadName, 200, ''),
        );
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function moneyFromMinor(int $amountMinor): string
    {
        $sign = $amountMinor < 0 ? '−' : '';

        return $sign.'₱'.number_format(abs($amountMinor) / 100, 2);
    }

    /**
     * Deduction amount for HTML: a leading minus for deductions, or a
     * leading plus when the amount is a credit (e.g. finalized commission
     * reversals exceeding the period's commission). The formatter is never
     * double-prefixed with another sign.
     */
    private function deductionMoney(int $amountMinor): string
    {
        return $amountMinor < 0
            ? '+'.$this->moneyFromMinor(abs($amountMinor))
            : '−'.$this->moneyFromMinor($amountMinor);
    }

    /**
     * Sign-safe deduction amount for CSV cells. decimalMinor() already
     * emits '-' for negative values, so credits are written with a leading
     * '+100.00' instead of double-signing the amount.
     */
    private function decimalDeduction(int $amountMinor): string
    {
        return $amountMinor < 0
            ? '+'.$this->decimalMinor(abs($amountMinor))
            : '-'.$this->decimalMinor($amountMinor);
    }

    public function reportsOverview(Request $request, SellerEarningsService $earnings): View
    {
        $range = $earnings->reportRange($request->query('from'), $request->query('to'));
        $data = $earnings->overviewFor($request->user()->sellerProfile, $range['from'], $range['to']);
        $periodLabel = $earnings->rangeLabel($range['from'], $range['to']);
        $previousLabel = $earnings->rangeLabel($data['previous_period']['from'], $data['previous_period']['to']);
        $commissionRateLabel = $this->commissionRateLabel($earnings->commissionRate());
        $fullMonth = $range['from']->isStartOfMonth() && $range['to']->isEndOfMonth() && $range['from']->isSameMonth($range['to']);
        $grossChange = $data['change']['gross'];

        return view('seller.Reports.overview', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'period' => $periodLabel,
            'periodTitle' => $periodLabel,
            'previousLabel' => $previousLabel,
            'dateRange' => ['from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()],
            'metrics' => [
                array_merge(
                    ['label' => 'Net Earnings', 'value' => $this->moneyFromMinor($data['ledger']['net_earnings_minor']), 'primary' => true],
                    $this->metricChange($data['change']['net_earnings'], $previousLabel),
                ),
                array_merge(
                    ['label' => 'Gross Sales', 'value' => $this->moneyFromMinor($data['totals']['gross_minor'])],
                    $this->metricChange($grossChange, $previousLabel),
                ),
                array_merge(
                    ['label' => 'Orders', 'value' => number_format($data['totals']['orders'])],
                    $this->metricChange($data['change']['orders'], $previousLabel),
                ),
                array_merge(
                    ['label' => 'Average Order', 'value' => $this->moneyFromMinor($data['totals']['average_order_minor'])],
                    $this->metricChange($data['change']['average_order'], $previousLabel),
                ),
            ],
            // The overview trend carries `gross_minor` from the shared sales
            // buckets; the chart geometry expects `value_minor`. Map them
            // explicitly so the server-rendered graph plots real bucket gross,
            // never zeros.
            'salesChart' => $this->reportChart(array_map(
                fn (array $bucket): array => [
                    'label' => $bucket['label'],
                    'value_minor' => (int) $bucket['gross_minor'],
                ],
                $data['trend'],
            )),
            'trendTitle' => $fullMonth ? $range['from']->format('F') : 'the period',
            'trendSummary' => $grossChange === null
                ? 'No prior-period sales for comparison.'
                : 'Sales closed '.number_format(abs($grossChange), 1).'% '.($grossChange >= 0 ? 'above ' : 'below ').$previousLabel.'.',
            'financialBridge' => [
                ['label' => 'Gross Sales', 'value' => $this->moneyFromMinor($data['ledger']['gross_sales_minor']), 'negative' => false],
                ['label' => 'Seller-funded Discounts', 'value' => $this->deductionMoney($data['ledger']['seller_discounts_minor']), 'negative' => true],
                ['label' => 'Refunds', 'value' => $this->deductionMoney($data['ledger']['refunds_minor']), 'negative' => true],
                ['label' => 'Platform Commission ('.$commissionRateLabel.'%)', 'value' => $this->deductionMoney($data['ledger']['net_commission_minor']), 'negative' => $data['ledger']['net_commission_minor'] >= 0],
            ],
            'netRevenue' => $this->moneyFromMinor($data['ledger']['net_earnings_minor']),
            'insights' => $this->overviewInsights($data, $previousLabel),
            'products' => collect(array_slice($data['products'], 0, 3))->map(fn (array $product): array => [
                'name' => $product['name'],
                'subcategory' => $product['subcategory'],
                'units' => $product['units'],
                'revenue' => $this->moneyFromMinor($product['gross_minor']),
                'share' => number_format($product['share'], 1),
                'icon' => 'package',
            ])->values(),
            'exportUrl' => route('seller.reports.export', [
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ]),
        ]);
    }

    /**
     * Insight cards built only from the selected period's data.
     */
    private function overviewInsights(array $data, string $previousLabel): array
    {
        $bestSeller = $data['products'][0] ?? null;
        $strongest = collect($data['trend'])->sortByDesc('gross_minor')->first();
        $refundRate = $data['refund_rate'];
        $previousRefundRate = $data['previous_refund_rate'];
        $hasStrongest = $strongest !== null && (int) $strongest['gross_minor'] > 0;

        $insights = [
            [
                'title' => 'Best seller',
                'value' => $bestSeller ? $bestSeller['name'].' · '.$bestSeller['units'].' sold' : 'No completed sales yet',
                'detail' => $bestSeller
                    ? 'Your top product by net sales for the selected period.'
                    : 'Product rankings appear once orders complete.',
                'icon' => 'package-check',
            ],
            [
                'title' => 'Strongest period',
                'value' => $hasStrongest
                    ? $strongest['label'].' · '.$this->moneyFromMinor((int) $strongest['gross_minor'])
                    : 'No sales recorded',
                'detail' => $hasStrongest
                    ? 'This window captured the highest gross sales of the selected period.'
                    : 'Gross sales will be bucketed here once orders complete.',
                'icon' => 'calendar-check-2',
            ],
        ];

        if ($refundRate === null) {
            $insights[] = [
                'title' => 'Refund rate',
                'value' => '—',
                'detail' => 'Refunds are measured against completed gross sales.',
                'icon' => 'rotate-ccw',
            ];
        } else {
            $status = $previousRefundRate === null
                ? null
                : (abs($refundRate - $previousRefundRate) <= 0.5
                    ? 'Stable'
                    : ($refundRate < $previousRefundRate ? 'Improving' : 'Watch'));

            $insights[] = [
                'title' => 'Refund rate',
                'value' => number_format($refundRate, 1).'%'.($status ? ' · '.$status : ''),
                'detail' => $previousRefundRate === null
                    ? 'Full refunds reverse the related platform commission.'
                    : 'Compared with '.number_format($previousRefundRate, 1).'% in '.$previousLabel.'.',
                'icon' => 'rotate-ccw',
            ];
        }

        return $insights;
    }

    public function financialReport(Request $request, SellerEarningsService $earnings): View
    {
        $range = $earnings->reportRange($request->query('from'), $request->query('to'));
        $data = $earnings->financialReportFor($request->user()->sellerProfile, $range['from'], $range['to']);
        $summary = $data['summary'];
        $commissionRateLabel = $this->commissionRateLabel($data['commission_rate']);
        $commissionIsCredit = $summary['net_commission_minor'] < 0;

        return view('seller.Reports.financial', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dateRange' => ['from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()],
            'summary' => [
                ['label' => 'Gross Sales', 'value' => $this->moneyFromMinor($summary['gross_sales_minor']), 'note' => 'Before deductions'],
                ['label' => 'Commissionable Sales', 'value' => $this->moneyFromMinor($summary['commissionable_sales_minor']), 'note' => 'After seller discounts and finalized refunds'],
                ['label' => 'Platform Commission', 'value' => $this->moneyFromMinor($summary['net_commission_minor']), 'note' => $commissionRateLabel.'% after reversal'],
                ['label' => 'Net Earnings', 'value' => $this->moneyFromMinor($summary['net_earnings_minor']), 'note' => 'Seller earnings after deductions'],
            ],
            // Explicit per-row kind so the view never infers credit/deduction
            // styling from row position; a net-commission reversal is a credit.
            'currentStatement' => [
                ['label' => 'Gross Sales', 'value' => $this->moneyFromMinor($summary['gross_sales_minor']), 'kind' => 'base'],
                ['label' => 'Seller-funded Discounts', 'value' => $this->deductionMoney($summary['seller_discounts_minor']), 'kind' => 'deduction'],
                ['label' => 'Refunds', 'value' => $this->deductionMoney($summary['refunds_minor']), 'kind' => 'deduction'],
                ['label' => 'Platform Commission ('.$commissionRateLabel.'%)'.($commissionIsCredit ? ' · credit' : ''), 'value' => $this->deductionMoney($summary['net_commission_minor']), 'kind' => $commissionIsCredit ? 'credit' : 'deduction'],
            ],
            'statementNetEarnings' => $this->moneyFromMinor($summary['net_earnings_minor']),
            'statementTotalDeductions' => $this->moneyFromMinor($summary['total_deductions_minor']),
            'statementStatus' => $data['statement_status'],
            'deductionRate' => $data['deduction_rate'] === null ? '—' : number_format($data['deduction_rate'], 1).'%',
            'deductionRateNote' => $data['deduction_rate'] === null ? 'no gross sales to measure against' : 'of gross sales',
            'deductions' => collect($data['deductions'])->map(fn (array $deduction): array => [
                'label' => $deduction['label'],
                'value' => $deduction['is_credit']
                    ? '+'.$this->moneyFromMinor(abs($deduction['minor']))
                    : $this->moneyFromMinor($deduction['minor']),
                'percent' => number_format($deduction['percent'], 1),
                'tone' => match ($deduction['key']) {
                    'commission' => 'brown',
                    'discounts' => 'gold',
                    default => 'olive',
                },
                'is_credit' => $deduction['is_credit'],
            ])->values(),
            'rows' => collect($data['archive'])->map(fn (array $row): array => [
                'period' => $row['label'],
                'gross' => $this->moneyFromMinor($row['gross_minor']),
                'discounts' => $row['discounts_minor'] > 0 ? $this->deductionMoney($row['discounts_minor']) : '—',
                'refunds' => $row['refunds_minor'] > 0 ? $this->deductionMoney($row['refunds_minor']) : '—',
                'commission' => $this->deductionMoney($row['commission_minor']),
                'net' => $this->moneyFromMinor($row['net_minor']),
                'status' => $row['status'],
            ])->values(),
            'commissionRateLabel' => $commissionRateLabel,
            'exportUrl' => route('seller.reports.financial.export', [
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ]),
        ]);
    }

    public function exportSalesReport(Request $request, SellerEarningsService $earnings): StreamedResponse
    {
        $range = $earnings->reportRange($request->query('from'), $request->query('to'));
        $sort = (string) $request->query('sort', 'net');
        $data = $earnings->salesReportFor($request->user()->sellerProfile, $range['from'], $range['to'], $sort);
        $periodLabel = $earnings->rangeLabel($range['from'], $range['to']);
        $previousLabel = $earnings->rangeLabel($data['previous_period']['from'], $data['previous_period']['to']);
        $filename = 'bearly-sales-performance-'.$range['from']->toDateString().'-to-'.$range['to']->toDateString().'.csv';

        return $this->csvDownload($filename, function ($stream) use ($periodLabel, $previousLabel, $data): void {
            $row = fn (...$cells) => fputcsv($stream, $cells, ',', '"', '\\');

            $row('Bearly Sales & Performance');
            $row('Report period', $periodLabel);
            $row('Compared with', $previousLabel);
            $row();
            $row('Sales summary', 'Value', 'Change vs previous period');
            $row('Gross Sales', $this->decimalMinor($data['totals']['gross_minor']), $this->csvChange($data['change']['gross']));
            $row('Completed Orders', $data['totals']['orders'], $this->csvChange($data['change']['orders']));
            $row('Units Sold', $data['totals']['units'], $this->csvChange($data['change']['units']));
            $row('Average Order Value', $this->decimalMinor($data['totals']['average_order_minor']), $this->csvChange($data['change']['average_order']));
            $row('Refunds', $this->decimalMinor($data['totals']['refunds_minor']));
            $row();
            $row('Sales by period');
            $row('Period', 'Orders', 'Units Sold', 'Gross Sales', 'Discounts', 'Refunds', 'Net Sales');
            foreach ($data['momentum'] as $bucket) {
                $row(
                    $bucket['start'].' to '.$bucket['end'],
                    $bucket['orders'],
                    $bucket['units'],
                    $this->decimalMinor($bucket['gross_minor']),
                    $this->decimalDeduction($bucket['discounts_minor']),
                    $this->decimalDeduction($bucket['refunds_minor']),
                    $this->decimalMinor($bucket['net_minor']),
                );
            }
            $row();
            $row('Product ranking', 'Sorted by '.($data['sort'] === 'units' ? 'units sold' : 'net sales'));
            $row('Rank', 'Product', 'Variant', 'SKU', 'Subcategory', 'Units Sold', 'Gross Sales', 'Discounts', 'Refunds', 'Net Sales', 'Share of Gross Sales (%)');
            foreach ($data['products'] as $product) {
                $row(
                    $product['rank'],
                    $product['name'],
                    $product['variant'],
                    $product['sku'],
                    $product['subcategory'],
                    $product['units'],
                    $this->decimalMinor($product['gross_minor']),
                    $this->decimalDeduction($product['discounts_minor']),
                    $this->decimalDeduction($product['refunds_minor']),
                    $this->decimalMinor($product['net_minor']),
                    $product['share'],
                );
            }
            $row();
            $row('Basis', 'Completed seller orders by completion date; refunds by refund completion date; item amounts exclude shipping; Gross Sales uses the product subtotal and can differ from the Finance ledger Gross Sales on the Financial Report; share uses the product subtotal total.');
        });
    }

    public function exportReportsOverview(Request $request, SellerEarningsService $earnings): StreamedResponse
    {
        $range = $earnings->reportRange($request->query('from'), $request->query('to'));
        $data = $earnings->overviewFor($request->user()->sellerProfile, $range['from'], $range['to']);
        $periodLabel = $earnings->rangeLabel($range['from'], $range['to']);
        $previousLabel = $earnings->rangeLabel($data['previous_period']['from'], $data['previous_period']['to']);
        $commissionRateLabel = $this->commissionRateLabel($earnings->commissionRate());
        $filename = 'bearly-reports-overview-'.$range['from']->toDateString().'-to-'.$range['to']->toDateString().'.csv';

        return $this->csvDownload($filename, function ($stream) use ($periodLabel, $previousLabel, $commissionRateLabel, $data): void {
            $row = fn (...$cells) => fputcsv($stream, $cells, ',', '"', '\\');

            $row('Bearly Reports Overview');
            $row('Report period', $periodLabel);
            $row('Compared with', $previousLabel);
            $row();
            $row('Key metrics', 'Value', 'Change vs previous period');
            $row('Net Earnings', $this->decimalMinor($data['ledger']['net_earnings_minor']), $this->csvChange($data['change']['net_earnings']));
            $row('Gross Sales', $this->decimalMinor($data['totals']['gross_minor']), $this->csvChange($data['change']['gross']));
            $row('Orders', $data['totals']['orders'], $this->csvChange($data['change']['orders']));
            $row('Average Order', $this->decimalMinor($data['totals']['average_order_minor']), $this->csvChange($data['change']['average_order']));
            $row();
            $row('From sales to earnings (Finance ledger)');
            $row('Item', 'Amount');
            $row('Gross Sales', $this->decimalMinor($data['ledger']['gross_sales_minor']));
            $row('Seller-funded Discounts', $this->decimalDeduction($data['ledger']['seller_discounts_minor']));
            $row('Refunds', $this->decimalDeduction($data['ledger']['refunds_minor']));
            $row('Platform Commission ('.$commissionRateLabel.'%)', $this->decimalDeduction($data['ledger']['net_commission_minor']));
            $row('Net Revenue', $this->decimalMinor($data['ledger']['net_earnings_minor']));
            $row();
            $row('Gross sales by period');
            $row('Period start', 'Period end', 'Gross Sales');
            foreach ($data['trend'] as $bucket) {
                $row($bucket['start'], $bucket['end'], $this->decimalMinor($bucket['gross_minor']));
            }
            $row();
            $row('Top products');
            $row('Rank', 'Product', 'Subcategory', 'Units Sold', 'Gross Sales', 'Share of Gross Sales (%)');
            foreach (array_slice($data['products'], 0, 5) as $product) {
                $row($product['rank'], $product['name'], $product['subcategory'], $product['units'], $this->decimalMinor($product['gross_minor']), $product['share']);
            }
            $row();
            $row('Basis', 'Gross Sales uses the seller-order product subtotal (shipping excluded); the sales-to-earnings bridge, Net Revenue, and Net Earnings follow the Finance ledger posted basis and can differ from Gross Sales.');
        });
    }

    public function exportFinancialReport(Request $request, SellerEarningsService $earnings): StreamedResponse
    {
        $range = $earnings->reportRange($request->query('from'), $request->query('to'));
        $data = $earnings->financialReportFor($request->user()->sellerProfile, $range['from'], $range['to']);
        $periodLabel = $earnings->rangeLabel($range['from'], $range['to']);
        $summary = $data['summary'];
        $filename = 'bearly-financial-report-'.$range['from']->toDateString().'-to-'.$range['to']->toDateString().'.csv';

        return $this->csvDownload($filename, function ($stream) use ($periodLabel, $data, $summary): void {
            $row = fn (...$cells) => fputcsv($stream, $cells, ',', '"', '\\');

            $row('Bearly Financial Report');
            $row('Statement period', $periodLabel);
            $row('Statement status', $data['statement_status']['label']);
            $row();
            $row('Summary', 'Amount');
            $row('Gross Sales', $this->decimalMinor($summary['gross_sales_minor']));
            $row('Commissionable Sales', $this->decimalMinor($summary['commissionable_sales_minor']));
            $row('Platform Commission', $this->decimalMinor($summary['net_commission_minor']));
            $row('Net Earnings', $this->decimalMinor($summary['net_earnings_minor']));
            $row();
            $row('Current statement');
            $row('Item', 'Amount');
            $row('Gross Sales', $this->decimalMinor($summary['gross_sales_minor']));
            $row('Seller-funded Discounts', $this->decimalDeduction($summary['seller_discounts_minor']));
            $row('Refunds', $this->decimalDeduction($summary['refunds_minor']));
            $row('Platform Commission', $this->decimalDeduction($summary['net_commission_minor']));
            $row('Net Earnings', $this->decimalMinor($summary['net_earnings_minor']));
            $row();
            $row('Deductions');
            $row('Item', 'Amount', 'Share of deductions (%)');
            foreach ($data['deductions'] as $deduction) {
                $row(
                    $deduction['is_credit'] ? $deduction['label'].' (credit)' : $deduction['label'],
                    $this->decimalMinor(abs($deduction['minor'])),
                    number_format($deduction['percent'], 1),
                );
            }
            $row();
            $row('Monthly financial history');
            $row('Statement Period', 'Gross Sales', 'Discounts', 'Refunds', 'Platform Commission', 'Net Earnings', 'Status');
            foreach ($data['archive'] as $archiveRow) {
                $row(
                    $archiveRow['label'],
                    $this->decimalMinor($archiveRow['gross_minor']),
                    $this->decimalDeduction($archiveRow['discounts_minor']),
                    $this->decimalDeduction($archiveRow['refunds_minor']),
                    $this->decimalDeduction($archiveRow['commission_minor']),
                    $this->decimalMinor($archiveRow['net_minor']),
                    $archiveRow['status']['label'],
                );
            }
            $row();
            $row('Basis', 'Finance ledger rows posted in the statement period; amounts are net of completed refunds and finalized commission reversals; Gross Sales uses the Finance ledger basis (finalized commission base plus seller-funded discounts) and can differ from Sales & Performance gross sales, which excludes shipping.');
        });
    }
}
