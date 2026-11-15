<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\CodCashCollection;
use App\Models\CodCashCollectionAllocation;
use App\Models\CodCashCollectionEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class CodCashCollectionService
{
    public function __construct(
        private readonly CommissionLedgerService $commissionLedger,
        private readonly NotificationService $notifications,
        private readonly PromotionService $promotions,
        private readonly SellerWalletService $sellerWallets,
    ) {}

    public function recordRiderCollection(Shipment $shipment, User $riderUser, int $actualAmountMinor): CodCashCollection
    {
        return DB::transaction(function () use ($shipment, $riderUser, $actualAmountMinor): CodCashCollection {
            $rider = $riderUser->riderProfile()->first();

            if ($riderUser->role !== UserRole::Rider->value
                || $riderUser->status !== AccountStatus::Active->value
                || ! $rider
                || $rider->verification_status !== 'approved') {
                throw new AuthorizationException('An active, approved Rider is required to record COD cash.');
            }

            // Match the commerce workflow lock order: marketplace Orders,
            // Seller Orders, then Shipment and Payment. Locking Shipment first
            // can deadlock with checkout/fulfillment paths that start at Order.
            $shipmentStub = Shipment::query()->whereKey($shipment->id)->firstOrFail();
            $memberStubs = $shipmentStub->sellerOrders()
                ->orderBy('seller_orders.id')
                ->get(['seller_orders.id', 'seller_orders.order_id']);
            if ($memberStubs->isEmpty()) {
                throw new ConflictHttpException('This Shipment has no Seller Order membership to allocate COD cash.');
            }

            $orderIds = $memberStubs->pluck('order_id')->unique()->sort()->values();
            $orders = Order::query()
                ->whereIn('id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($orders->count() !== $orderIds->count()) {
                throw new ConflictHttpException('A marketplace order in this Shipment is unavailable.');
            }

            $sellerOrderIds = $memberStubs->pluck('id')->unique()->sort()->values();
            $sellerOrders = SellerOrder::query()
                ->whereIn('id', $sellerOrderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($sellerOrders->count() !== $sellerOrderIds->count()) {
                throw new ConflictHttpException('A Seller Order in this Shipment is unavailable.');
            }

            $lockedShipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $lockedMemberIds = $lockedShipment->sellerOrders()
                ->orderBy('seller_orders.id')
                ->pluck('seller_orders.id')
                ->map(fn ($id): int => (int) $id)
                ->values();
            if ($lockedMemberIds->all() !== $sellerOrderIds->map(fn ($id): int => (int) $id)->values()->all()) {
                throw new ConflictHttpException('The Shipment membership changed while COD collection was being recorded.');
            }

            if ($lockedShipment->purpose !== 'outbound' || (int) $lockedShipment->cod_amount_minor < 1) {
                throw ValidationException::withMessages(['cod_collected_amount' => 'This delivery has no COD cash due.']);
            }
            if ((int) $lockedShipment->logistics_profile_id !== (int) $rider->logistics_profile_id) {
                throw new AuthorizationException('This COD shipment belongs to another Logistics provider.');
            }

            $expectedMinor = (int) $lockedShipment->cod_amount_minor;
            if ($actualAmountMinor < 0 || $actualAmountMinor > 9_999_999_999) {
                throw ValidationException::withMessages(['cod_collected_amount' => 'Enter the actual cash collected in centavos.']);
            }

            $payments = Payment::query()
                ->whereIn('order_id', $orderIds)
                ->where('method', 'cod')
                ->where('status', '!=', 'cancelled')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('order_id');
            $allocations = $sellerOrders->map(function (SellerOrder $sellerOrder) use ($orders, $payments): array {
                $order = $orders->get($sellerOrder->order_id);
                $orderPayments = $payments->get($sellerOrder->order_id, collect());
                if ($orderPayments->count() > 1) {
                    throw new ConflictHttpException('A marketplace order has more than one active COD payment.');
                }
                $payment = $orderPayments->first();
                if (! $order) {
                    throw new ConflictHttpException('A marketplace order in this Shipment is unavailable.');
                }
                if (! $payment) {
                    return [
                        'seller_order' => $sellerOrder,
                        'order' => $order,
                        'payment' => null,
                        'amount_due_minor' => 0,
                    ];
                }
                if (! in_array($payment->status, ['pending', 'paid'], true)) {
                    throw new ConflictHttpException('A marketplace COD payment in this Shipment is not collectible.');
                }

                return [
                    'seller_order' => $sellerOrder,
                    'order' => $order,
                    'payment' => $payment,
                    'amount_due_minor' => $this->codAmountDueMinor($sellerOrder),
                ];
            })->filter(fn (array $allocation): bool => $allocation['payment'] !== null
                && $allocation['amount_due_minor'] > 0)
                ->values();
            if ($allocations->isEmpty()) {
                throw new ConflictHttpException('This Shipment has no unpaid COD payment allocation.');
            }
            $expectedMinor = (int) $allocations->sum('amount_due_minor');
            if ($expectedMinor !== (int) $lockedShipment->cod_amount_minor || $expectedMinor < 1) {
                throw new ConflictHttpException('The Shipment COD total no longer matches its Seller Order payment allocations.');
            }

            $existing = CodCashCollection::query()
                ->where('shipment_id', $lockedShipment->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((int) $existing->amount_collected_minor === $actualAmountMinor
                    && (int) $existing->rider_profile_id === (int) $rider->id) {
                    return $existing;
                }
                throw new ConflictHttpException('COD cash collection has already been recorded for this shipment.');
            }
            if ($allocations->contains(fn (array $allocation): bool => $allocation['payment']->status !== 'pending')) {
                throw new ConflictHttpException('A paid COD payment cannot accept a new cash collection record.');
            }

            $collectedAt = now();
            $discrepancyMinor = $actualAmountMinor - $expectedMinor;
            $primary = $allocations->first();
            $collection = CodCashCollection::query()->create([
                'collection_no' => 'COD-'.Str::upper((string) Str::ulid()),
                'shipment_id' => $lockedShipment->id,
                'payment_id' => $primary['payment']->id,
                'order_id' => $primary['order']->id,
                'seller_order_id' => $primary['seller_order']->id,
                'logistics_profile_id' => $rider->logistics_profile_id,
                'rider_profile_id' => $rider->id,
                'amount_due_minor' => $expectedMinor,
                'amount_collected_minor' => $actualAmountMinor,
                'discrepancy_minor' => $discrepancyMinor,
                'status' => $discrepancyMinor === 0 ? 'collected' : 'disputed',
                'collected_at' => $collectedAt,
                'collected_by' => $riderUser->id,
            ]);

            foreach ($allocations as $allocation) {
                CodCashCollectionAllocation::query()->create([
                    'cod_cash_collection_id' => $collection->id,
                    'payment_id' => $allocation['payment']->id,
                    'order_id' => $allocation['order']->id,
                    'seller_order_id' => $allocation['seller_order']->id,
                    'amount_due_minor' => $allocation['amount_due_minor'],
                    'amount_collected_minor' => $discrepancyMinor === 0 ? $allocation['amount_due_minor'] : null,
                ]);
            }

            $this->event(
                $collection,
                $discrepancyMinor === 0 ? 'cash_collected_from_buyer' : 'cash_collection_discrepancy_recorded',
                null,
                null,
                'rider',
                $riderUser->id,
                $riderUser,
                $actualAmountMinor,
                null,
                $discrepancyMinor === 0
                    ? 'Rider recorded exact COD cash received at Buyer delivery.'
                    : 'Rider recorded actual COD cash collected; difference from the amount due is held for Finance review.',
                $collectedAt,
            );

            $this->notifyCollectionRecorded($collection, $riderUser, $discrepancyMinor);

            return $collection->fresh(['shipment', 'payment', 'allocations.payment', 'allocations.sellerOrder', 'events']);
        }, attempts: 3);
    }

    public function receiveAtLogistics(User $logisticsUser, int $collectionId, int $receivedAmountMinor): CodCashCollection
    {
        return DB::transaction(function () use ($logisticsUser, $collectionId, $receivedAmountMinor): CodCashCollection {
            $profile = $logisticsUser->logisticsProfile()->first();
            if ($logisticsUser->role !== UserRole::Logistics->value
                || $logisticsUser->status !== AccountStatus::Active->value
                || ! $profile) {
                throw new AuthorizationException('An active Logistics account is required to receive COD cash.');
            }

            $collection = CodCashCollection::query()->whereKey($collectionId)->lockForUpdate()->firstOrFail();
            $this->assertProvider($collection, $profile->id);
            $this->assertActualAmount($collection, $receivedAmountMinor, 'received_amount');

            if ($collection->logistics_reference !== null
                || $collection->logistics_received_at !== null
                || $collection->logistics_received_by !== null) {
                if ($collection->logistics_reference !== null
                    && (int) $collection->amount_received_by_logistics_minor === $receivedAmountMinor
                    && (int) $collection->logistics_received_by === (int) $logisticsUser->id) {
                    return $collection;
                }
                throw new ConflictHttpException('This COD collection has already been acknowledged by Logistics.');
            }
            if (! in_array($collection->status, ['collected', 'disputed'], true)) {
                throw new ConflictHttpException('Only Rider-recorded COD cash can be received by Logistics.');
            }

            $now = now();
            // Derive the handoff reference from the collection's unique number so it is unique without another schema constraint.
            $reference = 'CODH-'.Str::upper(Str::replaceFirst('COD-', '', $collection->collection_no));
            $collection->update([
                'amount_received_by_logistics_minor' => $receivedAmountMinor,
                'status' => 'received_by_logistics',
                'logistics_reference' => $reference,
                'logistics_received_at' => $now,
                'logistics_received_by' => $logisticsUser->id,
            ]);
            $this->event(
                $collection,
                $collection->discrepancy_minor === 0 ? 'cash_received_by_logistics' : 'disputed_cash_received_by_logistics',
                'rider',
                (int) $collection->collected_by,
                'logistics',
                $logisticsUser->id,
                $logisticsUser,
                $receivedAmountMinor,
                $reference,
                $collection->discrepancy_minor === 0 ? null : 'Cash handoff acknowledges actual cash; the amount due discrepancy remains unresolved.',
                $now,
            );

            return $collection->fresh(['events']);
        }, attempts: 3);
    }

    public function remitToFinance(User $logisticsUser, int $collectionId, int $amountMinor): CodCashCollection
    {
        return DB::transaction(function () use ($logisticsUser, $collectionId, $amountMinor): CodCashCollection {
            $profile = $logisticsUser->logisticsProfile()->first();
            if ($logisticsUser->role !== UserRole::Logistics->value
                || $logisticsUser->status !== AccountStatus::Active->value
                || ! $profile) {
                throw new AuthorizationException('An active Logistics account is required to remit COD cash.');
            }

            $collection = CodCashCollection::query()->whereKey($collectionId)->lockForUpdate()->firstOrFail();
            $this->assertProvider($collection, $profile->id);
            $this->assertActualAmount($collection, $amountMinor, 'amount');
            if ($collection->status === 'remitted_to_finance') {
                if ((int) $collection->amount_remitted_to_finance_minor === $amountMinor
                    && (int) $collection->remitted_to_finance_by === (int) $logisticsUser->id) {
                    return $collection;
                }
                throw new ConflictHttpException('This COD collection has already been remitted to Finance.');
            }
            if ($collection->status !== 'received_by_logistics') {
                throw new ConflictHttpException('Logistics must acknowledge the Rider handoff before remitting COD cash.');
            }

            $now = now();
            // Derive a separate handoff reference from the unique collection number.
            $reference = 'L2F-'.Str::upper(Str::replaceFirst('COD-', '', $collection->collection_no));
            $collection->update([
                'amount_remitted_to_finance_minor' => $amountMinor,
                'status' => 'remitted_to_finance',
                'finance_remittance_reference' => $reference,
                'remitted_to_finance_at' => $now,
                'remitted_to_finance_by' => $logisticsUser->id,
            ]);
            $this->event($collection, 'cash_remitted_to_finance', 'logistics', (int) $collection->logistics_received_by, 'admin', null, $logisticsUser, $amountMinor, $reference, null, $now);
            $this->notifyFinanceOfRemittance($collection, $logisticsUser);

            return $collection->fresh(['events']);
        }, attempts: 3);
    }

    private function notifyCollectionRecorded(CodCashCollection $collection, User $riderUser, int $discrepancyMinor): void
    {
        $providerUser = $collection->logisticsProfile?->user;
        $amount = '₱'.number_format(((int) $collection->amount_collected_minor) / 100, 2);

        if ($providerUser) {
            $this->notifications->sendAfterCommit($providerUser, new WorkflowNotification(
                eventKey: 'logistics_cod_collected',
                title: 'COD cash collected',
                body: "Rider {$riderUser->name} collected {$amount} for {$collection->collection_no}. Receive and remit the cash.",
                actionUrl: route('logistics.cash.index'),
                type: 'info',
                meta: ['collection_id' => (int) $collection->id],
            ));
        }

        if ($discrepancyMinor !== 0) {
            $due = '₱'.number_format(((int) $collection->amount_due_minor) / 100, 2);

            $this->notifications->sendManyAfterCommit(
                $this->notifications->admins(),
                new WorkflowNotification(
                    eventKey: 'admin_cod_discrepancy',
                    title: 'COD collection discrepancy',
                    body: "{$collection->collection_no}: {$amount} was collected against {$due} due. The difference is held for Finance review.",
                    actionUrl: route('admin.payments'),
                    type: 'danger',
                    meta: ['collection_id' => (int) $collection->id],
                ),
            );
        }
    }

    private function notifyFinanceOfRemittance(CodCashCollection $collection, User $logisticsUser): void
    {
        $amount = '₱'.number_format(((int) $collection->amount_remitted_to_finance_minor) / 100, 2);

        $this->notifications->sendManyAfterCommit(
            $this->notifications->admins(),
            new WorkflowNotification(
                eventKey: 'admin_cod_remitted',
                title: 'COD cash remitted for reconciliation',
                body: "{$collection->collection_no} ({$amount}) was remitted through {$logisticsUser->name} and is waiting for Finance reconciliation.",
                actionUrl: route('admin.payments'),
                type: 'warning',
                meta: ['collection_id' => (int) $collection->id],
            ),
        );
    }

    public function reconcileAtFinance(User $admin, int $collectionId, int $receivedAmountMinor): CodCashCollection
    {
        $reconciledNow = false;
        $collection = DB::transaction(function () use ($admin, $collectionId, $receivedAmountMinor, &$reconciledNow): CodCashCollection {
            if ($admin->role !== UserRole::Admin->value || $admin->status !== AccountStatus::Active->value) {
                throw new AuthorizationException('An active Admin/Finance user is required to reconcile COD cash.');
            }

            $collectionStub = CodCashCollection::query()->whereKey($collectionId)->firstOrFail();
            $allocationStubs = CodCashCollectionAllocation::query()
                ->where('cod_cash_collection_id', $collectionId)
                ->orderBy('order_id')
                ->orderBy('seller_order_id')
                ->get(['order_id', 'seller_order_id', 'payment_id']);
            $orderIds = $allocationStubs->pluck('order_id')
                ->push($collectionStub->order_id)
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            $orders = Order::query()
                ->whereIn('id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($orders->count() !== $orderIds->count()) {
                throw new ConflictHttpException('A marketplace order in this COD collection is unavailable.');
            }

            // Finance capture may inspect other Seller Orders and outbound
            // shipments belonging to the same marketplace Order. Lock them in
            // the same deterministic parent-to-child order before Payment.
            $sellerOrders = SellerOrder::query()
                ->whereIn('order_id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sellerOrderIds = $sellerOrders->keys()->map(fn ($id): int => (int) $id)->values();
            $shipmentIds = DB::table('shipment_seller_orders')
                ->join('shipments', 'shipments.id', '=', 'shipment_seller_orders.shipment_id')
                ->whereIn('shipment_seller_orders.seller_order_id', $sellerOrderIds)
                ->where('shipments.purpose', 'outbound')
                ->orderBy('shipments.id')
                ->pluck('shipments.id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            if ($shipmentIds->isNotEmpty()) {
                Shipment::query()
                    ->whereIn('id', $shipmentIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }

            // Match Rider collection's lock order: parent commerce rows,
            // Shipment, Payment, collection, then allocations. Never hold the
            // collection while waiting on a Payment that collection recording
            // may already have locked.
            $paymentIds = $allocationStubs->pluck('payment_id')->unique()->sort()->values();
            $payments = Payment::query()
                ->whereIn('id', $paymentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($payments->count() !== $paymentIds->count()) {
                throw new ConflictHttpException('A marketplace COD payment in this collection is unavailable.');
            }

            $collection = CodCashCollection::query()->whereKey($collectionId)->lockForUpdate()->firstOrFail();
            $this->assertActualAmount($collection, $receivedAmountMinor, 'received_amount');

            if ($collection->status === 'reconciled') {
                if ((int) $collection->amount_reconciled_minor === $receivedAmountMinor
                    && (int) $collection->reconciled_by === (int) $admin->id) {
                    return $collection->fresh(['events']);
                }
                throw new ConflictHttpException('This COD collection has already been reconciled by Finance.');
            }
            if ($collection->status !== 'remitted_to_finance') {
                throw new ConflictHttpException('Logistics must record the remittance before Finance can reconcile COD cash.');
            }

            $now = now();
            // The collection primary key keeps Bearly-issued receipt numbers unique without another table or migration.
            $reference = 'BFR-'.$now->format('Y').'-'.str_pad((string) $collection->id, 8, '0', STR_PAD_LEFT);
            $collection->update([
                'amount_reconciled_minor' => $receivedAmountMinor,
                'status' => 'reconciled',
                'finance_reference' => $reference,
                'reconciled_at' => $now,
                'reconciled_by' => $admin->id,
            ]);
            $allocations = CodCashCollectionAllocation::query()
                ->where('cod_cash_collection_id', $collection->id)
                ->orderBy('order_id')
                ->orderBy('seller_order_id')
                ->lockForUpdate()
                ->get();
            if ($allocations->pluck('payment_id')->unique()->sort()->values()->all() !== $paymentIds->all()) {
                throw new ConflictHttpException('The COD allocation membership changed during Finance reconciliation.');
            }
            if ($receivedAmountMinor === (int) $collection->amount_due_minor) {
                foreach ($allocations as $allocation) {
                    $allocation->update([
                        'amount_collected_minor' => (int) $allocation->amount_due_minor,
                        'amount_reconciled_minor' => (int) $allocation->amount_due_minor,
                    ]);
                }

                foreach ($paymentIds as $paymentId) {
                    $payment = $payments->get($paymentId);
                    $order = $payment ? $orders->get($payment->order_id) : null;
                    if ($payment && $order && $payment->method === 'cod') {
                        $this->captureMarketplacePaymentWhenFullyCollected($order, $payment);
                    }
                }
            }
            $this->event($collection, 'cash_reconciled_by_finance', 'logistics', (int) $collection->remitted_to_finance_by, 'admin', $admin->id, $admin, $receivedAmountMinor, $reference, null, $now);
            $reconciledNow = true;

            return $collection->fresh(['events', 'allocations.payment', 'allocations.sellerOrder']);
        }, attempts: 3);

        if ($reconciledNow) {
            $this->refreshSellersAfterFinanceReconciliation($collection);
        }

        return $collection;
    }

    public function assertPaymentCaptured(Order $order): void
    {
        if (! $this->hasPaymentCaptured($order)) {
            throw new ConflictHttpException('The COD payment cannot be completed until Finance reconciles the exact amount due for every required cash collection.');
        }
    }

    public function hasPaymentCaptured(Order $order): bool
    {
        $payment = Payment::query()->where('order_id', $order->id)->where('method', 'cod')->first();
        if (! $payment || $payment->status !== 'paid' || ! $payment->paid_at) {
            return false;
        }

        $sellerOrders = $order->sellerOrders()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get();
        $codSellerOrders = $sellerOrders->filter(fn ($sellerOrder): bool => $this->codAmountDueMinor($sellerOrder) > 0);
        if ($codSellerOrders->isEmpty()
            || (int) $codSellerOrders->sum(fn ($sellerOrder): int => $this->codAmountDueMinor($sellerOrder)) !== (int) $payment->amount_minor) {
            return false;
        }

        foreach ($codSellerOrders as $sellerOrder) {
            $shipment = $sellerOrder->shipments()
                ->where('shipments.purpose', 'outbound')
                ->where('shipments.cod_amount_minor', '>', 0)
                ->first();
            if (! $shipment) {
                return false;
            }

            $allocation = CodCashCollectionAllocation::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('payment_id', $payment->id)
                ->whereHas('collection', fn ($query) => $query
                    ->where('shipment_id', $shipment->id)
                    ->where('status', 'reconciled')
                    ->whereColumn('amount_reconciled_minor', 'amount_due_minor'))
                ->first();
            $due = $this->codAmountDueMinor($sellerOrder);
            if (! $allocation
                || (int) $allocation->amount_due_minor !== $due
                || (int) $allocation->amount_collected_minor !== $due
                || (int) $allocation->amount_reconciled_minor !== $due) {
                return false;
            }
        }

        return true;
    }

    public function codCollectionsForFinance()
    {
        return CodCashCollection::query()
            ->with([
                'order',
                'sellerOrder.store',
                'sellerOrder.items.product.images.variant',
                'sellerOrder.items.variant',
                'allocations.order',
                'allocations.payment',
                'allocations.sellerOrder.store',
                'allocations.sellerOrder.items.product.images.variant',
                'allocations.sellerOrder.items.variant',
                'logisticsProfile',
                'riderProfile.user',
            ])
            ->whereIn('status', ['collected', 'disputed', 'received_by_logistics', 'remitted_to_finance', 'reconciled'])
            ->orderByDesc('collected_at')
            ->get();
    }

    public function statusVersion(?Collection $collections = null): string
    {
        if ($collections !== null) {
            return $this->statusVersionForRows($collections);
        }

        return $this->statusVersionFor(CodCashCollection::query()
            ->whereIn('status', ['collected', 'disputed', 'received_by_logistics', 'remitted_to_finance', 'reconciled']));
    }

    public function reconciledStatusVersionForStore(int $storeId): string
    {
        if ($storeId < 1) {
            return hash('sha256', '');
        }

        return $this->statusVersionFor(CodCashCollection::query()
            ->where('status', 'reconciled')
            ->whereHas('allocations.sellerOrder', fn ($orders) => $orders->where('store_id', $storeId)));
    }

    private function statusVersionFor(Builder $query): string
    {
        return $this->statusVersionForRows($query
            ->orderBy('id')
            ->get(['id', 'status', 'updated_at']));
    }

    private function statusVersionForRows(Collection $collections): string
    {
        $state = $collections
            ->sortBy('id')
            ->map(fn (CodCashCollection $collection): string => implode(':', [
                (string) $collection->id,
                (string) $collection->status,
                $collection->updated_at?->format('Y-m-d H:i:s.u') ?? '',
            ]))
            ->implode('|');

        return hash('sha256', $state);
    }

    private function refreshSellersAfterFinanceReconciliation(CodCashCollection $collection): void
    {
        $collection->loadMissing('allocations.sellerOrder.store.sellerProfile.user');

        foreach ($collection->allocations as $allocation) {
            $sellerOrder = $allocation->sellerOrder;
            $sellerProfile = $sellerOrder?->store?->sellerProfile;
            $seller = $sellerProfile?->user;

            if (! $sellerOrder || ! $sellerProfile || ! $seller) {
                continue;
            }

            try {
                $this->sellerWallets->refreshForSeller($sellerProfile);
            } catch (Throwable $exception) {
                Log::error('Seller wallet refresh failed after Finance reconciled COD cash.', [
                    'cod_cash_collection_id' => (int) $collection->id,
                    'seller_profile_id' => (int) $sellerProfile->id,
                    'exception' => $exception::class,
                ]);
            }

            $this->notifications->send($seller, new WorkflowNotification(
                eventKey: 'seller_cod_cash_reconciled',
                title: 'COD cash reconciled by Finance',
                body: "Finance reconciled the COD cash for {$sellerOrder->seller_order_no}. Earnings remain subject to the return window and other settlement checks.",
                actionUrl: route('seller.finance.wallet'),
                type: 'success',
                meta: [
                    'seller_order_id' => (int) $sellerOrder->id,
                    'cod_collection_id' => (int) $collection->id,
                ],
            ));
        }
    }

    private function captureMarketplacePaymentWhenFullyCollected(Order $order, Payment $payment): void
    {
        if ($payment->method !== 'cod' || $payment->status === 'paid') {
            return;
        }

        $sellerOrders = $order->sellerOrders()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $codSellerOrders = $sellerOrders->filter(fn ($sellerOrder): bool => $this->codAmountDueMinor($sellerOrder) > 0);
        $expectedMinor = (int) $codSellerOrders->sum(fn ($sellerOrder): int => $this->codAmountDueMinor($sellerOrder));
        if ($codSellerOrders->isEmpty() || $expectedMinor !== (int) $payment->amount_minor) {
            return;
        }

        foreach ($codSellerOrders as $sellerOrder) {
            $shipment = $sellerOrder->shipments()
                ->where('shipments.purpose', 'outbound')
                ->where('shipments.cod_amount_minor', '>', 0)
                ->lockForUpdate()
                ->first();
            if (! $shipment || (int) $shipment->cod_amount_minor < $this->codAmountDueMinor($sellerOrder)) {
                return;
            }

            $allocation = CodCashCollectionAllocation::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('payment_id', $payment->id)
                ->whereHas('collection', fn ($query) => $query
                    ->where('shipment_id', $shipment->id)
                    ->where('status', 'reconciled')
                    ->whereColumn('amount_reconciled_minor', 'amount_due_minor'))
                ->lockForUpdate()
                ->first();
            $due = $this->codAmountDueMinor($sellerOrder);
            if (! $allocation
                || (int) $allocation->amount_due_minor !== $due
                || (int) $allocation->amount_collected_minor !== $due
                || (int) $allocation->amount_reconciled_minor !== $due) {
                return;
            }
        }

        $paidAt = CodCashCollection::query()
            ->whereHas('allocations', fn ($query) => $query
                ->where('payment_id', $payment->id)
                ->whereIn('seller_order_id', $codSellerOrders->pluck('id')))
            ->max('reconciled_at') ?? now();
        $payment->update(['status' => 'paid', 'paid_at' => $paidAt]);
        $order->update(['payment_status' => 'paid']);
        $this->promotions->markOrderPaid($order);
        $this->commissionLedger->finalize($payment->fresh());
    }

    private function codAmountDueMinor(\App\Models\SellerOrder $sellerOrder): int
    {
        return max(0, (int) $sellerOrder->total_minor
            - (int) ($sellerOrder->platform_discount_minor ?? 0)
            - (int) ($sellerOrder->campaign_platform_discount_minor ?? 0)
            - (int) ($sellerOrder->platform_shipping_discount_minor ?? 0));
    }

    private function assertProvider(CodCashCollection $collection, int $logisticsProfileId): void
    {
        if ((int) $collection->logistics_profile_id !== $logisticsProfileId) {
            throw new AuthorizationException('This COD cash belongs to another Logistics provider.');
        }
    }

    private function assertActualAmount(CodCashCollection $collection, int $actualMinor, string $field): void
    {
        if ($actualMinor !== (int) $collection->amount_collected_minor) {
            throw ValidationException::withMessages([
                $field => 'The count must match the Rider-recorded cash amount of ₱'.number_format((int) $collection->amount_collected_minor / 100, 2).'.',
            ]);
        }
    }

    private function event(
        CodCashCollection $collection,
        string $type,
        ?string $fromRole,
        ?int $fromUserId,
        ?string $toRole,
        ?int $toUserId,
        User $actor,
        int $amountMinor,
        ?string $reference,
        ?string $note,
        mixed $occurredAt,
    ): void {
        CodCashCollectionEvent::query()->create([
            'cod_cash_collection_id' => $collection->id,
            'event_type' => $type,
            'from_role' => $fromRole,
            'from_user_id' => $fromUserId,
            'to_role' => $toRole,
            'to_user_id' => $toUserId,
            'actor_user_id' => $actor->id,
            'amount_minor' => $amountMinor,
            'reference' => $reference,
            'note' => $note,
            'occurred_at' => $occurredAt,
        ]);
    }
}
