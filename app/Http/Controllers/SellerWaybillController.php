<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Waybill;
use App\Services\AuditService;
use App\Services\SellerIdentityService;
use App\Services\WaybillPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SellerWaybillController extends Controller
{
    public function index(Request $request, SellerIdentityService $identity): View
    {
        $storeId = (int) ($request->user()->sellerProfile?->store?->id ?? 0);
        abort_unless($storeId > 0, 404, 'Seller Store not found.');

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'ready', 'printed', 'voided'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $status = $filters['status'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));

        $owned = $this->ownedWaybills($storeId);
        $readyCount = (clone $owned)->whereNull('printed_at')->whereNull('voided_at')->where('status', '!=', 'voided')->count();
        $printedCount = (clone $owned)->whereNotNull('printed_at')->whereNull('voided_at')->count();
        $voidedCount = (clone $owned)->where(fn (Builder $query) => $query
            ->whereNotNull('voided_at')
            ->orWhere('status', 'voided'))->count();
        $waybillMorph = (new Waybill)->getMorphClass();
        $ownedWaybillIds = (clone $owned)->select('waybills.id');
        $generatedPdfIds = AuditLog::query()
            ->where('auditable_type', $waybillMorph)
            ->whereIn('auditable_id', $ownedWaybillIds)
            ->where('action', 'seller_waybill.pdf_generated')
            ->distinct()
            ->pluck('auditable_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $waybills = (clone $owned)
            ->with([
                'shipment.sellerOrder.order.buyer',
                'shipment.sellerOrder.store',
                'shipment.logisticsProfile',
                'shipment.originAddress' => fn ($query) => $query->withTrashed(),
                'parcels.pickupRequests',
            ])
            ->when($status === 'ready', fn (Builder $query) => $query
                ->whereNull('printed_at')->whereNull('voided_at')->where('status', '!=', 'voided'))
            ->when($status === 'printed', fn (Builder $query) => $query
                ->whereNotNull('printed_at')->whereNull('voided_at'))
            ->when($status === 'voided', fn (Builder $query) => $query
                ->where(fn (Builder $voided) => $voided->whereNotNull('voided_at')->orWhere('status', 'voided')))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($search): void {
                $like = '%'.$search.'%';
                $match->where('waybills.waybill_no', 'like', $like)
                    ->orWhereHas('shipment.sellerOrder', fn (Builder $sellerOrders) => $sellerOrders->where('seller_order_no', 'like', $like))
                    ->orWhereHas('shipment.sellerOrder.order', fn (Builder $orders) => $orders
                        ->where('recipient_name', 'like', $like)
                        ->orWhere('city_municipality', 'like', $like)
                        ->orWhere('province', 'like', $like));
            }))
            ->orderByDesc('waybills.generated_at')
            ->orderByDesc('waybills.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Waybill $waybill): array => $this->row($waybill, $generatedPdfIds));

        $history = AuditLog::query()
            ->where('auditable_type', $waybillMorph)
            ->whereIn('auditable_id', $ownedWaybillIds)
            ->whereIn('action', ['seller_waybill.pdf_generated', 'seller_waybill.print_confirmed'])
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'order' => (string) ($entry->new_values['seller_order_no'] ?? '—'),
                'tracking' => (string) ($entry->new_values['waybill_no'] ?? '—'),
                'action' => $entry->action === 'seller_waybill.pdf_generated'
                    ? 'PDF generated'
                    : 'Printed and attached',
                'actor' => $entry->actor?->name ?? 'Former user',
                'time' => $entry->created_at?->format('M j, Y g:i A') ?? '—',
            ])
            ->all();

        return view('seller.fulfillment.waybills', [
            'seller' => $identity->current(),
            'notifications' => [],
            'statusFilter' => $status,
            'search' => $search,
            'counts' => [
                'all' => (clone $owned)->count(),
                'ready' => $readyCount,
                'printed' => $printedCount,
                'voided' => $voidedCount,
            ],
            'waybills' => $waybills,
            'history' => $history,
        ]);
    }

    public function downloadPdf(
        Request $request,
        WaybillPdfService $pdfService,
        AuditService $audit,
    ): Response {
        $data = $request->validate([
            'waybill_ids' => ['required', 'array', 'min:1', 'max:25'],
            'waybill_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $storeId = (int) ($request->user()->sellerProfile?->store?->id ?? 0);
        abort_unless($storeId > 0, 404, 'Seller Store not found.');
        $ids = array_map('intval', $data['waybill_ids']);

        $pdfBytes = DB::transaction(function () use ($storeId, $ids, $request, $pdfService, $audit): string {
            $waybills = $this->ownedWaybills($storeId)
                ->whereIn('waybills.id', $ids)
                ->with([
                    'shipment.sellerOrder.order.buyer',
                    'shipment.sellerOrder.store',
                    'shipment.originAddress' => fn ($query) => $query->withTrashed(),
                    'parcels',
                ])
                ->orderBy('waybills.id')
                ->lockForUpdate()
                ->get();

            abort_unless($waybills->count() === count($ids), 404, 'One or more waybills were not found for this Store.');
            abort_if($waybills->contains(fn (Waybill $waybill): bool => $waybill->voided_at || $waybill->status === 'voided'), 409, 'Voided waybills cannot be downloaded.');

            $pdf = $pdfService->render($waybills);

            foreach ($waybills as $waybill) {
                $audit->record(
                    'seller_waybill.pdf_generated',
                    'fulfillment',
                    $waybill,
                    'Seller generated a printable PDF waybill.',
                    [],
                    [
                        'waybill_no' => $waybill->waybill_no,
                        'seller_order_no' => $waybill->shipment->sellerOrder->seller_order_no,
                    ],
                    'info',
                    $request,
                );
            }

            return $pdf;
        }, attempts: 3);

        $filename = count($ids) === 1
            ? 'Bearly-Waybill-'.$ids[0].'.pdf'
            : 'Bearly-Waybills-'.now()->format('Ymd-His').'.pdf';

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdfBytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function confirmPrinted(
        Request $request,
        int $waybill,
        AuditService $audit,
    ): RedirectResponse {
        $storeId = (int) ($request->user()->sellerProfile?->store?->id ?? 0);
        abort_unless($storeId > 0, 404, 'Seller Store not found.');

        DB::transaction(function () use ($request, $waybill, $storeId, $audit): void {
            $record = $this->ownedWaybills($storeId)
                ->whereKey($waybill)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($record->voided_at || $record->status === 'voided', 409, 'Voided waybills cannot be marked as printed.');

            $pdfGenerated = AuditLog::query()
                ->where('auditable_type', $record->getMorphClass())
                ->where('auditable_id', $record->id)
                ->where('action', 'seller_waybill.pdf_generated')
                ->exists();
            abort_unless($pdfGenerated, 409, 'Download the waybill PDF before confirming it was printed and attached.');

            $oldCount = (int) $record->print_count;
            abort_if($oldCount >= 65535, 409, 'The waybill print counter has reached its limit.');
            $printedAt = now();
            $newCount = $oldCount + 1;
            $record->update([
                'printed_at' => $printedAt,
                'print_count' => $newCount,
            ]);

            $audit->record(
                'seller_waybill.print_confirmed',
                'fulfillment',
                $record,
                'Seller confirmed that the waybill label was printed and attached to the parcel.',
                ['print_count' => $oldCount],
                [
                    'waybill_no' => $record->waybill_no,
                    'seller_order_no' => $record->shipment->sellerOrder->seller_order_no,
                    'print_count' => $newCount,
                    'printed_at' => $printedAt->toIso8601String(),
                ],
                'info',
                $request,
            );
        }, attempts: 3);

        return redirect()->route('seller.fulfillment.waybills')->with('success', 'Waybill marked as printed and attached.');
    }

    private function ownedWaybills(int $storeId): Builder
    {
        return Waybill::query()->whereHas('shipment', fn (Builder $shipments) => $shipments
            ->where('purpose', 'outbound')
            ->whereHas('sellerOrder', fn (Builder $sellerOrders) => $sellerOrders->where('store_id', $storeId)));
    }

    private function row(Waybill $waybill, array $generatedPdfIds): array
    {
        $shipment = $waybill->shipment;
        $sellerOrder = $shipment->sellerOrder;
        $order = $sellerOrder->order;
        $pickupRequest = $waybill->parcels
            ->flatMap(fn ($parcel) => $parcel->pickupRequests)
            ->sortByDesc('created_at')
            ->first();
        $isVoided = (bool) $waybill->voided_at || $waybill->status === 'voided';
        $isPrinted = ! $isVoided && (bool) $waybill->printed_at;

        return [
            'id' => (int) $waybill->id,
            'order' => (string) $sellerOrder->seller_order_no,
            'customer' => (string) ($order->recipient_name ?: $order->buyer?->name ?: 'Recipient'),
            'tracking' => (string) $waybill->waybill_no,
            'courier' => (string) ($shipment->logisticsProfile?->display_name ?? 'Bearly Logistics'),
            'packages' => max(1, (int) $waybill->piece_count),
            'weight' => number_format((float) $waybill->total_weight_kg, 3).' kg',
            'size' => implode(' × ', array_map(
                fn ($value): string => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : '—',
                [$waybill->length_cm, $waybill->width_cm, $waybill->height_cm],
            )).' cm',
            'destination' => implode(', ', array_filter([
                $order->city_municipality,
                $order->province,
            ], fn ($part): bool => filled($part))),
            'pickup' => $pickupRequest?->requested_date?->format('M j, Y') && $pickupRequest?->window_start?->format('g:i A')
                ? $pickupRequest->requested_date->format('M j, Y').' · '.$pickupRequest->window_start->format('g:i A')
                : 'Schedule unavailable',
            'status' => $isVoided ? 'Voided' : ($isPrinted ? 'Printed & Attached' : 'Ready to Print'),
            'status_key' => $isVoided ? 'voided' : ($isPrinted ? 'printed' : 'ready'),
            'pdf_generated' => in_array((int) $waybill->id, $generatedPdfIds, true),
            'print_count' => (int) $waybill->print_count,
            'generated_at' => $waybill->generated_at?->format('M j, Y g:i A') ?? '—',
        ];
    }
}
