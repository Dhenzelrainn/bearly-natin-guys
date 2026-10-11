<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\ReturnEvidence;
use App\Services\AuditService;
use App\Services\ReturnRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReturnRefundController extends Controller
{
    public function approve(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'financially_responsible_party' => ['required', Rule::in(['seller', 'platform'])],
            'financial_responsibility_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $case = $returns->adminApprove(
            $request->user(),
            $returnRequest,
            $data['financially_responsible_party'],
            trim($data['financial_responsibility_reason']),
            $request,
        );

        return back()->with('success', "{$case->return_no} was approved. Admin remains responsible for sending and recording the external Buyer payout.");
    }

    public function reject(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $case = $returns->adminReject($request->user(), $returnRequest, $data['reason'], $request);

        return back()->with('success', "{$case->return_no} was rejected with an audit reason.");
    }

    public function requestEvidence(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:1000'],
            'request_from' => ['required', Rule::in(['buyer', 'seller', 'both'])],
        ]);
        $case = $returns->adminRequestEvidence($request->user(), $returnRequest, $data['note'], $data['request_from'], $request);

        return back()->with('success', "Additional evidence was requested for {$case->return_no}.");
    }

    public function recordPayout(Request $request, Refund $refund, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'external_payout_confirmed' => ['required', 'accepted'],
            'method' => ['required', Rule::in(['gcash', 'bank_transfer', 'cash', 'other'])],
            'provider_reference' => ['required', 'string', 'max:150'],
            'external_paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'external_payout_confirmed.required' => 'Confirm that the external payout has already been made before recording it.',
            'external_payout_confirmed.accepted' => 'Confirm that the external payout has already been made before recording it.',
            'receipt.required' => 'Upload the payout receipt or Buyer acknowledgment before recording the completed payout.',
            'receipt.mimes' => 'The payout receipt must be a JPG, PNG, WebP, or PDF file.',
        ]);

        $recorded = $returns->recordManualPayout(
            $request->user(),
            (int) $refund->id,
            $data['method'],
            trim($data['provider_reference']),
            $data['external_paid_on'],
            $data['receipt'],
            $request,
        );

        return back()->with('success', "{$recorded->refund_no} payout was recorded. Bearly did not initiate the external transfer.");
    }

    public function recordResponsibility(Request $request, Refund $refund, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'financially_responsible_party' => ['required', Rule::in(['seller', 'platform'])],
            'financial_responsibility_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $returns->recordLegacyRefundResponsibility(
            $request->user(),
            (int) $refund->id,
            $data['financially_responsible_party'],
            trim($data['financial_responsibility_reason']),
            $request,
        );

        return back()->with('success', "Financial responsibility was recorded for {$refund->refund_no}.");
    }

    public function recordSellerRecovery(Request $request, Refund $refund, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'recovery_confirmed' => ['required', 'accepted'],
            'amount' => ['required', 'string', 'regex:/^\\d{1,8}(?:\\.\\d{1,2})?$/'],
            'method' => ['required', Rule::in(['external_collection', 'seller_payout_offset'])],
            'external_reference' => ['required', 'string', 'max:150'],
            'recovered_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
            'seller_payout_id' => [Rule::requiredIf(fn (): bool => $request->input('method') === 'seller_payout_offset'), 'nullable', 'integer', 'exists:seller_payouts,id'],
        ], [
            'recovery_confirmed.required' => 'Confirm that the Seller collection or payout offset has already occurred.',
            'recovery_confirmed.accepted' => 'Confirm that the Seller collection or payout offset has already occurred.',
            'seller_payout_id.required' => 'Select the paid Seller payout where the offset was applied.',
        ]);

        $recovery = $returns->recordSellerRecovery(
            $request->user(),
            (int) $refund->id,
            $this->amountToMinor($data['amount']),
            $data['method'],
            trim($data['external_reference']),
            $data['recovered_on'],
            trim($data['note']),
            isset($data['seller_payout_id']) ? (int) $data['seller_payout_id'] : null,
            $request,
        );

        return back()->with('success', 'Verified Seller recovery recorded. ₱'.number_format((int) $recovery->recovered_amount_minor / 100, 2).' recovered; the remaining balance is ₱'.number_format(((int) $recovery->amount_minor - (int) $recovery->recovered_amount_minor) / 100, 2).'.');
    }

    public function downloadPayoutReceipt(Request $request, Refund $refund, AuditService $audit): BinaryFileResponse
    {
        abort_unless($refund->status === 'completed'
            && $refund->payout_receipt_disk === 'local'
            && filled($refund->payout_receipt_path)
            && Storage::disk('local')->exists($refund->payout_receipt_path), 404);

        $audit->record(
            action: 'refund.payout_receipt_downloaded',
            module: 'Returns & Refunds',
            subject: $refund,
            description: "Payout receipt downloaded for {$refund->refund_no}.",
            oldValues: [],
            newValues: ['receipt_original_name' => $refund->payout_receipt_original_name],
            severity: 'info',
            request: $request,
        );

        return response()->download(
            Storage::disk('local')->path($refund->payout_receipt_path),
            $this->downloadName($refund->payout_receipt_original_name ?: 'refund-receipt'),
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function downloadEvidence(Request $request, ReturnEvidence $evidence, AuditService $audit): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);
        $case = $evidence->returnRequest;

        $audit->record(
            action: 'return.evidence_downloaded',
            module: 'Returns & Refunds',
            subject: $case,
            description: "Evidence downloaded for {$case?->return_no}.",
            oldValues: [],
            newValues: ['evidence_id' => $evidence->id, 'original_name' => $evidence->original_name],
            severity: 'info',
            request: $request,
        );

        return response()->download(
            Storage::disk('local')->path($evidence->file_path),
            $this->downloadName($evidence->original_name),
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function previewEvidence(Request $request, ReturnEvidence $evidence, AuditService $audit): StreamedResponse
    {
        $mimeType = strtolower((string) $evidence->mime_type);
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);

        $case = $evidence->returnRequest;
        $audit->record(
            action: 'return.evidence_previewed',
            module: 'Returns & Refunds',
            subject: $case,
            description: "Evidence previewed for {$case?->return_no}.",
            oldValues: [],
            newValues: ['evidence_id' => $evidence->id, 'original_name' => $evidence->original_name],
            severity: 'info',
            request: $request,
        );

        return Storage::disk('local')->response(
            $evidence->file_path,
            $this->downloadName($evidence->original_name),
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            ],
            'inline',
        );
    }

    private function downloadName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'return-evidence';

        return Str::limit($safe, 200, '');
    }

    private function amountToMinor(string $amount): int
    {
        if (! preg_match('/^(\\d{1,8})(?:\\.(\\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid amount with no more than two decimal places.']);
        }

        $fractionalDigits = str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);

        return ((int) $matches[1] * 100) + (int) $fractionalDigits;
    }
}
