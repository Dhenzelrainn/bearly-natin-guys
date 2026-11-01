<?php

namespace App\Http\Controllers;

use App\Models\SortingCenter;
use App\Models\Waybill;
use App\Services\ParcelIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WaybillScanController extends Controller
{
    public function show(
        Request $request,
        string $identifier
    ): JsonResponse {
        $logisticsProfileId = $request->user()->logisticsProfile?->id;

        abort_unless($logisticsProfileId, 403);

        $waybill = Waybill::query()
            ->forLogisticsProfile($logisticsProfileId)
            ->matchingIdentifier($identifier)
            ->with([
                'shipment.sellerOrder.order',
                'shipment.sellerOrder.store',
                'shipment.parcels',
                'shipment.events',
            ])
            ->firstOrFail();

        $shipment = $waybill->shipment;
        $destination = $shipment?->purpose === 'return'
            ? trim(implode(', ', array_filter([
                data_get($shipment->destination_address_snapshot, 'city_municipality'),
                data_get($shipment->destination_address_snapshot, 'province'),
            ])))
            : trim(implode(', ', array_filter([
                $shipment?->sellerOrder?->order?->city_municipality,
                $shipment?->sellerOrder?->order?->province,
            ])));

        return response()->json([
            'data' => [
                'waybill_no' => $waybill->waybill_no,
                'purpose' => $shipment?->purpose,
                'purpose_label' => $shipment?->purpose === 'return' ? 'Return to Seller' : 'Buyer delivery',
                'seller_order_no' => $shipment?->sellerOrder?->seller_order_no,
                'store' => $shipment?->sellerOrder?->store?->name,
                'pieces' => $waybill->piece_count,
                'weight_kg' => $waybill->total_weight_kg,
                'destination' => $destination,
                'status' => $shipment?->status,
                'parcels' => $shipment?->parcels?->map(
                    fn ($parcel): array => $parcel->only(['parcel_no', 'status', 'weight_kg']),
                ) ?? [],
            ],
        ]);
    }

    public function receive(
        Request $request,
        string $identifier,
        ParcelIntakeService $service
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

        $waybill = Waybill::query()
            ->forLogisticsProfile($logisticsProfileId)
            ->matchingIdentifier($identifier)
            ->firstOrFail();

        $center = SortingCenter::query()
            ->where('logistics_profile_id', $logisticsProfileId)
            ->findOrFail($data['sorting_center_id']);

        $receivedWaybill = $service->receive(
            $waybill,
            $center,
            $request->user(),
            $data['scan_method'] ?? 'manual'
        );

        return response()->json([
            'message' => 'Parcel received at sorting center.',
            'data' => $receivedWaybill,
        ]);
    }

    public function receiveForm(
        Request $request,
        ParcelIntakeService $service
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

        $waybill = Waybill::query()
            ->forLogisticsProfile($logisticsProfileId)
            ->matchingIdentifier(trim($data['identifier']))
            ->firstOrFail();

        $center = SortingCenter::query()
            ->where(
                'logistics_profile_id',
                $logisticsProfileId
            )
            ->where('status', 'active')
            ->findOrFail($data['sorting_center_id']);

        $service->receive(
            $waybill,
            $center,
            $request->user(),
            $data['scan_method'] ?? 'manual'
        );

        return back()->with(
            'success',
            "{$waybill->waybill_no} was received at {$center->name}."
        );
    }
}
