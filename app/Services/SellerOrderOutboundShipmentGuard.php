<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SellerOrderOutboundShipmentGuard
{
    /**
     * Lock the parent financial records in Order -> SellerOrder -> Shipment
     * order and require the supported one-outbound-per-SellerOrder shape.
     * Callers must keep the surrounding transaction open through their writes.
     *
     * @return array{order: Order, sellerOrder: SellerOrder, shipment: Shipment}
     */
    public function lockSingleForShipment(int $shipmentId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The outbound shipment integrity check requires an active transaction.');
        }

        $shipmentReference = Shipment::query()->whereKey($shipmentId)->firstOrFail();
        if ($shipmentReference->purpose !== 'outbound') {
            throw new ConflictHttpException('Financial processing is only supported for outbound shipments.');
        }

        $sellerOrderReference = SellerOrder::query()
            ->whereKey($shipmentReference->seller_order_id)
            ->firstOrFail(['id', 'order_id', 'seller_order_no']);

        $order = Order::query()
            ->whereKey($sellerOrderReference->order_id)
            ->lockForUpdate()
            ->firstOrFail();

        $sellerOrder = SellerOrder::query()
            ->whereKey($sellerOrderReference->id)
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->firstOrFail();

        $outboundShipments = Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('purpose', 'outbound')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($outboundShipments->count() !== 1 || (int) $outboundShipments->first()->id !== $shipmentId) {
            throw new ConflictHttpException(
                "Seller Order {$sellerOrder->seller_order_no} must have exactly one outbound Shipment before financial processing can continue.",
            );
        }

        return [
            'order' => $order,
            'sellerOrder' => $sellerOrder,
            'shipment' => $outboundShipments->first(),
        ];
    }
}
