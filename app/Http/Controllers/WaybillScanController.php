<?php

namespace App\Http\Controllers;

use App\Models\SortingCenter;
use App\Models\Parcel;
use App\Models\Waybill;
use App\Services\ParcelIntakeService;
use App\Services\RiderPickupEarningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WaybillScanController extends Controller
{
    public function show(
        Request $request,
        string $identifier
    ): JsonResponse {
        $logisticsProfileId = $request->user()->logisticsProfile?->id;

        abort_unless($logisticsProfileId, 403);

        $matchedParcel = Parcel::query()
            ->where('parcel_no', $identifier)
            ->whereHas('waybill', fn ($query) => $query->forLogisticsProfile($logisticsProfileId))
            ->first();

        $waybillQuery = Waybill::query()
            ->forLogisticsProfile($logisticsProfileId)
            ->with([
                'shipment.sellerOrder.order',
                'shipment.sellerOrder.store',
                'shipment.sellerOrders.order',
                'shipment.sellerOrders.store',
                'shipment.events',
                'parcels.contents.orderItem',
                'parcels.events',
            ]);

        $waybill = $matchedParcel
            ? $waybillQuery->whereKey($matchedParcel->waybill_id)->firstOrFail()
            : $waybillQuery->matchingIdentifier($identifier)->firstOrFail();

        $shipment = $waybill->shipment;
        $sellerOrders = $shipment?->includedSellerOrders() ?? collect();
        $destination = $shipment?->purpose === 'return'
            ? trim(implode(', ', array_filter([
                data_get($shipment->destination_address_snapshot, 'city_municipality'),
                data_get($shipment->destination_address_snapshot, 'province'),
            ])))
            : trim(implode(', ', array_filter([
                $shipment?->sellerOrder?->order?->city_municipality,
                $shipment?->sellerOrder?->order?->province,
            ])));

        $parcels = $waybill->parcels->sortBy('piece_sequence')->values();
        $receivedPieces = $parcels->filter(fn (Parcel $parcel): bool => $parcel->events
            ->contains(fn ($event): bool => $event->event_type === 'received_at_center'))
            ->count();

        return response()->json([
            'data' => [
                'waybill_no' => $waybill->waybill_no,
                'purpose' => $shipment?->purpose,
                'purpose_label' => $shipment?->purpose === 'return' ? 'Return to Seller' : 'Buyer delivery',
                'seller_order_no' => $sellerOrders->pluck('seller_order_no')->implode(', '),
                'seller_order_numbers' => $sellerOrders->pluck('seller_order_no')->values()->all(),
                'store' => $sellerOrders->first()?->store?->name,
                'pieces' => $waybill->piece_count,
                'received_pieces' => $receivedPieces,
                'remaining_pieces' => max(0, $parcels->count() - $receivedPieces),
                'matched_parcel_id' => $matchedParcel?->id,
                'matched_parcel_no' => $matchedParcel?->parcel_no,
                'weight_kg' => $waybill->total_weight_kg,
                'destination' => $destination,
                'status' => $shipment?->status,
                'parcels' => $parcels->map(fn (Parcel $parcel): array => [
                    'id' => (int) $parcel->id,
                    'parcel_no' => (string) $parcel->parcel_no,
                    'piece_sequence' => (int) $parcel->piece_sequence,
                    'status' => (string) $parcel->status,
                    'weight_kg' => (string) $parcel->weight_kg,
                    'length_cm' => $parcel->length_cm,
                    'width_cm' => $parcel->width_cm,
                    'height_cm' => $parcel->height_cm,
                    'size_class' => $parcel->size_class,
                    'contents' => $parcel->contents
                        ->map(fn ($content): ?array => $content->orderItem ? [
                            'name' => (string) $content->orderItem->product_name,
                            'variant' => (string) $content->orderItem->variant_name,
                            'quantity' => (int) $content->quantity,
                        ] : null)
                        ->filter()
                        ->values(),
                ])->all(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function receive(
        Request $request,
        string $identifier,
        ParcelIntakeService $service,
        RiderPickupEarningService $pickupEarnings,
    ): JsonResponse {
        $data = $request->validate([
            'sorting_center_id' => [
                'required',
                'exists:sorting_centers,id',
            ],
            'scan_method' => [
                'nullable',
                'in:camera,barcode,manual,image',
            ],
        ]);

        $logisticsProfileId = $request->user()->logisticsProfile?->id;

        abort_unless($logisticsProfileId, 403);

        [$waybill, $parcel] = $this->resolveOwnedScanTarget($identifier, (int) $logisticsProfileId);

        $center = SortingCenter::query()
            ->where('logistics_profile_id', $logisticsProfileId)
            ->findOrFail($data['sorting_center_id']);
        $parcelSizeClasses = $this->validatedParcelSizeClasses(
            $request,
            $pickupEarnings,
            required: false,
        );

        $receivedWaybill = $service->receive(
            $waybill,
            $center,
            $request->user(),
            $data['scan_method'] ?? 'manual',
            $parcelSizeClasses,
            $parcel?->id,
        );

        return response()->json([
            'message' => $parcel
                ? "Parcel {$parcel->parcel_no} received at sorting center."
                : 'All Waybill parcels received at sorting center.',
            'data' => $receivedWaybill,
        ]);
    }

    public function receiveForm(
        Request $request,
        ParcelIntakeService $service,
        RiderPickupEarningService $pickupEarnings,
    ): RedirectResponse {
        $data = $request->validate([
            'identifier' => [
                'required',
                'string',
                'max:120',
            ],
            'sorting_center_id' => [
                'required',
                'integer',
            ],
            'scan_method' => [
                'nullable',
                'in:camera,barcode,manual,image',
            ],
        ]);

        $logisticsProfileId = $request
            ->user()
            ->logisticsProfile
            ?->id;

        abort_unless($logisticsProfileId, 403);

        $identifier = trim($data['identifier']);
        $parcel = Parcel::query()
            ->where('parcel_no', $identifier)
            ->whereHas('waybill', fn ($query) => $query->forLogisticsProfile($logisticsProfileId))
            ->first();

        if (! $parcel) {
            $waybill = Waybill::query()
                ->forLogisticsProfile($logisticsProfileId)
                ->matchingIdentifier($identifier)
                ->first();

            if ($waybill) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'identifier' => 'Scan or enter the unique Parcel barcode. A Waybill reference lists the pieces but cannot receive them all in the web intake form.',
                    ]);
            }

            abort(404);
        }

        $waybill = Waybill::query()
            ->forLogisticsProfile($logisticsProfileId)
            ->whereKey($parcel->waybill_id)
            ->firstOrFail();

        $center = SortingCenter::query()
            ->where(
                'logistics_profile_id',
                $logisticsProfileId
            )
            ->where('status', 'active')
            ->findOrFail($data['sorting_center_id']);
        $parcelSizeClasses = $this->validatedParcelSizeClasses(
            $request,
            $pickupEarnings,
            required: true,
        );

        try {
            $service->receive(
                $waybill,
                $center,
                $request->user(),
                $data['scan_method'] ?? 'manual',
                $parcelSizeClasses,
                (int) $parcel->id,
            );
        } catch (ConflictHttpException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'intake' => $exception->getMessage(),
                ]);
        }

        $receivedCount = Parcel::query()
            ->where('waybill_id', $waybill->id)
            ->whereHas('events', fn ($query) => $query->where('event_type', 'received_at_center'))
            ->count();

        return back()->with(
            'success',
            "{$parcel->parcel_no} was received at {$center->name}. {$receivedCount} of {$waybill->piece_count} Waybill pieces received."
        );
    }

    /**
     * Resolve either a unique Parcel barcode or a Waybill reference. A Waybill
     * target preserves the legacy JSON contract; web intake passes a Parcel ID.
     *
     * @return array{0: Waybill, 1: Parcel|null}
     */
    private function resolveOwnedScanTarget(string $identifier, int $logisticsProfileId): array
    {
        $parcel = Parcel::query()
            ->where('parcel_no', $identifier)
            ->whereHas('waybill', fn ($query) => $query->forLogisticsProfile($logisticsProfileId))
            ->first();

        if ($parcel) {
            $waybill = Waybill::query()
                ->forLogisticsProfile($logisticsProfileId)
                ->whereKey($parcel->waybill_id)
                ->firstOrFail();

            return [$waybill, $parcel];
        }

        return [Waybill::query()
            ->forLogisticsProfile($logisticsProfileId)
            ->matchingIdentifier($identifier)
            ->firstOrFail(), null];
    }

    /**
     * @return array<int|string, string>|null
     */
    private function validatedParcelSizeClasses(
        Request $request,
        RiderPickupEarningService $pickupEarnings,
        bool $required,
    ): ?array {
        $data = $request->validate([
            'parcel_size_classes' => [$required ? 'required' : 'sometimes', 'array'],
            'parcel_size_classes.*' => [
                'required',
                'string',
                Rule::in($pickupEarnings->sizeClasses()),
            ],
        ], [
            'parcel_size_classes.required' => 'Choose a size class for the selected parcel.',
        ]);

        return $data['parcel_size_classes'] ?? null;
    }
}
