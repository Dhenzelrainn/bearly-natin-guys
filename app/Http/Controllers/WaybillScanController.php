<?php

namespace App\Http\Controllers;

use App\Models\SortingCenter;
use App\Models\Waybill;
use App\Services\ParcelIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaybillScanController extends Controller
{
    public function show(string $identifier): JsonResponse
    {
        $waybill = Waybill::with(['shipment.sellerOrder.order', 'shipment.sellerOrder.store', 'shipment.parcels', 'shipment.events'])->where('scan_token', $identifier)->orWhere('waybill_no', $identifier)->orWhere('barcode_value', $identifier)->firstOrFail();

        return response()->json(['data' => ['waybill_no' => $waybill->waybill_no, 'seller_order_no' => $waybill->shipment->sellerOrder->seller_order_no, 'store' => $waybill->shipment->sellerOrder->store->name, 'pieces' => $waybill->piece_count, 'weight_kg' => $waybill->total_weight_kg, 'destination' => $waybill->shipment->sellerOrder->order->city_municipality.', '.$waybill->shipment->sellerOrder->order->province, 'status' => $waybill->shipment->status, 'parcels' => $waybill->shipment->parcels->map->only(['parcel_no', 'status', 'weight_kg'])]]);
    }

    public function receive(Request $request, string $identifier, ParcelIntakeService $service): JsonResponse
    {
        $data = $request->validate(['sorting_center_id' => ['required', 'exists:sorting_centers,id'], 'scan_method' => ['nullable', 'in:camera,barcode,manual,image']]);
        $waybill = Waybill::where('scan_token', $identifier)->orWhere('waybill_no', $identifier)->orWhere('barcode_value', $identifier)->firstOrFail();
        $center = SortingCenter::findOrFail($data['sorting_center_id']);
        abort_unless($center->logistics_profile_id === $request->user()->logisticsProfile?->id, 403);

        return response()->json(['message' => 'Parcel received at sorting center.', 'data' => $service->receive($waybill, $center, $request->user(), $data['scan_method'] ?? 'manual')]);
    }
}
