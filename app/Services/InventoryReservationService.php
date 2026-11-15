<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Models\User;

class InventoryReservationService
{
    public function releaseForBuyerCancellation(SellerOrder $sellerOrder, Order $order, User $actor, string $reason): void
    {
        $this->release($sellerOrder, $actor, $reason, Order::class, (int) $order->id);
    }

    public function releaseForSellerCancellation(SellerOrder $sellerOrder, User $actor, string $reason): void
    {
        $this->release($sellerOrder, $actor, $reason, SellerOrder::class, (int) $sellerOrder->id);
    }

    private function release(
        SellerOrder $sellerOrder,
        User $actor,
        string $reason,
        string $referenceType,
        int $referenceId,
    ): void {
        $items = OrderItem::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->orderBy('id')
            ->get();

        abort_if($items->isEmpty(), 409, 'This Seller Order has no items to release safely. Contact support.');

        $variantIds = $items->pluck('product_variant_id')->filter()->unique()->sort()->values();
        $variants = ProductVariant::withTrashed()
            ->whereIn('id', $variantIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $variant = $variants->get($item->product_variant_id);
            $quantity = (int) $item->quantity;

            abort_unless(
                $variant && (int) $variant->stock_reserved >= $quantity,
                409,
                'Reserved stock no longer matches this Seller Order. Contact support to cancel it safely.',
            );

            $newReserved = (int) $variant->stock_reserved - $quantity;
            $variant->update(['stock_reserved' => $newReserved]);
            InventoryMovement::query()->create([
                'variant_id' => $variant->id,
                'type' => 'order_reservation_released',
                'quantity_delta' => 0,
                'balance_after' => (int) $variant->stock_on_hand,
                'reserved_delta' => -$quantity,
                'reserved_balance_after' => $newReserved,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => "Released {$quantity} reserved unit(s) after {$sellerOrder->seller_order_no} cancellation: {$reason}",
                'created_by' => $actor->id,
                'occurred_at' => now(),
            ]);
        }
    }
}
