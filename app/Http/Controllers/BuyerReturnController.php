<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Enums\ReturnDeliveryMethod;
use App\Models\ReturnEvidence;
use App\Models\ReturnRequest;
use App\Services\AuditService;
use App\Services\ReturnRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BuyerReturnController extends Controller
{
    public function create(Request $request, int $sellerOrder, ReturnRefundService $returns): View
    {
        return view('buyer.returns.create', $returns->buyerWorkspace($request->user(), $sellerOrder));
    }

    public function store(Request $request, int $sellerOrder, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['return_refund', 'refund_only'])],
            'return_delivery_method' => [
                Rule::requiredIf(fn (): bool => $request->input('request_type') === 'return_refund'),
                'nullable',
                Rule::in(array_map(static fn (ReturnDeliveryMethod $method): string => $method->value, ReturnDeliveryMethod::cases())),
            ],
            'reason_code' => ['required', Rule::in([
                'item_arrived_damaged',
                'wrong_item_received',
                'item_not_as_described',
                'missing_item',
                'other',
            ])],
            'item_quantities' => ['required', 'array', 'min:1', 'max:50'],
            'item_quantities.*' => ['nullable', 'integer', 'min:0', 'max:500'],
            'buyer_note' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $returnRequest = $returns->createBuyerRequest(
            $request->user(),
            $sellerOrder,
            $data['item_quantities'],
            $data['request_type'],
            $data['reason_code'],
            $data['buyer_note'] ?? null,
            array_values($request->file('evidence', [])),
            $data['request_type'] === 'return_refund'
                ? ($data['return_delivery_method'] ?? null)
                : null,
        );

        return redirect()
            ->route('buyer.returns.show', $returnRequest->id)
            ->with('success', 'Your request was sent to the Seller for a response.');
    }

    public function switchToPickup(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $case = $returns->switchExpiredDropoffToPickup($request->user(), $returnRequest);

        return redirect()
            ->route('buyer.returns.show', $case->id)
            ->with('success', 'Rider pickup has been requested. Logistics will arrange collection from your order address.');
    }

    public function show(Request $request, int $returnRequest): View
    {
        $case = ReturnRequest::query()
            ->whereKey($returnRequest)
            ->where('buyer_id', $request->user()->id)
            ->with([
                'order',
                'store',
                'sellerOrder',
                'items.orderItem',
                'evidence.uploader',
                'events.actor',
                'returnShipment.events',
                'returnShipment.waybill',
                'returnShipment.originAddress',
                'refunds',
            ])
            ->firstOrFail();
        $dropoffCenter = $case->return_delivery_method === ReturnDeliveryMethod::BuyerDropoff->value
            ? data_get($case->returnShipment?->return_shipping_quote, 'dropoff_center')
            : null;
        $dropoffCenter = is_array($dropoffCenter) ? $dropoffCenter : null;
        $evidenceRequest = $case->events->where('event_type', 'additional_evidence_requested')->last();
        $buyerEvidenceSubmitted = $evidenceRequest && $case->events->contains(
            fn ($event): bool => (int) $event->id > (int) $evidenceRequest->id
                && $event->event_type === 'buyer_evidence_submitted',
        );

        return view('buyer.returns.show', [
            'case' => $case,
            'dropoffCenter' => $dropoffCenter,
            'evidenceRequestedFrom' => (string) ($evidenceRequest?->metadata['requested_from'] ?? 'both'),
            'buyerEvidenceSubmitted' => $buyerEvidenceSubmitted,
        ]);
    }

    public function addEvidence(Request $request, int $returnRequest, ReturnRefundService $returns): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $files = array_values($request->file('evidence', []));
        if ($files === [] && ! filled($data['note'] ?? null)) {
            throw ValidationException::withMessages(['evidence' => 'Add a note or at least one image.']);
        }

        $returns->addBuyerEvidence($request->user(), $returnRequest, $data['note'] ?? null, $files);

        return back()->with('success', 'Additional information was sent to the Admin reviewer.');
    }

    public function downloadEvidence(Request $request, ReturnEvidence $evidence): BinaryFileResponse
    {
        $case = $evidence->returnRequest;
        abort_unless($case && (int) $case->buyer_id === (int) $request->user()->id, 404);
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);

        return response()->download(
            Storage::disk('local')->path($evidence->file_path),
            $this->downloadName($evidence->original_name),
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function downloadRefundReceipt(Request $request, Refund $refund, AuditService $audit): BinaryFileResponse
    {
        $case = $refund->returnRequest;
        abort_unless($refund->status === 'completed'
            && $case
            && (int) $case->buyer_id === (int) $request->user()->id
            && $refund->payout_receipt_disk === 'local'
            && filled($refund->payout_receipt_path)
            && Storage::disk('local')->exists($refund->payout_receipt_path), 404);

        $audit->record(
            action: 'refund.payout_receipt_downloaded',
            module: 'Returns & Refunds',
            subject: $refund,
            description: "Buyer downloaded the payout receipt for {$refund->refund_no}.",
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

    private function downloadName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'return-evidence';

        return Str::limit($safe, 200, '');
    }
}
