<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\ReturnRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\AuditService;

class AdminReturnRefundController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService
    ) {}

    public function approve(
        Request $request,
        ReturnRequest $returnRequest
    ): RedirectResponse {
        DB::transaction(function () use ($request, $returnRequest): void {
            $returnRequest = ReturnRequest::query()
                ->whereKey($returnRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($returnRequest->status, [
                'approved',
                'rejected',
                'resolved',
            ], true)) {
                throw ValidationException::withMessages([
                    'return' => 'This return request already has a final decision.',
                ]);
            }

            $payment = $returnRequest->order
                ->payments()
                ->whereIn('status', ['paid', 'completed'])
                ->latest('paid_at')
                ->first();

            if (! $payment) {
                throw ValidationException::withMessages([
                    'return' => 'A completed payment is required before a refund can be approved.',
                ]);
            }

            $oldValues = [
                'status' => $returnRequest->status,
                'reviewed_by' => $returnRequest->reviewed_by,
                'reviewed_at' => $returnRequest->reviewed_at,
                'resolution' => $returnRequest->resolution,
            ];

            $returnRequest->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'resolved_at' => null,
                'resolution' => 'Refund approved by administrator.',
            ]);

            $refund = Refund::firstOrCreate(
                [
                    'return_request_id' => $returnRequest->id,
                ],
                [
                    'refund_no' => 'RFD-'.Str::upper(Str::random(12)),
                    'payment_id' => $payment->id,
                    'order_id' => $returnRequest->order_id,
                    'amount_minor' => $returnRequest->requested_amount_minor,
                    'status' => 'pending',
                    'method' => $payment->method,
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]
            );

            $this->auditService->record(
                action: 'return.refund_approved',
                module: 'Returns & Refunds',
                subject: $returnRequest,
                description: "{$returnRequest->return_no} was approved for refund.",
                oldValues: $oldValues,
                newValues: [
                    'status' => $returnRequest->status,
                    'reviewed_by' => $returnRequest->reviewed_by,
                    'reviewed_at' => $returnRequest->reviewed_at?->toDateTimeString(),
                    'resolution' => $returnRequest->resolution,
                    'refund_id' => $refund->id,
                    'refund_no' => $refund->refund_no,
                    'refund_status' => $refund->status,
                    'refund_amount_minor' => $refund->amount_minor,
                ],
                severity: 'info',
                request: $request,
            );
        });

        return back()->with(
            'success',
            "{$returnRequest->return_no} was approved for refund."
        );
    }

    public function reject(
        Request $request,
        ReturnRequest $returnRequest
    ): RedirectResponse {
        DB::transaction(function () use ($request, $returnRequest): void {
            $returnRequest = ReturnRequest::query()
                ->whereKey($returnRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($returnRequest->status, [
                'approved',
                'rejected',
                'resolved',
            ], true)) {
                throw ValidationException::withMessages([
                    'return' => 'This return request already has a final decision.',
                ]);
            }

            $oldValues = [
                'status' => $returnRequest->status,
                'reviewed_by' => $returnRequest->reviewed_by,
                'reviewed_at' => $returnRequest->reviewed_at,
                'resolved_at' => $returnRequest->resolved_at,
                'resolution' => $returnRequest->resolution,
            ];

            $returnRequest->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'resolved_at' => now(),
                'resolution' => 'Return or refund request rejected by administrator.',
            ]);

            $this->auditService->record(
                action: 'return.request_rejected',
                module: 'Returns & Refunds',
                subject: $returnRequest,
                description: "{$returnRequest->return_no} was rejected by the administrator.",
                oldValues: $oldValues,
                newValues: [
                    'status' => $returnRequest->status,
                    'reviewed_by' => $returnRequest->reviewed_by,
                    'reviewed_at' => $returnRequest->reviewed_at?->toDateTimeString(),
                    'resolved_at' => $returnRequest->resolved_at?->toDateTimeString(),
                    'resolution' => $returnRequest->resolution,
                ],
                severity: 'info',
                request: $request,
            );
        });

        return back()->with(
            'success',
            "{$returnRequest->return_no} was rejected."
        );
    }

    public function requestEvidence(
        Request $request,
        ReturnRequest $returnRequest
    ): RedirectResponse {
        DB::transaction(function () use ($request, $returnRequest): void {
            $returnRequest = ReturnRequest::query()
                ->whereKey($returnRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($returnRequest->status, [
                'approved',
                'rejected',
                'resolved',
            ], true)) {
                throw ValidationException::withMessages([
                    'return' => 'A finalized return request cannot request additional evidence.',
                ]);
            }

            $oldValues = [
                'status' => $returnRequest->status,
                'reviewed_by' => $returnRequest->reviewed_by,
                'reviewed_at' => $returnRequest->reviewed_at,
                'resolution' => $returnRequest->resolution,
            ];

            $returnRequest->update([
                'status' => 'awaiting_evidence',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'resolution' => 'Additional evidence requested by administrator.',
            ]);

            $this->auditService->record(
                action: 'return.evidence_requested',
                module: 'Returns & Refunds',
                subject: $returnRequest,
                description: "Additional evidence was requested for {$returnRequest->return_no}.",
                oldValues: $oldValues,
                newValues: [
                    'status' => $returnRequest->status,
                    'reviewed_by' => $returnRequest->reviewed_by,
                    'reviewed_at' => $returnRequest->reviewed_at?->toDateTimeString(),
                    'resolution' => $returnRequest->resolution,
                ],
                severity: 'info',
                request: $request,
            );
        });

        return back()->with(
            'success',
            "Additional evidence was requested for {$returnRequest->return_no}."
        );
    }
}