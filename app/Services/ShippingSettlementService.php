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
use App\Models\RiderProfile;
use App\Models\Refund;
use App\Models\ReturnEvent;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShippingSettlement;
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
            $query->where('logistics_profile_id', $logisticsProfileId);
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
                'returnRequest.refunds',
                'returnRequest.items.orderItem.product.images.variant',
                'returnRequest.items.orderItem.variant',
                'shipment',
                'logisticsProfile',
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
            ->whereNotNull('shipping_settlement_id')
            ->with([
                'riderProfile.user',
                'shipment.sellerOrder.order',
                'shipment.sellerOrder.items.product.images.variant',
                'shipment.sellerOrder.items.variant',
                'shippingSettlement',
                'shippingSettlement.returnRequest.order',
                'shippingSettlement.returnRequest.refunds',
                'shippingSettlement.returnRequest.items.orderItem.product.images.variant',
                'shippingSettlement.returnRequest.items.orderItem.variant',
                'payout.recorder',
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

        $settlements = ShippingSettlement::query()
            ->where('logistics_profile_id', $profile->id);

        return [
            'total_minor' => (int) (clone $settlements)->sum('logistics_share_minor'),
            'pending_minor' => (int) (clone $settlements)->where('status', 'pending')->sum('logistics_share_minor'),
            'remitted_minor' => (int) (clone $settlements)->where('status', 'remitted')->sum('logistics_share_minor'),
        ];
    }

    public function riderEarningStatusVersionForRider(RiderProfile $rider): string
    {
        return $this->statusVersionForEarnings(
            RiderEarning::query()
                ->where('rider_profile_id', $rider->id)
                ->whereNotNull('shipping_settlement_id'),
        );
    }

    public function riderEarningStatusVersionForLogistics(User $user, bool $materialize = true): string
    {
        $profile = $this->logisticsProfile($user);
        if ($materialize) {
            $this->materializeEligibleSettlements(logisticsProfileId: (int) $profile->id);
        }

        return $this->statusVersionForEarnings(
            RiderEarning::query()
                ->where('logistics_profile_id', $profile->id)
                ->whereNotNull('shipping_settlement_id'),
        );
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
    ): ShippingSettlement {
        $this->assertAdmin($admin);
        if ((int) $request->user()?->id !== (int) $admin->id) {
            throw new AuthorizationException('The Finance audit actor does not match the authenticated user.');
        }
        $this->validatePayoutDetails($actualAmountMinor, $method, $reference, $paidOn, $reason);
        $paidDate = $this->paidDate($paidOn);

        $stub = ShippingSettlement::query()->findOrFail($settlementId);
        if ($stub->settlement_type === 'return') {
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
        $sellerOrderId = (int) $stub->seller_order_id;

        return DB::transaction(function () use ($admin, $settlementId, $sellerOrderId, $actualAmountMinor, $method, $payoutAccountId, $reference, $paidDate, $reason, $request): ShippingSettlement {
            $sellerOrder = SellerOrder::query()->whereKey($sellerOrderId)->first();
            $eligible = $sellerOrder !== null
                && $this->wallets->isSellerOrderSettlementEligible($sellerOrder);
            $settlement = ShippingSettlement::query()->whereKey($settlementId)->lockForUpdate()->firstOrFail();
            $expectedAmount = (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor;

            if ($actualAmountMinor !== $expectedAmount || $expectedAmount !== (int) $settlement->original_shipping_fee_minor) {
                throw ValidationException::withMessages([
                    'actual_amount' => 'Record the exact shipping allocation of ₱'.number_format($expectedAmount / 100, 2).'.',
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

                throw new ConflictHttpException('This shipping allocation has already been remitted to Logistics.');
            }
            if (! $eligible) {
                throw new ConflictHttpException('This shipping allocation is no longer settlement-eligible.');
            }
            if ($settlement->status !== 'pending' || $settlement->remitted_at !== null) {
                throw new ConflictHttpException('Only an eligible, unpaid shipping allocation can be remitted.');
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
                $earning->update([
                    'status' => 'posted',
                    'posted_at' => now(),
                ]);
            } elseif ($earning->status !== 'posted' && $earning->status !== 'paid') {
                throw new ConflictHttpException('The linked Rider earning is not payable.');
            }
            $this->broadcastRiderEarningUpdated($earning);

            $this->audit->record(
                action: 'shipping_settlement.remitted_to_logistics',
                module: 'Finance',
                subject: $settlement,
                description: "Shipping settlement {$settlement->settlement_no} was recorded as remitted to Logistics.",
                oldValues: $oldValues,
                newValues: [
                    'status' => 'remitted',
                    'seller_order_id' => (int) $settlement->seller_order_id,
                    'shipment_id' => (int) $settlement->shipment_id,
                    'logistics_profile_id' => (int) $settlement->logistics_profile_id,
                    'rider_profile_id' => (int) $settlement->rider_profile_id,
                    'zone_level' => (int) $settlement->zone_level,
                    'original_shipping_fee_minor' => (int) $settlement->original_shipping_fee_minor,
                    'buyer_paid_shipping_fee_minor' => (int) $settlement->buyer_paid_shipping_fee_minor,
                    'platform_shipping_subsidy_minor' => (int) $settlement->platform_shipping_subsidy_minor,
                    'shipping_funding_source' => $settlement->shipping_funding_source,
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

    private function verifiedPayoutAccount(
        ShippingSettlement $settlement,
        string $method,
        ?int $payoutAccountId,
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
            ->where('logistics_profile_id', $settlement->logistics_profile_id)
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

        return DB::transaction(function () use ($logisticsUser, $profile, $earningId, $actualAmountMinor, $method, $reference, $paidDate, $reason, $request): RiderEarningPayout {
            $earningStub = RiderEarning::query()
                ->whereKey($earningId)
                ->where('logistics_profile_id', $profile->id)
                ->whereNotNull('shipping_settlement_id')
                ->firstOrFail();
            $settlement = ShippingSettlement::query()
                ->whereKey($earningStub->shipping_settlement_id)
                ->where('logistics_profile_id', $profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $earning = RiderEarning::query()
                ->whereKey($earningStub->id)
                ->where('logistics_profile_id', $profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $rider = RiderProfile::query()
                ->whereKey($earning->rider_profile_id)
                ->where('logistics_profile_id', $profile->id)
                ->first();
            if (! $rider) {
                throw new AuthorizationException('This Rider does not belong to your Logistics provider.');
            }

            $existing = RiderEarningPayout::query()
                ->where('rider_earning_id', $earning->id)
                ->lockForUpdate()
                ->first();
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

            if ($settlement->status !== 'remitted' || ! $settlement->remitted_at) {
                throw new ConflictHttpException('Admin/Finance must remit the shipping allocation before Rider payout.');
            }
            if ($earning->status !== 'posted' || $earning->paid_at !== null) {
                throw new ConflictHttpException('Only a posted, unpaid Rider earning can be paid.');
            }
            if ($actualAmountMinor !== (int) $settlement->rider_share_minor
                || $actualAmountMinor !== (int) $earning->amount_minor) {
                throw ValidationException::withMessages([
                    'actual_amount' => 'Record the exact Rider pool payout of ₱'.number_format((int) $settlement->rider_share_minor / 100, 2).'.',
                ]);
            }

            $payoutReference = $method === 'cash'
                ? $this->riderCashPayoutReference()
                : trim($reference);

            $payout = RiderEarningPayout::query()->create([
                'rider_earning_id' => $earning->id,
                'logistics_profile_id' => $profile->id,
                'amount_minor' => $actualAmountMinor,
                'method' => $method,
                'reference' => $payoutReference,
                'paid_on' => $paidDate->toDateString(),
                'recorded_by' => $logisticsUser->id,
                'reason' => trim($reason),
            ]);
            $earning->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $this->audit->record(
                action: 'rider_earning.externally_paid',
                module: 'Logistics',
                subject: $payout,
                description: "Rider earning {$earning->id} was recorded as paid by Logistics.",
                oldValues: ['status' => 'posted', 'paid_at' => null],
                newValues: [
                    'status' => 'paid',
                    'settlement_type' => $settlement->settlement_type,
                    'rider_earning_id' => (int) $earning->id,
                    'shipping_settlement_id' => (int) $settlement->id,
                    'return_request_id' => $settlement->return_request_id === null ? null : (int) $settlement->return_request_id,
                    'rider_profile_id' => (int) $rider->id,
                    'logistics_profile_id' => (int) $profile->id,
                    'amount_minor' => $actualAmountMinor,
                    'method' => $method,
                    'reference' => $payoutReference,
                    'paid_on' => $paidDate->toDateString(),
                    'reason' => trim($reason),
                ],
                severity: 'warning',
                request: $request,
            );
            $this->broadcastRiderEarningUpdated($earning);

            return $payout->fresh(['riderEarning', 'logisticsProfile', 'recorder']);
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

            $sellerOrderStub = SellerOrder::query()
                ->whereKey($shipmentStub->seller_order_id)
                ->first(['id', 'order_id']);
            if (! $sellerOrderStub || ! $this->wallets->isSellerOrderSettlementEligible($sellerOrderStub)) {
                return;
            }

            $sellerOrder = SellerOrder::query()->whereKey($sellerOrderStub->id)->lockForUpdate()->first();
            $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first();
            if (! $sellerOrder || ! $shipment
                || (int) $shipment->seller_order_id !== (int) $sellerOrder->id
                || $shipment->purpose !== 'outbound'
                || $shipment->status !== ShipmentStatus::Delivered->value
                || $sellerOrder->status !== SellerOrderStatus::Completed->value) {
                return;
            }

            $quote = (array) $sellerOrder->shipping_quote;
            $zoneLevel = $quote['zone_level'] ?? null;
            if (($quote['pricing_rule'] ?? null) !== 'zone_based_v1'
                || ! is_int($zoneLevel)
                || $zoneLevel < 0
                || $zoneLevel > 4) {
                return;
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
            $quoteFundingSource = (string) data_get($quote, 'shipping_fee_breakdown.funding_source', '');
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
            $fundingSource = (string) ($sellerOrder->shipping_funding_source
                ?: data_get($quote, 'shipping_fee_breakdown.funding_source', $subsidyMinor > 0 ? 'buyer_and_platform' : 'buyer'));
            $settlement = ShippingSettlement::query()->firstOrCreate(
                ['shipment_id' => $shipment->id],
                [
                    'settlement_no' => 'SPS-'.Str::upper((string) Str::ulid()),
                    'seller_order_id' => $sellerOrder->id,
                    'logistics_profile_id' => $shipment->logistics_profile_id,
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

            if ((int) $settlement->seller_order_id !== (int) $sellerOrder->id
                || (int) $settlement->logistics_profile_id !== (int) $shipment->logistics_profile_id
                || (int) $settlement->rider_profile_id !== (int) $rider->id
                || (int) $settlement->original_shipping_fee_minor !== $feeMinor) {
                throw new ConflictHttpException('An existing shipping settlement does not match the immutable order snapshot.');
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
                ],
            );
            if ($earning->wasRecentlyCreated) {
                $this->broadcastRiderEarningUpdated($earning);
            }
        }, attempts: 3);
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
                ],
            );
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
