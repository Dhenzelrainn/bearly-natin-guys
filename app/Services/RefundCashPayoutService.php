<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\CodCashCollection;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundCashPayout;
use App\Models\RefundCashPayoutEvent;
use App\Models\RiderProfile;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class RefundCashPayoutService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ReturnRefundService $returns,
    ) {}

    /** @return Collection<int, Refund> */
    public function refundsReadyForCashDispatch()
    {
        return Refund::query()
            ->where('status', 'pending')
            ->whereNotNull('financially_responsible_party')
            ->whereHas('payment', fn ($query) => $query
                ->where('method', 'cod')
                ->whereIn('status', ['paid', 'partially_refunded'])
                ->whereNotNull('paid_at'))
            ->whereHas('returnRequest', function ($query): void {
                $query->where('status', 'refund_pending')
                    ->whereDoesntHave('cashPayout')
                    ->where(function ($ready): void {
                        $ready->where('request_type', '!=', 'return_refund')
                            ->orWhereDoesntHave('items', fn ($items) => $items
                                ->whereNull('condition_code')
                                ->orWhereNotIn('resolution', ['restock', 'quarantine', 'dispose']));
                    });
            })
            ->with(['order.buyer', 'payment', 'returnRequest.store', 'returnRequest.sellerOrder.shipments.logisticsProfile'])
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();
    }

    public function issueToLogistics(User $admin, int $refundId, Request $request): RefundCashPayout
    {
        $this->assertAdmin($admin);
        if (! $request->boolean('cash_issued_confirmed')) {
            throw ValidationException::withMessages([
                'cash_issued_confirmed' => 'Confirm that Finance has physically handed the exact refund cash to Logistics.',
            ]);
        }

        return DB::transaction(function () use ($admin, $refundId, $request): RefundCashPayout {
            $stub = Refund::query()->findOrFail($refundId);
            $order = Order::query()->whereKey($stub->order_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($stub->id)->lockForUpdate()->with(['payment', 'returnRequest.items', 'returnRequest.sellerOrder'])->firstOrFail();
            $returnRequest = $refund->returnRequest;

            if ($refund->status !== 'pending'
                || ! $returnRequest
                || $returnRequest->status !== 'refund_pending'
                || ! in_array($refund->financially_responsible_party, ['seller', 'platform'], true)) {
                throw ValidationException::withMessages(['refund' => 'This refund has not completed Seller/Admin review and is not ready for cash dispatch.']);
            }
            if ($returnRequest->request_type === 'return_refund'
                && $returnRequest->items->contains(fn ($item): bool => ! $item->condition_code
                    || ! in_array($item->resolution, ['restock', 'quarantine', 'dispose'], true))) {
                throw ValidationException::withMessages(['refund' => 'Seller inspection must be complete before cash dispatch.']);
            }

            $payment = Payment::query()->whereKey($refund->payment_id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($payment->method !== 'cod'
                || ! in_array($payment->status, ['paid', 'partially_refunded'], true)
                || ! $payment->paid_at) {
                throw ValidationException::withMessages(['refund' => 'A recorded paid COD payment is required for a cash refund.']);
            }

            $existing = RefundCashPayout::query()->where('refund_id', $refund->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->fresh(['refund', 'logisticsProfile', 'events']);
            }

            $outbound = Shipment::query()
                ->where('seller_order_id', $returnRequest->seller_order_id)
                ->where('purpose', 'outbound')
                ->where('cod_amount_minor', '>', 0)
                ->with('logisticsProfile')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            $provider = $outbound?->logisticsProfile;
            if (! $provider || $provider->status !== 'active' || $provider->trashed()) {
                throw ValidationException::withMessages(['refund' => 'The original COD Logistics provider is unavailable; reassigning a provider requires an explicit audited workflow.']);
            }

            $amountMinor = (int) ($refund->payout_amount_minor ?? $refund->amount_minor);
            if ($amountMinor < 1) {
                throw ValidationException::withMessages(['refund' => 'The approved refund amount must be greater than zero.']);
            }

            $now = now();
            $payoutNo = 'RCP-'.Str::upper((string) Str::ulid());
            $reference = 'CFH-'.substr($payoutNo, 4);
            $payout = RefundCashPayout::query()->create([
                'payout_no' => $payoutNo,
                'refund_id' => $refund->id,
                'return_request_id' => $returnRequest->id,
                'logistics_profile_id' => $provider->id,
                'amount_minor' => $amountMinor,
                'status' => 'awaiting_logistics_receipt',
                'finance_issue_reference' => $reference,
                'issued_to_logistics_at' => $now,
                'issued_to_logistics_by' => $admin->id,
            ]);
            $this->event($payout, 'cash_issued_to_logistics', null, 'awaiting_logistics_receipt', $admin, $amountMinor, $reference, 'Finance recorded the verified cash handoff to the original outbound Logistics provider.', $now);
            $this->audit->record(
                action: 'refund_cash.issued_to_logistics',
                module: 'Finance',
                subject: $payout,
                description: "Refund cash {$payout->payout_no} was recorded as handed to Logistics under reference {$reference}.",
                oldValues: [],
                newValues: ['refund_id' => $refund->id, 'logistics_profile_id' => $provider->id, 'amount_minor' => $amountMinor, 'reference' => $reference],
                severity: 'warning',
                request: $request,
            );

            return $payout->fresh(['refund', 'logisticsProfile', 'events']);
        }, attempts: 3);
    }

    public function receiveAtLogistics(User $user, int $payoutId, int $amountMinor, string $reference): RefundCashPayout
    {
        return DB::transaction(function () use ($user, $payoutId, $amountMinor, $reference): RefundCashPayout {
            $profile = $this->logisticsProfile($user);
            $payout = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            $this->assertProvider($payout, $profile->id);
            $this->assertAmount($payout, $amountMinor, 'amount');
            $reference = $this->requiredReference($reference, 'reference', 'Enter the Logistics cash receipt reference.');

            if ($payout->status === 'awaiting_rider_assignment'
                && $payout->logistics_receipt_reference === $reference
                && (int) $payout->received_by_logistics === (int) $user->id) {
                return $payout;
            }
            $this->assertStatus($payout, 'awaiting_logistics_receipt', 'Finance must record the cash handoff before Logistics can acknowledge receipt.');

            $now = now();
            $payout->update([
                'status' => 'awaiting_rider_assignment',
                'logistics_receipt_reference' => $reference,
                'received_by_logistics_at' => $now,
                'received_by_logistics' => $user->id,
            ]);
            $this->event($payout, 'cash_received_by_logistics', 'awaiting_logistics_receipt', 'awaiting_rider_assignment', $user, $amountMinor, $reference, null, $now);

            return $payout->fresh(['events']);
        }, attempts: 3);
    }

    public function assignRider(User $user, int $payoutId, int $riderUserId): RefundCashPayout
    {
        return DB::transaction(function () use ($user, $payoutId, $riderUserId): RefundCashPayout {
            $provider = $this->logisticsProfile($user);
            $payout = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            $this->assertProvider($payout, $provider->id);
            $this->assertStatus($payout, 'awaiting_rider_assignment', 'Logistics must acknowledge cash receipt before assigning a Rider.');

            $riderUser = User::query()->whereKey($riderUserId)->lockForUpdate()->firstOrFail();
            $rider = $riderUser->riderProfile()->first();
            if ($riderUser->role !== UserRole::Rider->value
                || $riderUser->status !== AccountStatus::Active->value
                || ! $rider
                || $rider->verification_status !== 'approved'
                || $rider->availability_status !== 'available'
                || (int) $rider->logistics_profile_id !== (int) $provider->id) {
                throw ValidationException::withMessages(['rider_user_id' => 'Choose an active, approved, available Rider belonging to this Logistics provider.']);
            }

            $alreadyAssigned = RefundCashPayout::query()
                ->where('rider_profile_id', $rider->id)
                ->whereIn('status', ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery', 'cash_return_pending', 'logistics_return_verification', 'delivered_pending_verification'])
                ->where('id', '!=', $payout->id)
                ->exists();
            if ($alreadyAssigned) {
                throw ValidationException::withMessages(['rider_user_id' => 'This Rider already has an active refund cash assignment.']);
            }

            $now = now();
            $reference = $this->acknowledgmentReferenceForAttempt($payout);
            $payout->update([
                'rider_profile_id' => $rider->id,
                'status' => 'assigned_to_rider',
                'assigned_at' => $now,
                'assigned_by' => $user->id,
                'received_by_rider_at' => null,
                'received_by_rider_user_id' => null,
                'started_at' => null,
                'last_attempt_at' => null,
                'failure_reason' => null,
                'amount_returned_to_logistics_minor' => null,
                'cash_return_reference' => null,
                'cash_returned_to_logistics_at' => null,
                'cash_returned_to_logistics_by' => null,
                'logistics_cash_return_reference' => null,
                'logistics_cash_return_received_at' => null,
                'logistics_cash_return_received_by' => null,
                'recipient_name' => null,
                'acknowledgment_reference' => $reference,
                'acknowledgment_disk' => null,
                'acknowledgment_path' => null,
                'acknowledgment_mime_type' => null,
                'acknowledgment_original_name' => null,
                'acknowledgment_size_bytes' => null,
                'delivered_at' => null,
                'delivered_by_user_id' => null,
                'logistics_verified_at' => null,
                'logistics_verified_by' => null,
            ]);
            $this->event($payout, 'cash_assigned_to_rider', 'awaiting_rider_assignment', 'assigned_to_rider', $user, (int) $payout->amount_minor, $reference, "Assigned to approved Rider {$riderUser->name}; acknowledgment slip is ready to print.", $now);

            return $payout->fresh(['riderProfile.user', 'events']);
        }, attempts: 3);
    }

    public function prepareAcknowledgmentForLogistics(User $user, int $payoutId): RefundCashPayout
    {
        return DB::transaction(function () use ($user, $payoutId): RefundCashPayout {
            $provider = $this->logisticsProfile($user);
            $payout = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            $this->assertProvider($payout, $provider->id);

            if (! in_array($payout->status, ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery'], true)
                || ! $payout->rider_profile_id) {
                throw new ConflictHttpException('A Rider must be assigned before Logistics can prepare the acknowledgment slip.');
            }

            if (filled($payout->acknowledgment_reference)) {
                return $payout->fresh(['refund.order.buyer', 'returnRequest.store', 'logisticsProfile', 'riderProfile.user']);
            }

            $reference = $this->acknowledgmentReferenceForAttempt($payout);
            $now = now();
            $status = $payout->status;
            $payout->update(['acknowledgment_reference' => $reference]);
            $this->event(
                $payout,
                'refund_cash_acknowledgment_reference_generated',
                $status,
                $status,
                $user,
                (int) $payout->amount_minor,
                $reference,
                'Logistics prepared the acknowledgment reference before handing the printed slip to the Rider.',
                $now,
            );

            return $payout->fresh(['refund.order.buyer', 'returnRequest.store', 'logisticsProfile', 'riderProfile.user', 'events']);
        }, attempts: 3);
    }

    public function riderReceivesCash(User $user, int $payoutId, int $amountMinor, string $acknowledgmentReference): RefundCashPayout
    {
        return DB::transaction(function () use ($user, $payoutId, $amountMinor, $acknowledgmentReference): RefundCashPayout {
            $payout = $this->riderPayout($user, $payoutId);
            $this->assertAmount($payout, $amountMinor, 'amount');
            $acknowledgmentReference = $this->requiredReference(
                $acknowledgmentReference,
                'acknowledgment_reference',
                'Enter the acknowledgment reference printed on the paper form from Logistics.',
            );
            if ($payout->acknowledgment_reference !== $acknowledgmentReference) {
                throw ValidationException::withMessages(['acknowledgment_reference' => 'This reference does not match the current assignment. Ask Logistics for the correct printed form.']);
            }
            if ($payout->status === 'cash_with_rider' && (int) $payout->received_by_rider_user_id === (int) $user->id) {
                return $payout;
            }
            $this->assertStatus($payout, 'assigned_to_rider', 'This refund cash is not assigned and ready for Rider receipt.');

            $now = now();
            $payout->update([
                'status' => 'cash_with_rider',
                'received_by_rider_at' => $now,
                'received_by_rider_user_id' => $user->id,
            ]);
            $this->event($payout, 'cash_received_by_rider', 'assigned_to_rider', 'cash_with_rider', $user, $amountMinor, $acknowledgmentReference, 'Rider confirmed receipt of the Logistics-issued paper acknowledgment form.', $now);

            return $payout->fresh(['events']);
        }, attempts: 3);
    }

    public function startDelivery(User $user, int $payoutId): RefundCashPayout
    {
        return DB::transaction(function () use ($user, $payoutId): RefundCashPayout {
            $payout = $this->riderPayout($user, $payoutId);
            if ($payout->status === 'out_for_delivery'
                && (int) $payout->received_by_rider_user_id === (int) $user->id) {
                if (! filled($payout->acknowledgment_reference)) {
                    throw ValidationException::withMessages(['payout' => 'Ask Logistics to prepare and provide the acknowledgment slip before continuing this delivery.']);
                }

                return $payout;
            }
            $this->assertStatus($payout, 'cash_with_rider', 'Confirm receipt of the assigned cash before starting delivery.');
            $reference = trim((string) $payout->acknowledgment_reference);
            if ($reference === '') {
                throw ValidationException::withMessages(['payout' => 'Ask Logistics to prepare and provide the acknowledgment slip before starting delivery.']);
            }
            $now = now();
            $payout->update([
                'status' => 'out_for_delivery',
                'started_at' => $now,
            ]);
            $this->event(
                $payout,
                'refund_cash_delivery_started',
                'cash_with_rider',
                'out_for_delivery',
                $user,
                (int) $payout->amount_minor,
                $reference,
                'Rider started delivery with the Logistics-issued acknowledgment reference.',
                $now,
            );

            return $payout->fresh(['events']);
        }, attempts: 3);
    }

    public function failDelivery(User $user, int $payoutId, string $reason): RefundCashPayout
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['failure_reason' => 'Describe why the cash refund could not be delivered.']);
        }

        return DB::transaction(function () use ($user, $payoutId, $reason): RefundCashPayout {
            $payout = $this->riderPayout($user, $payoutId);
            if ($payout->status === 'cash_return_pending'
                && $payout->failure_reason === $reason
                && (int) $payout->rider_profile_id === (int) $user->riderProfile?->id) {
                return $payout;
            }
            $this->assertStatus($payout, 'out_for_delivery', 'Start the assigned cash delivery before recording a failed attempt.');
            $reference = trim((string) $payout->acknowledgment_reference);
            if ($reference === '') {
                throw ValidationException::withMessages(['payout' => 'Prepare the system-generated acknowledgment reference before recording this delivery attempt.']);
            }
            $fromStatus = $payout->status;
            $now = now();
            $payout->update([
                'status' => 'cash_return_pending',
                'last_attempt_at' => $now,
                'delivery_attempt_count' => (int) $payout->delivery_attempt_count + 1,
                'failure_reason' => $reason,
            ]);
            $this->event($payout, 'refund_cash_delivery_failed', $fromStatus, 'cash_return_pending', $user, (int) $payout->amount_minor, $reference, $reason, $now);

            return $payout->fresh(['events']);
        }, attempts: 3);
    }

    public function returnCashToLogistics(User $user, int $payoutId, int $amountMinor, string $reference): RefundCashPayout
    {
        return DB::transaction(function () use ($user, $payoutId, $amountMinor, $reference): RefundCashPayout {
            $this->riderProfile($user);
            $payout = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            $this->assertRiderOwns($payout, $user);
            $this->assertAmount($payout, $amountMinor, 'amount');
            $reference = $this->requiredReference($reference, 'reference', 'Enter the Logistics receipt reference for the returned cash.');
            if ($payout->status === 'logistics_return_verification'
                && $payout->cash_return_reference === $reference
                && (int) $payout->cash_returned_to_logistics_by === (int) $user->id) {
                return $payout;
            }
            $this->assertStatus($payout, 'cash_return_pending', 'A failed cash delivery must be returned to Logistics before reassignment.');

            $now = now();
            $payout->update([
                'status' => 'logistics_return_verification',
                'amount_returned_to_logistics_minor' => $amountMinor,
                'cash_return_reference' => $reference,
                'cash_returned_to_logistics_at' => $now,
                'cash_returned_to_logistics_by' => $user->id,
            ]);
            $this->event($payout, 'cash_returned_to_logistics', 'cash_return_pending', 'logistics_return_verification', $user, $amountMinor, $reference, 'Rider recorded the handoff; Logistics must confirm physical receipt before reassignment.', $now);

            return $payout->fresh(['events']);
        }, attempts: 3);
    }

    public function confirmReturnedCashAtLogistics(User $user, int $payoutId, int $amountMinor, string $reference): RefundCashPayout
    {
        return DB::transaction(function () use ($user, $payoutId, $amountMinor, $reference): RefundCashPayout {
            $provider = $this->logisticsProfile($user);
            $payout = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            $this->assertProvider($payout, $provider->id);
            $this->assertAmount($payout, $amountMinor, 'amount');
            $reference = $this->requiredReference($reference, 'reference', 'Enter the Logistics cash-return receipt reference.');
            if ($payout->status === 'awaiting_rider_assignment'
                && $payout->logistics_cash_return_reference === $reference
                && (int) $payout->logistics_cash_return_received_by === (int) $user->id) {
                return $payout;
            }
            $this->assertStatus($payout, 'logistics_return_verification', 'Rider must record a failed delivery and cash return before Logistics can receive the cash.');

            $now = now();
            $payout->update([
                'status' => 'awaiting_rider_assignment',
                'rider_profile_id' => null,
                'logistics_cash_return_reference' => $reference,
                'logistics_cash_return_received_at' => $now,
                'logistics_cash_return_received_by' => $user->id,
            ]);
            $this->event($payout, 'cash_return_received_by_logistics', 'logistics_return_verification', 'awaiting_rider_assignment', $user, $amountMinor, $reference, 'Logistics confirmed receipt of the returned cash; the refund may be reassigned.', $now);

            return $payout->fresh(['events']);
        }, attempts: 3);
    }

    public function deliverToBuyer(User $user, int $payoutId, int $amountMinor, string $recipientName, UploadedFile $acknowledgment): RefundCashPayout
    {
        $payout = $this->riderPayout($user, $payoutId);
        $this->assertAmount($payout, $amountMinor, 'amount');
        if ($payout->status === 'delivered_pending_verification'
            && (int) $payout->delivered_by_user_id === (int) $user->id) {
            return $payout;
        }
        $recipientName = trim($recipientName);
        if ($recipientName === '' || mb_strlen($recipientName) > 160) {
            throw ValidationException::withMessages(['recipient_name' => 'Enter the name of the person who received the refund cash.']);
        }
        if (! $acknowledgment->isValid()
            || ! in_array($acknowledgment->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            throw ValidationException::withMessages(['acknowledgment' => 'Upload a valid signed acknowledgment image or PDF.']);
        }
        $this->assertStatus($payout, 'out_for_delivery', 'Start the assigned cash delivery before recording Buyer receipt.');
        $reference = trim((string) $payout->acknowledgment_reference);
        if ($reference === '') {
            throw ValidationException::withMessages(['payout' => 'Generate the Buyer acknowledgment reference before recording receipt.']);
        }

        $path = $acknowledgment->store('refund-cash-acknowledgments/'.$payout->payout_no, 'local');
        if (! $path) {
            throw ValidationException::withMessages(['acknowledgment' => 'The Buyer acknowledgment could not be stored.']);
        }

        try {
            return DB::transaction(function () use ($user, $payoutId, $amountMinor, $recipientName, $reference, $acknowledgment, $path): RefundCashPayout {
                $locked = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
                $this->assertRiderOwns($locked, $user);
                $this->assertAmount($locked, $amountMinor, 'amount');
                $this->assertStatus($locked, 'out_for_delivery', 'This refund cash delivery has already changed state.');
                if ($locked->acknowledgment_reference !== $reference) {
                    throw new ConflictHttpException('The acknowledgment reference changed. Refresh the assignment and retry.');
                }
                $now = now();
                $locked->update([
                    'status' => 'delivered_pending_verification',
                    'recipient_name' => $recipientName,
                    'acknowledgment_reference' => $reference,
                    'acknowledgment_disk' => 'local',
                    'acknowledgment_path' => $path,
                    'acknowledgment_mime_type' => $acknowledgment->getMimeType(),
                    'acknowledgment_original_name' => $this->safeOriginalName($acknowledgment->getClientOriginalName()),
                    'acknowledgment_size_bytes' => $acknowledgment->getSize(),
                    'delivered_at' => $now,
                    'delivered_by_user_id' => $user->id,
                    'last_attempt_at' => $now,
                    'delivery_attempt_count' => (int) $locked->delivery_attempt_count + 1,
                ]);
                $this->event($locked, 'refund_cash_delivered_to_buyer', 'out_for_delivery', 'delivered_pending_verification', $user, $amountMinor, $reference, 'Rider uploaded signed Buyer acknowledgment. Logistics and Finance verification are still required.', $now);

                return $locked->fresh(['events']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function verifyBuyerReceipt(User $user, int $payoutId, string $note): RefundCashPayout
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['verification_note' => 'Describe the proof/reference checks completed by Logistics.']);
        }

        return DB::transaction(function () use ($user, $payoutId, $note): RefundCashPayout {
            $provider = $this->logisticsProfile($user);
            $payout = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            $this->assertProvider($payout, $provider->id);
            if ($payout->status === 'awaiting_finance_completion' && $payout->logistics_verified_by === $user->id) {
                return $payout;
            }
            $this->assertStatus($payout, 'delivered_pending_verification', 'A Rider-delivered Buyer acknowledgment is required before Logistics verification.');
            if ($payout->acknowledgment_disk !== 'local'
                || ! filled($payout->acknowledgment_path)
                || ! Storage::disk('local')->exists($payout->acknowledgment_path)) {
                throw ValidationException::withMessages(['payout' => 'The private signed Buyer acknowledgment file is missing.']);
            }

            $now = now();
            $payout->update([
                'status' => 'awaiting_finance_completion',
                'logistics_verified_at' => $now,
                'logistics_verified_by' => $user->id,
            ]);
            $this->event($payout, 'buyer_acknowledgment_verified_by_logistics', 'delivered_pending_verification', 'awaiting_finance_completion', $user, (int) $payout->amount_minor, $payout->acknowledgment_reference, $note, $now);

            return $payout->fresh(['events']);
        }, attempts: 3);
    }

    public function completeAtFinance(User $admin, int $payoutId, string $reason, Request $request): RefundCashPayout
    {
        $this->assertAdmin($admin);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['completion_reason' => 'Describe how Finance verified the Buyer receipt.']);
        }

        $payout = RefundCashPayout::query()->whereKey($payoutId)->with('refund')->firstOrFail();
        if ($payout->status !== 'awaiting_finance_completion'
            || ! $payout->refund
            || $payout->acknowledgment_disk !== 'local'
            || ! filled($payout->acknowledgment_path)
            || ! Storage::disk('local')->exists($payout->acknowledgment_path)
            || ! $payout->logistics_verified_at) {
            throw ValidationException::withMessages(['payout' => 'Finance completion requires a Logistics-verified Buyer acknowledgment and intact private proof.']);
        }

        $file = new UploadedFile(
            Storage::disk('local')->path($payout->acknowledgment_path),
            $payout->acknowledgment_original_name ?: 'buyer-acknowledgment',
            $payout->acknowledgment_mime_type,
            UPLOAD_ERR_OK,
            true,
        );
        $request->merge(['external_payout_confirmed' => '1']);
        $this->returns->recordManualPayout(
            $admin,
            (int) $payout->refund_id,
            'cash',
            (string) $payout->acknowledgment_reference,
            $payout->delivered_at?->toDateString() ?? now()->toDateString(),
            $file,
            $request,
            (int) $payout->id,
            $reason,
        );

        return $payout->fresh(['refund', 'events']);
    }

    public function riderTasks(User $user)
    {
        $rider = $this->riderProfile($user);

        return RefundCashPayout::query()
            ->where('rider_profile_id', $rider->id)
            ->whereIn('status', ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery', 'cash_return_pending'])
            ->with(['refund.order.buyer', 'returnRequest.store', 'logisticsProfile'])
            ->orderBy('assigned_at')
            ->get();
    }

    public function logisticsWorkspace(User $user): array
    {
        $provider = $this->logisticsProfile($user);

        return [
            'codCollections' => CodCashCollection::query()
                ->where('logistics_profile_id', $provider->id)
                ->with(['order', 'sellerOrder.store', 'riderProfile.user'])
                ->whereIn('status', ['collected', 'disputed', 'received_by_logistics'])
                ->orderBy('collected_at')
                ->get(),
            'refundPayouts' => RefundCashPayout::query()
                ->where('logistics_profile_id', $provider->id)
                ->whereNotIn('status', ['completed'])
                ->with(['refund.order.buyer', 'returnRequest.store', 'riderProfile.user'])
                ->orderBy('created_at')
                ->get(),
            'riders' => $provider->riders()
                ->where('verification_status', 'approved')
                ->where('availability_status', 'available')
                ->whereHas('user', fn ($query) => $query->where('status', AccountStatus::Active->value))
                ->with('user')
                ->orderBy('id')
                ->get(),
        ];
    }

    public function acknowledgmentForLogistics(User $user, int $payoutId): RefundCashPayout
    {
        $provider = $this->logisticsProfile($user);
        $payout = RefundCashPayout::query()->whereKey($payoutId)->firstOrFail();
        $this->assertProvider($payout, $provider->id);
        abort_unless($payout->status !== 'awaiting_logistics_receipt'
            && $payout->acknowledgment_disk === 'local'
            && filled($payout->acknowledgment_path)
            && Storage::disk('local')->exists($payout->acknowledgment_path), 404);

        return $payout;
    }

    public function acknowledgmentSlipForLogistics(User $user, int $payoutId): RefundCashPayout
    {
        $provider = $this->logisticsProfile($user);
        $payout = RefundCashPayout::query()
            ->with(['refund.order.buyer', 'returnRequest.store', 'logisticsProfile', 'riderProfile.user'])
            ->whereKey($payoutId)
            ->firstOrFail();
        $this->assertProvider($payout, $provider->id);

        abort_unless(in_array($payout->status, ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery'], true)
            && $payout->rider_profile_id
            && filled($payout->acknowledgment_reference), 404);

        return $payout;
    }

    public function refundPayoutsForFinance()
    {
        return RefundCashPayout::query()
            ->with(['refund.order.buyer', 'returnRequest.store', 'logisticsProfile', 'riderProfile.user'])
            ->whereNotIn('status', ['completed'])
            ->orderBy('created_at')
            ->get();
    }

    private function riderPayout(User $user, int $payoutId): RefundCashPayout
    {
        $this->riderProfile($user);
        $payout = RefundCashPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
        $this->assertRiderOwns($payout, $user);

        return $payout;
    }

    private function riderProfile(User $user): RiderProfile
    {
        $rider = $user->riderProfile()->first();
        if ($user->role !== UserRole::Rider->value
            || $user->status !== AccountStatus::Active->value
            || ! $rider
            || $rider->verification_status !== 'approved') {
            throw new AuthorizationException('An active, approved Rider account is required.');
        }

        return $rider;
    }

    private function logisticsProfile(User $user): LogisticsProfile
    {
        $provider = $user->logisticsProfile()->first();
        if ($user->role !== UserRole::Logistics->value
            || $user->status !== AccountStatus::Active->value
            || ! $provider
            || $provider->status !== 'active') {
            throw new AuthorizationException('An active Logistics account is required.');
        }

        return $provider;
    }

    private function assertRiderOwns(RefundCashPayout $payout, User $user): void
    {
        $rider = $user->riderProfile;
        if (! $rider || (int) $payout->rider_profile_id !== (int) $rider->id
            || (int) $rider->logistics_profile_id !== (int) $payout->logistics_profile_id) {
            throw new AuthorizationException('This refund cash assignment does not belong to this Rider.');
        }
    }

    private function assertProvider(RefundCashPayout $payout, int $providerId): void
    {
        if ((int) $payout->logistics_profile_id !== $providerId) {
            throw new AuthorizationException('This refund cash belongs to another Logistics provider.');
        }
    }

    private function assertAmount(RefundCashPayout $payout, int $amountMinor, string $field): void
    {
        if ($amountMinor !== (int) $payout->amount_minor) {
            throw ValidationException::withMessages([$field => 'The cash amount must exactly match ₱'.number_format((int) $payout->amount_minor / 100, 2).'.']);
        }
    }

    private function assertStatus(RefundCashPayout $payout, string $expected, string $message): void
    {
        if ($payout->status !== $expected) {
            throw new ConflictHttpException($message);
        }
    }

    private function requiredReference(string $reference, string $field, string $message): string
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 150) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $reference;
    }

    private function assertAdmin(User $admin): void
    {
        if ($admin->role !== UserRole::Admin->value || $admin->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('An active Admin/Finance user is required.');
        }
    }

    private function event(
        RefundCashPayout $payout,
        string $type,
        ?string $fromStatus,
        ?string $toStatus,
        User $actor,
        ?int $amount,
        ?string $reference,
        ?string $note,
        mixed $occurredAt,
    ): void {
        RefundCashPayoutEvent::query()->create([
            'refund_cash_payout_id' => $payout->id,
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actor->id,
            'amount_minor' => $amount,
            'reference' => $reference,
            'note' => $note,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function safeOriginalName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'buyer-acknowledgment';

        return Str::limit($safe, 200, '');
    }

    private function acknowledgmentReferenceForAttempt(RefundCashPayout $payout): string
    {
        $attempt = (int) $payout->delivery_attempt_count + 1;

        return sprintf('ACK-%s-A%02d', $payout->payout_no, $attempt);
    }
}
