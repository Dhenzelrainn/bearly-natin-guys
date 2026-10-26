<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\SellerOrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\DeliveryAttempt;
use App\Models\LogisticsProfile;
use App\Models\RiderEarning;
use App\Models\RiderEarningPayout;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShippingSettlement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShippingSettlementService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SellerWalletService $wallets,
        private readonly SellerOrderOutboundShipmentGuard $outboundShipmentGuard,
    ) {}

    /**
     * Materialize immutable shipping allocations only after the shared Seller
     * settlement gate passes. The unique shipment constraint makes concurrent
     * Finance/Rider page reads idempotent.
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
    }

    /** @return Collection<int, ShippingSettlement> */
    public function financeSettlements(): Collection
    {
        $this->materializeEligibleSettlements();

        return ShippingSettlement::query()
            ->with([
                'sellerOrder.order',
                'sellerOrder.store',
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
    public function riderEarningsForLogistics(User $user): Collection
    {
        $profile = $this->logisticsProfile($user);
        $this->materializeEligibleSettlements(logisticsProfileId: (int) $profile->id);

        return RiderEarning::query()
            ->where('logistics_profile_id', $profile->id)
            ->whereNotNull('shipping_settlement_id')
            ->with([
                'riderProfile.user',
                'shipment.sellerOrder.order',
                'shippingSettlement',
                'payout.recorder',
            ])
            ->orderByRaw("CASE status WHEN 'posted' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('earned_at')
            ->orderByDesc('id')
            ->get();
    }

    public function recordLogisticsRemittance(
        User $admin,
        int $settlementId,
        int $actualAmountMinor,
        string $method,
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

        $stub = ShippingSettlement::query()->findOrFail($settlementId);
        $shipmentId = (int) $stub->shipment_id;
        $paidDate = $this->paidDate($paidOn);

        return DB::transaction(function () use ($admin, $settlementId, $shipmentId, $actualAmountMinor, $method, $reference, $paidDate, $reason, $request): ShippingSettlement {
            $lockedFinancialState = $this->outboundShipmentGuard->lockSingleForShipment($shipmentId);
            $sellerOrder = $lockedFinancialState['sellerOrder'];
            $eligible = $this->wallets->isSellerOrderSettlementEligible($sellerOrder);
            $settlement = ShippingSettlement::query()->whereKey($settlementId)->lockForUpdate()->firstOrFail();
            if ((int) $settlement->seller_order_id !== (int) $sellerOrder->id
                || (int) $settlement->shipment_id !== (int) $lockedFinancialState['shipment']->id) {
                throw new ConflictHttpException('This shipping settlement no longer matches its authoritative outbound shipment and Seller Order.');
            }
            $expectedAmount = (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor;

            if ($actualAmountMinor !== $expectedAmount || $expectedAmount !== (int) $settlement->original_shipping_fee_minor) {
                throw ValidationException::withMessages([
                    'actual_amount' => 'Record the exact shipping allocation of ₱'.number_format($expectedAmount / 100, 2).'.',
                ]);
            }

            if ($settlement->status === 'remitted') {
                if ((int) $settlement->remitted_amount_minor === $actualAmountMinor
                    && $settlement->remittance_method === $method
                    && hash_equals((string) $settlement->remittance_reference, $reference)
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

            $earning = RiderEarning::query()
                ->where('shipping_settlement_id', $settlement->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $earning->logistics_profile_id !== (int) $settlement->logistics_profile_id
                || (int) $earning->rider_profile_id !== (int) $settlement->rider_profile_id
                || (int) $earning->amount_minor !== (int) $settlement->rider_share_minor) {
                throw new ConflictHttpException('The Rider earning no longer matches this shipping allocation.');
            }

            $oldValues = [
                'status' => $settlement->status,
                'remitted_amount_minor' => $settlement->remitted_amount_minor,
                'remittance_reference' => $settlement->remittance_reference,
            ];
            $settlement->update([
                'status' => 'remitted',
                'remitted_amount_minor' => $actualAmountMinor,
                'remittance_method' => $method,
                'remittance_reference' => $reference,
                'remitted_on' => $paidDate->toDateString(),
                'remitted_at' => now(),
                'remitted_by' => $admin->id,
                'remittance_reason' => trim($reason),
            ]);

            if ($earning->status === 'pending') {
                $earning->update([
                    'status' => 'posted',
                    'posted_at' => now(),
                ]);
            } elseif ($earning->status !== 'posted' && $earning->status !== 'paid') {
                throw new ConflictHttpException('The linked Rider earning is not payable.');
            }

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
                    'reference' => $reference,
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
        $this->validatePayoutDetails($actualAmountMinor, $method, $reference, $paidOn, $reason);
        $paidDate = $this->paidDate($paidOn);

        return DB::transaction(function () use ($logisticsUser, $profile, $earningId, $actualAmountMinor, $method, $reference, $paidDate, $reason, $request): RiderEarningPayout {
            $earningStub = RiderEarning::query()
                ->whereKey($earningId)
                ->where('logistics_profile_id', $profile->id)
                ->whereNotNull('shipping_settlement_id')
                ->firstOrFail();
            $settlementReference = ShippingSettlement::query()
                ->whereKey($earningStub->shipping_settlement_id)
                ->where('logistics_profile_id', $profile->id)
                ->firstOrFail();
            $lockedFinancialState = $this->outboundShipmentGuard->lockSingleForShipment((int) $settlementReference->shipment_id);
            $settlement = ShippingSettlement::query()
                ->whereKey($earningStub->shipping_settlement_id)
                ->where('logistics_profile_id', $profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $settlement->seller_order_id !== (int) $lockedFinancialState['sellerOrder']->id
                || (int) $settlement->shipment_id !== (int) $lockedFinancialState['shipment']->id) {
                throw new ConflictHttpException('This Rider earning no longer matches its authoritative outbound shipment and Seller Order.');
            }
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
                if ((int) $existing->amount_minor === $actualAmountMinor
                    && $existing->method === $method
                    && hash_equals($existing->reference, $reference)
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

            $payout = RiderEarningPayout::query()->create([
                'rider_earning_id' => $earning->id,
                'logistics_profile_id' => $profile->id,
                'amount_minor' => $actualAmountMinor,
                'method' => $method,
                'reference' => $reference,
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
                    'rider_earning_id' => (int) $earning->id,
                    'shipping_settlement_id' => (int) $settlement->id,
                    'rider_profile_id' => (int) $rider->id,
                    'logistics_profile_id' => (int) $profile->id,
                    'amount_minor' => $actualAmountMinor,
                    'method' => $method,
                    'reference' => $reference,
                    'paid_on' => $paidDate->toDateString(),
                    'reason' => trim($reason),
                ],
                severity: 'warning',
                request: $request,
            );

            return $payout->fresh(['riderEarning', 'logisticsProfile', 'recorder']);
        }, attempts: 3);
    }

    private function materializeForShipment(int $shipmentId): void
    {
        DB::transaction(function () use ($shipmentId): void {
            $shipmentReference = Shipment::query()->whereKey($shipmentId)->first();
            if (! $shipmentReference || $shipmentReference->purpose !== 'outbound') {
                return;
            }

            $lockedFinancialState = $this->outboundShipmentGuard->lockSingleForShipment($shipmentId);
            $sellerOrder = $lockedFinancialState['sellerOrder'];
            $shipment = $lockedFinancialState['shipment'];
            if (! $this->wallets->isSellerOrderSettlementEligible($sellerOrder)
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

            RiderEarning::query()->firstOrCreate(
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
        }, attempts: 3);
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
        if (trim($reference) === '' || mb_strlen(trim($reference)) > 150) {
            throw ValidationException::withMessages(['reference' => 'Enter the actual payout reference (up to 150 characters).']);
        }
        if (mb_strlen(trim($reason)) < 5 || mb_strlen(trim($reason)) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason between 5 and 1,000 characters.']);
        }

        $this->paidDate($paidOn);
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
