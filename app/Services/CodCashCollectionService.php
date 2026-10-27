<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\CodCashCollection;
use App\Models\CodCashCollectionEvent;
use App\Models\Order;
use App\Models\Payment;
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
            $lockedShipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $rider = $riderUser->riderProfile()->first();

            if ($riderUser->role !== UserRole::Rider->value
                || $riderUser->status !== AccountStatus::Active->value
                || ! $rider
                || $rider->verification_status !== 'approved') {
                throw new AuthorizationException('An active, approved Rider is required to record COD cash.');
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

            $sellerOrder = $lockedShipment->sellerOrder()->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($sellerOrder->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()
                ->where('order_id', $order->id)
                ->where('method', 'cod')
                ->lockForUpdate()
                ->first();

            if (! $payment || ! in_array($payment->status, ['pending', 'paid'], true)) {
                throw new ConflictHttpException('The marketplace COD payment is not collectible.');
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
            if ($payment->status !== 'pending') {
                throw new ConflictHttpException('A paid COD order cannot accept a new cash collection record.');
            }

            $collectedAt = now();
            $discrepancyMinor = $actualAmountMinor - $expectedMinor;
            $collection = CodCashCollection::query()->create([
                'collection_no' => 'COD-'.Str::upper((string) Str::ulid()),
                'shipment_id' => $lockedShipment->id,
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'seller_order_id' => $sellerOrder->id,
                'logistics_profile_id' => $rider->logistics_profile_id,
                'rider_profile_id' => $rider->id,
                'amount_due_minor' => $expectedMinor,
                'amount_collected_minor' => $actualAmountMinor,
                'discrepancy_minor' => $discrepancyMinor,
                'status' => $discrepancyMinor === 0 ? 'collected' : 'disputed',
                'collected_at' => $collectedAt,
                'collected_by' => $riderUser->id,
            ]);

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

            if ($discrepancyMinor === 0) {
                $this->captureMarketplacePaymentWhenFullyCollected($order, $payment);
            }

            return $collection->fresh(['shipment', 'payment', 'events']);
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
            $this->event($collection, 'cash_reconciled_by_finance', 'logistics', (int) $collection->remitted_to_finance_by, 'admin', $admin->id, $admin, $receivedAmountMinor, $reference, null, $now);
            $reconciledNow = true;

            return $collection->fresh(['events']);
        }, attempts: 3);

        if ($reconciledNow) {
            $this->refreshSellerAfterFinanceReconciliation($collection);
        }

        return $collection;
    }

    public function assertPaymentCaptured(Order $order): void
    {
        if (! $this->hasPaymentCaptured($order)) {
            throw new ConflictHttpException('The COD payment cannot be completed until every required Rider cash collection is recorded.');
        }
    }

    public function hasPaymentCaptured(Order $order): bool
    {
        $payment = Payment::query()->where('order_id', $order->id)->where('method', 'cod')->first();
        if (! $payment || $payment->status !== 'paid' || ! $payment->paid_at) {
            return false;
        }

        $expectedShipmentIds = Shipment::query()
            ->where('purpose', 'outbound')
            ->where('cod_amount_minor', '>', 0)
            ->whereHas('sellerOrder', fn ($query) => $query
                ->where('order_id', $order->id)
                ->where('status', '!=', 'cancelled'))
            ->pluck('id');
        $collections = CodCashCollection::query()->whereIn('shipment_id', $expectedShipmentIds)->get();

        return $expectedShipmentIds->isNotEmpty()
            && $collections->count() === $expectedShipmentIds->count()
            && ! $collections->contains(fn (CodCashCollection $collection): bool => ! in_array($collection->status, ['collected', 'received_by_logistics', 'remitted_to_finance', 'reconciled'], true)
                || (int) $collection->amount_due_minor !== (int) $collection->amount_collected_minor)
            && (int) $collections->sum('amount_collected_minor') === (int) $payment->amount_minor;
    }

    public function codCollectionsForFinance()
    {
        return CodCashCollection::query()
            ->with([
                'order',
                'sellerOrder.store',
                'sellerOrder.items.product.images.variant',
                'sellerOrder.items.variant',
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
            ->whereHas('sellerOrder', fn ($orders) => $orders->where('store_id', $storeId)));
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

    private function refreshSellerAfterFinanceReconciliation(CodCashCollection $collection): void
    {
        $collection->loadMissing('sellerOrder.store.sellerProfile.user');
        $sellerOrder = $collection->sellerOrder;
        $sellerProfile = $sellerOrder?->store?->sellerProfile;
        $seller = $sellerProfile?->user;

        if (! $sellerOrder || ! $sellerProfile || ! $seller) {
            return;
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

    private function captureMarketplacePaymentWhenFullyCollected(Order $order, Payment $payment): void
    {
        $expectedShipments = Shipment::query()
            ->where('purpose', 'outbound')
            ->where('cod_amount_minor', '>', 0)
            ->whereHas('sellerOrder', fn ($query) => $query
                ->where('order_id', $order->id)
                ->where('status', '!=', 'cancelled'))
            ->lockForUpdate()
            ->get(['id', 'cod_amount_minor']);

        $collections = CodCashCollection::query()
            ->whereIn('shipment_id', $expectedShipments->pluck('id'))
            ->lockForUpdate()
            ->get();

        $expectedMinor = (int) $expectedShipments->sum('cod_amount_minor');
        $collectedMinor = (int) $collections->sum('amount_collected_minor');
        if ($expectedShipments->isEmpty()
            || $expectedMinor !== (int) $payment->amount_minor
            || $collectedMinor > (int) $payment->amount_minor) {
            return;
        }

        if ($collections->count() !== $expectedShipments->count()
            || $collections->contains(fn (CodCashCollection $collection): bool => $collection->status !== 'collected'
                || (int) $collection->amount_due_minor !== (int) $collection->amount_collected_minor)
            || $collectedMinor !== (int) $payment->amount_minor) {
            return;
        }
        if ($payment->status === 'paid') {
            return;
        }

        $paidAt = $collections->max('collected_at') ?? now();
        $payment->update(['status' => 'paid', 'paid_at' => $paidAt]);
        $order->update(['payment_status' => 'paid']);
        $this->promotions->markOrderPaid($order);
        $this->commissionLedger->finalize($payment->fresh());
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
