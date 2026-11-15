<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\ReturnRequestStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\RiderShippingEarningUpdated;
use App\Models\DeliveryAttempt;
use App\Models\LogisticsPayoutAccount;
use App\Models\LogisticsProfile;
use App\Models\RiderEarning;
use App\Models\RiderEarningPayout;
use App\Models\RiderPayoutBatchItem;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\Refund;
use App\Models\ReturnEvent;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTransfer;
use App\Models\ShippingSettlement;
use App\Models\ShippingSettlementAllocation;
use App\Models\ShippingSettlementOriginRemittance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class ShippingSettlementService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SellerWalletService $wallets,
    ) {}

    /**
     * Materialize outbound allocations through the shared Seller settlement
     * gate and return allocations after verified Seller delivery. The unique
     * shipment constraint makes concurrent Finance/Rider page reads idempotent.
     */
    public function materializeEligibleSettlements(?int $riderProfileId = null, ?int $logisticsProfileId = null): void
    {
        $query = Shipment::query()
            ->where('purpose', 'outbound')
            ->where('status', ShipmentStatus::Delivered->value)
            ->whereNotNull('delivered_at')
            ->whereDoesntHave('shippingSettlement')
            ->whereHas('sellerOrder', fn ($query) => $query
                ->where('status', SellerOrderStatus::Completed->value)
                ->where('shipping_quote->pricing_rule', 'zone_based_v1'));

        if ($riderProfileId !== null) {
            $query->whereHas('events', fn ($events) => $events
                ->where('event_type', 'shipment_delivered')
                ->where('metadata->rider_profile_id', $riderProfileId));
        }
        if ($logisticsProfileId !== null) {
            $query->where(function ($providers) use ($logisticsProfileId): void {
                $providers->where('logistics_profile_id', $logisticsProfileId)
                    ->orWhere('origin_logistics_profile_id', $logisticsProfileId);
            });
        }

        $shipmentIds = $query->orderBy('id')->pluck('id');

        foreach ($shipmentIds as $shipmentId) {
            $this->materializeForShipment((int) $shipmentId);
        }

        $returnQuery = Shipment::query()
            ->where('purpose', 'return')
            ->where('status', ShipmentStatus::Delivered->value)
            ->whereNotNull('delivered_at')
            ->whereNotNull('return_shipping_quote')
            ->whereDoesntHave('shippingSettlement')
            ->whereHas('returnRequest', fn ($query) => $query
                ->where('request_type', 'return_refund')
                ->whereNotNull('return_shipment_id')
                ->whereIn('status', [
                    ReturnRequestStatus::AwaitingInspection->value,
                    ReturnRequestStatus::RefundPending->value,
                    ReturnRequestStatus::Resolved->value,
                ]));

        if ($riderProfileId !== null) {
            $returnQuery->whereHas('events', fn ($events) => $events
                ->where('event_type', 'shipment_delivered')
                ->where('metadata->rider_profile_id', $riderProfileId));
        }
        if ($logisticsProfileId !== null) {
            $returnQuery->where('logistics_profile_id', $logisticsProfileId);
        }

        foreach ($returnQuery->orderBy('id')->pluck('id') as $shipmentId) {
            $this->materializeForReturnShipment((int) $shipmentId);
        }
    }

    /** @return Collection<int, ShippingSettlement> */
    public function financeSettlements(): Collection
    {
        $this->materializeEligibleSettlements();

        return ShippingSettlement::query()
            ->with([
                'sellerOrder.order',
                'sellerOrder.store',
                'sellerOrder.items.product.images.variant',
                'sellerOrder.items.variant',
                'allocations.sellerOrder.items.product.images.variant',
                'allocations.sellerOrder.items.variant',
                'shipment.sellerOrders.store',
                'shipment.sellerOrders.items.product.images.variant',
                'shipment.sellerOrders.items.variant',
                'returnRequest.refunds',
                'returnRequest.items.orderItem.product.images.variant',
                'returnRequest.items.orderItem.variant',
                'shipment',
                'logisticsProfile',
                'originLogisticsProfile',
                'originRemittance.logisticsProfile',
                'originRemittance.logisticsPayoutAccount',
                'originRemittance.remitter',
                'riderProfile.user',
                'deliveryAttempt',
                'remitter',
                'riderEarning.payout',
            ])
            ->orderByDesc('eligible_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, RiderEarning> */
    public function riderEarningsForLogistics(User $user, bool $materialize = true): Collection
    {
        $profile = $this->logisticsProfile($user);
        if ($materialize) {
            $this->materializeEligibleSettlements(logisticsProfileId: (int) $profile->id);
        }

        return RiderEarning::query()
            ->where('logistics_profile_id', $profile->id)
            ->where(function ($query): void {
                $query->whereNotNull('shipping_settlement_id')
                    ->orWhereNotNull('pickup_assignment_id');
            })
            ->with([
                'riderProfile.user',
                'shipment.sellerOrder.order',
                'shipment.sellerOrder.items.product.images.variant',
                'shipment.sellerOrder.items.variant',
                'shipment.sellerOrders.items.product.images.variant',
                'shipment.sellerOrders.items.variant',
                'pickupAssignment.pickupRequest',
                'parcel',
                'shippingSettlement',
                'shippingSettlement.originLogisticsProfile',
                'shippingSettlement.originRemittance.logisticsProfile',
                'shippingSettlement.returnRequest.order',
                'shippingSettlement.returnRequest.refunds',
                'shippingSettlement.returnRequest.items.orderItem.product.images.variant',
                'shippingSettlement.returnRequest.items.orderItem.variant',
                'payout.recorder',
                'payoutBatchItem.batch',
            ])
            ->orderByRaw("CASE status WHEN 'posted' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('earned_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return array{total_minor:int,pending_minor:int,remitted_minor:int} */
    public function logisticsEarningsSummary(User $user, bool $materialize = true): array
    {
        $profile = $this->logisticsProfile($user);
        if ($materialize) {
            $this->materializeEligibleSettlements(logisticsProfileId: (int) $profile->id);
        }

        $finalSettlements = ShippingSettlement::query()
            ->where('logistics_profile_id', $profile->id);
        $originRemittances = ShippingSettlementOriginRemittance::query()
            ->where('logistics_profile_id', $profile->id);

        return [
            'total_minor' => (int) (clone $finalSettlements)->sum('logistics_share_minor')
                + (int) (clone $originRemittances)->sum('amount_minor'),
            'pending_minor' => (int) (clone $finalSettlements)->whereNull('remitted_at')->sum('logistics_share_minor')
                + (int) (clone $originRemittances)->where('status', 'pending')->sum('amount_minor'),
            'remitted_minor' => (int) (clone $finalSettlements)->whereNotNull('remitted_at')->sum('logistics_share_minor')
                + (int) (clone $originRemittances)->where('status', 'remitted')->sum('amount_minor'),
        ];
    }

    /** @return Collection<int, ShippingSettlementOriginRemittance> */
    public function originRemittancesForLogistics(User $user): Collection
    {
        $profile = $this->logisticsProfile($user);

        return ShippingSettlementOriginRemittance::query()
            ->where('logistics_profile_id', $profile->id)
            ->with([
                'logisticsProfile',
                'remitter',
                'settlement.shipment',
                'settlement.sellerOrder.store',
                'settlement.logisticsProfile',
            ])
            ->latest('id')
            ->get();
    }

    public function riderEarningStatusVersionForRider(RiderProfile $rider): string
    {
        return $this->statusVersionForEarnings(
            RiderEarning::query()
                ->where('rider_profile_id', $rider->id)
                ->where(function ($query): void {
                    $query->whereNotNull('shipping_settlement_id')
                        ->orWhereNotNull('pickup_assignment_id')
                        ->orWhere('type', config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily'));
                }),
        );
    }

    public function riderEarningStatusVersionForLogistics(User $user, bool $materialize = true): string
    {
        $profile = $this->logisticsProfile($user);
        if ($materialize) {
            $this->materializeEligibleSettlements(logisticsProfileId: (int) $profile->id);
        }

        $earningVersion = $this->statusVersionForEarnings(
            RiderEarning::query()
                ->where('logistics_profile_id', $profile->id)
                ->where(function ($query): void {
                    $query->whereNotNull('shipping_settlement_id')
                        ->orWhereNotNull('pickup_assignment_id')
                        ->orWhere('type', config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily'));
                }),
        );
        $originVersion = ShippingSettlementOriginRemittance::query()
            ->where('logistics_profile_id', $profile->id)
            ->select('status')
            ->selectRaw('COUNT(*) as entry_count')
            ->selectRaw('COALESCE(SUM(amount_minor), 0) as amount_minor')
            ->selectRaw('MAX(id) as latest_id')
            ->selectRaw('MAX(updated_at) as latest_updated_at')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): string => implode(':', [
                (string) $row->status,
                (string) $row->entry_count,
                (string) $row->amount_minor,
                (string) $row->latest_id,
                (string) $row->latest_updated_at,
            ]))
            ->implode('|');

        return hash('sha256', $earningVersion.'|'.$originVersion);
    }

    public function recordLogisticsRemittance(
        User $admin,
        int $settlementId,
        int $actualAmountMinor,
        string $method,
        ?int $payoutAccountId,
        string $reference,
        string $paidOn,
        string $reason,
        Request $request,
        string $recipient = 'final',
    ): ShippingSettlement {
        $this->assertAdmin($admin);
        if ((int) $request->user()?->id !== (int) $admin->id) {
            throw new AuthorizationException('The Finance audit actor does not match the authenticated user.');
        }
        $this->validatePayoutDetails($actualAmountMinor, $method, $reference, $paidOn, $reason);
        $paidDate = $this->paidDate($paidOn);

        if (! in_array($recipient, ['origin', 'final'], true)) {
            throw ValidationException::withMessages(['recipient' => 'Choose a valid Logistics remittance recipient.']);
        }

        $stub = ShippingSettlement::query()->findOrFail($settlementId);
        if ($stub->settlement_type === 'return') {
            if ($recipient !== 'final') {
                throw new ConflictHttpException('Return-shipping settlements keep their existing single-provider remittance.');
            }

            return $this->recordReturnLogisticsRemittance(
                $admin,
                $stub,
                $actualAmountMinor,
                $method,
                $payoutAccountId,
                $reference,
                $paidDate,
                $reason,
                $request,
            );
        }

        if ($recipient === 'origin') {
            return $this->recordOriginLogisticsRemittance(
                $admin,
                $stub,
                $actualAmountMinor,
                $method,
                $payoutAccountId,
                $reference,
                $paidDate,
                $reason,
                $request,
            );
        }

        $sellerOrderId = (int) $stub->seller_order_id;

        return DB::transaction(function () use ($admin, $settlementId, $sellerOrderId, $actualAmountMinor, $method, $payoutAccountId, $reference, $paidDate, $reason, $request): ShippingSettlement {
            $sellerOrder = SellerOrder::query()->whereKey($sellerOrderId)->first();
            $settlementStub = ShippingSettlement::query()->whereKey($settlementId)->firstOrFail();
            $eligible = $sellerOrder !== null && $this->settlementEligibleForSellerWallet($settlementStub, $sellerOrder);
            $settlement = ShippingSettlement::query()->whereKey($settlementId)->lockForUpdate()->firstOrFail();
            $expectedAmount = $settlement->expectedFinalLogisticsRemittanceMinor();
            $expectedTotal = $expectedAmount + ($settlement->isInterHub()
                ? (int) $settlement->origin_logistics_share_minor
                : 0);

            if ($actualAmountMinor !== $expectedAmount
                || $expectedTotal !== (int) $settlement->original_shipping_fee_minor
                || ($settlement->isInterHub() && $expectedAmount < 1)) {
                throw ValidationException::withMessages([
                    'actual_amount' => 'Record the exact remittance of ₱'.number_format($expectedAmount / 100, 2).' to the destination Logistics provider.',
                ]);
            }

            if ($settlement->remitted_at !== null) {
                $referenceMatches = $method === 'cash'
                    ? filled($settlement->remittance_reference)
                    : hash_equals((string) $settlement->remittance_reference, trim($reference));
                if ((int) $settlement->remitted_amount_minor === $actualAmountMinor
                    && $settlement->remittance_method === $method
                    && (int) ($settlement->logistics_payout_account_id ?? 0) === (int) ($payoutAccountId ?? 0)
                    && $referenceMatches
                    && $settlement->remitted_on?->toDateString() === $paidDate->toDateString()
                    && trim((string) $settlement->remittance_reason) === trim($reason)) {
                    return $settlement->fresh(['riderEarning', 'originRemittance']);
                }

                throw new ConflictHttpException('This destination Logistics remittance has already been recorded.');
            }
            if (! $eligible) {
                throw new ConflictHttpException('This shipping allocation is no longer settlement-eligible.');
            }
            if (! in_array($settlement->status, ['pending', 'partially_remitted'], true)) {
                throw new ConflictHttpException('Only an eligible, unpaid shipping allocation can be remitted.');
            }

            if ($settlement->isInterHub() && $method !== 'cash') {
                $originRemittance = ShippingSettlementOriginRemittance::query()
                    ->where('shipping_settlement_id', $settlement->id)
                    ->lockForUpdate()
                    ->first();
                if ($originRemittance?->status === 'remitted'
                    && hash_equals((string) $originRemittance->remittance_reference, trim($reference))) {
                    throw ValidationException::withMessages([
                        'reference' => 'Use the separate external transaction reference for the destination remittance.',
                    ]);
                }
            }

            $payoutAccount = $this->verifiedPayoutAccount($settlement, $method, $payoutAccountId);

            $earning = RiderEarning::query()
                ->where('shipping_settlement_id', $settlement->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $earning->logistics_profile_id !== (int) $settlement->logistics_profile_id
                || (int) $earning->rider_profile_id !== (int) $settlement->rider_profile_id
                || (int) $earning->amount_minor !== (int) $settlement->rider_share_minor) {
                throw new ConflictHttpException('The Rider earning no longer matches this shipping allocation.');
            }

            $remittanceReference = $method === 'cash'
                ? $this->cashRemittanceReference()
                : trim($reference);

            $oldValues = [
                'status' => $settlement->status,
                'remitted_amount_minor' => $settlement->remitted_amount_minor,
                'remittance_reference' => $settlement->remittance_reference,
            ];
            $settlement->update([
                'remitted_amount_minor' => $actualAmountMinor,
                'remittance_method' => $method,
                'remittance_reference' => $remittanceReference,
                'remitted_on' => $paidDate->toDateString(),
                'remitted_at' => now(),
                'remitted_by' => $admin->id,
                'remittance_reason' => trim($reason),
                'logistics_payout_account_id' => $payoutAccount?->id,
                'remitted_to_provider' => $payoutAccount?->provider_name,
                'remitted_to_account_name' => $payoutAccount?->account_name,
                'remitted_to_last4' => $payoutAccount?->destination_last4,
            ]);

            $this->refreshOutboundRemittanceState($settlement, $earning);
            $settlement->refresh();

            $this->audit->record(
                action: 'shipping_settlement.remitted_to_logistics',
                module: 'Finance',
                subject: $settlement,
                description: "Shipping settlement {$settlement->settlement_no} was recorded as remitted to Logistics.",
                oldValues: $oldValues,
                newValues: [
                    'status' => $settlement->status,
                    'remittance_recipient' => 'final',
                    'seller_order_id' => (int) $settlement->seller_order_id,
                    'shipment_id' => (int) $settlement->shipment_id,
                    'logistics_profile_id' => (int) $settlement->logistics_profile_id,
                    'rider_profile_id' => (int) $settlement->rider_profile_id,
                    'zone_level' => (int) $settlement->zone_level,
                    'original_shipping_fee_minor' => (int) $settlement->original_shipping_fee_minor,
                    'buyer_paid_shipping_fee_minor' => (int) $settlement->buyer_paid_shipping_fee_minor,
                    'platform_shipping_subsidy_minor' => (int) $settlement->platform_shipping_subsidy_minor,
                    'shipping_funding_source' => $settlement->shipping_funding_source,
                    'origin_logistics_profile_id' => $settlement->origin_logistics_profile_id,
                    'origin_logistics_share_minor' => (int) $settlement->origin_logistics_share_minor,
                    'logistics_share_minor' => (int) $settlement->logistics_share_minor,
                    'rider_share_minor' => (int) $settlement->rider_share_minor,
                    'amount_minor' => $actualAmountMinor,
                    'method' => $method,
                    'reference' => $remittanceReference,
                    'logistics_payout_account_id' => $payoutAccount?->id,
                    'remitted_to_provider' => $payoutAccount?->provider_name,
                    'remitted_to_last4' => $payoutAccount?->destination_last4,
                    'paid_on' => $paidDate->toDateString(),
                    'reason' => trim($reason),
                ],
                severity: 'warning',
                request: $request,
            );

            return $settlement->fresh(['riderEarning', 'originRemittance']);
        }, attempts: 3);
    }

    private function recordOriginLogisticsRemittance(
        User $admin,
        ShippingSettlement $stub,
        int $actualAmountMinor,
        string $method,
        ?int $payoutAccountId,
        string $reference,
        Carbon $paidDate,
        string $reason,
        Request $request,
    ): ShippingSettlement {
        $sellerOrderId = (int) $stub->seller_order_id;

        return DB::transaction(function () use ($admin, $stub, $sellerOrderId, $actualAmountMinor, $method, $payoutAccountId, $reference, $paidDate, $reason, $request): ShippingSettlement {
            $sellerOrder = SellerOrder::query()->whereKey($sellerOrderId)->first();
            $settlementStub = ShippingSettlement::query()->whereKey($stub->id)->firstOrFail();
            $eligible = $sellerOrder !== null && $this->settlementEligibleForSellerWallet($settlementStub, $sellerOrder);
            $settlement = ShippingSettlement::query()->whereKey($stub->id)->lockForUpdate()->firstOrFail();

            if (! $settlement->isInterHub()) {
                throw new ConflictHttpException('Only a snapshotted inter-hub outbound settlement has an origin-provider remittance.');
            }

            $originRemittance = ShippingSettlementOriginRemittance::query()
                ->where('shipping_settlement_id', $settlement->id)
                ->lockForUpdate()
                ->first();
            if (! $originRemittance
                || (int) $originRemittance->logistics_profile_id !== (int) $settlement->origin_logistics_profile_id
                || (int) $originRemittance->amount_minor !== (int) $settlement->origin_logistics_share_minor
                || (int) $originRemittance->amount_minor < 1) {
                throw new ConflictHttpException('The origin Logistics remittance does not match the immutable settlement allocation.');
            }

            $expectedAmount = (int) $settlement->origin_logistics_share_minor;
            if ($actualAmountMinor !== $expectedAmount) {
                throw ValidationException::withMessages([
                    'actual_amount' => 'Record the exact origin Logistics remittance of ₱'.number_format($expectedAmount / 100, 2).'.',
                ]);
            }

            if ($originRemittance->status === 'remitted') {
                $referenceMatches = $method === 'cash'
                    ? filled($originRemittance->remittance_reference)
                    : hash_equals((string) $originRemittance->remittance_reference, trim($reference));
                if ((int) $originRemittance->amount_minor === $actualAmountMinor
                    && $originRemittance->remittance_method === $method
                    && (int) ($originRemittance->logistics_payout_account_id ?? 0) === (int) ($payoutAccountId ?? 0)
                    && $referenceMatches
                    && $originRemittance->remitted_on?->toDateString() === $paidDate->toDateString()
                    && trim((string) $originRemittance->remittance_reason) === trim($reason)) {
                    return $settlement->fresh(['riderEarning', 'originRemittance']);
                }

                throw new ConflictHttpException('The origin Logistics remittance has already been recorded.');
            }

            if (! $eligible) {
                throw new ConflictHttpException('This shipping allocation is no longer settlement-eligible.');
            }
            if ($originRemittance->status !== 'pending' || $originRemittance->remitted_at !== null
                || ! in_array($settlement->status, ['pending', 'partially_remitted'], true)) {
                throw new ConflictHttpException('Only an eligible, unpaid origin Logistics allocation can be remitted.');
            }

            if ($method !== 'cash'
                && $settlement->remitted_at !== null
                && hash_equals((string) $settlement->remittance_reference, trim($reference))) {
                throw ValidationException::withMessages([
                    'reference' => 'Use the separate external transaction reference for the origin remittance.',
                ]);
            }

            $payoutAccount = $this->verifiedPayoutAccount(
                $settlement,
                $method,
                $payoutAccountId,
                (int) $originRemittance->logistics_profile_id,
            );
            $remittanceReference = $method === 'cash'
                ? $this->cashRemittanceReference()
                : trim($reference);
            $oldValues = [
                'status' => $settlement->status,
                'origin_remittance_status' => $originRemittance->status,
                'amount_minor' => (int) $originRemittance->amount_minor,
            ];

            $originRemittance->update([
                'status' => 'remitted',
                'remittance_method' => $method,
                'remittance_reference' => $remittanceReference,
                'remitted_on' => $paidDate->toDateString(),
                'remitted_at' => now(),
                'remitted_by' => $admin->id,
                'remittance_reason' => trim($reason),
                'logistics_payout_account_id' => $payoutAccount?->id,
                'remitted_to_provider' => $payoutAccount?->provider_name,
                'remitted_to_account_name' => $payoutAccount?->account_name,
                'remitted_to_last4' => $payoutAccount?->destination_last4,
            ]);

            $earning = RiderEarning::query()
                ->where('shipping_settlement_id', $settlement->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->refreshOutboundRemittanceState($settlement, $earning);
            $settlement->refresh();

            $this->audit->record(
                action: 'shipping_settlement.remitted_to_origin_logistics',
                module: 'Finance',
                subject: $originRemittance,
                description: "Origin Logistics share for settlement {$settlement->settlement_no} was recorded as remitted.",
                oldValues: $oldValues,
                newValues: [
                    'status' => $settlement->status,
                    'settlement_id' => (int) $settlement->id,
                    'settlement_no' => $settlement->settlement_no,
                    'shipment_id' => (int) $settlement->shipment_id,
                    'origin_logistics_profile_id' => (int) $originRemittance->logistics_profile_id,
                    'origin_logistics_share_minor' => (int) $originRemittance->amount_minor,
                    'destination_logistics_profile_id' => (int) $settlement->logistics_profile_id,
                    'rider_profile_id' => (int) $settlement->rider_profile_id,
                    'method' => $method,
                    'reference' => $remittanceReference,
                    'logistics_payout_account_id' => $payoutAccount?->id,
                    'remitted_to_provider' => $payoutAccount?->provider_name,
                    'remitted_to_last4' => $payoutAccount?->destination_last4,
                    'paid_on' => $paidDate->toDateString(),
                    'reason' => trim($reason),
                ],
                severity: 'warning',
                request: $request,
            );

            return $settlement->fresh(['riderEarning', 'originRemittance']);
        }, attempts: 3);
    }

    private function refreshOutboundRemittanceState(ShippingSettlement $settlement, RiderEarning $earning): void
    {
        $finalExpectedMinor = $settlement->expectedFinalLogisticsRemittanceMinor();
        $allocationTotalMinor = $finalExpectedMinor + ($settlement->isInterHub()
            ? (int) $settlement->origin_logistics_share_minor
            : 0);
        if ($allocationTotalMinor !== (int) $settlement->original_shipping_fee_minor) {
            throw new ConflictHttpException('The shipping allocations no longer reconcile to the original fee snapshot.');
        }

        $finalRemitted = $settlement->remitted_at !== null;
        if ($finalRemitted && (int) $settlement->remitted_amount_minor !== $finalExpectedMinor) {
            throw new ConflictHttpException('The destination Logistics remittance no longer matches the settlement snapshot.');
        }

        $originRemittance = null;
        if ($settlement->isInterHub()) {
            $originRemittance = ShippingSettlementOriginRemittance::query()
                ->where('shipping_settlement_id', $settlement->id)
                ->lockForUpdate()
                ->first();
            if (! $originRemittance
                || (int) $originRemittance->logistics_profile_id !== (int) $settlement->origin_logistics_profile_id
                || (int) $originRemittance->amount_minor !== (int) $settlement->origin_logistics_share_minor) {
                throw new ConflictHttpException('The origin Logistics remittance no longer matches the settlement snapshot.');
            }
        }

        $originRemitted = ! $settlement->isInterHub()
            || ($originRemittance?->status === 'remitted'
                && $originRemittance->remitted_at !== null
                && (int) $originRemittance->amount_minor === (int) $settlement->origin_logistics_share_minor);
        $allRemitted = $finalRemitted && $originRemitted;
        $anyRemitted = $finalRemitted || $originRemittance?->status === 'remitted';
        $nextStatus = $allRemitted ? 'remitted' : ($anyRemitted ? 'partially_remitted' : 'pending');

        if ($settlement->status !== $nextStatus) {
            $settlement->update(['status' => $nextStatus]);
        }

        if ($allRemitted) {
            if ((int) $earning->logistics_profile_id !== (int) $settlement->logistics_profile_id
                || (int) $earning->rider_profile_id !== (int) $settlement->rider_profile_id
                || (int) $earning->amount_minor !== (int) $settlement->rider_share_minor) {
                throw new ConflictHttpException('The Rider earning no longer matches this shipping allocation.');
            }
            if ($earning->status === 'pending') {
                $earning->update(['status' => 'posted', 'posted_at' => now()]);
                $this->broadcastRiderEarningUpdated($earning);
            } elseif ($earning->status !== 'posted' && $earning->status !== 'paid') {
                throw new ConflictHttpException('The linked Rider earning is not payable.');
            }
        } elseif ($earning->status !== 'pending') {
            throw new ConflictHttpException('The Rider earning cannot be released before every provider remittance is complete.');
        }
    }

    private function verifiedPayoutAccount(
        ShippingSettlement $settlement,
        string $method,
        ?int $payoutAccountId,
        ?int $logisticsProfileId = null,
    ): ?LogisticsPayoutAccount {
        $requiresAccount = in_array($method, ['bank_transfer', 'gcash'], true);
        if (! $requiresAccount) {
            if ($payoutAccountId !== null) {
                throw ValidationException::withMessages([
                    'logistics_payout_account_id' => 'A bank or GCash destination is not used for this remittance method.',
                ]);
            }

            return null;
        }

        if ($payoutAccountId === null || $payoutAccountId < 1) {
            throw ValidationException::withMessages([
                'logistics_payout_account_id' => 'Choose a verified payout destination for this transfer.',
            ]);
        }

        $account = LogisticsPayoutAccount::query()
            ->whereKey($payoutAccountId)
            ->where('logistics_profile_id', $logisticsProfileId ?? $settlement->logistics_profile_id)
            ->where('status', 'verified')
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'logistics_payout_account_id' => 'Choose a verified, active destination belonging to this Logistics provider.',
            ]);
        }
        if ($account->channel !== $method) {
            throw ValidationException::withMessages([
                'logistics_payout_account_id' => 'The selected payout destination does not match the remittance method.',
            ]);
        }

        return $account;
    }

    private function recordReturnLogisticsRemittance(
        User $admin,
        ShippingSettlement $stub,
        int $actualAmountMinor,
        string $method,
        ?int $payoutAccountId,
        string $reference,
        Carbon $paidDate,
        string $reason,
        Request $request,
    ): ShippingSettlement {
        $requestStub = ReturnRequest::query()->whereKey($stub->return_request_id)->firstOrFail();

        return DB::transaction(function () use ($admin, $stub, $requestStub, $actualAmountMinor, $method, $payoutAccountId, $reference, $paidDate, $reason, $request): ShippingSettlement {
            $order = \App\Models\Order::query()->whereKey($requestStub->order_id)->lockForUpdate()->firstOrFail();
            $returnRequest = ReturnRequest::query()
                ->whereKey($requestStub->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();
            $refund = \App\Models\Refund::query()
                ->where('return_request_id', $returnRequest->id)
                ->whereIn('financially_responsible_party', ['seller', 'platform'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $settlement = ShippingSettlement::query()
                ->whereKey($stub->id)
                ->where('settlement_type', 'return')
                ->where('return_request_id', $returnRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            $shipment = Shipment::query()
                ->whereKey($settlement->shipment_id)
                ->where('purpose', 'return')
                ->lockForUpdate()
                ->firstOrFail();
            $feeMinor = (int) $settlement->return_shipping_fee_minor;
            $expectedAmount = (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor;

            if ($feeMinor < 1 || $expectedAmount !== $feeMinor || $actualAmountMinor !== $feeMinor) {
                throw ValidationException::withMessages([
                    'actual_amount' => 'Record the exact return-shipping allocation of ₱'.number_format(max(0, $feeMinor) / 100, 2).'.',
                ]);
            }

            if ($settlement->status === 'remitted') {
                $referenceMatches = $method === 'cash'
                    ? filled($settlement->remittance_reference)
                    : hash_equals((string) $settlement->remittance_reference, trim($reference));
                if ((int) $settlement->remitted_amount_minor === $actualAmountMinor
                    && $settlement->remittance_method === $method
                    && (int) ($settlement->logistics_payout_account_id ?? 0) === (int) ($payoutAccountId ?? 0)
                    && $referenceMatches
                    && $settlement->remitted_on?->toDateString() === $paidDate->toDateString()
                    && trim((string) $settlement->remittance_reason) === trim($reason)) {
                    return $settlement->fresh(['riderEarning']);
                }

                throw new ConflictHttpException('This return-shipping allocation has already been remitted to Logistics.');
            }

            $quote = (array) $shipment->return_shipping_quote;
            $quoteZoneLevel = $quote['zone_level'] ?? null;
            $quoteBaseFeeMinor = (int) ($quote['base_fee_minor'] ?? -1);
            $quoteZoneSurchargeMinor = (int) ($quote['zone_surcharge_minor'] ?? -1);
            $quoteSurchargePerLevelMinor = (int) ($quote['zone_surcharge_per_level_minor'] ?? -1);
            $deliveryEvent = ShipmentEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('event_type', 'shipment_delivered')
                ->where('to_status', ShipmentStatus::Delivered->value)
                ->orderByDesc('id')
                ->first();
            if ($settlement->status !== 'pending'
                || $settlement->remitted_at !== null
                || $shipment->status !== ShipmentStatus::Delivered->value
                || ! $shipment->delivered_at
                || ($quote['quote_kind'] ?? null) !== 'return_shipping_v1'
                || ($quote['direction'] ?? null) !== 'reverse'
                || ! is_int($quoteZoneLevel)
                || $quoteZoneLevel < 0
                || $quoteZoneLevel > 4
                || $quoteBaseFeeMinor < 1
                || $quoteZoneSurchargeMinor < 0
                || $quoteSurchargePerLevelMinor < 0
                || $quoteBaseFeeMinor + $quoteZoneSurchargeMinor !== $feeMinor
                || $quoteZoneSurchargeMinor !== $quoteSurchargePerLevelMinor * $quoteZoneLevel
                || (int) $settlement->zone_level !== $quoteZoneLevel
                || ! in_array($returnRequest->status, [
                    ReturnRequestStatus::AwaitingInspection->value,
                    ReturnRequestStatus::RefundPending->value,
                    ReturnRequestStatus::Resolved->value,
                ], true)
                || ! ReturnEvent::query()
                    ->where('return_request_id', $returnRequest->id)
                    ->where('event_type', 'return_delivered_to_seller')
                    ->exists()
                || ! $refund
                || ! $refund->approved_at
                || $refund->financially_responsible_party !== $settlement->financially_responsible_party
                || (int) ($quote['return_shipping_fee_minor'] ?? 0) !== $feeMinor
                || ! $deliveryEvent) {
                throw new ConflictHttpException('This return-shipping allocation is no longer eligible for Finance remittance.');
            }

            $payoutAccount = $this->verifiedPayoutAccount($settlement, $method, $payoutAccountId);

            $earning = RiderEarning::query()
                ->where('shipping_settlement_id', $settlement->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $earning->logistics_profile_id !== (int) $settlement->logistics_profile_id
                || (int) $earning->rider_profile_id !== (int) $settlement->rider_profile_id
                || (int) $earning->amount_minor !== (int) $settlement->rider_share_minor
                || (int) ($deliveryEvent->metadata['rider_profile_id'] ?? 0) !== (int) $settlement->rider_profile_id
                || (int) $shipment->logistics_profile_id !== (int) $settlement->logistics_profile_id) {
                throw new ConflictHttpException('The final Rider and Logistics provider no longer match this return-shipping allocation.');
            }

            $remittanceReference = $method === 'cash'
                ? $this->cashRemittanceReference()
                : trim($reference);

            $oldValues = [
                'status' => $settlement->status,
                'remitted_amount_minor' => $settlement->remitted_amount_minor,
                'remittance_reference' => $settlement->remittance_reference,
            ];
            $settlement->update([
                'status' => 'remitted',
                'remitted_amount_minor' => $actualAmountMinor,
                'remittance_method' => $method,
                'remittance_reference' => $remittanceReference,
                'remitted_on' => $paidDate->toDateString(),
                'remitted_at' => now(),
                'remitted_by' => $admin->id,
                'remittance_reason' => trim($reason),
                'logistics_payout_account_id' => $payoutAccount?->id,
                'remitted_to_provider' => $payoutAccount?->provider_name,
                'remitted_to_account_name' => $payoutAccount?->account_name,
                'remitted_to_last4' => $payoutAccount?->destination_last4,
            ]);

            if ($earning->status === 'pending') {
                $earning->update(['status' => 'posted', 'posted_at' => now()]);
            } elseif ($earning->status !== 'posted' && $earning->status !== 'paid') {
                throw new ConflictHttpException('The linked return Rider earning is not payable.');
            }
            $this->broadcastRiderEarningUpdated($earning);

            if ($refund->financially_responsible_party === 'seller') {
                $recovery = $this->wallets->recordReturnShippingRecovery($settlement);
                ReturnEvent::query()->create([
                    'return_request_id' => $returnRequest->id,
                    'event_type' => 'seller_return_shipping_recovery_created',
                    'actor_user_id' => $admin->id,
                    'from_status' => $returnRequest->status,
                    'to_status' => $returnRequest->status,
                    'note' => 'Finance advanced the return-shipping fee to Logistics; the Seller-liable fee is recorded separately from the full Buyer refund.',
                    'metadata' => [
                        'refund_id' => (int) $refund->id,
                        'shipping_settlement_id' => (int) $settlement->id,
                        'seller_refund_recovery_id' => (int) $recovery->id,
                        'return_shipping_fee_minor' => $feeMinor,
                    ],
                    'created_at' => now(),
                ]);
            }

            $this->audit->record(
                action: 'shipping_settlement.return_fee_remitted_to_logistics',
                module: 'Finance',
                subject: $settlement,
                description: "Return-shipping settlement {$settlement->settlement_no} was recorded as remitted to Logistics.",
                oldValues: $oldValues,
                newValues: [
                    'settlement_type' => 'return',
                    'return_request_id' => (int) $returnRequest->id,
                    'shipment_id' => (int) $shipment->id,
                    'financially_responsible_party' => $refund->financially_responsible_party,
                    'logistics_profile_id' => (int) $settlement->logistics_profile_id,
                    'rider_profile_id' => (int) $settlement->rider_profile_id,
                    'zone_level' => (int) $settlement->zone_level,
                    'return_shipping_fee_minor' => $feeMinor,
                    'logistics_share_minor' => (int) $settlement->logistics_share_minor,
                    'rider_share_minor' => (int) $settlement->rider_share_minor,
                    'amount_minor' => $actualAmountMinor,
                    'method' => $method,
                    'reference' => $remittanceReference,
                    'logistics_payout_account_id' => $payoutAccount?->id,
                    'remitted_to_provider' => $payoutAccount?->provider_name,
                    'remitted_to_last4' => $payoutAccount?->destination_last4,
                    'paid_on' => $paidDate->toDateString(),
                    'reason' => trim($reason),
                ],
                severity: 'warning',
                request: $request,
            );

            return $settlement->fresh(['riderEarning']);
        }, attempts: 3);
    }

    public function recordRiderPayout(
        User $logisticsUser,
        int $earningId,
        int $actualAmountMinor,
        string $method,
        string $reference,
        string $paidOn,
        string $reason,
        Request $request,
    ): RiderEarningPayout {
        $profile = $this->logisticsProfile($logisticsUser);
        if ((int) $request->user()?->id !== (int) $logisticsUser->id) {
            throw new AuthorizationException('The Logistics audit actor does not match the authenticated user.');
        }
        if ($method === 'cash') {
            $reference = '';
        }
        $this->validatePayoutDetails($actualAmountMinor, $method, $reference, $paidOn, $reason);
        $paidDate = $this->paidDate($paidOn);

        return DB::transaction(function () use ($profile, $earningId, $actualAmountMinor, $method, $reference, $paidDate, $reason): RiderEarningPayout {
            $earning = RiderEarning::query()
                ->whereKey($earningId)
                ->where('logistics_profile_id', $profile->id)
                ->whereNotNull('shipping_settlement_id')
                ->lockForUpdate()
                ->firstOrFail();

            $existing = RiderEarningPayout::query()
                ->where('rider_earning_id', $earning->id)
                ->where('logistics_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if (RiderPayoutBatchItem::query()->where('rider_earning_id', $earning->id)->exists()) {
                throw new ConflictHttpException(
                    'This earning is reserved in a consolidated payout batch; use the batch review and payment workflow.'
                );
            }
            if ($existing) {
                $referenceMatches = $method === 'cash'
                    ? str_starts_with((string) $existing->reference, 'LRP-')
                    : hash_equals((string) $existing->reference, trim($reference));
                if ((int) $existing->amount_minor === $actualAmountMinor
                    && $existing->method === $method
                    && $referenceMatches
                    && $existing->paid_on?->toDateString() === $paidDate->toDateString()
                    && trim($existing->reason) === trim($reason)) {
                    return $existing;
                }
                throw new ConflictHttpException('A payout has already been recorded for this Rider earning.');
            }

            throw new ConflictHttpException(
                'New Rider payments must go through a consolidated payout batch and Logistics approval.'
            );
        }, attempts: 3);
    }

    public function cashRiderPayoutForLogistics(User $logisticsUser, int $earningId): RiderEarningPayout
    {
        $profile = $this->logisticsProfile($logisticsUser);
        $earning = RiderEarning::query()
            ->whereKey($earningId)
            ->where('logistics_profile_id', $profile->id)
            ->whereNotNull('shipping_settlement_id')
            ->with([
                'riderProfile.user',
                'shippingSettlement.shipment.sellerOrder.order',
                'shippingSettlement.sellerOrder.store',
                'shippingSettlement.returnRequest.order',
                'shippingSettlement.returnRequest.store',
            ])
            ->firstOrFail();
        $payout = RiderEarningPayout::query()
            ->where('rider_earning_id', $earning->id)
            ->where('logistics_profile_id', $profile->id)
            ->with(['logisticsProfile', 'recorder'])
            ->firstOrFail();

        abort_unless($earning->status === 'paid'
            && $payout->method === 'cash'
            && str_starts_with((string) $payout->reference, 'LRP-'), 404);

        return $payout->setRelation('riderEarning', $earning);
    }

    private function materializeForShipment(int $shipmentId): void
    {
        DB::transaction(function () use ($shipmentId): void {
            $shipmentStub = Shipment::query()->whereKey($shipmentId)->first();
            if (! $shipmentStub || $shipmentStub->purpose !== 'outbound') {
                return;
            }

            $memberStubs = $shipmentStub->sellerOrders()
                ->orderBy('seller_orders.id')
                ->get(['seller_orders.id', 'seller_orders.order_id']);
            if ($memberStubs->isEmpty()) {
                return;
            }

            $orderIds = $memberStubs->pluck('order_id')->unique()->sort()->values();
            $orders = \App\Models\Order::query()
                ->whereIn('id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($orders->count() !== $orderIds->count()) {
                return;
            }

            $sellerOrderIds = $memberStubs->pluck('id')->unique()->sort()->values();
            $sellerOrders = SellerOrder::query()
                ->whereIn('id', $sellerOrderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($sellerOrders->count() !== $sellerOrderIds->count()) {
                return;
            }

            $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first();
            if (! $shipment
                || $shipment->purpose !== 'outbound'
                || $shipment->status !== ShipmentStatus::Delivered->value) {
                return;
            }

            $primarySellerOrder = $sellerOrders->get((int) $shipment->seller_order_id);
            if (! $primarySellerOrder || $sellerOrders->contains(fn (SellerOrder $member): bool => $member->status !== SellerOrderStatus::Completed->value
                || ! $this->wallets->isSellerOrderSettlementEligible($member))) {
                return;
            }

            $allocationSnapshots = collect();
            foreach ($sellerOrders as $includedOrder) {
                $snapshot = $this->outboundShippingSnapshot($includedOrder);
                if ($snapshot === null) {
                    return;
                }
                $allocationSnapshots->push(['seller_order' => $includedOrder] + $snapshot);
            }

            $feeMinor = (int) $allocationSnapshots->sum('original_shipping_fee_minor');
            $buyerPaidMinor = (int) $allocationSnapshots->sum('buyer_paid_shipping_fee_minor');
            $subsidyMinor = (int) $allocationSnapshots->sum('platform_shipping_subsidy_minor');
            $zoneLevel = (int) $allocationSnapshots->first()['zone_level'];
            $fundingSources = $allocationSnapshots->pluck('shipping_funding_source')->unique()->values();
            $fundingSource = $fundingSources->count() === 1 ? (string) $fundingSources->first() : 'mixed';
            if ($feeMinor < 1
                || $buyerPaidMinor + $subsidyMinor !== $feeMinor
                || $feeMinor !== (int) $shipment->shipping_fee_minor) {
                throw new ConflictHttpException('The combined shipping fee and per-Seller-Order allocation snapshots do not reconcile.');
            }

            $deliveryEvent = ShipmentEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('event_type', 'shipment_delivered')
                ->where('to_status', ShipmentStatus::Delivered->value)
                ->orderByDesc('id')
                ->first();
            $riderProfileId = (int) ($deliveryEvent?->metadata['rider_profile_id'] ?? 0);
            if ($riderProfileId < 1 || ! $deliveryEvent) {
                return;
            }

            $rider = RiderProfile::query()->whereKey($riderProfileId)->first();
            if (! $rider || (int) $rider->logistics_profile_id !== (int) $shipment->logistics_profile_id) {
                return;
            }

            $dispatchBatchIds = array_values(array_filter(array_map('intval', (array) ($deliveryEvent->metadata['dispatch_batch_ids'] ?? []))));
            $attemptQuery = DeliveryAttempt::query()
                ->where('rider_profile_id', $rider->id)
                ->where('outcome', DeliveryAttemptOutcome::Delivered->value)
                ->where('attempted_at', $deliveryEvent->occurred_at)
                ->whereHas('parcel', fn ($query) => $query->where('shipment_id', $shipment->id));
            if ($dispatchBatchIds !== []) {
                $attemptQuery->whereIn('dispatch_batch_id', $dispatchBatchIds);
            }
            $finalAttempt = $attemptQuery->orderByDesc('id')->first();
            if (! $finalAttempt) {
                return;
            }

            $transfer = ShipmentTransfer::query()
                ->where('shipment_id', $shipment->id)
                ->lockForUpdate()
                ->first();
            $currentProviderId = (int) $shipment->logistics_profile_id;
            $shipmentOriginId = (int) ($shipment->origin_logistics_profile_id ?? $currentProviderId);
            $originProviderId = null;
            $settlementMode = 'single_provider';

            if ($transfer) {
                if ($transfer->status !== 'accepted'
                    || $transfer->accepted_at === null
                    || (int) $transfer->from_logistics_profile_id !== $shipmentOriginId
                    || (int) $transfer->to_logistics_profile_id !== $currentProviderId
                    || (int) $transfer->from_logistics_profile_id === $currentProviderId) {
                    throw new ConflictHttpException('The accepted shipment transfer does not match the immutable origin and final Logistics providers.');
                }

                $originProviderId = (int) $transfer->from_logistics_profile_id;
                $settlementMode = 'inter_hub';
            } elseif ($shipmentOriginId !== $currentProviderId) {
                throw new ConflictHttpException('The shipment origin and final Logistics providers differ, but no accepted transfer is recorded.');
            }

            $allocationSnapshots = $allocationSnapshots->map(function (array $snapshot) use ($settlementMode): array {
                $fee = (int) $snapshot['original_shipping_fee_minor'];
                if ($settlementMode === 'inter_hub') {
                    $originShare = intdiv($fee * 10, 100);
                    $logisticsShare = intdiv($fee * 30, 100);
                    $riderShare = $fee - $originShare - $logisticsShare;
                } else {
                    $originShare = 0;
                    $logisticsShare = intdiv($fee * 40, 100);
                    $riderShare = $fee - $logisticsShare;
                }

                return $snapshot + [
                    'origin_logistics_share_minor' => $originShare,
                    'logistics_share_minor' => $logisticsShare,
                    'rider_share_minor' => $riderShare,
                ];
            });
            $originLogisticsShareMinor = (int) $allocationSnapshots->sum('origin_logistics_share_minor');
            $logisticsShareMinor = (int) $allocationSnapshots->sum('logistics_share_minor');
            $riderShareMinor = (int) $allocationSnapshots->sum('rider_share_minor');
            $fundingSources = $allocationSnapshots->pluck('shipping_funding_source')->unique()->values();
            $fundingSource = $fundingSources->count() === 1 ? (string) $fundingSources->first() : 'mixed';
            if ($originLogisticsShareMinor + $logisticsShareMinor + $riderShareMinor !== $feeMinor) {
                throw new ConflictHttpException('The combined shipping-share allocation does not equal the original shipping fees.');
            }
            $settlement = ShippingSettlement::query()->firstOrCreate(
                ['shipment_id' => $shipment->id],
                [
                    'settlement_no' => 'SPS-'.Str::upper((string) Str::ulid()),
                    'settlement_type' => 'outbound',
                    'settlement_mode' => $settlementMode,
                    'seller_order_id' => $primarySellerOrder->id,
                    'logistics_profile_id' => $shipment->logistics_profile_id,
                    'origin_logistics_profile_id' => $originProviderId,
                    'origin_logistics_share_minor' => $originLogisticsShareMinor,
                    'rider_profile_id' => $rider->id,
                    'delivery_attempt_id' => $finalAttempt->id,
                    'zone_level' => $zoneLevel,
                    'original_shipping_fee_minor' => $feeMinor,
                    'buyer_paid_shipping_fee_minor' => $buyerPaidMinor,
                    'platform_shipping_subsidy_minor' => $subsidyMinor,
                    'shipping_funding_source' => $fundingSource,
                    'logistics_share_minor' => $logisticsShareMinor,
                    'rider_share_minor' => $riderShareMinor,
                    'status' => 'pending',
                    'eligible_at' => now(),
                ],
            );

            if ((int) $settlement->seller_order_id !== (int) $primarySellerOrder->id
                || (int) $settlement->logistics_profile_id !== (int) $shipment->logistics_profile_id
                || (int) $settlement->rider_profile_id !== (int) $rider->id
                || (int) $settlement->original_shipping_fee_minor !== $feeMinor
                || (int) $settlement->buyer_paid_shipping_fee_minor !== $buyerPaidMinor
                || (int) $settlement->platform_shipping_subsidy_minor !== $subsidyMinor
                || (int) $settlement->origin_logistics_share_minor !== $originLogisticsShareMinor
                || (int) $settlement->logistics_share_minor !== $logisticsShareMinor
                || (int) $settlement->rider_share_minor !== $riderShareMinor
                || $settlement->shipping_funding_source !== $fundingSource) {
                throw new ConflictHttpException('An existing shipping settlement does not match the immutable Seller Order allocation snapshots.');
            }

            foreach ($allocationSnapshots as $snapshot) {
                $includedOrder = $snapshot['seller_order'];
                unset($snapshot['seller_order']);
                $snapshot['shipping_settlement_id'] = $settlement->id;
                $snapshot['seller_order_id'] = $includedOrder->id;
                $saved = ShippingSettlementAllocation::query()->firstOrCreate(
                    [
                        'shipping_settlement_id' => $settlement->id,
                        'seller_order_id' => $includedOrder->id,
                    ],
                    $snapshot,
                );
                foreach ($snapshot as $field => $value) {
                    if ((string) $saved->{$field} !== (string) $value) {
                        throw new ConflictHttpException('An existing shipping settlement allocation does not match its immutable Seller Order snapshot.');
                    }
                }
            }

            if ($settlement->isInterHub()) {
                if ((int) $settlement->origin_logistics_profile_id !== (int) $originProviderId
                    || (int) $settlement->origin_logistics_share_minor !== $originLogisticsShareMinor) {
                    throw new ConflictHttpException('An existing inter-hub settlement does not match its immutable provider allocation.');
                }

                $originRemittance = ShippingSettlementOriginRemittance::query()->firstOrCreate(
                    ['shipping_settlement_id' => $settlement->id],
                    [
                        'logistics_profile_id' => $originProviderId,
                        'amount_minor' => $originLogisticsShareMinor,
                        'status' => 'pending',
                    ],
                );
                if ((int) $originRemittance->logistics_profile_id !== $originProviderId
                    || (int) $originRemittance->amount_minor !== $originLogisticsShareMinor) {
                    throw new ConflictHttpException('The origin Logistics remittance does not match its immutable settlement allocation.');
                }
            }

            $earning = RiderEarning::query()->firstOrCreate(
                ['shipping_settlement_id' => $settlement->id],
                [
                    'rider_profile_id' => $rider->id,
                    'shipment_id' => $shipment->id,
                    'logistics_profile_id' => $shipment->logistics_profile_id,
                    'delivery_attempt_id' => $finalAttempt->id,
                    'type' => 'shipping_delivery',
                    'amount_minor' => $riderShareMinor,
                    'status' => 'pending',
                    'description' => '60% Rider pool for '.$shipment->shipment_no,
                    'earned_at' => $settlement->eligible_at,
                    'rider_shift_id' => $this->riderShiftIdForEvent(
                        (int) $rider->id,
                        Carbon::parse($finalAttempt->attempted_at),
                    ),
                ],
            );
            $this->attachEventShiftIfMissing($earning, (int) $rider->id, Carbon::parse($finalAttempt->attempted_at));
            if ($earning->wasRecentlyCreated) {
                $this->broadcastRiderEarningUpdated($earning);
            }
        }, attempts: 3);
    }

    private function settlementEligibleForSellerWallet(ShippingSettlement $settlement, SellerOrder $primarySellerOrder): bool
    {
        if ($settlement->settlement_type !== 'outbound') {
            return $this->wallets->isSellerOrderSettlementEligible($primarySellerOrder);
        }

        $members = $settlement->shipment?->sellerOrders()->orderBy('seller_orders.id')->get();
        if (! $members || $members->isEmpty()
            || ! $members->contains(fn (SellerOrder $member): bool => (int) $member->id === (int) $primarySellerOrder->id)) {
            return false;
        }

        foreach ($members as $member) {
            if ($member->status !== SellerOrderStatus::Completed->value
                || ! $this->wallets->isSellerOrderSettlementEligible($member)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate and expose one Seller Order's immutable shipping-fee snapshot.
     * Physical consolidation changes neither this fee nor its funding split.
     *
     * @return array{
     *     original_shipping_fee_minor:int,
     *     buyer_paid_shipping_fee_minor:int,
     *     platform_shipping_subsidy_minor:int,
     *     shipping_funding_source:string,
     *     zone_level:int
     * }|null
     */
    private function outboundShippingSnapshot(SellerOrder $sellerOrder): ?array
    {
        $quote = (array) $sellerOrder->shipping_quote;
        $zoneLevel = $quote['zone_level'] ?? null;
        if (($quote['pricing_rule'] ?? null) !== 'zone_based_v1'
            || ! is_int($zoneLevel)
            || $zoneLevel < 0
            || $zoneLevel > 4) {
            return null;
        }

        $feeMinor = (int) $sellerOrder->shipping_fee_minor;
        $subsidyMinor = (int) $sellerOrder->platform_shipping_discount_minor;
        $buyerPaidMinor = $sellerOrder->buyer_paid_shipping_fee_minor === null
            ? $feeMinor - $subsidyMinor
            : (int) $sellerOrder->buyer_paid_shipping_fee_minor;
        $baseFeeMinor = (int) ($quote['base_fee_minor'] ?? -1);
        $zoneSurchargeMinor = (int) ($quote['zone_surcharge_minor'] ?? -1);
        $surchargePerLevelMinor = (int) ($quote['zone_surcharge_per_level_minor'] ?? -1);
        $feeBreakdown = (array) ($quote['shipping_fee_breakdown'] ?? []);
        $quoteFundingSource = trim((string) data_get($quote, 'shipping_fee_breakdown.funding_source', ''));
        if ($feeMinor < 1
            || $subsidyMinor < 0
            || $subsidyMinor > $feeMinor
            || $buyerPaidMinor < 0
            || $buyerPaidMinor + $subsidyMinor !== $feeMinor
            || (int) ($quote['original_shipping_fee_minor'] ?? -1) !== $feeMinor
            || (int) ($quote['shipping_fee_minor'] ?? -1) !== $feeMinor
            || $baseFeeMinor < 1
            || $zoneSurchargeMinor < 0
            || $surchargePerLevelMinor < 0
            || $baseFeeMinor + $zoneSurchargeMinor !== $feeMinor
            || $zoneSurchargeMinor !== $surchargePerLevelMinor * $zoneLevel
            || (int) ($feeBreakdown['original_fee_minor'] ?? -1) !== $feeMinor
            || (int) ($feeBreakdown['buyer_paid_minor'] ?? -1) !== $buyerPaidMinor
            || (int) ($feeBreakdown['platform_subsidy_minor'] ?? -1) !== $subsidyMinor
            || $quoteFundingSource === ''
            || ($sellerOrder->shipping_funding_source !== null && $sellerOrder->shipping_funding_source !== $quoteFundingSource)) {
            throw new ConflictHttpException('The saved shipping fee and funding snapshots do not reconcile.');
        }

        return [
            'original_shipping_fee_minor' => $feeMinor,
            'buyer_paid_shipping_fee_minor' => $buyerPaidMinor,
            'platform_shipping_subsidy_minor' => $subsidyMinor,
            'shipping_funding_source' => (string) ($sellerOrder->shipping_funding_source ?: $quoteFundingSource),
            'zone_level' => $zoneLevel,
        ];
    }

    private function materializeForReturnShipment(int $shipmentId): void
    {
        DB::transaction(function () use ($shipmentId): void {
            $shipmentStub = Shipment::query()->whereKey($shipmentId)->first();
            if (! $shipmentStub || $shipmentStub->purpose !== 'return') {
                return;
            }

            $requestStub = ReturnRequest::query()
                ->where('return_shipment_id', $shipmentStub->id)
                ->where('request_type', 'return_refund')
                ->first(['id', 'order_id']);
            if (! $requestStub) {
                return;
            }

            $order = \App\Models\Order::query()->whereKey($requestStub->order_id)->lockForUpdate()->first();
            if (! $order) {
                return;
            }
            $returnRequest = ReturnRequest::query()
                ->whereKey($requestStub->id)
                ->where('return_shipment_id', $shipmentId)
                ->lockForUpdate()
                ->first();
            $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first();
            if (! $returnRequest || ! $shipment
                || $shipment->purpose !== 'return'
                || (int) $returnRequest->return_shipment_id !== (int) $shipment->id
                || $shipment->status !== ShipmentStatus::Delivered->value
                || ! $shipment->delivered_at
                || ! in_array($returnRequest->status, [
                    ReturnRequestStatus::AwaitingInspection->value,
                    ReturnRequestStatus::RefundPending->value,
                    ReturnRequestStatus::Resolved->value,
                ], true)
                || ! ReturnEvent::query()
                    ->where('return_request_id', $returnRequest->id)
                    ->where('event_type', 'return_delivered_to_seller')
                    ->exists()) {
                return;
            }

            $refund = Refund::query()
                ->where('return_request_id', $returnRequest->id)
                ->whereIn('financially_responsible_party', ['seller', 'platform'])
                ->orderByDesc('id')
                ->first();
            $quote = (array) $shipment->return_shipping_quote;
            $zoneLevel = $quote['zone_level'] ?? null;
            $feeMinor = (int) ($quote['return_shipping_fee_minor'] ?? 0);
            $baseFeeMinor = (int) ($quote['base_fee_minor'] ?? 0);
            $zoneSurchargeMinor = (int) ($quote['zone_surcharge_minor'] ?? -1);
            $surchargePerLevelMinor = (int) ($quote['zone_surcharge_per_level_minor'] ?? -1);
            if (! $refund
                || ! $refund->approved_at
                || ($quote['quote_kind'] ?? null) !== 'return_shipping_v1'
                || ($quote['direction'] ?? null) !== 'reverse'
                || ! is_int($zoneLevel)
                || $zoneLevel < 0
                || $zoneLevel > 4
                || $feeMinor < 1
                || (int) $shipment->shipping_fee_minor !== $feeMinor
                || $baseFeeMinor < 1
                || $zoneSurchargeMinor < 0
                || $surchargePerLevelMinor < 0
                || $baseFeeMinor + $zoneSurchargeMinor !== $feeMinor
                || $zoneSurchargeMinor !== $surchargePerLevelMinor * $zoneLevel) {
                return;
            }

            $deliveryEvent = ShipmentEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('event_type', 'shipment_delivered')
                ->where('to_status', ShipmentStatus::Delivered->value)
                ->orderByDesc('id')
                ->first();
            $riderProfileId = (int) ($deliveryEvent?->metadata['rider_profile_id'] ?? 0);
            if ($riderProfileId < 1 || ! $deliveryEvent) {
                return;
            }

            $rider = RiderProfile::query()->whereKey($riderProfileId)->first();
            if (! $rider || (int) $rider->logistics_profile_id !== (int) $shipment->logistics_profile_id) {
                return;
            }

            $dispatchBatchIds = array_values(array_filter(array_map('intval', (array) ($deliveryEvent->metadata['dispatch_batch_ids'] ?? []))));
            $attemptQuery = DeliveryAttempt::query()
                ->where('rider_profile_id', $rider->id)
                ->where('outcome', DeliveryAttemptOutcome::Delivered->value)
                ->where('attempted_at', $deliveryEvent->occurred_at)
                ->whereHas('parcel', fn ($query) => $query->where('shipment_id', $shipment->id));
            if ($dispatchBatchIds !== []) {
                $attemptQuery->whereIn('dispatch_batch_id', $dispatchBatchIds);
            }
            $finalAttempt = $attemptQuery->orderByDesc('id')->first();
            if (! $finalAttempt) {
                return;
            }

            $logisticsShareMinor = intdiv($feeMinor * 40, 100);
            $riderShareMinor = $feeMinor - $logisticsShareMinor;
            $settlement = ShippingSettlement::query()->firstOrCreate(
                ['shipment_id' => $shipment->id],
                [
                    'settlement_no' => 'SPS-'.Str::upper((string) Str::ulid()),
                    'settlement_type' => 'return',
                    'return_request_id' => $returnRequest->id,
                    'seller_order_id' => $shipment->seller_order_id,
                    'logistics_profile_id' => $shipment->logistics_profile_id,
                    'rider_profile_id' => $rider->id,
                    'delivery_attempt_id' => $finalAttempt->id,
                    'zone_level' => $zoneLevel,
                    'original_shipping_fee_minor' => 0,
                    'return_shipping_fee_minor' => $feeMinor,
                    'buyer_paid_shipping_fee_minor' => 0,
                    'platform_shipping_subsidy_minor' => 0,
                    'shipping_funding_source' => 'return_fee',
                    'financially_responsible_party' => $refund->financially_responsible_party,
                    'logistics_share_minor' => $logisticsShareMinor,
                    'rider_share_minor' => $riderShareMinor,
                    'status' => 'pending',
                    'eligible_at' => now(),
                ],
            );

            if ($settlement->settlement_type !== 'return'
                || (int) $settlement->return_request_id !== (int) $returnRequest->id
                || (int) $settlement->logistics_profile_id !== (int) $shipment->logistics_profile_id
                || (int) $settlement->rider_profile_id !== (int) $rider->id
                || (int) $settlement->delivery_attempt_id !== (int) $finalAttempt->id
                || (int) $settlement->zone_level !== $zoneLevel
                || (int) $settlement->return_shipping_fee_minor !== $feeMinor
                || (int) $settlement->logistics_share_minor !== $logisticsShareMinor
                || (int) $settlement->rider_share_minor !== $riderShareMinor
                || $settlement->financially_responsible_party !== $refund->financially_responsible_party) {
                throw new ConflictHttpException('An existing return-shipping settlement does not match the approved return quote.');
            }

            $earning = RiderEarning::query()->firstOrCreate(
                ['shipping_settlement_id' => $settlement->id],
                [
                    'rider_profile_id' => $rider->id,
                    'shipment_id' => $shipment->id,
                    'logistics_profile_id' => $shipment->logistics_profile_id,
                    'delivery_attempt_id' => $finalAttempt->id,
                    'type' => 'return_shipping_delivery',
                    'amount_minor' => $riderShareMinor,
                    'status' => 'pending',
                    'description' => '60% return Rider pool for final Seller delivery '.$shipment->shipment_no,
                    'earned_at' => $settlement->eligible_at,
                    'rider_shift_id' => $this->riderShiftIdForEvent(
                        (int) $rider->id,
                        Carbon::parse($finalAttempt->attempted_at),
                    ),
                ],
            );
            $this->attachEventShiftIfMissing($earning, (int) $rider->id, Carbon::parse($finalAttempt->attempted_at));
            if ((int) $earning->rider_profile_id !== (int) $rider->id
                || (int) $earning->shipment_id !== (int) $shipment->id
                || (int) $earning->logistics_profile_id !== (int) $shipment->logistics_profile_id
                || (int) $earning->delivery_attempt_id !== (int) $finalAttempt->id
                || $earning->type !== 'return_shipping_delivery'
                || (int) $earning->amount_minor !== $riderShareMinor) {
                throw new ConflictHttpException('An existing Rider earning does not match the approved return-shipping allocation.');
            }
            if ($earning->wasRecentlyCreated) {
                $this->broadcastRiderEarningUpdated($earning);
            }
        }, attempts: 3);
    }

    private function attachEventShiftIfMissing(
        RiderEarning $earning,
        int $riderProfileId,
        Carbon $occurredAt,
    ): void {
        if ($earning->rider_shift_id !== null) {
            return;
        }

        $shiftId = $this->riderShiftIdForEvent($riderProfileId, $occurredAt);

        if ($shiftId !== null) {
            $earning->update(['rider_shift_id' => $shiftId]);
        }
    }

    private function riderShiftIdForEvent(int $riderProfileId, Carbon $occurredAt): ?int
    {
        $shiftId = RiderShift::query()
            ->where('rider_profile_id', $riderProfileId)
            ->where('checked_in_at', '<=', $occurredAt)
            ->where(function ($query) use ($occurredAt): void {
                $query->whereNull('checked_out_at')
                    ->orWhere('checked_out_at', '>=', $occurredAt);
            })
            ->orderByDesc('checked_in_at')
            ->value('id');

        return $shiftId === null ? null : (int) $shiftId;
    }

    private function statusVersionForEarnings(EloquentBuilder $earnings): string
    {
        $snapshot = (clone $earnings)
            ->select('status')
            ->selectRaw('COUNT(*) as entry_count')
            ->selectRaw('COALESCE(SUM(amount_minor), 0) as amount_minor')
            ->selectRaw('MAX(id) as latest_id')
            ->selectRaw('MAX(updated_at) as latest_updated_at')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): string => implode(':', [
                (string) $row->status,
                (string) $row->entry_count,
                (string) $row->amount_minor,
                (string) $row->latest_id,
                (string) $row->latest_updated_at,
            ]))
            ->implode('|');

        return hash('sha256', $snapshot);
    }

    private function broadcastRiderEarningUpdated(RiderEarning $earning): void
    {
        $riderUserId = (int) RiderProfile::query()
            ->whereKey($earning->rider_profile_id)
            ->value('user_id');
        $logisticsUserId = (int) LogisticsProfile::query()
            ->whereKey($earning->logistics_profile_id)
            ->value('user_id');
        if ($riderUserId < 1 || $logisticsUserId < 1) {
            return;
        }

        $event = new RiderShippingEarningUpdated(
            riderUserId: $riderUserId,
            logisticsUserId: $logisticsUserId,
            earningId: (int) $earning->id,
            status: (string) $earning->status,
            updatedAt: $earning->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );

        DB::afterCommit(function () use ($event): void {
            try {
                Event::dispatch($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function logisticsProfile(User $user): LogisticsProfile
    {
        if ($user->role !== UserRole::Logistics->value || $user->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('An active Logistics account is required.');
        }

        $profile = $user->logisticsProfile()->first();
        if (! $profile) {
            throw new AuthorizationException('An active Logistics profile is required.');
        }

        return $profile;
    }

    private function assertAdmin(User $user): void
    {
        if ($user->role !== UserRole::Admin->value || $user->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('An active Admin/Finance user is required.');
        }
    }

    private function validatePayoutDetails(int $amountMinor, string $method, string $reference, string $paidOn, string $reason): void
    {
        if ($amountMinor < 1) {
            throw ValidationException::withMessages(['actual_amount' => 'Enter the exact positive payout amount in centavos.']);
        }
        if (! in_array($method, ['bank_transfer', 'gcash', 'cash', 'other'], true)) {
            throw ValidationException::withMessages(['method' => 'Choose a supported payout method.']);
        }
        if ($method === 'cash') {
            $reference = '';
        }
        if (($method !== 'cash' && trim($reference) === '') || mb_strlen(trim($reference)) > 150) {
            throw ValidationException::withMessages(['reference' => 'Enter the actual payout reference (up to 150 characters).']);
        }
        if (mb_strlen(trim($reason)) < 5 || mb_strlen(trim($reason)) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason between 5 and 1,000 characters.']);
        }

        $this->paidDate($paidOn);
    }

    private function cashRemittanceReference(): string
    {
        return 'SRM-'.Str::upper((string) Str::ulid());
    }

    private function riderCashPayoutReference(): string
    {
        return 'LRP-'.Str::upper((string) Str::ulid());
    }

    private function paidDate(string $paidOn): Carbon
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $paidOn, config('app.timezone'));
        } catch (\Throwable) {
            $date = null;
        }

        if (! $date || $date->greaterThan(Carbon::today(config('app.timezone')))) {
            throw ValidationException::withMessages(['paid_on' => 'Enter the actual payout date; future dates are not allowed.']);
        }

        return $date;
    }
}
