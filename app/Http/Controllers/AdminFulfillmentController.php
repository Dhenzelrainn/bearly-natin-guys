<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Waybill;
use App\Services\AuditService;
use App\Services\WaybillPdfService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AdminFulfillmentController extends Controller
{
    private const PICKUP_SCAN_EVENTS = [
        'parcel_picked_up',
        'shipment_picked_up',
    ];

    private const MOVEMENT_EVENTS = [
        'parcel_picked_up',
        'shipment_picked_up',
        'received_at_center',
        'logistics_handoff_sent',
        'logistics_handoff_accepted',
        'parcel_sorted',
        'parcel_resorted',
        'parcel_dispatched',
        'shipment_dispatched',
        'parcel_out_for_delivery',
        'shipment_out_for_delivery',
        'parcel_delivered',
        'shipment_delivered',
        'parcel_delivery_failed',
        'shipment_delivery_failed',
        'parcel_delivery_retried',
        'shipment_delivery_retried',
    ];

    private const POST_PICKUP_STATUSES = [
        ShipmentStatus::PickedUp->value,
        ShipmentStatus::AtSortingCenter->value,
        ShipmentStatus::HandoffPending->value,
        ShipmentStatus::Sorted->value,
        ShipmentStatus::Dispatched->value,
        ShipmentStatus::OutForDelivery->value,
        ShipmentStatus::Delivered->value,
        ShipmentStatus::DeliveryFailed->value,
        ShipmentStatus::ReturnToSender->value,
        'in_transit',
        'exception',
    ];

    public function index(Request $request): View
    {
        $shipments = Shipment::query()
            ->with([
                'sellerOrder.order.buyer',
                'sellerOrder.store',
                'waybill.parcels',
                'events' => fn ($query) => $query->orderBy('occurred_at')->orderBy('id'),
                'parcels.deliveryAttempts.proofs',
                'parcels.pickupRequests',
            ])
            ->latest('id')
            ->paginate(15)
            ->through(function (Shipment $shipment): Shipment {
                $shipment->setAttribute('tracking_review_signal', $this->trackingReviewSignal($shipment));

                return $shipment;
            });

        $admin = $request->user();

        return view('admin.fulfillment.index', [
            'admin' => [
                'name' => $admin->name,
                'role' => 'Admin',
                'email' => $admin->email,
                'initials' => collect(explode(' ', $admin->name))
                    ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
                    ->take(2)
                    ->implode(''),
            ],
            'topNotifications' => [],
            'shipments' => $shipments,
            'metrics' => [
                'pending_pickup' => Shipment::query()->whereIn('status', [
                    'pending_waybill',
                    ShipmentStatus::ReadyForPickup->value,
                ])->count(),
                'intake' => Shipment::query()->where('status', ShipmentStatus::AtSortingCenter->value)->count(),
                'transit' => Shipment::query()->whereIn('status', [
                    'in_transit',
                    ShipmentStatus::OutForDelivery->value,
                ])->count(),
                'delivered' => Shipment::query()->where('status', ShipmentStatus::Delivered->value)->count(),
                'failed' => Shipment::query()->whereIn('status', [
                    ShipmentStatus::DeliveryFailed->value,
                    'exception',
                ])->count(),
                'tracking_review' => $this->expiredPickupWindowCount(),
            ],
        ]);
    }

    public function downloadWaybillPdf(
        Request $request,
        int $waybill,
        WaybillPdfService $pdfService,
        AuditService $audit,
    ): Response {
        $pdfBytes = DB::transaction(function () use ($request, $waybill, $pdfService, $audit): string {
            $record = Waybill::query()
                ->whereKey($waybill)
                ->whereHas('shipment', fn ($query) => $query->where('purpose', 'outbound'))
                ->with([
                    'shipment.sellerOrder.order:id,order_no,recipient_name,address_line,barangay,city_municipality,province,postal_code',
                    'shipment.sellerOrder.store',
                    'shipment.sellerOrders.order:id,order_no',
                    'shipment.originAddress' => fn ($query) => $query->withTrashed(),
                    'parcels',
                ])
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($record->voided_at || $record->status === 'voided', 409, 'Voided waybills cannot be downloaded.');

            $pdf = $pdfService->render(collect([$record]));

            $audit->record(
                action: 'admin_waybill.pdf_generated',
                module: 'fulfillment',
                subject: $record,
                description: 'Administrator generated a printable Waybill PDF containing delivery address data.',
                oldValues: [],
                newValues: [
                    'waybill_no' => $record->waybill_no,
                    'shipment_no' => $record->shipment?->shipment_no,
                    'seller_order_nos' => $record->shipment?->includedSellerOrders()->pluck('seller_order_no')->values()->all() ?? [],
                ],
                severity: 'warning',
                request: $request,
            );

            return $pdf;
        }, attempts: 3);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Bearly-Waybill-'.$waybill.'.pdf"',
            'Content-Length' => (string) strlen($pdfBytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function override(Request $request, Shipment $shipment, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                'pending_waybill',
                ShipmentStatus::ReadyForPickup->value,
                ShipmentStatus::AtSortingCenter->value,
                'in_transit',
                ShipmentStatus::OutForDelivery->value,
                ShipmentStatus::Delivered->value,
                ShipmentStatus::DeliveryFailed->value,
                'exception',
            ])],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $shipment, $data, $audit): void {
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $old = $shipment->status;
            $shipment->update([
                'status' => $data['status'],
                'delivered_at' => $data['status'] === ShipmentStatus::Delivered->value
                    ? ($shipment->delivered_at ?? now())
                    : $shipment->delivered_at,
            ]);

            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'event_type' => 'admin_override',
                'from_status' => $old,
                'to_status' => $data['status'],
                'actor_user_id' => $request->user()->id,
                'source' => 'admin',
                'notes' => $data['note'],
                'occurred_at' => now(),
            ]);

            $audit->record(
                'shipment.status_overridden',
                'fulfillment',
                $shipment,
                'Administrator overrode shipment status.',
                ['status' => $old],
                ['status' => $data['status'], 'note' => $data['note']],
                'warning',
                $request,
            );
        }, attempts: 3);

        return back()->with('success', 'Shipment status updated.');
    }

    /**
     * @return array{label: string, detail: string}|null
     */
    private function trackingReviewSignal(Shipment $shipment): ?array
    {
        if ($shipment->purpose !== 'outbound') {
            return null;
        }

        $events = $shipment->events;

        if (in_array($shipment->status, self::POST_PICKUP_STATUSES, true)
            && ! $events->contains(fn ($event): bool => in_array($event->event_type, self::MOVEMENT_EVENTS, true))) {
            return [
                'label' => 'Movement status has no scan history',
                'detail' => 'Verify the operational records and custody before taking action. This signal alone is not proof of misconduct.',
            ];
        }

        $hasPickupScan = $events->contains(fn ($event): bool => in_array($event->event_type, self::PICKUP_SCAN_EVENTS, true));

        if ($hasPickupScan || ! in_array($shipment->status, [
            ShipmentStatus::ReadyForPickup->value,
            ShipmentStatus::PickupAssigned->value,
        ], true)) {
            return null;
        }

        $expiredAppointment = $shipment->parcels
            ->flatMap(fn ($parcel) => $parcel->pickupRequests)
            ->filter(fn ($pickup): bool => $pickup->status === 'scheduled' && filled($pickup->window_end))
            ->filter(fn ($pickup): bool => Carbon::parse($pickup->window_end)->isPast())
            ->sortByDesc('window_end')
            ->first();

        if (! $expiredAppointment) {
            return null;
        }

        return [
            'label' => 'Scheduled pickup window passed without a pickup scan',
            'detail' => 'Confirm the appointment and custody with Logistics. A missing scan alone is not proof of misconduct.',
        ];
    }

    private function expiredPickupWindowCount(): int
    {
        return Shipment::query()
            ->where('purpose', 'outbound')
            ->whereIn('status', [
                ShipmentStatus::ReadyForPickup->value,
                ShipmentStatus::PickupAssigned->value,
            ])
            ->whereHas('parcels.pickupRequests', fn ($query) => $query
                ->where('pickup_requests.status', 'scheduled')
                ->whereNotNull('pickup_requests.window_end')
                ->where('pickup_requests.window_end', '<', now()))
            ->whereDoesntHave('events', fn ($query) => $query->whereIn('event_type', self::PICKUP_SCAN_EVENTS))
            ->distinct('shipments.id')
            ->count('shipments.id');
    }
}
