<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SellerOrderStatusHistory;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\DB;

class BuyerOrderConfirmationService
{
    public function __construct(
        private readonly CodCashCollectionService $codCash,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Confirm buyer receipt of a fully delivered marketplace order.
     *
     * Every non-cancelled Seller Order must already be completed or have all
     * of its shipments delivered; otherwise the confirmation is rejected so a
     * partial delivery can never complete the order. Repeating the call after
     * a successful confirmation is a no-op success without duplicate history.
     */
    public function confirmReceipt(User $buyer, int $orderId): Order
    {
        return DB::transaction(function () use ($buyer, $orderId): Order {
            $order = Order::query()
                ->where('buyer_id', $buyer->id)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($order->status === 'cancelled', 409, 'This order was cancelled and cannot be confirmed as received.');

            $payments = Payment::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->get();
            $codPayment = $payments->first(fn (Payment $payment): bool => $payment->method === 'cod');
            if ($codPayment) {
                $this->codCash->assertPaymentCaptured($order);
            }

            $sellerOrders = $order->sellerOrders()->orderBy('id')->lockForUpdate()->get();
            $active = $sellerOrders->reject(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Cancelled->value);

            abort_if($active->isEmpty(), 409, 'This order has no active Seller Orders to confirm.');

            // A fulfilled parent with every active Seller Order completed is a
            // true replay. Return its recorded state before writing timestamps,
            // payment state, histories, or notifications again.
            if ($order->status === 'fulfilled'
                && $active->every(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Completed->value)) {
                return $this->confirmationResult($order->fresh(['sellerOrders.items', 'payments']), true);
            }

            foreach ($active as $sellerOrder) {
                if ($sellerOrder->status === SellerOrderStatus::Completed->value) {
                    continue;
                }

                $shipmentStatuses = $sellerOrder->shipments()->orderBy('id')->lockForUpdate()->pluck('status');

                abort_if(
                    $shipmentStatuses->isEmpty() || $shipmentStatuses->contains(fn (string $status): bool => $status !== ShipmentStatus::Delivered->value),
                    409,
                    'This order cannot be confirmed yet because not every parcel has been delivered.'
                );
            }

            $now = now();
            $completedNow = [];

            foreach ($active as $sellerOrder) {
                if ($sellerOrder->status === SellerOrderStatus::Completed->value) {
                    continue;
                }

                $previousStatus = (string) $sellerOrder->status;
                $sellerOrder->update([
                    'status' => SellerOrderStatus::Completed->value,
                    'completed_at' => $now,
                ]);
                SellerOrderStatusHistory::query()->create([
                    'seller_order_id' => $sellerOrder->id,
                    'from_status' => $previousStatus,
                    'to_status' => SellerOrderStatus::Completed->value,
                    'actor_user_id' => $buyer->id,
                    'reason' => 'Buyer confirmed receipt of the delivered order.',
                    'occurred_at' => $now,
                ]);

                $completedNow[] = $sellerOrder;
            }

            $order->update([
                'status' => 'fulfilled',
                'payment_status' => 'paid',
                // Legacy rows can already carry a completion timestamp. Keep
                // it rather than replacing it during a repair/partial finish.
                'completed_at' => $order->completed_at ?? $now,
            ]);
            $pendingNonCod = $payments->where('status', 'pending')->where('method', '!=', 'cod');
            foreach ($pendingNonCod as $payment) {
                $payment->update(['status' => 'paid', 'paid_at' => $now]);
            }

            $this->notifySellersOfCompletion($order, $completedNow, $buyer);

            return $this->confirmationResult($order->fresh(['sellerOrders.items', 'payments']), false);
        }, attempts: 3);
    }

    private function confirmationResult(Order $order, bool $alreadyConfirmed): Order
    {
        // Keep the existing Order return contract used by the Web controller,
        // while giving the mobile response an outcome captured under the same
        // row lock (so a concurrent replay is reported accurately).
        $order->setAttribute('_receipt_already_confirmed', $alreadyConfirmed);
        $order->makeHidden('_receipt_already_confirmed');

        return $order;
    }

    /**
     * @param  list<SellerOrder>  $sellerOrders
     */
    private function notifySellersOfCompletion(Order $order, array $sellerOrders, User $buyer): void
    {
        foreach ($sellerOrders as $sellerOrder) {
            $sellerUser = $sellerOrder->store?->sellerProfile?->user;

            if (! $sellerUser) {
                continue;
            }

            $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
                eventKey: 'seller_shipment_updates',
                title: 'Order delivered and completed',
                body: "{$buyer->name} confirmed receipt of {$sellerOrder->seller_order_no}. The order is now complete.",
                actionUrl: route('seller.orders'),
                type: 'success',
                meta: ['order_id' => (int) $order->id, 'seller_order_id' => (int) $sellerOrder->id],
            ));
        }
    }

    /**
     * Whether the buyer-owned order currently offers a receipt confirmation.
     */
    public function canConfirm(Order $order): bool
    {
        if ($order->status === 'cancelled') {
            return false;
        }

        $pending = $order->sellerOrders
            ->reject(fn ($sellerOrder): bool => in_array($sellerOrder->status, [SellerOrderStatus::Cancelled->value, SellerOrderStatus::Completed->value], true));

        if ($pending->isEmpty()) {
            return false;
        }

        if ($order->payments()->where('method', 'cod')->exists() && ! $this->codCash->hasPaymentCaptured($order)) {
            return false;
        }

        return $pending->every(fn ($sellerOrder): bool => $sellerOrder->shipments->isNotEmpty()
            && $sellerOrder->shipments->every(fn ($shipment): bool => $shipment->status === ShipmentStatus::Delivered->value));
    }

    /**
     * Whether every active Seller Order in the buyer-owned order is complete.
     */
    public function isConfirmed(Order $order): bool
    {
        $active = $order->sellerOrders
            ->reject(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Cancelled->value);

        return $active->isNotEmpty()
            && $active->every(fn ($sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Completed->value);
    }
}
