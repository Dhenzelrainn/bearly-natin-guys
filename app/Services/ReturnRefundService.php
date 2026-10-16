<?php

namespace App\Services;

use App\Enums\ReturnRequestStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\InventoryMovement;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\PickupRequest;
use App\Models\PlatformCommission;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\RefundCashPayout;
use App\Models\RefundCashPayoutEvent;
use App\Models\ReturnEvent;
use App\Models\ReturnEvidence;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerRefundRecovery;
use App\Models\SellerRefundRecoveryEvent;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReturnRefundService
{
    public const RETURN_WINDOW_DAYS = 7;

    private const NON_REFUND_STATUSES = ['failed', 'cancelled'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly CommissionLedgerService $commissionLedger,
    ) {}

    /** @return array<string, mixed> */
    public function buyerWorkspace(User $buyer, int $sellerOrderId): array
    {
        $sellerOrder = SellerOrder::query()
            ->whereKey($sellerOrderId)
            ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))
            ->with(['order.payments', 'store', 'items'])
            ->firstOrFail();

        $eligible = $this->eligibleForRequest($sellerOrder);
        $returnable = $eligible
            ? $this->returnableQuantities($sellerOrder->items)
            : [];
        $items = $sellerOrder->items->map(fn (OrderItem $item): array => [
            'id' => (int) $item->id,
            'name' => (string) $item->product_name,
            'variant' => (string) ($item->variant_name ?: 'Standard'),
            'sku' => (string) $item->sku,
            'quantity' => (int) $item->quantity,
            'returnable_quantity' => (int) ($returnable[$item->id] ?? 0),
            'unit_price_minor' => (int) $item->unit_price_minor,
            'image_url' => $this->orderItemImageUrl($item),
        ])->values()->all();

        $cases = ReturnRequest::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->with(['refunds', 'returnShipment.waybill'])
            ->latest('submitted_at')
            ->get();

        return [
            'seller_order' => $sellerOrder,
            'items' => $items,
            'cases' => $cases,
            'can_request' => $eligible && collect($returnable)->contains(fn (int $quantity): bool => $quantity > 0),
            'deadline' => $sellerOrder->completed_at
                ? CarbonImmutable::parse($sellerOrder->completed_at)->addDays(self::RETURN_WINDOW_DAYS)->endOfDay()
                : null,
            'reason_codes' => [
                'item_arrived_damaged' => 'Item arrived damaged',
                'wrong_item_received' => 'Wrong item received',
                'item_not_as_described' => 'Item not as described',
                'missing_item' => 'Item missing from the parcel',
                'other' => 'Other issue',
            ],
        ];
    }

    public function canRequest(User $buyer, SellerOrder $sellerOrder): bool
    {
        $owned = SellerOrder::query()
            ->whereKey($sellerOrder->id)
            ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))
            ->with(['order.payments', 'items'])
            ->first();

        if (! $owned || ! $this->eligibleForRequest($owned)) {
            return false;
        }

        return collect($this->returnableQuantities($owned->items))
            ->contains(fn (int $quantity): bool => $quantity > 0);
    }

    /** @param array<int|string, int|string> $itemQuantities
     * @param  array<int, UploadedFile>  $evidenceFiles
     */
    public function createBuyerRequest(
        User $buyer,
        int $sellerOrderId,
        array $itemQuantities,
        string $requestType,
        string $reasonCode,
        ?string $buyerNote,
        array $evidenceFiles,
    ): ReturnRequest {
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $buyer,
                $sellerOrderId,
                $itemQuantities,
                $requestType,
                $reasonCode,
                $buyerNote,
                $evidenceFiles,
                &$storedPaths,
            ): ReturnRequest {
                $sellerOrderStub = SellerOrder::query()
                    ->whereKey($sellerOrderId)
                    ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))
                    ->firstOrFail();

                $order = Order::query()
                    ->whereKey($sellerOrderStub->order_id)
                    ->where('buyer_id', $buyer->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $sellerOrder = SellerOrder::query()
                    ->whereKey($sellerOrderId)
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->with('items')
                    ->firstOrFail();

                if (! $this->eligibleForRequest($sellerOrder)) {
                    throw ValidationException::withMessages([
                        'return' => 'This Seller Order is outside the 7-day return window or is not completed.',
                    ]);
                }

                if (! in_array($requestType, ['return_refund', 'refund_only'], true)) {
                    throw ValidationException::withMessages(['request_type' => 'Choose a valid request type.']);
                }

                if (! in_array($reasonCode, [
                    'item_arrived_damaged',
                    'wrong_item_received',
                    'item_not_as_described',
                    'missing_item',
                    'other',
                ], true)) {
                    throw ValidationException::withMessages(['reason_code' => 'Choose a valid reason.']);
                }

                $returnable = $this->returnableQuantities($sellerOrder->items);
                $quantities = [];
                foreach ($itemQuantities as $itemId => $quantity) {
                    if (! is_numeric($itemId) || ! is_numeric($quantity) || (int) $quantity < 0) {
                        throw ValidationException::withMessages(['item_quantities' => 'Enter valid item quantities.']);
                    }

                    $quantity = (int) $quantity;
                    if ($quantity === 0) {
                        continue;
                    }

                    $itemId = (int) $itemId;
                    if (! isset($returnable[$itemId]) || $quantity > $returnable[$itemId]) {
                        throw ValidationException::withMessages([
                            'item_quantities' => 'One or more requested quantities are no longer eligible for return.',
                        ]);
                    }
                    $quantities[$itemId] = $quantity;
                }

                if ($quantities === []) {
                    throw ValidationException::withMessages(['item_quantities' => 'Select at least one item quantity.']);
                }

                $captured = $this->capturedAmountMinor($order);
                if ($captured < 1) {
                    throw ValidationException::withMessages([
                        'return' => 'A recorded paid order is required before requesting a refund.',
                    ]);
                }

                $amountMinor = 0;
                $itemAmounts = [];
                $allItemNet = max(1, (int) $sellerOrder->items->sum(fn (OrderItem $item): int => max(
                    0,
                    ((int) $item->unit_price_minor * (int) $item->quantity) - (int) $item->discount_minor,
                )));

                foreach ($sellerOrder->items as $item) {
                    if (! isset($quantities[$item->id])) {
                        continue;
                    }
                    $quantity = $quantities[$item->id];
                    $selectedGross = (int) $item->unit_price_minor * $quantity;
                    $selectedLineDiscount = intdiv((int) $item->discount_minor * $quantity, max(1, (int) $item->quantity));
                    $selectedNet = max(0, $selectedGross - $selectedLineDiscount);
                    $sellerDiscountShare = intdiv((int) $sellerOrder->discount_minor * $selectedNet, $allItemNet);
                    $refundMinor = max(0, $selectedNet - $sellerDiscountShare);

                    if ($quantity === (int) $item->quantity) {
                        $fullLineNet = max(0, (int) $item->subtotal_minor - (int) $item->discount_minor);
                        $refundMinor = max(0, $fullLineNet - $sellerDiscountShare);
                    }

                    $itemAmounts[$item->id] = [$quantity, $refundMinor];
                    $amountMinor += $refundMinor;
                }

                if ($amountMinor < 1) {
                    throw ValidationException::withMessages(['item_quantities' => 'The selected items have no refundable value.']);
                }

                $this->assertRefundCapacity($order, $amountMinor, null, $sellerOrder->id);

                $now = now();
                $returnRequest = ReturnRequest::query()->create([
                    'return_no' => 'RR-'.Str::upper((string) Str::ulid()),
                    'order_id' => $order->id,
                    'seller_order_id' => $sellerOrder->id,
                    'buyer_id' => $buyer->id,
                    'store_id' => $sellerOrder->store_id,
                    'request_type' => $requestType,
                    'reason_code' => $reasonCode,
                    'buyer_note' => filled($buyerNote) ? trim((string) $buyerNote) : null,
                    'status' => ReturnRequestStatus::AwaitingSeller->value,
                    'requested_amount_minor' => $amountMinor,
                    'response_due_at' => $now->copy()->addDays(2),
                    'submitted_at' => $now,
                ]);

                foreach ($itemAmounts as $itemId => [$quantity, $refundMinor]) {
                    ReturnItem::query()->create([
                        'return_request_id' => $returnRequest->id,
                        'order_item_id' => $itemId,
                        'quantity' => $quantity,
                        'refund_amount_minor' => $refundMinor,
                        'resolution' => $requestType === 'return_refund' ? 'return_required' : 'refund_only',
                    ]);
                }

                $this->storeEvidence($returnRequest, $buyer, $evidenceFiles, 'buyer', $storedPaths);
                $this->event($returnRequest, $buyer, 'request_submitted', null, ReturnRequestStatus::AwaitingSeller->value, $buyerNote);

                return $returnRequest->fresh(['items.orderItem', 'evidence', 'events']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }
            throw $exception;
        }
    }

    /** @param array<int, UploadedFile> $evidenceFiles */
    public function sellerRespond(
        User $seller,
        int $returnRequestId,
        string $response,
        array $evidenceFiles,
    ): ReturnRequest {
        $storeId = $seller->sellerProfile?->store?->id;
        abort_unless($storeId, 404, 'Seller Store not found.');
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($seller, $storeId, $returnRequestId, $response, $evidenceFiles, &$storedPaths): ReturnRequest {
                $stub = ReturnRequest::query()->whereKey($returnRequestId)->where('store_id', $storeId)->firstOrFail();
                Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
                $returnRequest = ReturnRequest::query()->whereKey($stub->id)->where('store_id', $storeId)->lockForUpdate()->firstOrFail();

                if ($returnRequest->status !== ReturnRequestStatus::AwaitingSeller->value) {
                    throw ValidationException::withMessages(['return' => 'This case is no longer awaiting a Seller response.']);
                }
                if ($evidenceFiles === []) {
                    throw ValidationException::withMessages([
                        'evidence' => 'Attach at least one supporting evidence file with the Seller response.',
                    ]);
                }

                $fromStatus = $returnRequest->status;
                $returnRequest->update([
                    'seller_response' => trim($response),
                    'seller_responded_at' => now(),
                    'status' => ReturnRequestStatus::UnderReview->value,
                ]);
                $this->storeEvidence($returnRequest, $seller, $evidenceFiles, 'seller', $storedPaths);
                $this->event($returnRequest, $seller, 'seller_response_submitted', $fromStatus, ReturnRequestStatus::UnderReview->value, trim($response));

                return $returnRequest->fresh(['items.orderItem', 'evidence', 'events']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }
            throw $exception;
        }
    }

    /** @param array<int, UploadedFile> $evidenceFiles */
    public function addBuyerEvidence(
        User $buyer,
        int $returnRequestId,
        ?string $note,
        array $evidenceFiles,
    ): ReturnRequest {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($buyer, $returnRequestId, $note, $evidenceFiles, &$storedPaths): ReturnRequest {
                $stub = ReturnRequest::query()->whereKey($returnRequestId)->where('buyer_id', $buyer->id)->firstOrFail();
                Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
                $returnRequest = ReturnRequest::query()->whereKey($stub->id)->where('buyer_id', $buyer->id)->lockForUpdate()->firstOrFail();

                if ($returnRequest->status !== ReturnRequestStatus::AwaitingEvidence->value) {
                    throw ValidationException::withMessages(['return' => 'This case is not awaiting additional evidence.']);
                }
                if (! in_array($this->evidenceRequestedFrom($returnRequest), ['buyer', 'both'], true)) {
                    throw ValidationException::withMessages(['return' => 'The current evidence request is not assigned to the Buyer.']);
                }
                $this->assertAdditionalEvidenceProvided($note, $evidenceFiles);
                if ($this->hasEvidenceResponseSinceLatestRequest($returnRequest, 'buyer')) {
                    throw ValidationException::withMessages(['return' => 'Buyer evidence has already been submitted for the current request.']);
                }

                $this->storeEvidence($returnRequest, $buyer, $evidenceFiles, 'buyer_additional', $storedPaths);
                $fromStatus = $returnRequest->status;
                $newStatus = $this->allRequestedEvidenceSubmitted($returnRequest, 'buyer')
                    ? ReturnRequestStatus::UnderReview->value
                    : ReturnRequestStatus::AwaitingEvidence->value;
                $returnRequest->update(['status' => $newStatus]);
                $this->event($returnRequest, $buyer, 'buyer_evidence_submitted', $fromStatus, $newStatus, $note);

                return $returnRequest->fresh(['items.orderItem', 'evidence', 'events']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }
            throw $exception;
        }
    }

    /** @param array<int, UploadedFile> $evidenceFiles */
    public function addSellerEvidence(
        User $seller,
        int $returnRequestId,
        ?string $note,
        array $evidenceFiles,
    ): ReturnRequest {
        $storeId = $seller->sellerProfile?->store?->id;
        abort_unless($storeId, 404, 'Seller Store not found.');
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($seller, $storeId, $returnRequestId, $note, $evidenceFiles, &$storedPaths): ReturnRequest {
                $stub = ReturnRequest::query()->whereKey($returnRequestId)->where('store_id', $storeId)->firstOrFail();
                Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
                $returnRequest = ReturnRequest::query()->whereKey($stub->id)->where('store_id', $storeId)->lockForUpdate()->firstOrFail();

                if ($returnRequest->status !== ReturnRequestStatus::AwaitingEvidence->value
                    || ! in_array($this->evidenceRequestedFrom($returnRequest), ['seller', 'both'], true)) {
                    throw ValidationException::withMessages(['return' => 'This case is not awaiting additional Seller evidence.']);
                }
                $this->assertAdditionalEvidenceProvided($note, $evidenceFiles);
                if ($this->hasEvidenceResponseSinceLatestRequest($returnRequest, 'seller')) {
                    throw ValidationException::withMessages(['return' => 'Seller evidence has already been submitted for the current request.']);
                }

                $this->storeEvidence($returnRequest, $seller, $evidenceFiles, 'seller_additional', $storedPaths);
                $fromStatus = $returnRequest->status;
                $newStatus = $this->allRequestedEvidenceSubmitted($returnRequest, 'seller')
                    ? ReturnRequestStatus::UnderReview->value
                    : ReturnRequestStatus::AwaitingEvidence->value;
                $returnRequest->update(['status' => $newStatus]);
                $this->event($returnRequest, $seller, 'seller_evidence_submitted', $fromStatus, $newStatus, $note);

                return $returnRequest->fresh(['items.orderItem', 'evidence', 'events']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }
            throw $exception;
        }
    }

    public function adminApprove(
        User $admin,
        int $returnRequestId,
        string $financiallyResponsibleParty,
        string $financialResponsibilityReason,
        Request $request,
    ): ReturnRequest {
        if (! in_array($financiallyResponsibleParty, ['seller', 'platform'], true)) {
            throw ValidationException::withMessages(['financially_responsible_party' => 'Choose whether the Seller or Platform is financially responsible.']);
        }
        if (mb_strlen(trim($financialResponsibilityReason)) < 5) {
            throw ValidationException::withMessages(['financial_responsibility_reason' => 'Explain why this party is financially responsible.']);
        }

        return DB::transaction(function () use ($admin, $returnRequestId, $financiallyResponsibleParty, $financialResponsibilityReason, $request): ReturnRequest {
            $stub = ReturnRequest::query()->findOrFail($returnRequestId);
            $order = Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
            $returnRequest = ReturnRequest::query()->whereKey($stub->id)->lockForUpdate()->with(['sellerOrder.items', 'store.sellerProfile.pickupAddress'])->firstOrFail();

            if ($returnRequest->status !== ReturnRequestStatus::UnderReview->value) {
                throw ValidationException::withMessages(['return' => 'The Seller must respond before Admin can decide this case.']);
            }

            if (Refund::query()->where('return_request_id', $returnRequest->id)->exists()) {
                throw ValidationException::withMessages(['return' => 'A refund has already been created for this case.']);
            }

            $payment = Payment::query()
                ->where('order_id', $order->id)
                ->where('method', 'cod')
                ->whereIn('status', ['paid', 'partially_refunded'])
                ->whereNotNull('paid_at')
                ->orderByDesc('paid_at')
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                throw ValidationException::withMessages(['return' => 'A recorded paid COD payment is required before approving a refund.']);
            }

            $this->assertRefundCapacity($order, (int) $returnRequest->requested_amount_minor, $returnRequest->id, $returnRequest->seller_order_id);
            if ($payment->status === 'paid' && ! PlatformCommission::query()
                ->where('seller_order_id', $returnRequest->seller_order_id)
                ->whereNull('refund_id')
                ->where('type', 'sale')
                ->exists()) {
                $this->commissionLedger->finalize($payment);
            }

            $refund = Refund::query()->create([
                'refund_no' => 'RFD-'.Str::upper((string) Str::ulid()),
                'return_request_id' => $returnRequest->id,
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'amount_minor' => (int) $returnRequest->requested_amount_minor,
                'status' => 'pending',
                'method' => 'manual',
                'financially_responsible_party' => $financiallyResponsibleParty,
                'financial_responsibility_reason' => trim($financialResponsibilityReason),
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            $oldStatus = $returnRequest->status;
            if ($returnRequest->request_type === 'return_refund') {
                $pickupRequest = $this->createReturnShipment($returnRequest, $order, $payment, $admin);
                $newStatus = ReturnRequestStatus::ReturnPickupRequested->value;
                $resolution = 'Admin approved the physical return and refund. Pickup is handled by the Logistics provider serving the Buyer’s city; a cross-city return is handed to the Seller-address city provider before final delivery. Refund waits for Seller inspection. '.ucfirst($financiallyResponsibleParty).' is financially responsible for the approved refund.';
                $metadata = [
                    'refund_id' => $refund->id,
                    'pickup_request_id' => $pickupRequest->id,
                    'shipment_id' => $returnRequest->return_shipment_id,
                    'financially_responsible_party' => $financiallyResponsibleParty,
                    'financial_responsibility_reason' => trim($financialResponsibilityReason),
                ];
            } else {
                $newStatus = ReturnRequestStatus::RefundPending->value;
                $resolution = 'Admin approved the refund. Admin records the external payout after it is sent. '.ucfirst($financiallyResponsibleParty).' is financially responsible for the approved refund.';
                $metadata = [
                    'refund_id' => $refund->id,
                    'financially_responsible_party' => $financiallyResponsibleParty,
                    'financial_responsibility_reason' => trim($financialResponsibilityReason),
                ];
            }

            $returnRequest->update([
                'status' => $newStatus,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'resolved_at' => null,
                'resolution' => $resolution,
            ]);
            $this->event($returnRequest, $admin, 'admin_approved', $oldStatus, $newStatus, $resolution, $metadata);
            $this->audit->record(
                action: 'return.refund_approved',
                module: 'Returns & Refunds',
                subject: $returnRequest,
                description: "{$returnRequest->return_no} was approved for an externally paid refund.",
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => $newStatus,
                    'refund_id' => $refund->id,
                    'refund_amount_minor' => $refund->amount_minor,
                    'financially_responsible_party' => $financiallyResponsibleParty,
                    'financial_responsibility_reason' => trim($financialResponsibilityReason),
                ],
                severity: 'warning',
                request: $request,
            );

            return $returnRequest->fresh(['refunds', 'returnShipment.waybill', 'events']);
        }, attempts: 3);
    }

    public function recordLegacyRefundResponsibility(
        User $admin,
        int $refundId,
        string $financiallyResponsibleParty,
        string $financialResponsibilityReason,
        Request $request,
    ): Refund {
        if (! in_array($financiallyResponsibleParty, ['seller', 'platform'], true)) {
            throw ValidationException::withMessages(['financially_responsible_party' => 'Choose whether the Seller or Platform is financially responsible.']);
        }
        if (mb_strlen(trim($financialResponsibilityReason)) < 5) {
            throw ValidationException::withMessages(['financial_responsibility_reason' => 'Explain why this party is financially responsible.']);
        }

        return DB::transaction(function () use ($admin, $refundId, $financiallyResponsibleParty, $financialResponsibilityReason, $request): Refund {
            $stub = Refund::query()->findOrFail($refundId);
            Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($stub->id)->lockForUpdate()->firstOrFail();
            $returnRequest = $refund->return_request_id
                ? ReturnRequest::query()->whereKey($refund->return_request_id)->lockForUpdate()->firstOrFail()
                : null;

            if ($refund->status !== 'pending'
                || ! $returnRequest
                || $returnRequest->status !== ReturnRequestStatus::RefundPending->value
                || filled($refund->financially_responsible_party)) {
                throw ValidationException::withMessages(['refund' => 'Financial responsibility is already recorded or this refund is no longer awaiting payout.']);
            }

            $refund->update([
                'financially_responsible_party' => $financiallyResponsibleParty,
                'financial_responsibility_reason' => trim($financialResponsibilityReason),
            ]);
            $this->event(
                $returnRequest,
                $admin,
                'refund_responsibility_recorded',
                $returnRequest->status,
                $returnRequest->status,
                'Admin recorded who is financially responsible before any payout or Seller recovery.',
                [
                    'refund_id' => $refund->id,
                    'financially_responsible_party' => $financiallyResponsibleParty,
                    'financial_responsibility_reason' => trim($financialResponsibilityReason),
                ],
            );
            $this->audit->record(
                action: 'refund.financial_responsibility_recorded',
                module: 'Returns & Refunds',
                subject: $refund,
                description: "Financial responsibility was recorded for {$refund->refund_no}.",
                oldValues: ['financially_responsible_party' => null],
                newValues: [
                    'financially_responsible_party' => $financiallyResponsibleParty,
                    'financial_responsibility_reason' => trim($financialResponsibilityReason),
                ],
                severity: 'warning',
                request: $request,
            );

            return $refund->fresh(['returnRequest', 'sellerRecovery']);
        }, attempts: 3);
    }

    public function adminReject(User $admin, int $returnRequestId, string $reason, Request $request): ReturnRequest
    {
        return DB::transaction(function () use ($admin, $returnRequestId, $reason, $request): ReturnRequest {
            $stub = ReturnRequest::query()->findOrFail($returnRequestId);
            Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
            $returnRequest = ReturnRequest::query()->whereKey($stub->id)->lockForUpdate()->firstOrFail();
            $this->assertAdminReviewable($returnRequest);

            $fromStatus = $returnRequest->status;
            $returnRequest->update([
                'status' => ReturnRequestStatus::Rejected->value,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'resolved_at' => now(),
                'resolution' => trim($reason),
            ]);
            $this->event($returnRequest, $admin, 'admin_rejected', $fromStatus, ReturnRequestStatus::Rejected->value, trim($reason));
            $this->audit->record(
                action: 'return.request_rejected',
                module: 'Returns & Refunds',
                subject: $returnRequest,
                description: "{$returnRequest->return_no} was rejected by the administrator.",
                oldValues: ['status' => $fromStatus],
                newValues: ['status' => ReturnRequestStatus::Rejected->value, 'reason' => trim($reason)],
                severity: 'warning',
                request: $request,
            );

            return $returnRequest->fresh(['events']);
        }, attempts: 3);
    }

    public function adminRequestEvidence(User $admin, int $returnRequestId, string $note, string $requestedFrom, Request $request): ReturnRequest
    {
        return DB::transaction(function () use ($admin, $returnRequestId, $note, $requestedFrom, $request): ReturnRequest {
            $stub = ReturnRequest::query()->findOrFail($returnRequestId);
            Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
            $returnRequest = ReturnRequest::query()->whereKey($stub->id)->lockForUpdate()->firstOrFail();
            $this->assertAdminReviewable($returnRequest);
            if (! in_array($requestedFrom, ['buyer', 'seller', 'both'], true)) {
                throw ValidationException::withMessages(['request_from' => 'Choose who should provide additional evidence.']);
            }

            $fromStatus = $returnRequest->status;
            $returnRequest->update([
                'status' => ReturnRequestStatus::AwaitingEvidence->value,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'resolution' => trim($note),
            ]);
            $this->event($returnRequest, $admin, 'additional_evidence_requested', $fromStatus, ReturnRequestStatus::AwaitingEvidence->value, trim($note), ['requested_from' => $requestedFrom]);
            $this->audit->record(
                action: 'return.evidence_requested',
                module: 'Returns & Refunds',
                subject: $returnRequest,
                description: "Additional evidence was requested for {$returnRequest->return_no}.",
                oldValues: ['status' => $fromStatus],
                newValues: ['status' => ReturnRequestStatus::AwaitingEvidence->value, 'note' => trim($note), 'requested_from' => $requestedFrom],
                severity: 'info',
                request: $request,
            );

            return $returnRequest->fresh(['events']);
        }, attempts: 3);
    }

    public function recordReturnPickedUp(int $returnRequestId, User $actor, int $shipmentId): void
    {
        $returnRequest = ReturnRequest::query()->whereKey($returnRequestId)->lockForUpdate()->first();
        if (! $returnRequest || (int) $returnRequest->return_shipment_id !== $shipmentId) {
            return;
        }

        if ($returnRequest->status !== ReturnRequestStatus::ReturnPickupRequested->value) {
            return;
        }

        $fromStatus = $returnRequest->status;
        $returnRequest->update(['status' => ReturnRequestStatus::ReturnInTransit->value]);
        $this->event($returnRequest, $actor, 'return_picked_up', $fromStatus, ReturnRequestStatus::ReturnInTransit->value, 'The Logistics provider assigned to the original shipment collected the return parcel.');
    }

    public function recordReturnDelivered(int $shipmentId, User $actor): void
    {
        $returnRequest = ReturnRequest::query()
            ->where('return_shipment_id', $shipmentId)
            ->lockForUpdate()
            ->first();
        if (! $returnRequest || $returnRequest->status !== ReturnRequestStatus::ReturnInTransit->value) {
            return;
        }

        $fromStatus = $returnRequest->status;
        $returnRequest->update([
            'status' => ReturnRequestStatus::AwaitingInspection->value,
            'resolution' => 'Return parcel was delivered to the Seller. Seller inspection is required before payout.',
        ]);
        $this->event($returnRequest, $actor, 'return_delivered_to_seller', $fromStatus, ReturnRequestStatus::AwaitingInspection->value, 'Rider recorded delivery to the Seller.');
    }

    /** @param array<int, array{condition_code: string, resolution: string}> $dispositions */
    public function inspectReturn(User $seller, int $returnRequestId, array $dispositions): ReturnRequest
    {
        $storeId = $seller->sellerProfile?->store?->id;
        abort_unless($storeId, 404, 'Seller Store not found.');

        return DB::transaction(function () use ($seller, $storeId, $returnRequestId, $dispositions): ReturnRequest {
            $stub = ReturnRequest::query()->whereKey($returnRequestId)->where('store_id', $storeId)->firstOrFail();
            Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
            $returnRequest = ReturnRequest::query()->whereKey($stub->id)->where('store_id', $storeId)->lockForUpdate()->with('items.orderItem')->firstOrFail();

            if ($returnRequest->status !== ReturnRequestStatus::AwaitingInspection->value) {
                throw ValidationException::withMessages(['return' => 'This return is not ready for Seller inspection.']);
            }
            $shipmentDelivered = Shipment::query()
                ->whereKey($returnRequest->return_shipment_id)
                ->where('purpose', 'return')
                ->where('status', 'delivered')
                ->exists();
            if (! $shipmentDelivered) {
                throw ValidationException::withMessages(['return' => 'Logistics must record delivery to the Seller before inspection.']);
            }

            $items = $returnRequest->items->keyBy('id');
            if (count($dispositions) !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Inspect every returned item before saving.']);
            }

            $variantIds = $items->map(fn (ReturnItem $item) => $item->orderItem?->product_variant_id)->filter()->unique()->sort()->values();
            $variants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->whereHas('product', fn ($query) => $query->where('store_id', $storeId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $now = now();

            foreach ($items as $item) {
                $input = $dispositions[$item->id] ?? null;
                if (! is_array($input)) {
                    throw ValidationException::withMessages(['items' => 'Choose an inspection result for every item.']);
                }
                $condition = $input['condition_code'] ?? null;
                $resolution = $input['resolution'] ?? null;
                if (! in_array($condition, ['sellable', 'damaged', 'opened', 'missing_parts'], true)
                    || ! in_array($resolution, ['restock', 'quarantine', 'dispose'], true)
                    || ($resolution === 'restock' && $condition !== 'sellable')
                    || ($condition === 'sellable' && $resolution !== 'restock')) {
                    throw ValidationException::withMessages(['items' => 'Sellable items must be restocked; damaged or unsellable items must be quarantined or disposed.']);
                }

                $item->update([
                    'condition_code' => $condition,
                    'resolution' => $resolution,
                ]);

                if ($resolution !== 'restock') {
                    continue;
                }

                $variantId = $item->orderItem?->product_variant_id;
                $variant = $variantId ? $variants->get($variantId) : null;
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => 'A returned item can only be restocked while its original Seller variant still exists.']);
                }
                $newBalance = (int) $variant->stock_on_hand + (int) $item->quantity;
                if ($newBalance > 4_294_967_295) {
                    throw ValidationException::withMessages(['items' => 'Restocking this item exceeds the supported stock balance.']);
                }
                $variant->update(['stock_on_hand' => $newBalance]);
                InventoryMovement::query()->create([
                    'variant_id' => $variant->id,
                    'type' => 'return_restock',
                    'quantity_delta' => (int) $item->quantity,
                    'balance_after' => $newBalance,
                    'reference_type' => ReturnRequest::class,
                    'reference_id' => $returnRequest->id,
                    'reason' => "Inspected resalable return {$returnRequest->return_no}",
                    'created_by' => $seller->id,
                    'occurred_at' => $now,
                ]);
            }

            $fromStatus = $returnRequest->status;
            $returnRequest->update([
                'status' => ReturnRequestStatus::RefundPending->value,
                'resolution' => 'Seller inspection recorded. Admin may now make the approved manual refund payout.',
            ]);
            $this->event($returnRequest, $seller, 'seller_inspection_completed', $fromStatus, ReturnRequestStatus::RefundPending->value, 'Seller recorded condition and disposition for every returned item.');

            return $returnRequest->fresh(['items.orderItem', 'events']);
        }, attempts: 3);
    }

    public function recordManualPayout(
        User $admin,
        int $refundId,
        string $method,
        string $reference,
        string $externalPaidOn,
        UploadedFile $receipt,
        Request $request,
        ?int $cashPayoutId = null,
        ?string $completionReason = null,
    ): Refund {
        if (! in_array($method, ['gcash', 'bank_transfer', 'cash', 'other'], true)) {
            throw ValidationException::withMessages(['method' => 'Choose a valid manual payout method.']);
        }
        if (trim($reference) === '') {
            throw ValidationException::withMessages(['provider_reference' => 'Enter the external payout reference or receipt or acknowledgment number.']);
        }
        if (! $request->boolean('external_payout_confirmed')) {
            throw ValidationException::withMessages([
                'external_payout_confirmed' => 'Confirm that the external payout has already been made before recording it.',
            ]);
        }
        if (! $receipt->isValid() || ! in_array($receipt->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            throw ValidationException::withMessages(['receipt' => 'Upload a valid JPG, PNG, WebP, or PDF payout receipt.']);
        }

        $stub = Refund::query()->findOrFail($refundId);
        $receiptPath = $receipt->store('refund-payout-receipts/'.$stub->refund_no, 'local');
        if (! $receiptPath) {
            throw ValidationException::withMessages(['receipt' => 'The payout receipt could not be stored. Please try again.']);
        }

        try {
            return DB::transaction(function () use ($admin, $refundId, $method, $reference, $externalPaidOn, $receipt, $receiptPath, $request, $cashPayoutId, $completionReason): Refund {
                $stub = Refund::query()->findOrFail($refundId);
                $order = Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
                $refund = Refund::query()->whereKey($stub->id)->lockForUpdate()->firstOrFail();
                $returnRequest = $refund->return_request_id
                    ? ReturnRequest::query()->whereKey($refund->return_request_id)->lockForUpdate()->firstOrFail()
                    : null;

                $cashPayout = RefundCashPayout::query()
                    ->where('refund_id', $refund->id)
                    ->lockForUpdate()
                    ->first();
                if ($cashPayoutId !== null) {
                    if (! $cashPayout
                        || (int) $cashPayout->id !== $cashPayoutId
                        || $cashPayout->status !== 'awaiting_finance_completion'
                        || $method !== 'cash'
                        || (int) $cashPayout->amount_minor !== (int) $refund->amount_minor
                        || ! $cashPayout->logistics_verified_at
                        || ! filled($completionReason)
                        || mb_strlen(trim((string) $completionReason)) < 5) {
                        throw ValidationException::withMessages(['refund' => 'The assigned cash payout requires Logistics verification and a Finance completion reason.']);
                    }
                } elseif ($cashPayout && $cashPayout->status !== 'completed') {
                    throw ValidationException::withMessages(['refund' => 'This refund is assigned to the Rider cash-custody workflow and cannot be completed through a separate payout action.']);
                }

                if ($refund->status !== 'pending' || ! $returnRequest || $returnRequest->status !== ReturnRequestStatus::RefundPending->value) {
                    throw ValidationException::withMessages(['refund' => 'This refund is not ready for manual payout recording.']);
                }
                if (! in_array($refund->financially_responsible_party, ['seller', 'platform'], true)) {
                    throw ValidationException::withMessages(['refund' => 'Record who is financially responsible before recording the external payout.']);
                }
                if ($returnRequest->request_type === 'return_refund' && $returnRequest->items()
                    ->where(function ($query): void {
                        $query->whereNull('condition_code')
                            ->orWhereNotIn('resolution', ['restock', 'quarantine', 'dispose']);
                    })
                    ->exists()) {
                    throw ValidationException::withMessages(['refund' => 'Seller inspection must be complete before recording this return refund.']);
                }

                try {
                    $paidOn = CarbonImmutable::createFromFormat('!Y-m-d', $externalPaidOn, config('app.timezone'));
                } catch (Throwable) {
                    $paidOn = null;
                }
                if (! $paidOn || $paidOn->greaterThan(CarbonImmutable::today(config('app.timezone')))) {
                    throw ValidationException::withMessages(['external_paid_on' => 'Enter the actual payout date; future dates are not allowed.']);
                }

                $payment = Payment::query()->whereKey($refund->payment_id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
                if ($payment->method !== 'cod' || ! in_array($payment->status, ['paid', 'partially_refunded'], true) || ! $payment->paid_at) {
                    throw ValidationException::withMessages(['refund' => 'The original order does not have a recorded paid COD payment.']);
                }

                $priorRefundedMinor = (int) Refund::query()
                    ->where('order_id', $order->id)
                    ->where('status', 'completed')
                    ->where('id', '!=', $refund->id)
                    ->sum('amount_minor');
                $capturedMinor = $this->capturedAmountMinor($order);
                if ($priorRefundedMinor + (int) $refund->amount_minor > $capturedMinor) {
                    throw ValidationException::withMessages(['refund' => 'The payout would exceed the remaining captured order amount.']);
                }

                $financialEffect = $this->postRefundLedger($refund, $returnRequest, $payment);
                $processedAt = now();
                $refund->update([
                    'status' => 'completed',
                    'payout_amount_minor' => (int) $refund->amount_minor,
                    'method' => $method,
                    'provider_reference' => trim($reference),
                    'external_paid_on' => $paidOn->toDateString(),
                    'payout_receipt_disk' => 'local',
                    'payout_receipt_path' => $receiptPath,
                    'payout_receipt_mime_type' => $receipt->getMimeType(),
                    'payout_receipt_original_name' => $this->safeOriginalName($receipt->getClientOriginalName()),
                    'payout_receipt_size_bytes' => $receipt->getSize(),
                    'processed_by' => $admin->id,
                    'processed_at' => $processedAt,
                    'completed_at' => $processedAt,
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);

                if ($cashPayoutId !== null && $cashPayout) {
                    $cashPayout->update([
                        'status' => 'completed',
                        'completed_at' => $processedAt,
                        'completed_by' => $admin->id,
                    ]);
                    RefundCashPayoutEvent::query()->create([
                        'refund_cash_payout_id' => $cashPayout->id,
                        'event_type' => 'cash_refund_completed_by_finance',
                        'from_status' => 'awaiting_finance_completion',
                        'to_status' => 'completed',
                        'actor_user_id' => $admin->id,
                        'amount_minor' => (int) $cashPayout->amount_minor,
                        'reference' => $cashPayout->acknowledgment_reference,
                        'note' => trim((string) $completionReason),
                        'occurred_at' => $processedAt,
                    ]);
                }

                $returnRequest->update([
                    'status' => ReturnRequestStatus::Resolved->value,
                    'resolved_at' => $processedAt,
                    'resolution' => 'External payout of ₱'.number_format((int) $refund->amount_minor / 100, 2).' recorded by Admin via '.str_replace('_', ' ', $method).' on '.$paidOn->format('M j, Y').'. '.ucfirst($refund->financially_responsible_party).' is financially responsible. Bearly did not transfer funds.',
                ]);
                $this->event($returnRequest, $admin, 'manual_refund_recorded', ReturnRequestStatus::RefundPending->value, ReturnRequestStatus::Resolved->value, 'Admin recorded the completed external payout. Bearly did not transfer funds.', [
                    'refund_id' => $refund->id,
                    'method' => $method,
                    'reference' => trim($reference),
                    'external_paid_on' => $paidOn->toDateString(),
                    'amount_minor' => (int) $refund->amount_minor,
                    'payout_amount_minor' => (int) $refund->amount_minor,
                    'financially_responsible_party' => $refund->financially_responsible_party,
                    'seller_recovery_amount_minor' => $financialEffect['seller_recovery_minor'],
                ]);

                $refundedTotal = $priorRefundedMinor + (int) $refund->amount_minor;
                $paymentStatus = $refundedTotal >= $capturedMinor ? 'refunded' : 'partially_refunded';
                $payment->update([
                    'status' => $paymentStatus,
                    'refunded_at' => $paymentStatus === 'refunded' ? $processedAt : $payment->refunded_at,
                ]);
                $order->update(['payment_status' => $paymentStatus]);

                $this->audit->record(
                    action: 'refund.manual_payout_recorded',
                    module: 'Returns & Refunds',
                    subject: $refund,
                    description: "Manual refund payout {$refund->refund_no} was recorded; no transfer was initiated by Bearly.",
                    oldValues: ['status' => 'pending', 'method' => $refund->getOriginal('method')],
                    newValues: [
                        'status' => 'completed',
                        'method' => $method,
                        'reference' => trim($reference),
                        'external_paid_on' => $paidOn->toDateString(),
                        'amount_minor' => (int) $refund->amount_minor,
                        'payout_amount_minor' => (int) $refund->payout_amount_minor,
                        'financially_responsible_party' => $refund->financially_responsible_party,
                        'seller_recovery_amount_minor' => $financialEffect['seller_recovery_minor'],
                        'receipt_original_name' => $refund->payout_receipt_original_name,
                        'receipt_size_bytes' => $refund->payout_receipt_size_bytes,
                        'processed_by' => $admin->id,
                        'payment_status' => $paymentStatus,
                        'external_payout_confirmed' => true,
                        'cash_payout_id' => $cashPayoutId,
                        'completion_reason' => $completionReason ? trim($completionReason) : null,
                    ],
                    severity: 'warning',
                    request: $request,
                );

                return $refund->fresh(['returnRequest', 'payment', 'sellerTransactions', 'commissions', 'sellerRecovery.events.sellerPayout']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($receiptPath);
            throw $exception;
        }
    }

    public function recordSellerRecovery(
        User $admin,
        int $refundId,
        int $amountMinor,
        string $method,
        string $reference,
        string $recoveredOn,
        string $note,
        ?int $sellerPayoutId,
        Request $request,
    ): SellerRefundRecovery {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter a recovery amount greater than zero.']);
        }
        if (! in_array($method, ['external_collection', 'seller_payout_offset'], true)) {
            throw ValidationException::withMessages(['method' => 'Choose a valid Seller recovery method.']);
        }
        if (trim($reference) === '' || mb_strlen(trim($note)) < 5) {
            throw ValidationException::withMessages(['external_reference' => 'Enter the verified collection or payout reference and a short note.']);
        }

        return DB::transaction(function () use ($admin, $refundId, $amountMinor, $method, $reference, $recoveredOn, $note, $sellerPayoutId, $request): SellerRefundRecovery {
            $stub = Refund::query()->findOrFail($refundId);
            Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($stub->id)->lockForUpdate()->firstOrFail();
            $returnRequest = $refund->return_request_id
                ? ReturnRequest::query()->whereKey($refund->return_request_id)->lockForUpdate()->firstOrFail()
                : null;
            $recovery = SellerRefundRecovery::query()->where('refund_id', $refund->id)->lockForUpdate()->first();

            if ($refund->status !== 'completed'
                || $refund->financially_responsible_party !== 'seller'
                || ! $returnRequest
                || ! $recovery) {
                throw ValidationException::withMessages(['refund' => 'There is no outstanding Seller recovery to record for this refund.']);
            }

            try {
                $recoveredDate = CarbonImmutable::createFromFormat('!Y-m-d', $recoveredOn, config('app.timezone'));
            } catch (Throwable) {
                $recoveredDate = null;
            }
            if (! $recoveredDate || $recoveredDate->greaterThan(CarbonImmutable::today(config('app.timezone')))) {
                throw ValidationException::withMessages(['recovered_on' => 'Enter the actual recovery date; future dates are not allowed.']);
            }

            $sellerPayout = null;
            if ($method === 'seller_payout_offset') {
                if (! $sellerPayoutId) {
                    throw ValidationException::withMessages(['seller_payout_id' => 'Select the Seller payout where the offset was already applied.']);
                }
                $sellerPayout = SellerPayout::query()
                    ->whereKey($sellerPayoutId)
                    ->where('seller_profile_id', $recovery->seller_profile_id)
                    ->whereIn('status', ['paid', 'completed'])
                    ->whereNotNull('paid_at')
                    ->lockForUpdate()
                    ->first();
                if (! $sellerPayout) {
                    throw ValidationException::withMessages(['seller_payout_id' => 'Choose a paid Seller payout belonging to this Seller.']);
                }
            } elseif ($sellerPayoutId) {
                throw ValidationException::withMessages(['seller_payout_id' => 'A Seller payout may only be selected when recording an applied payout offset.']);
            }

            $outstandingMinor = (int) $recovery->amount_minor - (int) $recovery->recovered_amount_minor;
            if ($amountMinor > $outstandingMinor) {
                throw ValidationException::withMessages(['amount' => 'The recovery amount cannot exceed the outstanding Seller balance of ₱'.number_format($outstandingMinor / 100, 2).'.']);
            }
            if (SellerRefundRecoveryEvent::query()
                ->where('seller_refund_recovery_id', $recovery->id)
                ->where('method', $method)
                ->where('external_reference', trim($reference))
                ->exists()) {
                throw ValidationException::withMessages(['external_reference' => 'This collection or settlement reference has already been recorded for the refund.']);
            }

            $previouslyRecoveredMinor = (int) $recovery->recovered_amount_minor;
            $newRecoveredMinor = $previouslyRecoveredMinor + $amountMinor;
            $newStatus = $newRecoveredMinor >= (int) $recovery->amount_minor ? 'recovered' : 'partially_recovered';
            SellerRefundRecoveryEvent::query()->create([
                'seller_refund_recovery_id' => $recovery->id,
                'recorded_by' => $admin->id,
                'seller_payout_id' => $sellerPayout?->id,
                'method' => $method,
                'amount_minor' => $amountMinor,
                'external_reference' => trim($reference),
                'recovered_on' => $recoveredDate->toDateString(),
                'note' => trim($note),
                'created_at' => now(),
            ]);
            $recovery->update([
                'recovered_amount_minor' => $newRecoveredMinor,
                'status' => $newStatus,
            ]);

            $remainingMinor = (int) $recovery->amount_minor - $newRecoveredMinor;
            $this->event(
                $returnRequest,
                $admin,
                'seller_recovery_recorded',
                $returnRequest->status,
                $returnRequest->status,
                'Admin recorded a verified Seller '.str_replace('_', ' ', $method).' of ₱'.number_format($amountMinor / 100, 2).'. No automatic payout deduction was made by Bearly.',
                [
                    'refund_id' => $refund->id,
                    'seller_refund_recovery_id' => $recovery->id,
                    'method' => $method,
                    'amount_minor' => $amountMinor,
                    'recovered_on' => $recoveredDate->toDateString(),
                    'external_reference' => trim($reference),
                    'seller_payout_id' => $sellerPayout?->id,
                    'seller_payout_no' => $sellerPayout?->payout_no,
                    'total_recovered_minor' => $newRecoveredMinor,
                    'outstanding_minor' => $remainingMinor,
                ],
            );
            $this->audit->record(
                action: 'refund.seller_recovery_recorded',
                module: 'Returns & Refunds',
                subject: $refund,
                description: "A verified Seller refund recovery was recorded for {$refund->refund_no}; Bearly did not automatically deduct or transfer funds.",
                oldValues: ['recovered_amount_minor' => $previouslyRecoveredMinor],
                newValues: [
                    'method' => $method,
                    'amount_minor' => $amountMinor,
                    'external_reference' => trim($reference),
                    'recovered_on' => $recoveredDate->toDateString(),
                    'seller_payout_id' => $sellerPayout?->id,
                    'recovered_amount_minor' => $newRecoveredMinor,
                    'outstanding_minor' => $remainingMinor,
                    'note' => trim($note),
                ],
                severity: 'warning',
                request: $request,
            );

            return $recovery->fresh(['refund', 'events.recorder', 'events.sellerPayout']);
        }, attempts: 3);
    }

    /** @return array{commission_reversal_minor: int, seller_recovery_minor: int} */
    private function postRefundLedger(Refund $refund, ReturnRequest $returnRequest, Payment $payment): array
    {
        if ($refund->financially_responsible_party !== 'seller') {
            return ['commission_reversal_minor' => 0, 'seller_recovery_minor' => 0];
        }

        $sellerOrder = SellerOrder::query()
            ->whereKey($returnRequest->seller_order_id)
            ->with('store.sellerProfile')
            ->lockForUpdate()
            ->firstOrFail();
        $saleCommission = PlatformCommission::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->whereNull('refund_id')
            ->where('type', 'sale')
            ->lockForUpdate()
            ->first();

        if ($payment->status === 'paid' && ! $saleCommission) {
            $this->commissionLedger->finalize($payment);
            $saleCommission = PlatformCommission::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->whereNull('refund_id')
                ->where('type', 'sale')
                ->lockForUpdate()
                ->first();
        }

        $rateBps = (int) ($saleCommission?->rate_bps
            ?? $sellerOrder->store?->sellerProfile?->commission_rate_bps
            ?? 0);
        $saleCommissionMinor = (int) ($saleCommission?->amount_minor
            ?? round((int) $sellerOrder->total_minor * $rateBps / 10000));
        $priorRefundsMinor = (int) Refund::query()
            ->where('status', 'completed')
            ->where('financially_responsible_party', 'seller')
            ->where('id', '!=', $refund->id)
            ->whereHas('returnRequest', fn ($query) => $query->where('seller_order_id', $sellerOrder->id))
            ->sum('amount_minor');
        $priorReversalMinor = (int) PlatformCommission::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->whereNotNull('refund_id')
            ->where('type', 'refund_reversal')
            ->sum('amount_minor');
        $newCumulativeRefund = $priorRefundsMinor + (int) $refund->amount_minor;
        $targetReversal = min($saleCommissionMinor, (int) round($newCumulativeRefund * $rateBps / 10000));
        $commissionReversal = max(0, $targetReversal - $priorReversalMinor);

        if ($commissionReversal > 0) {
            PlatformCommission::query()->firstOrCreate(
                [
                    'seller_order_id' => $sellerOrder->id,
                    'refund_id' => $refund->id,
                    'type' => 'refund_reversal',
                ],
                [
                    'base_amount_minor' => (int) $refund->amount_minor,
                    'rate_bps' => $rateBps,
                    'amount_minor' => $commissionReversal,
                    'status' => 'finalized',
                    'calculated_at' => now(),
                    'finalized_at' => now(),
                    'reversed_at' => now(),
                ],
            );
        }

        $sellerRecoveryMinor = max(0, (int) $refund->amount_minor - $commissionReversal);
        if ($sellerRecoveryMinor > 0) {
            $sellerProfileId = (int) ($sellerOrder->store?->seller_profile_id ?? 0);
            if ($sellerProfileId < 1) {
                throw ValidationException::withMessages(['refund' => 'The Seller account is unavailable for recovery accounting.']);
            }
            SellerRefundRecovery::query()->firstOrCreate(
                ['refund_id' => $refund->id],
                [
                    'seller_profile_id' => $sellerProfileId,
                    'amount_minor' => $sellerRecoveryMinor,
                    'recovered_amount_minor' => 0,
                    'status' => 'pending',
                ],
            );
        }

        return [
            'commission_reversal_minor' => $commissionReversal,
            'seller_recovery_minor' => $sellerRecoveryMinor,
        ];
    }

    private function createReturnShipment(ReturnRequest $returnRequest, Order $order, Payment $payment, User $admin): PickupRequest
    {
        $store = Store::query()
            ->whereKey($returnRequest->store_id)
            ->with('sellerProfile.pickupAddress')
            ->firstOrFail();
        $sellerAddress = $store->sellerProfile?->pickupAddress;
        if (! $sellerAddress) {
            throw ValidationException::withMessages(['return' => 'The Seller must set a pickup address before a physical return can be approved.']);
        }

        $outbound = Shipment::query()
            ->where('seller_order_id', $returnRequest->seller_order_id)
            ->where('purpose', 'outbound')
            ->with('waybill')
            ->latest('id')
            ->first();
        $parcel = $outbound?->parcels()->with('waybill')->latest('id')->first();
        $package = $parcel?->waybill ?? $outbound?->waybill;
        if (! $outbound || ! $package || ! is_numeric($package->total_weight_kg) || (float) $package->total_weight_kg <= 0) {
            throw ValidationException::withMessages(['return' => 'The original fulfilled shipment has no usable package dimensions for a reverse waybill.']);
        }

        $buyerCityCode = $order->psgc_city_code
            ?: app(PostalCodeLookup::class)->canonicalCityCode(
                null,
                (string) $order->province,
                (string) $order->city_municipality,
            );

        if (! $buyerCityCode) {
            throw ValidationException::withMessages([
                'return' => 'The Buyer’s order address needs a verified municipality/city before a physical return can be approved.',
            ]);
        }

        $provider = LogisticsProfile::query()
            ->where('psgc_city_code', $buyerCityCode)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query
                ->where('status', 'active')
                ->where(fn ($roles) => $roles
                    ->where('role', UserRole::Logistics->value)
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery
                        ->where('roles.name', UserRole::Logistics->value))))
            ->lockForUpdate()
            ->first();

        if (! $provider) {
            throw ValidationException::withMessages([
                'return' => 'The active Logistics provider serving the Buyer’s municipality/city must be available to approve a physical return.',
            ]);
        }

        $destinationCityCode = app(PostalCodeLookup::class)->canonicalCityCode(
            $sellerAddress->psgc_city_code,
            (string) $sellerAddress->province,
            (string) $sellerAddress->city_municipality,
        );

        if (! $destinationCityCode) {
            throw ValidationException::withMessages([
                'return' => 'The Seller pickup address needs a verified municipality/city before a cross-city return can be approved.',
            ]);
        }

        $now = now();
        $requestedDate = CarbonImmutable::tomorrow(config('app.timezone'));
        $window = SellerPickupRequestService::PICKUP_WINDOWS['10:00-12:00'];
        $windowStart = $requestedDate->setTimeFromTimeString($window['start']);
        $windowEnd = $requestedDate->setTimeFromTimeString($window['end']);
        $pickupAddressId = $order->shipping_address_id
            ? Address::withTrashed()->whereKey($order->shipping_address_id)->value('id')
            : null;

        $pickupRequest = PickupRequest::query()->create([
            'pickup_no' => 'PU-'.Str::upper((string) Str::ulid()),
            'store_id' => $store->id,
            'return_request_id' => $returnRequest->id,
            // The JSON snapshot is the pickup authority. This FK preserves the
            // legacy non-null relationship when a historical address is gone.
            'pickup_address_id' => $pickupAddressId ?: $sellerAddress->id,
            'pickup_address_snapshot' => [
                'recipient_name' => (string) $order->recipient_name,
                'phone' => (string) $order->recipient_phone,
                'house_number' => null,
                'street' => (string) $order->address_line,
                'barangay' => (string) $order->barangay,
                'city_municipality' => (string) $order->city_municipality,
                'province' => (string) $order->province,
                'postal_code' => $order->postal_code,
                'psgc_city_code' => $buyerCityCode,
            ],
            'logistics_profile_id' => $provider->id,
            'status' => 'requested',
            'requested_date' => $requestedDate->toDateString(),
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'return_pickup_date' => $requestedDate->toDateString(),
            'return_pickup_window' => '10:00-12:00',
            'seller_instructions' => 'Buyer return pickup. No return-shipping fee is collected in this phase.',
        ]);

        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-'.Str::upper((string) Str::ulid()),
            'seller_order_id' => $returnRequest->seller_order_id,
            'logistics_profile_id' => $provider->id,
            'origin_logistics_profile_id' => $provider->id,
            'origin_address_id' => $pickupAddressId,
            'destination_address_id' => $sellerAddress->id,
            'destination_city_code' => $destinationCityCode,
            'destination_address_snapshot' => [
                'recipient_name' => (string) $sellerAddress->recipient_name,
                'phone' => (string) $sellerAddress->phone,
                'house_number' => $sellerAddress->house_number,
                'street' => (string) $sellerAddress->street,
                'barangay' => (string) $sellerAddress->barangay,
                'city_municipality' => (string) $sellerAddress->city_municipality,
                'province' => (string) $sellerAddress->province,
                'postal_code' => $sellerAddress->postal_code,
                'psgc_city_code' => $destinationCityCode,
            ],
            'purpose' => 'return',
            'status' => 'ready_for_pickup',
            'shipping_fee_minor' => 0,
            'cod_amount_minor' => 0,
        ]);
        $waybillNo = 'BRLY-RET-'.Str::upper((string) Str::ulid());
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => $waybillNo,
            'scan_token' => Str::random(64),
            'barcode_value' => $waybillNo,
            'piece_count' => 1,
            'total_weight_kg' => (float) $package->total_weight_kg,
            'length_cm' => $package->length_cm,
            'width_cm' => $package->width_cm,
            'height_cm' => $package->height_cm,
            'status' => 'generated',
            'generated_by' => $admin->id,
            'generated_at' => $now,
        ]);
        $returnParcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'PCL-'.Str::upper((string) Str::ulid()),
            'piece_sequence' => 1,
            'weight_kg' => (float) $package->total_weight_kg,
            'length_cm' => $package->length_cm,
            'width_cm' => $package->width_cm,
            'height_cm' => $package->height_cm,
            'status' => 'created',
            'last_event_at' => $now,
        ]);
        $pickupRequest->parcels()->attach($returnParcel->id, ['added_at' => $now]);
        $shipment->events()->create([
            'parcel_id' => $returnParcel->id,
            'event_type' => 'return_shipment_ready_for_pickup',
            'from_status' => null,
            'to_status' => 'ready_for_pickup',
            'actor_user_id' => $admin->id,
            'source' => 'admin',
            'notes' => "Reverse shipment created from return request {$returnRequest->return_no}.",
            'metadata' => ['return_request_id' => $returnRequest->id, 'pickup_request_id' => $pickupRequest->id],
            'occurred_at' => $now,
        ]);
        $returnRequest->update(['return_shipment_id' => $shipment->id]);

        return $pickupRequest;
    }

    private function assertAdminReviewable(ReturnRequest $returnRequest): void
    {
        if ($returnRequest->status !== ReturnRequestStatus::UnderReview->value) {
            throw ValidationException::withMessages(['return' => 'A Seller response and any requested evidence are required before an Admin decision.']);
        }
    }

    private function evidenceRequestedFrom(ReturnRequest $returnRequest): string
    {
        $event = $this->latestEvidenceRequest($returnRequest);

        return (string) ($event?->metadata['requested_from'] ?? 'both');
    }

    private function latestEvidenceRequest(ReturnRequest $returnRequest): ?ReturnEvent
    {
        return ReturnEvent::query()
            ->where('return_request_id', $returnRequest->id)
            ->where('event_type', 'additional_evidence_requested')
            ->latest('id')
            ->first();
    }

    private function hasEvidenceResponseSinceLatestRequest(ReturnRequest $returnRequest, string $party): bool
    {
        $requestEvent = $this->latestEvidenceRequest($returnRequest);
        if (! $requestEvent) {
            return false;
        }

        return ReturnEvent::query()
            ->where('return_request_id', $returnRequest->id)
            ->where('id', '>', $requestEvent->id)
            ->where('event_type', $party.'_evidence_submitted')
            ->exists();
    }

    private function allRequestedEvidenceSubmitted(ReturnRequest $returnRequest, string $currentParty): bool
    {
        $requestedFrom = $this->evidenceRequestedFrom($returnRequest);
        $requiredParties = match ($requestedFrom) {
            'buyer' => ['buyer'],
            'seller' => ['seller'],
            'both' => ['buyer', 'seller'],
            default => [],
        };

        foreach ($requiredParties as $party) {
            if ($party !== $currentParty && ! $this->hasEvidenceResponseSinceLatestRequest($returnRequest, $party)) {
                return false;
            }
        }

        return $requiredParties !== [];
    }

    /** @param array<int, UploadedFile> $evidenceFiles */
    private function assertAdditionalEvidenceProvided(?string $note, array $evidenceFiles): void
    {
        if ($evidenceFiles === [] && ! filled($note)) {
            throw ValidationException::withMessages([
                'evidence' => 'Add a note or at least one evidence file.',
            ]);
        }
    }

    private function eligibleForRequest(SellerOrder $sellerOrder): bool
    {
        if ($sellerOrder->status !== 'completed' || ! $sellerOrder->completed_at) {
            return false;
        }

        $deadline = CarbonImmutable::parse($sellerOrder->completed_at)
            ->addDays(self::RETURN_WINDOW_DAYS)
            ->endOfDay();
        if (now()->greaterThan($deadline)) {
            return false;
        }

        return $this->capturedAmountMinor($sellerOrder->order) > 0;
    }

    /** @param iterable<OrderItem> $items
     * @return array<int, int>
     */
    private function returnableQuantities(iterable $items): array
    {
        $items = collect($items);
        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $reserved = $itemIds === [] ? [] : DB::table('return_items')
            ->join('return_requests', 'return_requests.id', '=', 'return_items.return_request_id')
            ->whereIn('return_items.order_item_id', $itemIds)
            ->where('return_requests.status', '!=', ReturnRequestStatus::Rejected->value)
            ->groupBy('return_items.order_item_id')
            ->select('return_items.order_item_id')
            ->selectRaw('SUM(return_items.quantity) as reserved_quantity')
            ->pluck('reserved_quantity', 'order_item_id')
            ->all();

        $result = [];
        foreach ($items as $item) {
            $result[(int) $item->id] = max(0, (int) $item->quantity - (int) ($reserved[$item->id] ?? 0));
        }

        return $result;
    }

    private function capturedAmountMinor(Order $order): int
    {
        return (int) Payment::query()
            ->where('order_id', $order->id)
            ->where('method', 'cod')
            ->whereNotNull('paid_at')
            ->whereNotIn('status', ['failed', 'cancelled', 'pending', 'authorized'])
            ->sum('amount_minor');
    }

    private function assertRefundCapacity(Order $order, int $amountMinor, ?int $excludeReturnRequestId, ?int $sellerOrderId): void
    {
        $captured = $this->capturedAmountMinor($order);
        $reservedQuery = ReturnRequest::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', ReturnRequestStatus::Rejected->value);
        if ($excludeReturnRequestId) {
            $reservedQuery->where('id', '!=', $excludeReturnRequestId);
        }
        if ($sellerOrderId) {
            $sellerReserved = ReturnRequest::query()
                ->where('seller_order_id', $sellerOrderId)
                ->where('status', '!=', ReturnRequestStatus::Rejected->value);
            if ($excludeReturnRequestId) {
                $sellerReserved->where('id', '!=', $excludeReturnRequestId);
            }
            $sellerOrder = SellerOrder::query()->find($sellerOrderId);
            if ($sellerOrder && (int) $sellerReserved->sum('requested_amount_minor') + $amountMinor > (int) $sellerOrder->total_minor) {
                throw ValidationException::withMessages(['item_quantities' => 'The requested refund would exceed this Seller Order total.']);
            }
        }

        $caseReserved = (int) $reservedQuery->sum('requested_amount_minor');
        $unlinkedRefunds = (int) Refund::query()
            ->where('order_id', $order->id)
            ->whereNull('return_request_id')
            ->whereNotIn('status', self::NON_REFUND_STATUSES)
            ->sum('amount_minor');
        if ($caseReserved + $unlinkedRefunds + $amountMinor > $captured) {
            throw ValidationException::withMessages(['item_quantities' => 'The requested amount exceeds the remaining paid order balance.']);
        }
    }

    /** @param array<int, UploadedFile> $files
     * @param  array<int, string>  $storedPaths
     */
    private function storeEvidence(ReturnRequest $returnRequest, User $actor, array $files, string $type, array &$storedPaths): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['evidence' => 'One or more evidence uploads are invalid.']);
            }
            $path = $file->store('return-evidence/'.$returnRequest->return_no, 'local');
            if (! $path) {
                throw new \RuntimeException('The return evidence file could not be stored.');
            }
            $storedPaths[] = $path;
            ReturnEvidence::query()->create([
                'return_request_id' => $returnRequest->id,
                'uploaded_by' => $actor->id,
                'type' => $type,
                'mime_type' => $file->getMimeType(),
                'file_path' => $path,
                'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
                'size_bytes' => $file->getSize(),
                'description' => null,
                'created_at' => now(),
            ]);
        }
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'evidence';

        return mb_substr($name, 0, 255);
    }

    private function event(
        ReturnRequest $returnRequest,
        ?User $actor,
        string $eventType,
        ?string $from,
        ?string $to,
        ?string $note = null,
        array $metadata = [],
    ): void {
        ReturnEvent::query()->create([
            'return_request_id' => $returnRequest->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'note' => filled($note) ? trim($note) : null,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }

    private function orderItemImageUrl(OrderItem $item): ?string
    {
        if (! $item->product_image_path) {
            return null;
        }

        return Storage::disk($item->product_image_disk ?: 'public')->url($item->product_image_path);
    }
}
