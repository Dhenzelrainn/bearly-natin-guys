<?php

namespace App\Services;

use App\Enums\DeliveryAttemptOutcome;
use App\Enums\ParcelStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryProof;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuyerOrderConfirmationService
{
    /**
     * Refund service excludes these states from refund capacity because no
     * refund amount is active in either state.
     *
     * @var list<string>
     */
    private const INACTIVE_REFUND_STATUSES = ['failed', 'cancelled'];

    public function __construct(
        private readonly CodCashCollectionService $codCash,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Confirm buyer receipt of a fully delivered marketplace order.
     *
     * First-time confirmation is limited to an in-progress parent order whose
     * active Seller Orders each have delivered outbound parcels and normal
     * Rider delivery evidence. Repeating a completed parent confirmation is
     * a read-only success, even if its payment was later refunded.
     */
    public function confirmReceipt(User $buyer, int $orderId): Order
    {
        return DB::transaction(function () use ($buyer, $orderId): Order {
            $order = Order::query()
                ->where('buyer_id', $buyer->id)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $sellerOrders = $order->sellerOrders()->orderBy('id')->lockForUpdate()->get();
            $activeSellerOrders = $this->activeSellerOrders($sellerOrders);

            abort_if($activeSellerOrders->isEmpty(), 409, 'This order has no active Seller Orders to confirm.');

            // A fulfilled parent with all active Seller Orders completed is a
            // legitimate replay. Keep it ahead of payment, return, and proof
            // checks so later refund activity cannot rewrite completed history.
            if ($this->isConfirmedState($order, $activeSellerOrders)) {
                return $this->confirmationResult($order->fresh(['sellerOrders.items', 'payments']), true);
            }

            $payments = Payment::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $blockMessage = $this->firstConfirmationBlockMessage($order, $sellerOrders, $payments, true);
            abort_if($blockMessage !== null, 409, $blockMessage);

            $now = now();
            $completedNow = [];

            foreach ($activeSellerOrders as $sellerOrder) {
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
                // Keep any pre-existing timestamp rather than rewriting it.
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
        // Keep the Order return contract used by Web and expose the replay
        // outcome to Mobile from the same transaction and row lock.
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
     * Whether the buyer-owned order currently offers a first-time confirmation.
     */
    public function canConfirm(Order $order): bool
    {
        $sellerOrders = $order->relationLoaded('sellerOrders')
            ? $order->sellerOrders
            : $order->sellerOrders()->orderBy('id')->get();
        $payments = $order->relationLoaded('payments')
            ? $order->payments
            : $order->payments()->orderBy('id')->get();

        return $this->firstConfirmationBlockMessage($order, $sellerOrders, $payments, false) === null;
    }

    /**
     * Whether every active Seller Order belongs to a completed parent order.
     */
    public function isConfirmed(Order $order): bool
    {
        $sellerOrders = $order->relationLoaded('sellerOrders')
            ? $order->sellerOrders
            : $order->sellerOrders()->orderBy('id')->get();

        return $this->isConfirmedState($order, $this->activeSellerOrders($sellerOrders));
    }

    /**
     * @param  Collection<int, SellerOrder>  $sellerOrders
     * @return Collection<int, SellerOrder>
     */
    private function activeSellerOrders(Collection $sellerOrders): Collection
    {
        return collect($sellerOrders)
            ->reject(fn (SellerOrder $sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Cancelled->value)
            ->values();
    }

    /**
     * @param  Collection<int, SellerOrder>  $activeSellerOrders
     */
    private function isConfirmedState(Order $order, Collection $activeSellerOrders): bool
    {
        return $order->status === 'fulfilled'
            && $activeSellerOrders->isNotEmpty()
            && $activeSellerOrders->every(fn (SellerOrder $sellerOrder): bool => $sellerOrder->status === SellerOrderStatus::Completed->value);
    }

    /**
     * @param  Collection<int, SellerOrder>  $sellerOrders
     * @param  Collection<int, Payment>  $payments
     */
    private function firstConfirmationBlockMessage(
        Order $order,
        Collection $sellerOrders,
        Collection $payments,
        bool $lockEvidence,
    ): ?string {
        if ($order->status === 'cancelled') {
            return 'This order was cancelled and cannot be confirmed as received.';
        }

        // CheckoutService creates new orders as processing. Unknown, pending,
        // or already-final parent states are not first-time receipt candidates.
        if ($order->status !== 'processing') {
            return 'This order is not eligible for receipt confirmation.';
        }

        $activeSellerOrders = $this->activeSellerOrders($sellerOrders);
        if ($activeSellerOrders->isEmpty()) {
            return 'This order has no active Seller Orders to confirm.';
        }

        if ($this->hasUnresolvedReturnOrRefund($order)) {
            return 'This order has an unresolved return, refund, or dispute case and cannot be confirmed as received.';
        }

        if ($payments->contains(fn (Payment $payment): bool => $payment->method === 'cod')
            && ! $this->codCash->hasPaymentCaptured($order)) {
            return 'The COD payment cannot be completed until every required Rider cash collection is recorded.';
        }

        foreach ($activeSellerOrders as $sellerOrder) {
            if (! $this->hasDeliveredOutboundEvidence($sellerOrder, $lockEvidence)) {
                return 'This order cannot be confirmed yet because not every parcel has been delivered.';
            }
        }

        return null;
    }

    private function hasUnresolvedReturnOrRefund(Order $order): bool
    {
        $hasUnresolvedReturnOrDispute = ReturnRequest::query()
            ->where('order_id', $order->id)
            ->where(function ($query): void {
                $query->whereNotIn('status', [
                    ReturnRequestStatus::Resolved->value,
                    ReturnRequestStatus::Rejected->value,
                ])->orWhereHas('disputes', fn ($disputes) => $disputes->whereNotIn('status', ['resolved', 'closed']));
            })
            ->exists();

        if ($hasUnresolvedReturnOrDispute) {
            return true;
        }

        // Pending, completed, and unknown refund states represent money or a
        // refund workflow that must not be combined with a new order completion.
        // Failed/cancelled are the refund service's explicitly inactive states.
        return Refund::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', self::INACTIVE_REFUND_STATUSES)
            ->exists();
    }

    private function hasDeliveredOutboundEvidence(SellerOrder $sellerOrder, bool $lockRows): bool
    {
        $shipmentQuery = Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('purpose', 'outbound')
            ->orderBy('id');
        if ($lockRows) {
            $shipmentQuery->lockForUpdate();
        }
        $shipments = $shipmentQuery->get();

        if ($shipments->isEmpty()) {
            return false;
        }

        foreach ($shipments as $shipment) {
            if ($shipment->status !== ShipmentStatus::Delivered->value) {
                return false;
            }

            $parcelQuery = Parcel::query()
                ->where('shipment_id', $shipment->id)
                ->orderBy('id');
            if ($lockRows) {
                $parcelQuery->lockForUpdate();
            }
            $parcels = $parcelQuery->get();

            if ($parcels->isEmpty()) {
                return false;
            }

            foreach ($parcels as $parcel) {
                if ($parcel->status !== ParcelStatus::Delivered->value) {
                    return false;
                }

                $attemptQuery = DeliveryAttempt::query()
                    ->where('parcel_id', $parcel->id)
                    ->orderByDesc('attempted_at')
                    ->orderByDesc('id');
                if ($lockRows) {
                    $attemptQuery->lockForUpdate();
                }
                $attempt = $attemptQuery->first();

                if (! $attempt || $attempt->outcome !== DeliveryAttemptOutcome::Delivered->value) {
                    return false;
                }

                $proofQuery = DeliveryProof::query()
                    ->where('delivery_attempt_id', $attempt->id)
                    ->where('type', 'photo')
                    ->orderBy('id');
                if ($lockRows) {
                    $proofQuery->lockForUpdate();
                }

                $hasRequiredProof = $proofQuery->get(['file_path', 'recipient_name', 'captured_at'])
                    ->contains(fn (DeliveryProof $proof): bool => filled($proof->file_path)
                        && filled($proof->recipient_name)
                        && $proof->captured_at !== null);

                if (! $hasRequiredProof) {
                    return false;
                }
            }
        }

        return true;
    }
}
