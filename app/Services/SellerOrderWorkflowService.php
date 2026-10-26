<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SellerOrderWorkflowService
{
    public function __construct(
        private readonly InventoryReservationService $reservations,
        private readonly NotificationService $notifications,
        private readonly SellerFulfillmentService $fulfillment,
        private readonly PromotionService $promotions,
    ) {}

    public function transition(User $actor, int $sellerOrderId, string $action, ?string $reason = null): SellerOrder
    {
        $storeId = $actor->sellerProfile?->store?->id;

        if (! $storeId) {
            abort(403, 'Your Seller account does not have an active Store.');
        }

        // Resolve ownership before taking locks, then re-check it under the parent-order lock.
        $target = SellerOrder::query()
            ->where('store_id', $storeId)
            ->findOrFail($sellerOrderId);

        return DB::transaction(function () use ($actor, $action, $reason, $sellerOrderId, $storeId, $target): SellerOrder {
            $order = Order::query()
                ->whereKey($target->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<int, SellerOrder> $sellerOrders */
            $sellerOrders = SellerOrder::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var SellerOrder|null $sellerOrder */
            $sellerOrder = $sellerOrders->first(fn (SellerOrder $candidate): bool => (int) $candidate->id === $sellerOrderId && (int) $candidate->store_id === (int) $storeId
            );

            abort_unless($sellerOrder, 404);
            abort_if($order->status === 'cancelled', 409, 'This marketplace order has already been cancelled.');

            return match ($action) {
                'confirm' => $this->advance(
                    $sellerOrder,
                    $actor,
                    [SellerOrderStatus::Pending->value, 'placed'],
                    SellerOrderStatus::Accepted,
                    'confirmed_at',
                    'Seller confirmed that this order can be fulfilled.',
                ),
                'start_preparing' => $this->startPreparing($sellerOrder, $actor),
                'mark_ready' => $this->markReady($sellerOrder, $actor),
                'cancel' => $this->cancelPending($order, $sellerOrders, $sellerOrder, $actor, trim((string) $reason)),
                default => abort(422, 'Unsupported Seller Order action.'),
            };
        }, attempts: 3);
    }

    private function startPreparing(SellerOrder $sellerOrder, User $actor): SellerOrder
    {
        abort_unless(
            $sellerOrder->status === SellerOrderStatus::Accepted->value,
            409,
            'This order has changed or is not ready for that action. Refresh the order list and try again.',
        );

        $pickupAddress = $this->fulfillment->pickupAddressFor($actor, lock: true);

        abort_unless(
            $pickupAddress,
            409,
            'Set a valid Seller pickup address before starting preparation.',
        );

        $provider = $this->fulfillment->providerForPickupAddress($pickupAddress, lock: true);
        $this->fulfillment->createShipmentFor(
            $sellerOrder,
            $actor,
            $provider,
            $pickupAddress,
        );

        return $this->advance(
            $sellerOrder,
            $actor,
            [SellerOrderStatus::Accepted->value],
            SellerOrderStatus::Packing,
            'preparing_at',
            'Seller started preparing the order and generated its waybill.',
        );
    }

    private function markReady(SellerOrder $sellerOrder, User $actor): SellerOrder
    {
        abort_unless(
            $sellerOrder->status === SellerOrderStatus::Packing->value,
            409,
            'This order has changed or is not ready for that action. Refresh the order list and try again.',
        );

        $this->fulfillment->markShipmentReady($sellerOrder, $actor);

        return $this->advance(
            $sellerOrder,
            $actor,
            [SellerOrderStatus::Packing->value],
            SellerOrderStatus::ReadyForPickup,
            'ready_at',
            'Seller completed the packing checklist and marked the order ready for pickup.',
        );
    }

    private function advance(
        SellerOrder $sellerOrder,
        User $actor,
        array $expectedStatuses,
        SellerOrderStatus $nextStatus,
        string $timestampColumn,
        string $reason,
    ): SellerOrder {
        abort_unless(
            in_array($sellerOrder->status, $expectedStatuses, true),
            409,
            'This order has changed or is not ready for that action. Refresh the order list and try again.',
        );

        $previousStatus = (string) $sellerOrder->status;
        $now = now();

        $sellerOrder->update([
            'status' => $nextStatus->value,
            $timestampColumn => $now,
        ]);

        $this->recordChange($sellerOrder, $actor, $previousStatus, $nextStatus->value, $reason, $now);
        $this->notifyBuyerOfProgress($sellerOrder, $nextStatus);

        return $sellerOrder->fresh(['statusHistory.actor']);
    }

    private function notifyBuyerOfProgress(SellerOrder $sellerOrder, SellerOrderStatus $status): void
    {
        $buyer = $sellerOrder->order?->buyer;

        if (! $buyer) {
            return;
        }

        $message = match ($status) {
            SellerOrderStatus::Accepted => "The Seller confirmed order {$sellerOrder->seller_order_no} and will start preparing it.",
            SellerOrderStatus::Packing => "The Seller started preparing order {$sellerOrder->seller_order_no}.",
            SellerOrderStatus::ReadyForPickup => "Order {$sellerOrder->seller_order_no} is packed and ready for pickup by Logistics.",
            default => "Order {$sellerOrder->seller_order_no} was updated.",
        };

        $this->notifications->sendAfterCommit($buyer, new WorkflowNotification(
            eventKey: 'buyer_order_updates',
            title: 'Order update',
            body: $message,
            actionUrl: route('buyer.profile').'#purchases',
            type: 'info',
            meta: ['seller_order_id' => (int) $sellerOrder->id],
        ));
    }

    /** @param Collection<int, SellerOrder> $sellerOrders */
    private function cancelPending(
        Order $order,
        Collection $sellerOrders,
        SellerOrder $sellerOrder,
        User $actor,
        string $reason,
    ): SellerOrder {
        abort_unless(
            in_array($sellerOrder->status, [SellerOrderStatus::Pending->value, 'placed'], true),
            409,
            'A Seller Order can only be cancelled before it is confirmed.',
        );
        abort_if(mb_strlen($reason) < 5, 422, 'Provide a cancellation reason of at least 5 characters.');

        $payments = Payment::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        abort_unless(
            $payments->count() === 1
                && $payments->sole()->method === 'cod'
                && $payments->sole()->status === 'pending',
            409,
            'This order no longer has a pending COD payment and cannot be cancelled by the Seller.',
        );

        $this->reservations->releaseForSellerCancellation($sellerOrder, $actor, $reason);

        $previousStatus = (string) $sellerOrder->status;
        $now = now();
        $sellerOrder->update([
            'status' => SellerOrderStatus::Cancelled->value,
            'cancelled_at' => $now,
        ]);
        $this->recordChange(
            $sellerOrder,
            $actor,
            $previousStatus,
            SellerOrderStatus::Cancelled->value,
            $reason,
            $now,
        );

        $remainingOrders = $sellerOrders->reject(fn (SellerOrder $candidate): bool => $candidate->status === SellerOrderStatus::Cancelled->value
        );
        $payment = $payments->sole();

        if ($remainingOrders->isEmpty()) {
            $order->update([
                'subtotal_minor' => 0,
                'shipping_fee_minor' => 0,
                'discount_minor' => 0,
                'platform_discount_minor' => 0,
                'campaign_platform_discount_minor' => 0,
                'platform_shipping_discount_minor' => 0,
                'total_minor' => 0,
                'status' => 'cancelled',
                'payment_status' => 'cancelled',
                'cancelled_at' => $now,
                'cancel_reason' => "Seller cancelled {$sellerOrder->seller_order_no}: {$reason}",
            ]);
            $payment->update(['status' => 'cancelled', 'amount_minor' => 0]);
            $this->promotions->releaseForOrder($order);
        } else {
            $this->promotions->releaseForSellerOrder($order, $sellerOrder, $actor, $reason);

            $subtotal = (int) $remainingOrders->sum(fn (SellerOrder $item): int => (int) $item->subtotal_minor);
            $shipping = (int) $remainingOrders->sum(fn (SellerOrder $item): int => (int) $item->shipping_fee_minor);
            $sellerDiscount = (int) $remainingOrders->sum(fn (SellerOrder $item): int => (int) $item->discount_minor);
            $platformDiscount = (int) $remainingOrders->sum(fn (SellerOrder $item): int => (int) $item->platform_discount_minor);
            $campaignPlatformDiscount = (int) $remainingOrders->sum(fn (SellerOrder $item): int => (int) $item->campaign_platform_discount_minor);
            $platformShipping = (int) $remainingOrders->sum(fn (SellerOrder $item): int => (int) $item->platform_shipping_discount_minor);
            $total = (int) $remainingOrders->sum(fn (SellerOrder $item): int => max(
                0,
                (int) $item->total_minor
                    - (int) $item->platform_discount_minor
                    - (int) $item->campaign_platform_discount_minor
                    - (int) $item->platform_shipping_discount_minor,
            ));

            $order->update([
                'subtotal_minor' => max(0, $subtotal - $campaignPlatformDiscount),
                'shipping_fee_minor' => $shipping,
                'discount_minor' => $sellerDiscount + $platformDiscount,
                'platform_discount_minor' => $platformDiscount,
                'campaign_platform_discount_minor' => $campaignPlatformDiscount,
                'platform_shipping_discount_minor' => $platformShipping,
                'total_minor' => $total,
            ]);
            $payment->update(['amount_minor' => $total]);
        }

        $this->notifyBuyerOfSellerCancellation($order, $sellerOrder, $reason);

        return $sellerOrder->fresh(['statusHistory.actor']);
    }

    private function notifyBuyerOfSellerCancellation(Order $order, SellerOrder $sellerOrder, string $reason): void
    {
        $buyer = $order->buyer;

        if (! $buyer) {
            return;
        }

        $this->notifications->sendAfterCommit($buyer, new WorkflowNotification(
            eventKey: 'buyer_order_updates',
            title: 'Order cancelled by Seller',
            body: "The Seller cancelled {$sellerOrder->seller_order_no}. Reason: {$reason}.",
            actionUrl: route('buyer.profile').'#purchases',
            type: 'warning',
            meta: ['order_id' => (int) $order->id, 'seller_order_id' => (int) $sellerOrder->id],
        ));
    }

    private function recordChange(
        SellerOrder $sellerOrder,
        User $actor,
        string $fromStatus,
        string $toStatus,
        string $reason,
        mixed $occurredAt,
    ): void {
        SellerOrderStatusHistory::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actor->id,
            'reason' => $reason,
            'occurred_at' => $occurredAt,
        ]);
    }
}
